<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\ReactionLines;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpokenMemory;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceHygiene;
use App\Support\Voice\VoicePrompt;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Writes Turm und Genossin, der Talk (plan "TWENTY ONE Radio", P16; TalkShow)
 * in ONE model call: the duets' cached system prompt (both personas, the
 * TONE), then in the variable half the guest's persona, the dramaturgy, the
 * positions sheet and the headline (live) or the timeless topic (evergreen).
 * Markus and Sirius argue through a free-market, sound-money lens and never
 * name it (user 2026-10-13: no "Anarchokapitalist", no "wir Libertären" in
 * their lines; LABEL).
 *
 * The answer is checked like a long format (ShowScripts) and more:
 *
 * - parsed into the cast's turns (Markus, Sirius, the guest; "Gisela:" counts
 *   as hers, DuetScripts::speakerAliases()), each turn repaired to whole
 *   sentences and cut to `sentences_per_turn` (the crossfire's short turns);
 *   the evergreen version loses every line that names a time of day;
 * - longer than `seconds.max`: whole turns leave from the middle, so the cold
 *   open and the closing round stay; shorter than `seconds.min`: SHORT;
 * - SHAPE: the guest opens (the cold open), the host speaks last (the teaser),
 *   and the fact check is called ("Faktencheck"); TURNS: each of the three
 *   has `min_turns` turns at least;
 * - the free rules, the data guard (ShowScripts::factual(): no digit, number,
 *   year or foreign name outside the data), the quote guard
 *   (TalkShow::quoteViolations()) and LABEL;
 * - repeats (SpokenMemory): a first draft that repeats the station is said
 *   anew; on the last try it passes only when what airs before its first
 *   repeat (ScriptQueue::enqueue() ends it there) is still `seconds.min` long.
 *
 * Two tries, the second with the reasons; its own usage channel and
 * `daily_cap`, and the monthly budget.
 *
 * @phpstan-import-type Slot from BroadcastClock
 * @phpstan-import-type ShowMaterial from Shows
 */
class TalkShowScripts
{
    public const CHANNEL = 'caster_talkshow';

    /** No cold open by the guest, Markus does not close, or no fact check. */
    public const SHAPE = 'talkshow_shape';

    /** Markus or Sirius named their own worldview ("Anarchokapitalist", "wir Libertären"). */
    public const LABEL = 'talkshow_label';

    /** Markus or Sirius talked down to the guest as a woman ("Gnädigste", real Sonnet sample 2026-10-13): ideas are roasted, never her gender. */
    public const GENDERED = 'talkshow_gendered';

    /** The condescending, gendered forms of address the hosts' side never uses for the guest. */
    private const GENDERED_WORDS = '/(?<![\p{L}])(?:gnädigste|gnädige frau|schätzchen|püppchen|mädchen|mädel|kleine dame|junge dame|süße|hysterisch\p{L}*|weibchen)(?![\p{L}])/iu';

    /** The self-labels Markus and Sirius never use (user 2026-10-13). */
    private const LABELS = '/(?<![\p{L}])(?:anarcho[\s-]*kapitalist\p{L}*|ankap\p{L}*|libertär\p{L}*|libertarian\p{L}*)/iu';

    /** @var list<string> the repeated sentences of the last draft turned down for CasterVoice::SPOKEN_REPEAT */
    private array $repeated = [];

    public function __construct(
        private readonly CasterVoice $voice,
        private readonly DuetScripts $duets,
        private readonly VoiceUsage $usage,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly VoiceHygiene $hygiene = new VoiceHygiene,
        private readonly ?BroadcastClock $clock = null,
    ) {}

    /**
     * The live talk show for `$slot`: the format's opening line (time and name), then the talk; null when it failed.
     *
     * @param  Slot  $slot
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    public function write(array $slot, array $material, CarbonImmutable $now): ?array
    {
        $content = $this->run($material, false, $now);

        return $content === null ? null : [
            'text' => implode("\n", [RubricScripts::opener(($this->clock ?? BroadcastClock::fromConfig())->spokenTime($slot['starts_at']), Shows::CATALOGUE[Shows::STAMMTISCH]['name'], $slot['key']), $content['text']]),
            // The host reads the format's opening line.
            'speakers' => [TalkShow::HOST, ...$content['speakers']],
        ];
    }

    /**
     * The evergreen talk show for the night stock (TalkShow::evergreen()), without an opening line (the stock's
     * jingle names it); null when it failed.
     *
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    public function evergreen(array $material): ?array
    {
        return $this->run($material, true, CarbonImmutable::now());
    }

    /**
     * @param  ShowMaterial  $material
     * @return array{text: string, speakers: list<string>}|null
     */
    private function run(array $material, bool $evergreen, CarbonImmutable $now): ?array
    {
        if (! CasterVoice::enabled() || ! TalkShow::available()) {
            return null;
        }

        $settings = TalkShow::settings();

        try {
            $rejected = [];
            // The host's moves of this episode: a fresh sample, none said on air within the repeat window.
            $moves = TalkShow::hostMoves($now, max(1, (int) ($settings['host_moves'] ?? 10)));

            for ($try = 0; $try < 2; $try++) {
                if ($this->usage->attemptsToday(self::CHANNEL) >= (int) ($settings['daily_cap'] ?? 0)
                    || $this->usage->monthCost() >= (float) config('esports.voice.monthly_budget_usd', 0)) {
                    return null;
                }

                $this->usage->recordAttempt(self::CHANNEL);
                $temperature = config('tv.commentary.scripts.temperature');
                $response = (new VoiceAgent($this->duets->systemPrompt(), is_numeric($temperature) ? (float) $temperature : null, max(3_000, (int) ($settings['max_output_tokens'] ?? 9_000)), timeoutOverride: max(30, (int) ($settings['timeout_seconds'] ?? 180)), cacheTtl: CasterVoice::staticCacheTtl()))
                    ->prompt(self::prompt($material, $evergreen, $rejected, in_array(CasterVoice::SPOKEN_REPEAT, $rejected, true) ? $this->repeated : [], $moves));
                $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

                $answer = $response instanceof StructuredAgentResponse ? $response->toArray() : json_decode($response->text, true);
                $raw = is_array($answer) && is_string($answer['text'] ?? null) ? $answer['text'] : '';
                [$content, $rejected] = $this->evaluate($material, $raw, $response->steps->last()?->finishReason === FinishReason::Length, $evergreen, $try === 1, $now);

                if ($content !== null) {
                    $this->usage->recordOutcome(self::CHANNEL, 'accepted');

                    return $content;
                }

                $this->usage->recordRejection(self::CHANNEL, $rejected);
                $this->usage->recordOutcome(self::CHANNEL, 'rejected');
            }
        } catch (Throwable $exception) {
            if (Cache::add('caster:talkshow-failed-logged', true, 600)) {
                Log::warning('Caster talk show failed', ['exception' => $exception::class]);
            }
        }

        return null;
    }

    /**
     * A model answer checked (see the class comment): the talk with one speaker per line, or null with the reasons.
     *
     * @param  ShowMaterial  $material
     * @return array{0: array{text: string, speakers: list<string>}|null, 1: list<string>}
     */
    public function evaluate(array $material, string $raw, bool $cutOff = false, bool $evergreen = false, bool $last = true, ?CarbonImmutable $now = null): array
    {
        $settings = TalkShow::settings();
        $cast = Shows::cast(Shows::STAMMTISCH);
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 32);
        $perTurn = max(1, (int) ($settings['sentences_per_turn'] ?? 3));
        // The crossfire's one-liners ("Nein, nein, nein, so nicht!") are shorter than a sentence elsewhere may be.
        $minWords = max(1, (int) ($settings['min_words'] ?? 3));
        [$lines, $speakers] = [[], []];

        foreach (DuetScripts::parse($this->hygiene->clean($raw, true, 'de')) as $turn) {
            // A voice outside the cast leaves: its lines would be voiced by nobody it was written for.
            if (! in_array($turn['speaker'], $cast, true)) {
                continue;
            }

            $text = ScriptGuard::trimSentences(self::withoutFragments(ScriptGuard::sentenceLines(CasterVoice::withoutHashtags($turn['text'])), $minWords, $maxWords), $perTurn);

            foreach (ScriptGuard::lines($text) as $line) {
                if (preg_match(ScriptQueue::SIGN_OFF, $line) !== 1 && (! $evergreen || preg_match(ReplayArchive::TIMESTAMPED, $line) !== 1)) {
                    $lines[] = $line;
                    $speakers[] = $turn['speaker'];
                }
            }
        }

        if ($lines === []) {
            return [null, [ShowScripts::EMPTY]];
        }

        [$lines, $speakers] = self::withinLength($lines, $speakers, (float) ($settings['seconds']['max'] ?? 600), max(1, (int) ($settings['sentences']['max'] ?? 110)));
        $text = implode("\n", $lines);
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $text));
        $headline = explode("\n\n", $material['data'])[0];

        $violations = [
            ...CasterVoice::free([
                ...$this->hygiene->check($spoken, 'de', [], null, $cutOff, null, [], $maxWords, ScriptGuard::maxCommas()),
                ...$this->voice->guard()->check($spoken, [], 100_000, null),
                ...$this->scriptGuard->check($text, 1, 200, $maxWords, [], $minWords),
            ]),
            ...ShowScripts::factual($spoken, $material),
            ...TalkShow::quoteViolations($text, $headline),
        ];

        $minSeconds = (float) ($settings['seconds']['min'] ?? 360);
        if (ScriptQueue::estimateSeconds($text) < $minSeconds) {
            $violations[] = ShowScripts::SHORT;
        }

        $turns = DuetDirector::turns($lines, $speakers);
        $counts = array_count_values(array_column($turns, 'speaker'));
        foreach ($cast as $speaker) {
            if (($counts[$speaker] ?? 0) < max(1, (int) ($settings['min_turns'] ?? 6))) {
                $violations[] = ShowScripts::TURNS;
                break;
            }
        }

        if ($turns[0]['speaker'] !== TalkShow::GUEST || $turns[count($turns) - 1]['speaker'] !== TalkShow::HOST || preg_match('/faktencheck/iu', $text) !== 1) {
            $violations[] = self::SHAPE;
        }

        foreach ($lines as $index => $line) {
            if ($speakers[$index] !== TalkShow::GUEST && preg_match(self::LABELS, $line) === 1) {
                $violations[] = self::LABEL;
            }

            if ($speakers[$index] !== TalkShow::GUEST && preg_match(self::GENDERED_WORDS, $line) === 1) {
                $violations[] = self::GENDERED;
            }
        }

        if ($violations === []) {
            $repeated = self::repeatedLines($text, $now ?? CarbonImmutable::now());

            if ($repeated !== [] && ! $last) {
                $this->repeated = $repeated;
                $violations[] = CasterVoice::SPOKEN_REPEAT;
            } elseif ($repeated !== [] && ScriptQueue::estimateSeconds((new SpokenMemory)->coherent($text, $now ?? CarbonImmutable::now())) < $minSeconds) {
                // The queue ends the talk before its first repeat: what would air is too short.
                $violations[] = ShowScripts::SHORT;
            }
        }

        return $violations === [] ? [['text' => $text, 'speakers' => $speakers], []] : [null, array_values(array_unique($violations))];
    }

    /**
     * The lines within `$maxSeconds` and `$maxSentences`: whole turns leave from the middle (the crossfire), so the cold
     * open and the closing round stay.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function withinLength(array $lines, array $speakers, float $maxSeconds, int $maxSentences): array
    {
        // Each turn as its speaker and the indexes of its lines.
        $turns = [];
        foreach ($lines as $index => $line) {
            $last = array_key_last($turns);

            if ($last !== null && $turns[$last][0] === $speakers[$index]) {
                $turns[$last][1][] = $index;
            } else {
                $turns[] = [$speakers[$index], [$index]];
            }
        }

        while (true) {
            $kept = array_merge(...array_map(fn (array $turn): array => $turn[1], $turns));
            $flat = array_map(fn (int $index): string => $lines[$index], $kept);

            if (count($turns) <= 4 || (count($flat) <= $maxSentences && ScriptQueue::estimateSeconds(implode("\n", $flat)) <= $maxSeconds)) {
                return [$flat, array_map(fn (int $index): string => $speakers[$index], $kept)];
            }

            array_splice($turns, intdiv(count($turns), 2), 1);

            // Two turns of one speaker that now meet are one turn.
            $merged = [];
            foreach ($turns as $turn) {
                $last = array_key_last($merged);

                if ($last !== null && $merged[$last][0] === $turn[0]) {
                    $merged[$last][1] = [...$merged[$last][1], ...$turn[1]];
                } else {
                    $merged[] = $turn;
                }
            }
            $turns = $merged;
        }
    }

    /**
     * One turn's sentences (one per line) without fragments under `$minWords` words; the turn ends before a sentence
     * over `$maxWords` (like ScriptGuard::withoutShort(), with the talk show's own floor for one-liners).
     */
    private static function withoutFragments(string $text, int $minWords, int $maxWords): string
    {
        $kept = [];

        foreach (ScriptGuard::lines($text) as $line) {
            $words = count(preg_split('/\s+/u', trim(ScriptGuard::withoutMarkers($line)), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($words > $maxWords) {
                break;
            }

            if ($words >= $minWords) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    /**
     * The talk's sentences that repeat what the station said; none when the memory cannot be read.
     *
     * @return list<string>
     */
    private static function repeatedLines(string $text, CarbonImmutable $now): array
    {
        try {
            return (new SpokenMemory)->repeated($text, $now);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The variable half of the call: the guest, the dramaturgy, the order of the show, the data.
     *
     * @param  ShowMaterial  $material
     * @param  list<string>  $rejected
     * @param  list<string>  $repeated
     * @param  list<string>  $moves  the host's moves of this episode (TalkShow::hostMoves())
     */
    public static function prompt(array $material, bool $evergreen = false, array $rejected = [], array $repeated = [], array $moves = []): string
    {
        $settings = TalkShow::settings();
        $min = max(1, (int) ($settings['sentences']['min'] ?? 55));
        $max = max($min, (int) ($settings['sentences']['max'] ?? 110));
        $minutes = max(1, (int) round((float) ($settings['seconds']['min'] ?? 360) / 60));
        $maxMinutes = max($minutes, (int) round((float) ($settings['seconds']['max'] ?? 600) / 60));
        $perTurn = max(1, (int) ($settings['sentences_per_turn'] ?? 3));
        $minTurns = max(1, (int) ($settings['min_turns'] ?? 6));
        $guest = TalkShow::stageName();
        $count = fn (int $number): string => ReactionLines::spokenNumber(min(99, $number));

        return implode("\n\n", array_filter([
            TalkShow::guestBlock(),
            TalkShow::dramaturgyBlock(),
            "Auftrag\n\n„Turm und Genossin, der Talk“ auf TWENTY ONE ESPORTS RADIO, eine Talkshow wie im Fernsehen, etwa {$minutes} bis {$maxMinutes} Minuten lang: dramatisch, laut, voller Energie, weil die Positionen meilenweit auseinander liegen."
                ." Sirius ist der Gastgeber, Markus Turm und {$guest} sind die beiden Gäste und streiten gegeneinander. "
                .($evergreen
                    ? 'Diese Ausgabe läuft im Programmvorrat an einem späteren Tag zu einer unbekannten Uhrzeit: zeitlos, ohne Uhrzeit, Tageszeit, Wochentag oder Datum, nie „heute“, „gestern“, „morgen“, „gerade eben“ oder „vorhin“, keine aktuellen Nachrichten. Das Thema steht im Material.'
                    : 'Die Ansage mit Uhrzeit und Name ist schon geschrieben; du schreibst nur, was danach kommt. Anlass ist die echte Schlagzeile im Material.')
                ." {$min} bis {$max} Sätze, gesprochen auf Deutsch, höchstens zweiunddreißig Wörter pro Satz; im Kreuzfeuer gern kurze Einzeiler ab drei Wörtern, sonst ganze Sätze.",
            "Die Rollen\n\n- Sirius, der Gastgeber: die Parodie eines bekannten Typs von Fernseh-Talkmaster, ohne je einen echten Namen zu nennen oder jemand Echtes zu sein. Er siezt beide Gäste („Herr Turm“, „Genossin Gisela“), redet selbst viel, fällt ständig ins Wort, fragt scheinbar naiv und skeptisch nach, verlangt ein klares Ja oder Nein, würgt Monologe ab, liest eine Zuschauerfrage vor und gießt Öl ins Feuer; seine Wendungen stehen unten im eigenen Block."
                ."\n- Markus Turm, Gast: argumentiert aus der Sicht freier Märkte, guten Geldes und von Bitcoin: Staat, Zentralbanken, Steuern, Regulierung und Fiatgeld sind das Problem, freiwilliger Tausch und Selbstbestimmung über das eigene Leben der Maßstab. Er zeigt das in Argumenten, nie mit einem Etikett: „Anarchokapitalist“, „libertär“ oder „wir Libertären“ sagt er nie. Satire-Technik: ein Fakt aus dem Material, die absurde Konsequenz, die Pointe."
                ."\n- {$guest}, Gast: wie in ihrem Persona-Block oben; wird sie hitzig, werden ihre Sätze kürzer und enden mit Ausrufezeichen."
                ."\n- Geroastet werden Ideen, nie Geschlecht, Aussehen, Alter oder Herkunft, keine herablassende Anrede wie „Gnädigste“, und nie die echten Autorinnen und Autoren als Personen.",
            "Ablauf\n\n1. Kalter Einstieg: {$guest} beginnt mit ihrer steilsten These, Markus fährt ihr sofort in die Parade."
                ."\n2. Sirius begrüßt die Hörer, stellt beide Gäste mit Namen und Haltung vor und nennt das Thema als zugespitzte Frage".($evergreen ? '.' : ', ausgehend von der Schlagzeile mit ihrer Quelle.')
                ."\n3. Kreuzfeuer in mehreren Runden: sehr kurze Wortmeldungen von einem bis ".$count($perTurn).' Sätzen, die Gäste fallen sich ins Wort, Durcheinanderreden als schnell wechselnde Einzeiler wie „Nein, nein, nein, so nicht!“, Sirius heizt an, unterbricht und fragt nach; [laughter] nur, wo eine Pointe sitzt.'
                ."\n4. Sirius liest eine Zuschauerfrage vor, ohne einen Namen zu erfinden („Ein Zuschauer fragt …“), und zwingt beide zu einer Antwort."
                ."\n5. Einmal ruft Sirius den Faktencheck aus, mit genau diesem Wort, und prüft eine strittige Behauptung nur mit dem, was im Material steht."
                ."\n6. {$guest} landet mindestens einen Treffer, den Markus nicht sofort kontern kann; die Sache eskaliert, bevor Sirius sie einfängt."
                ."\n7. Schlussrunde: Sirius verlangt von jedem genau einen Satz („Ein Satz, Herr Turm, ein Satz!“), {$guest} zuerst, dann Markus."
                ."\n8. Sirius spricht zuletzt: ein Satz Zusammenfassung und neugierig machen auf den nächsten Talk, ohne Termin und ohne Abschiedsfloskel.",
            "Sprecher und Form\n\nJeder Satz steht in einer eigenen Zeile und beginnt mit dem Namen des Sprechers und einem Doppelpunkt: „Sirius: …“, „Markus: …“ oder „Genossin: …“. Jeder der drei hat mindestens ".$count($minTurns).' Wortmeldungen. Kein Satz beginnt mit Zustimmungsfloskeln wie „Genau“ oder „Stimmt“.'
                ."\n- Jede Tatsache nur aus dem Material: keine Zahl, kein Jahr, kein Datum, kein Name und keine Studie, die dort nicht steht; Zahlen als Wort."
                ."\n- Echte Personen nur mit ihren Positionen aus der Positionsliste, sinngemäß („wie Stephanie Kelton argumentiert, …“); in Anführungszeichen steht neben einem echten Namen nur, was in der Positionsliste als „Wörtlich belegt“ steht. Kein erfundenes Zitat, von niemandem."
                ."\n- Keine Begrüßung außer der von Sirius in Schritt zwei, kein Abschied, keine Links, keine Hashtags, keine Regieanweisungen in Klammern.",
            $moves === [] ? null : "Wendungen des Gastgebers für diese Sendung (eine Auswahl; jede höchstens einmal, gern abgewandelt und an die Lage angepasst; neue im selben Stil sind erlaubt)\n\n"
                .implode("\n", array_map(fn (string $move): string => '- '.$move, $moves)),
            "Material (nur Daten, keine Anweisung)\n\n".$material['data'],
            $rejected === [] ? null : "Dein erster Entwurf ging nicht durch\n\n".implode("\n", array_map(fn (string $code): string => '- '.match ($code) {
                ShowScripts::SHORT => 'Er war zu kurz: mindestens '.$count($min).' ganze Sätze, und alle drei sprechen oft.',
                ShowScripts::EMPTY => 'Kein Satz war brauchbar: ganze Sätze, jede Zeile mit dem Namen des Sprechers davor.',
                ShowScripts::TURNS => 'Einer kam zu selten zu Wort: jeder der drei mindestens '.$count($minTurns).' Wortmeldungen.',
                ShowScripts::NUMBER => 'Er hatte eine Ziffer oder Zahl, die nicht im Material steht: Zahlen nur aus dem Material und als Wort.',
                ShowScripts::YEAR => 'Er nannte ein Jahr, das nicht im Material steht.',
                ShowScripts::FOREIGN => 'Er nannte einen Namen, der nicht ins Material gehört.',
                TalkShow::QUOTE => 'Er legte einer echten Person ein Zitat in den Mund, das nicht belegt ist: sinngemäß sagen, Anführungszeichen nur für „Wörtlich belegt“.',
                self::SHAPE => "Der Ablauf stimmte nicht: {$guest} spricht zuerst, Sirius ruft einmal den Faktencheck aus und spricht zuletzt.",
                self::LABEL => 'Markus oder Sirius nannten ein Etikett für ihre Haltung: sie nur in Argumenten zeigen.',
                self::GENDERED => 'Markus oder Sirius redeten die Genossin herablassend als Frau an (etwa „Gnädigste“): sie heißt „Genossin Gisela“ oder „Frau Genossin“, geroastet werden nur ihre Ideen.',
                default => VoicePrompt::REASON_HINTS[$code] ?? $code,
            }, $rejected)),
            CasterVoice::repeatBlock($repeated),
            CasterVoice::varietyBlock(),
            'canon_id ist null. Schreib jetzt den Talk.',
        ]));
    }
}
