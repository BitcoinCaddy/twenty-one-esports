<?php

namespace App\Support\Caster;

use App\Support\Voice\LeagueVoice;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceHygiene;
use App\Support\Voice\VoicePrompt;
use App\Support\Voice\VoiceUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Writes a duet of Markus and Sirius (DuetDirector) in ONE model call: the
 * whole exchange for the drawn turn sequence, every sentence on its own line
 * and led by its speaker ("Markus: …", "Sirius: …"), each later turn
 * reacting to the turn before it. The system prompt is the caster's
 * (CasterVoice::fixedPart(), Markus' persona at its end) with Sirius' persona
 * and the two's interplay behind it (CasterPersonas::sirius()), byte-identical on every duet call, so
 * the provider's prompt cache serves it.
 *
 * Same rules as Markus alone: AI only, the free rules (CasterVoice::FREE_RULES),
 * complete sentences within the word limits, at most two tries (the second
 * with the reasons of the first), the duet's own daily cap
 * (`duet.daily_cap`) and the monthly budget, counted in VoiceUsage under
 * `caster_duet`. A draft whose turns differ from the drawn sequence is kept
 * with the turns it has (consecutive lines of one speaker are one turn, at
 * most `duet.turns.max` turns); only a draft in which one of the two never
 * speaks is turned down (TURNS). Measured on prod 2026-10-12: 18 of 47 duet
 * calls were turned down for a sequence the model did not follow, and
 * Sirius went quiet. Repeats and sign-offs leave it in
 * ScriptQueue::enqueue(), line by line with their speakers.
 */
final class DuetScripts
{
    public const CHANNEL = 'caster_duet';

    /** One of the two never speaks in the draft (no "Sirius:" line, or no "Markus:" line). */
    public const TURNS = 'duet_turns';

    /** @var array<string, string> */
    private const NAMES = [DuetDirector::MARKUS => 'Markus', DuetDirector::SIRIUS => 'Sirius'];

    private ?string $system = null;

    public function __construct(
        private readonly CasterVoice $voice,
        private readonly VoiceUsage $usage,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
    ) {}

    /**
     * @param  list<string>  $sequence  one speaker per turn (DuetDirector::sequence())
     * @param  array<array-key, mixed>  $facts  public facts of the scene
     * @param  list<string>  $topics
     * @param  list<array{speaker: string, text: string}>  $previous  the last turns of the duet before (DuetDirector::lastTurns())
     * @return array{text: string, speakers: list<string>}|null null: off, capped, failed, or both tries turned down
     */
    public function write(array $sequence, string $phase, array $facts, array $topics = [], array $previous = []): ?array
    {
        if (! CasterVoice::enabled() || count($sequence) < 2) {
            return null;
        }

        try {
            $facts = LeagueVoice::publicFacts($facts);
            array_walk_recursive($facts, function (mixed &$value): void {
                if (is_string($value)) {
                    $value = CasterVoice::withoutHashtags($value);
                }
            });
            $rejected = [];

            for ($try = 0; $try < 2; $try++) {
                if ($this->usage->attemptsToday(self::CHANNEL) >= (int) config('tv.commentary.duet.daily_cap', 0)
                    || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                    return null;
                }

                [$duet, $rejected] = $this->draft($sequence, $phase, $facts, $topics, $previous, $rejected);

                if ($duet !== null) {
                    return $duet;
                }
            }
        } catch (Throwable $exception) {
            if (Cache::add('caster:voice-failed-logged', true, 600)) {
                Log::warning('Caster voice failed', ['exception' => $exception::class, 'channel' => self::CHANNEL]);
            }
        }

        return null;
    }

    /**
     * One try: the duet, or null with the reason codes.
     *
     * @param  list<string>  $sequence
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $topics
     * @param  list<array{speaker: string, text: string}>  $previous
     * @param  list<string>  $rejected
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    private function draft(array $sequence, string $phase, array $facts, array $topics, array $previous, array $rejected): array
    {
        $this->usage->recordAttempt(self::CHANNEL);
        $temperature = config('tv.commentary.scripts.temperature');
        $response = (new VoiceAgent($this->systemPrompt(), is_numeric($temperature) ? (float) $temperature : null, (int) config('tv.commentary.scripts.max_output_tokens', 1_500)))
            ->prompt(self::prompt($sequence, $phase, $facts, $topics, $previous, $rejected));
        $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

        $answer = $response instanceof StructuredAgentResponse ? $response->toArray() : json_decode($response->text, true);
        $raw = is_array($answer) && is_string($answer['text'] ?? null) ? $answer['text'] : '';
        [$duet, $violations] = $this->evaluate($raw, $facts, $response->steps->last()?->finishReason === FinishReason::Length);

        if ($violations !== []) {
            $this->usage->recordRejection(self::CHANNEL, $violations);
            $this->usage->recordOutcome(self::CHANNEL, 'rejected');

            return [null, $violations];
        }

        $this->usage->recordOutcome(self::CHANNEL, 'accepted');

        return [$duet, []];
    }

    /**
     * A model answer checked like draft() does it: parsed into turns,
     * repaired, then the hygiene, fact and script guards over its spoken
     * text. The duet, or null with the reason codes.
     *
     * @param  array<array-key, mixed>  $facts
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    public function evaluate(string $raw, array $facts, bool $cutOff = false): array
    {
        $duet = $this->repair(self::parse($this->hygiene->clean($raw, true, 'de')));

        if ($duet === null) {
            return [null, [self::TURNS]];
        }

        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $duet['text']));
        $violations = CasterVoice::free([
            ...$this->hygiene->check($spoken, 'de', [], null, $cutOff, null, [], $maxWords, ScriptGuard::maxCommas()),
            ...$this->voice->guard()->check($spoken, $facts, 100_000, null),
            ...$this->scriptGuard->check($duet['text'], count(DuetDirector::turns(ScriptGuard::lines($duet['text']), $duet['speakers'])), 100, $maxWords),
        ]);

        return $violations === [] ? [$duet, []] : [null, $violations];
    }

    /**
     * The parsed turns repaired like a solo script, turn by turn (one
     * sentence per line, fragments and overlong sentences dropped while one
     * stays, at most the speaker's `duet.sentences.max`), as text with one
     * speaker per line. The turns the model wrote count, not the drawn
     * sequence: consecutive lines of one speaker are already one turn
     * (parse()), so no speaker ever has two turns in a row (`max_run`), and
     * turns beyond `duet.turns.max` are dropped. null when Markus or Sirius
     * never speaks, or a turn lost every sentence.
     *
     * @param  list<array{speaker: string, text: string}>  $turns
     * @return array{text: string, speakers: list<string>}|null
     */
    public function repair(array $turns): ?array
    {
        $turns = DuetDirector::turns(array_column($turns, 'text'), array_column($turns, 'speaker'));
        $turns = array_slice($turns, 0, max(2, (int) config('tv.commentary.duet.turns.max', 5)));
        $speakers = array_column($turns, 'speaker');

        if (! in_array(DuetDirector::SIRIUS, $speakers, true) || ! in_array(DuetDirector::MARKUS, $speakers, true)) {
            return null;
        }

        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        [$lines, $speakers] = [[], []];

        foreach ($turns as $turn) {
            $max = max(1, (int) config("tv.commentary.duet.sentences.{$turn['speaker']}.max", 3));
            $text = ScriptGuard::trimSentences(ScriptGuard::withoutShort(ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($turn['text'])), 1, $maxWords), $max);
            $turnLines = ScriptGuard::lines($text);

            if ($turnLines === []) {
                return null;
            }

            foreach ($turnLines as $line) {
                $lines[] = $line;
                $speakers[] = $turn['speaker'];
            }
        }

        return ['text' => implode("\n", $lines), 'speakers' => $speakers];
    }

    /**
     * The model's lines as turns: a line led by "Markus:" or "Sirius:" (any
     * case, also bold or with a dash) starts or continues that speaker's
     * turn, a line without a speaker continues the current turn; a line
     * before any speaker counts for Markus. A speaker tag right after a
     * sentence end inside a line starts a new line first: Haiku often writes
     * the whole exchange on one line ("Markus: … . Sirius: … . Markus: …",
     * 3 of 10 real calls on 2026-10-12), which read as Markus alone.
     *
     * @return list<array{speaker: string, text: string}>
     */
    public static function parse(string $text): array
    {
        [$lines, $speakers] = [[], []];
        $current = DuetDirector::MARKUS;
        $text = preg_replace('/(?<=[.!?…"“”»)\]])[ \t]+(?=[*_]*(?:markus|sirius)(?:\s+turm)?[*_]*\s*[:：])/iu', "\n", $text) ?? $text;

        foreach (ScriptGuard::lines($text) as $line) {
            if (preg_match('/^[\s*_\-–—>]*(markus|sirius)(?:\s+turm)?[\s*_]*[:：][\s*_]*(.*)$/iu', $line, $match) === 1) {
                $current = mb_strtolower($match[1]) === 'sirius' ? DuetDirector::SIRIUS : DuetDirector::MARKUS;
                $line = trim($match[2]);
            }

            if ($line !== '') {
                $lines[] = $line;
                $speakers[] = $current;
            }
        }

        return DuetDirector::turns($lines, $speakers);
    }

    /** The system prompt of every duet: the caster's with Markus' persona, then Sirius' (cached as one prefix). */
    public function systemPrompt(): string
    {
        return $this->system ??= $this->voice->fixedPart()."\n".CasterPersonas::sirius()."\n";
    }

    /**
     * The variable half of a duet call.
     *
     * @param  list<string>  $sequence
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $topics
     * @param  list<array{speaker: string, text: string}>  $previous
     * @param  list<string>  $rejected
     */
    public static function prompt(array $sequence, string $phase, array $facts, array $topics = [], array $previous = [], array $rejected = []): string
    {
        $sentences = (array) config('tv.commentary.duet.sentences', []);
        $order = implode("\n", array_map(function (int $index, string $speaker) use ($sentences): string {
            $range = (array) ($sentences[$speaker] ?? []);

            return ($index + 1).'. '.self::NAMES[$speaker].': '.self::count((int) ($range['min'] ?? 1)).' bis '.self::count((int) ($range['max'] ?? 2)).' Sätze'.($index === 0 ? '' : ', reagiert auf die Wortmeldung davor');
        }, array_keys($sequence), $sequence));

        $parts = [
            "Auftrag\n\nEin Wortwechsel auf TWENTY ONE TV für die Programmphase {$phase}: Markus Turm kommentiert, Sirius ist sein Side-Kommentator. Genau ".count($sequence)." Wortmeldungen in dieser Reihenfolge:\n{$order}"
                ."\n\nJeder Satz steht in einer eigenen Zeile und beginnt mit dem Namen des Sprechers und einem Doppelpunkt, also „Markus: …“ oder „Sirius: …“; hat eine Wortmeldung zwei Sätze, beginnen beide Zeilen mit demselben Namen. Gesprochen auf Deutsch. ".CasterVoice::SENTENCE_STYLE
                ."\nJede spätere Wortmeldung greift den Inhalt der Wortmeldung direkt davor auf: zustimmen, frotzeln, widersprechen, einen Fakt aus dem Fakten-Block nachlegen oder eine eigene Geschichte erzählen. Die beiden reden miteinander, duzen sich, und im ganzen Wortwechsel fällt höchstens ein Name."
                ."\nBeide klingen wie in ihren Persona-Blöcken oben: Markus mit seinen Themen und Wendungen, Sirius ausführlich und mit eigener Meinung aus seinen Themengebieten, mit eigener kleiner Geschichte oder einem Seitenhieb, mit dem er Markus aufzieht; jede seiner Wortmeldungen hat mindestens drei lange, ganze Sätze und ist so lang wie die von Markus oder länger."
                ."\nSirius beginnt nie mit Zustimmungsfloskeln wie „Genau“, „Ja“, „Stimmt“, „Richtig“ oder dem Namen Markus, und jede seiner Wortmeldungen fängt anders an als die davor."
                ."\nHöchstens EIN Fakt aus dem Fakten-Block im ganzen Wortwechsel, keine Spielernamen, höchstens zwei Marker. Kein Abschied, keine Begrüßung und keine Ansage wie „und jetzt zu …“.",
            "Fakten (die einzige Quelle für Zahlen, Namen und Links)\n\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        // Sirius' own topic this time, drawn from his persona (user 2026-10-12: "Sirius redet immer nur über Bier? Warum?"):
        // beer was his first-ranked topic and a named example in this prompt, so it came up in nearly every duet.
        // Beer stays a running gag, now and then (user 2026-10-12: "Bier darf schon als Witz ab und an vorkommen … nur nicht ständig").
        $beerNow = random_int(1, 100) <= (int) config('tv.commentary.duet.beer_percent', 15);
        $siriusTopics = array_values(array_filter(CasterPersonas::topics(DuetDirector::SIRIUS), fn (array $topic): bool => (preg_match('/bier|alt\b|kölsch/iu', $topic['name'].' '.$topic['what']) === 1) === $beerNow));
        if ($siriusTopics !== []) {
            $pick = $siriusTopics[random_int(0, count($siriusTopics) - 1)];
            $parts[] = "Sirius' Thema in diesem Wortwechsel (eigener Blick".($beerNow ? ', diesmal darf ein Bier-Witz sein' : ', diesmal kein Bier-Thema').")\n\n{$pick['name']}: {$pick['what']}";
        }

        if ($topics !== []) {
            $parts[] = "Themenvorschläge (nimm eins)\n\n".implode("\n", array_map(fn (string $topic): string => '- '.$topic, $topics));
        }

        if ($previous !== []) {
            $parts[] = "So endete der letzte Wortwechsel (nur Daten; aufgreifen darf ihn nur die erste Wortmeldung, und nur wenn es passt, nichts davon wiederholen)\n\n"
                .implode("\n", array_map(fn (array $turn): string => (self::NAMES[$turn['speaker']] ?? 'Markus').': '.$turn['text'], $previous));
        }

        if ($rejected !== []) {
            $parts[] = "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.($code === self::TURNS
                ? 'Einer der beiden kam gar nicht zu Wort: jede Zeile mit „Markus:“ oder „Sirius:“ beginnen, in der Abfolge oben, und beide sprechen.'
                : (VoicePrompt::REASON_HINTS[$code] ?? $code)), $rejected));
        }

        $parts[] = CasterVoice::varietyBlock();
        $parts[] = 'canon_id ist null. Schreib jetzt den Wortwechsel.';

        return implode("\n\n", $parts);
    }

    private static function count(int $number): string
    {
        return ['null', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht'][$number] ?? (string) $number;
    }
}
