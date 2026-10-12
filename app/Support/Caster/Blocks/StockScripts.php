<?php

namespace App\Support\Caster\Blocks;

use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\ScriptGuard;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoicePrompt;
use App\Support\Voice\VoiceUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * The evergreen programmes of the day stock (user 2026-10-12: "lustige
 * Programme ohne aktuellen Bezug, sondern sinnvolles Besprechen; Programm
 * für Tutorials für Bitcoin-Dinge oder anderes, was zu EINUNDZWANZIG
 * passt"): one long talk of the stock's speakers (`stock.speakers`) per
 * model call, `stock.turns` turns of up to `stock.sentences_per_turn`
 * sentences, in one of the formats of `stock.formats`, rotating (the format
 * used longest ago that has material goes next):
 *
 *  - talk: one evergreen question (`stock.questions`) discussed with
 *    substance and a roast, hooked on two entries of the sourced canon;
 *  - tutorial: a Bitcoin tutorial (`stock.tutorials`), Markus explains and
 *    Sirius asks the dumb questions; general, well-known basics only, no
 *    financial or tax advice, no product or brand claims;
 *  - geschichte: a Geschichtsstunde of Bitcoin and the cypherpunks, on three
 *    bitcoin entries of the sourced canon;
 *  - podcast: the digest of the newest EINUNDZWANZIG episode (only with one);
 *  - liga: how the league works at any hour (CasterVoice::leagueBasics()).
 *
 * Every format brings its own Anchor jingle line where there is one
 * (`stock.jingles`, else the talk jingle; BlockComposer). The speakers are a
 * list, so a talk show of three or four needs only their voice profiles and
 * a parser that knows their names (DuetScripts::parse() knows Markus and
 * Sirius today).
 *
 * Evergreen: the block airs at an unknown hour of a later day, so the prompt
 * forbids any time of day, date or "heute", and every line that still names
 * one (ReplayArchive::TIMESTAMPED) leaves the draft; a draft left with fewer
 * than `turns.min` turns or without one of the speakers is turned down. Same
 * guards as a duet (DuetScripts::evaluate()), its own usage channel and
 * daily cap (`stock.daily_cap`), the monthly budget, at most two tries.
 */
final class StockScripts
{
    public const CHANNEL = 'caster_stock';

    /** The scene of a script written for the day stock: only a stock claim hands it out (ScriptQueue::claimStock()). */
    public const SCENE = 'stock';

    /** The draft named the time of day or a date in too many lines, or a speaker hardly spoke. */
    public const TIMESTAMPED = 'stock_timestamped';

    /** The topic prefix of a stock script (`stock:<format>`). */
    public const TOPIC_PREFIX = 'stock:';

    public const FORMATS = ['talk', 'tutorial', 'geschichte', 'podcast', 'liga'];

    /** @var array<string, string> */
    private const NAMES = [DuetDirector::MARKUS => 'Markus', DuetDirector::SIRIUS => 'Sirius'];

    private const LAST_FORMATS = 'caster:stock:last_formats';

    private const LAST_PICKS = 'caster:stock:last_picks';

    private const USED_CANON = 'caster:stock:used_canon';

    public function __construct(
        private readonly DuetScripts $duets,
        private readonly VoiceUsage $usage,
        private readonly VoiceCanon $canon,
    ) {}

    /**
     * A new evergreen programme, or null (voice off, capped, no material, both tries turned down).
     *
     * @return array{text: string, speakers: list<string>, format: string}|null
     */
    public function write(): ?array
    {
        if (! CasterVoice::enabled()) {
            return null;
        }

        try {
            [$format, $material] = $this->material();

            if ($format === null) {
                return null;
            }

            $rejected = [];

            for ($try = 0; $try < 2; $try++) {
                if ($this->usage->attemptsToday(self::CHANNEL) >= (int) config('tv.commentary.blocks.stock.daily_cap', 0)
                    || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                    return null;
                }

                [$talk, $rejected] = $this->draft($format, $material, $rejected);

                if ($talk !== null) {
                    $this->remember(self::LAST_FORMATS, $format);

                    return $talk + ['format' => $format];
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
     * The stock's speakers (`stock.speakers`), Markus and Sirius by default; the first leads.
     *
     * @return list<string>
     */
    public static function speakers(): array
    {
        $speakers = array_values(array_unique(array_filter((array) config('tv.commentary.blocks.stock.speakers', []), 'is_string')));

        return count($speakers) >= 2 ? $speakers : [DuetDirector::MARKUS, DuetDirector::SIRIUS];
    }

    /**
     * The Anchor jingle categories a stock script of `$topic` opens with (`stock.jingles`, then the talk jingle),
     * or null for a script that is not one of the stock's.
     *
     * @return list<string>|null
     */
    public static function jinglesFor(string $topic): ?array
    {
        if (! str_starts_with($topic, self::TOPIC_PREFIX)) {
            return null;
        }

        $own = array_values(array_filter((array) config('tv.commentary.blocks.stock.jingles.'.substr($topic, strlen(self::TOPIC_PREFIX)), []), 'is_string'));

        return array_values(array_unique([...$own, 'talk', 'station']));
    }

    /**
     * A model answer checked as draft() does it: the duet guards with the
     * stock's limits, then every line that names a time leaves it. The talk,
     * or null with the reason codes.
     *
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    public function evaluate(string $raw, bool $cutOff = false): array
    {
        $settings = (array) config('tv.commentary.blocks.stock', []);
        [$duet, $violations] = $this->duets->evaluate($raw, [], $cutOff, (int) ($settings['turns']['max'] ?? 10), (int) ($settings['sentences_per_turn'] ?? 4));

        if ($duet === null) {
            return [null, $violations];
        }

        [$lines, $speakers] = [[], []];

        foreach (ScriptGuard::lines($duet['text']) as $index => $line) {
            if (preg_match(ReplayArchive::TIMESTAMPED, $line) !== 1) {
                $lines[] = $line;
                $speakers[] = $duet['speakers'][$index] ?? DuetDirector::MARKUS;
            }
        }

        if (count(DuetDirector::turns($lines, $speakers)) < (int) ($settings['turns']['min'] ?? 6) || array_diff(self::speakers(), $speakers) !== []) {
            return [null, [self::TIMESTAMPED]];
        }

        return [['text' => implode("\n", $lines), 'speakers' => $speakers], []];
    }

    /**
     * @param  list<string>  $rejected
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    private function draft(string $format, string $material, array $rejected): array
    {
        $this->usage->recordAttempt(self::CHANNEL);
        $temperature = config('tv.commentary.scripts.temperature');
        $response = (new VoiceAgent($this->duets->systemPrompt(), is_numeric($temperature) ? (float) $temperature : null, (int) config('tv.commentary.blocks.stock.max_output_tokens', 3000)))
            ->prompt(self::prompt($format, $material, $rejected));
        $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

        $answer = $response instanceof StructuredAgentResponse ? $response->toArray() : json_decode($response->text, true);
        $raw = is_array($answer) && is_string($answer['text'] ?? null) ? $answer['text'] : '';
        [$talk, $violations] = $this->evaluate($raw, $response->steps->last()?->finishReason === FinishReason::Length);

        if ($violations !== []) {
            $this->usage->recordRejection(self::CHANNEL, $violations);
            $this->usage->recordOutcome(self::CHANNEL, 'rejected');

            return [null, $violations];
        }

        $this->usage->recordOutcome(self::CHANNEL, 'accepted');

        return [$talk, []];
    }

    /**
     * The format of the next programme (of `stock.formats`, the one used longest ago that has material) and its material.
     *
     * @return array{0: string|null, 1: string}
     */
    public function material(): array
    {
        $formats = array_values(array_intersect(array_filter((array) config('tv.commentary.blocks.stock.formats', self::FORMATS), 'is_string'), self::FORMATS));
        shuffle($formats);

        foreach (self::byLastUse($formats, self::LAST_FORMATS) as $format) {
            $material = $this->materialFor($format);

            if ($material !== null) {
                return [$format, $material];
            }
        }

        return [null, ''];
    }

    private function materialFor(string $format): ?string
    {
        $settings = (array) config('tv.commentary.blocks.stock', []);

        return match ($format) {
            'talk' => ($question = $this->pick((array) ($settings['questions'] ?? []))) === null ? null
                : "Die Frage: {$question}\n\nAufhänger aus dem Kanon (höchstens einer davon):\n".($this->canonLines(['einundzwanzig', 'bitcoin', 'nostr'], 2) ?? '-'),
            'tutorial' => ($topic = $this->pick((array) ($settings['tutorials'] ?? []))) === null ? null
                : "Das Tutorial: {$topic}\n\nAufhänger aus dem Kanon (höchstens einer davon):\n".($this->canonLines(['bitcoin'], 2) ?? '-'),
            'geschichte' => $this->canonLines(['bitcoin'], 3),
            'podcast' => app(PodcastTranscripts::class)->digestBlock(),
            'liga' => ($lines = CasterVoice::leagueBasics()) === [] ? null : implode("\n", array_map(fn (string $line): string => '- '.$line, $lines)),
            default => null,
        };
    }

    /**
     * The entry of `$items` used longest ago (never used first, shuffled among equals), remembered as used.
     *
     * @param  array<array-key, mixed>  $items
     */
    private function pick(array $items): ?string
    {
        $items = array_values(array_filter($items, fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
        shuffle($items);
        $item = self::byLastUse($items, self::LAST_PICKS)[0] ?? null;

        if ($item !== null) {
            $this->remember(self::LAST_PICKS, $item);
        }

        return $item;
    }

    /**
     * `$items` ordered by their last use in the cached list `$key` (never used first).
     *
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function byLastUse(array $items, string $key): array
    {
        $last = array_values(array_filter((array) Cache::get($key, []), 'is_string'));
        $rank = fn (string $item): int => ($position = array_search($item, $last, true)) === false ? -1 : (int) $position;
        usort($items, fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        return $items;
    }

    private function remember(string $key, string $item): void
    {
        $last = array_values(array_filter((array) Cache::get($key, []), fn (mixed $value): bool => is_string($value) && $value !== $item));
        Cache::put($key, array_slice([...$last, $item], -200), now()->addDays(7));
    }

    /**
     * `$count` canon entries of `$categories` not offered to the stock lately, as the prompt shows them.
     *
     * @param  list<string>  $categories
     */
    private function canonLines(array $categories, int $count): ?string
    {
        $used = array_values(array_filter((array) Cache::get(self::USED_CANON, []), 'is_string'));
        $all = array_values(array_filter($this->canon->entries(), fn (array $entry): bool => in_array($entry['category'], $categories, true)));
        $entries = array_values(array_filter($all, fn (array $entry): bool => ! in_array($entry['id'], $used, true)));
        $entries = $entries === [] ? $all : $entries;

        if ($entries === []) {
            return null;
        }

        shuffle($entries);
        $picked = array_slice($entries, 0, $count);
        Cache::put(self::USED_CANON, array_slice([...$used, ...array_column($picked, 'id')], -600), now()->addDays(7));

        return implode("\n", array_map(fn (array $entry): string => CasterVoice::canonSourceLine($entry), $picked));
    }

    /**
     * The variable half of a stock call.
     *
     * @param  list<string>  $rejected
     */
    public static function prompt(string $format, string $material, array $rejected = []): string
    {
        $settings = (array) config('tv.commentary.blocks.stock', []);
        $turns = (array) ($settings['turns'] ?? []);
        $speakers = self::speakers();
        $names = array_map(fn (string $speaker): string => self::NAMES[$speaker] ?? ucfirst($speaker), $speakers);
        $lead = $names[0];
        $others = implode(' und ', array_slice($names, 1));
        $what = match ($format) {
            'tutorial' => "ein Bitcoin-Tutorial im Gespräch: {$lead} erklärt Schritt für Schritt und verständlich, {$others} stellt die dummen Fragen, die sich jeder Hörer auch stellt, und roastet die Fachsprache."
                .' Nur allgemein bekanntes, sachlich richtiges Grundwissen; wo es unsicher ist, sagt ihr das. Keine Anlage-, Rechts- oder Steuerberatung (sagt das einmal beiläufig, wenn es um Geld oder Steuern geht), keine Kaufempfehlung, keine Produkt- oder Markennamen und keine Behauptungen über einzelne Produkte.',
            'geschichte' => "eine Geschichtsstunde über Bitcoin und die Cypherpunks: {$lead} erzählt die Geschichten hinter den Einträgen aus dem Material mit Substanz (was war, warum es zählt), {$others} fragt nach und hält mit eigener Meinung dagegen.",
            'podcast' => 'die Podcast-Ecke: zwei oder drei Gedanken aus der letzten Folge des EINUNDZWANZIG-Podcasts im Material, weitergesponnen und mit eigener Meinung besprochen.',
            'liga' => 'Liga-Geschichten: wie die TWENTY ONE Liga funktioniert und warum man mitspielen sollte, aus dem Material, ohne aktuelle Ergebnisse, ohne Spielernamen, mit Selbstironie über die eigene Liga.',
            default => 'ein Themen-Talk: die Frage aus dem Material wird mit Substanz besprochen (Gründe, Beispiele, Gegenargumente), mit gegenseitigem Roast und Spott über Staat, Fiatgeld und Zentralbanken, und am Ende steht eine klare Haltung.',
        };

        $parts = [
            "Auftrag\n\nEin langer Wortwechsel für den Programmvorrat von TWENTY ONE ESPORTS RADIO, {$what}"
                ."\n\n".(int) ($turns['min'] ?? 6).' bis '.(int) ($turns['max'] ?? 10).' Wortmeldungen im Wechsel zwischen '.implode(', ', $names).", {$lead} beginnt, jede Wortmeldung zwei bis ".(int) ($settings['sentences_per_turn'] ?? 4).' Sätze, jede spätere greift die davor auf, und jeder kommt zu Wort.'
                .' Jeder Satz steht in einer eigenen Zeile und beginnt mit dem Namen des Sprechers und einem Doppelpunkt („'.$lead.': …“). Gesprochen auf Deutsch. '.CasterVoice::SENTENCE_STYLE
                ."\nZEITLOS: Der Beitrag läuft an einem späteren Tag zu einer unbekannten Uhrzeit. Nenne nie eine Uhrzeit, eine Tageszeit, einen Wochentag oder ein Datum von heute, nie „heute“, „gestern“, „morgen“, „gerade eben“, „vorhin“, „guten Morgen“ oder „gute Nacht“, nichts Aktuelles aus den Nachrichten und keine Ergebnisse. Geschichtliche Jahreszahlen sind erlaubt."
                ."\nLustig, aber mit Sinn: konkrete Geschichten, Gründe und Beispiele, kein Geschwafel über nichts. Kein Abschied, keine Begrüßung, keine Ansage wie „und jetzt zu …“, keine Spielernamen, höchstens zwei Marker.",
            "Material (die einzige Quelle für Zahlen, Namen und Zitate)\n\n".$material,
        ];

        if ($rejected !== []) {
            $parts[] = "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.match ($code) {
                self::TIMESTAMPED => 'Zu viele Zeilen nannten eine Uhrzeit, eine Tageszeit oder „heute“, oder einer kam kaum zu Wort: zeitlos schreiben, alle sprechen im Wechsel.',
                DuetScripts::TURNS => 'Einer kam gar nicht zu Wort: jede Zeile mit dem Namen des Sprechers beginnen, alle sprechen.',
                default => VoicePrompt::REASON_HINTS[$code] ?? $code,
            }, $rejected));
        }

        $parts[] = CasterVoice::varietyBlock();
        $parts[] = 'canon_id ist null. Schreib jetzt den Wortwechsel.';

        return implode("\n\n", $parts);
    }
}
