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
 * `caster_duet`. A draft whose turns do not follow the drawn sequence is
 * turned down (TURNS). Repeats and sign-offs leave it in
 * ScriptQueue::enqueue(), line by line with their speakers.
 */
final class DuetScripts
{
    public const CHANNEL = 'caster_duet';

    /** The turns of a draft did not follow the drawn sequence (a turn missing, merged or by the wrong speaker). */
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
        $cutOff = $response->steps->last()?->finishReason === FinishReason::Length;
        $duet = $this->repair(self::parse($this->hygiene->clean($raw, true, 'de')), $sequence);

        if ($duet === null) {
            $this->usage->recordRejection(self::CHANNEL, [self::TURNS]);
            $this->usage->recordOutcome(self::CHANNEL, 'rejected');

            return [null, [self::TURNS]];
        }

        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $duet['text']));
        $violations = CasterVoice::free([
            ...$this->hygiene->check($spoken, 'de', [], null, $cutOff, null, [], $maxWords, ScriptGuard::maxCommas()),
            ...$this->voice->guard()->check($spoken, $facts, 100_000, null),
            ...$this->scriptGuard->check($duet['text'], count($sequence), 100, $maxWords),
        ]);

        if ($violations !== []) {
            $this->usage->recordRejection(self::CHANNEL, $violations);
            $this->usage->recordOutcome(self::CHANNEL, 'rejected');

            return [null, $violations];
        }

        $this->usage->recordOutcome(self::CHANNEL, 'accepted');

        return [$duet, []];
    }

    /**
     * The parsed turns repaired like a solo script, turn by turn (one
     * sentence per line, fragments and overlong sentences dropped while one
     * stays, at most the speaker's `duet.sentences.max`), as text with one
     * speaker per line; null when the turns do not follow `$sequence` or a
     * turn lost every sentence.
     *
     * @param  list<array{speaker: string, text: string}>  $turns
     * @param  list<string>  $sequence
     * @return array{text: string, speakers: list<string>}|null
     */
    public function repair(array $turns, array $sequence): ?array
    {
        if (array_column($turns, 'speaker') !== $sequence) {
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
     * before any speaker counts for Markus.
     *
     * @return list<array{speaker: string, text: string}>
     */
    public static function parse(string $text): array
    {
        [$lines, $speakers] = [[], []];
        $current = DuetDirector::MARKUS;

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
                ."\nJede spätere Wortmeldung greift den Inhalt der Wortmeldung direkt davor auf: zustimmen, frotzeln, widersprechen, einen Fakt aus dem Fakten-Block nachlegen, ein Bier- oder Düsseldorf-Köln-Witz. Die beiden reden miteinander, duzen sich, und im ganzen Wortwechsel fällt höchstens ein Name."
                ."\nBeide klingen wie in ihren Persona-Blöcken oben: Markus mit seinen Themen und Wendungen, Sirius ausführlich und mit eigener Meinung aus seinen Themengebieten, mit eigener kleiner Geschichte oder einem Seitenhieb, mit dem er Markus aufzieht; seine Wortmeldungen sind so lang wie die von Markus."
                ."\nSirius beginnt nie mit Zustimmungsfloskeln wie „Genau“, „Ja“, „Stimmt“, „Richtig“ oder dem Namen Markus, und jede seiner Wortmeldungen fängt anders an als die davor."
                ."\nHöchstens EIN Fakt aus dem Fakten-Block im ganzen Wortwechsel, keine Spielernamen, höchstens zwei Marker. Kein Abschied, keine Begrüßung und keine Ansage wie „und jetzt zu …“.",
            "Fakten (die einzige Quelle für Zahlen, Namen und Links)\n\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($topics !== []) {
            $parts[] = "Themenvorschläge (nimm eins)\n\n".implode("\n", array_map(fn (string $topic): string => '- '.$topic, $topics));
        }

        if ($previous !== []) {
            $parts[] = "So endete der letzte Wortwechsel (nur Daten; aufgreifen darf ihn nur die erste Wortmeldung, und nur wenn es passt, nichts davon wiederholen)\n\n"
                .implode("\n", array_map(fn (array $turn): string => (self::NAMES[$turn['speaker']] ?? 'Markus').': '.$turn['text'], $previous));
        }

        if ($rejected !== []) {
            $parts[] = "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.($code === self::TURNS
                ? 'Die Wortmeldungen folgten nicht der Reihenfolge oben: jede Zeile mit „Markus:“ oder „Sirius:“ beginnen, genau in dieser Abfolge.'
                : (VoicePrompt::REASON_HINTS[$code] ?? $code)), $rejected));
        }

        $parts[] = CasterVoice::varietyBlock();
        $parts[] = 'canon_id ist null. Schreib jetzt den Wortwechsel.';

        return implode("\n\n", $parts);
    }

    private static function count(int $number): string
    {
        return ['null', 'ein', 'zwei', 'drei', 'vier', 'fünf'][$number] ?? (string) $number;
    }
}
