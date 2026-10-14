<?php

namespace App\Support\Caster\Programme;

use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\NewsFilter;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\SpokenMemory;
use App\Support\Caster\WorldMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Turm und Genossin, der Talk, the talk show of TWENTY ONE ESPORTS RADIO
 * (plan "TWENTY ONE Radio", P16; user 2026-10-12: "die Talkshow auch wie eine
 * echte … greif aus allen Richtungen an, um das perfekt hinzubekommen";
 * 2026-10-13: dramatic like a real TV talk show, the host a parody of the
 * interrupting late-night interviewer): Sirius hosts (HOST) and fans the
 * flames, Markus and the guest fight. The guest is Genossin Gisela, a
 * fictional radio figure voiced by VoiceStudio's designed archetype "German
 * Companion" (`voicestudio.profiles.genossin`), who argues for Modern
 * Monetary Theory with the sourced positions of
 * resources/data/voice/mmt-positions.json only; Markus and Sirius roast her
 * ideas with their free-market, sound-money views (never labelled) and the
 * sourced counters of the same file.
 * The dramaturgy (cold open, topic as a question, crossfire, fact check,
 * closing round, teaser) comes from resources/data/voice/talkshow.json, every
 * rule with the real show it was read from.
 *
 * Two versions, both written by TalkShowScripts:
 *
 * - live (material()): at its slots (`shows.schedule`) on a real money or
 *   economy headline of the stored news (CasterNews) of the last
 *   `headline_hours` (ECB, Fed, interest rates, inflation, the digital euro,
 *   the debt brake …; NewsFilter's tragedies left out), none twice within
 *   `repeat_days`; no headline, no show (the slot falls back to the rotation);
 * - evergreen (evergreen()): a timeless topic of the file for the night stock
 *   (StockScripts), the one used longest ago, at most `stock_per_day` a day.
 *
 * Neither exists without the guest's voice: with no profile for her the cast
 * is Markus and Sirius alone (Shows::cast()) and both give null.
 *
 * quoteViolations() is the guard against invented quotes: text in quotation
 * marks (or direct speech after "sagt:") in a sentence that names a real
 * person of the file must be that person's position or documented verbatim
 * line, or stand in the headline itself.
 *
 * @phpstan-import-type ShowMaterial from Shows
 *
 * @phpstan-type Position array{id: string, author: string, position: string, verbatim: list<string>, source: string, source_title: string, evidence: string}
 * @phpstan-type Topic array{id: string, question: string, positions: list<string>}
 */
class TalkShow
{
    public const ID = Shows::STAMMTISCH;

    /** The guest's speaker key (a VoiceStudio profile under `voicestudio.profiles`). */
    public const GUEST = 'genossin';

    /** The host: Sirius (user 2026-10-13), the parody of an interrupting talk show host, never named after a real one. */
    public const HOST = 'sirius';

    /** Text in quotation marks attributed to a real person that is none of their documented lines. */
    public const QUOTE = 'talkshow_quote';

    private const USED_TOPICS = 'caster:talkshow:topics';

    private const OFFERED_MOVES = 'caster:talkshow:host_moves';

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /**
     * The settings of the format (`shows.formats.stammtisch`).
     *
     * @return array<array-key, mixed>
     */
    public static function settings(): array
    {
        return Shows::settings(self::ID);
    }

    /**
     * The breath between two turns of a talk show script (`turn_gap_ms`, in seconds), null for any other script (it
     * keeps `voicestudio.speaker_pause`): the live talk (topic "stammtisch") and the stock's ("stock:stammtisch").
     *
     * @return array{min: float, max: float}|null
     */
    public static function turnGap(mixed $topic): ?array
    {
        if (! in_array($topic, [self::ID, StockScripts::TOPIC_PREFIX.self::ID], true)) {
            return null;
        }

        $gap = (array) (self::settings()['turn_gap_ms'] ?? []);
        $min = max(0.05, (float) ($gap['min'] ?? 450) / 1000);

        return ['min' => $min, 'max' => max($min, (float) ($gap['max'] ?? 800) / 1000)];
    }

    /** A turn after the previous one at `turn_gap_ms`. */
    public const GAP_TURN = 'turn';

    /** A cut-in ("Moment, Moment …") at `interrupt_gap_ms`, at most `max_interruptions` per episode. */
    public const GAP_INTERRUPT = 'interrupt';

    /** The breath before the fact check, the closing round or the host's reaction to a hit, at `long_gap_ms`. */
    public const GAP_LONG = 'long';

    /** A host line that reacts to a hit ("Das hat gesessen …", talkshow-host-moves.json) or calls one. */
    public const HIT = '/hat gesessen|volltreffer|\btreffer\b|das saß|der saß|autsch/iu';

    /** A host line that opens an act: the fact check or the closing round. */
    public const ACT_BREAK = '/faktencheck|schlussrunde|schlusswort/iu';

    /** A turn that cuts into the one before it. */
    private const CUT_IN = '/^(?:\[[^\]]+\]\s*)?(?:moment|stopp|halt|nee|nein, nein|warten sie|jetzt lassen sie)\b/iu';

    /**
     * How long the breath before a turn of the talk show is (P20; user 2026-10-13: "es geht auch viel zu schnell, die
     * Talkshow"): GAP_LONG before the host's act break (the fact check, the closing round) or his reaction to a hit,
     * GAP_INTERRUPT when the turn cuts in, else GAP_TURN.
     *
     * @param  list<string>  $lines  the next turn's sentences
     */
    public static function gapKind(string $speaker, array $lines): string
    {
        $text = implode(' ', $lines);

        if ($speaker === self::HOST && (preg_match(self::ACT_BREAK, $text) === 1 || preg_match(self::HIT, $text) === 1)) {
            return self::GAP_LONG;
        }

        return preg_match(self::CUT_IN, trim($lines[0] ?? '')) === 1 ? self::GAP_INTERRUPT : self::GAP_TURN;
    }

    /**
     * The breath of a gap kind in seconds for a talk show script, null for any other script.
     *
     * @return array{min: float, max: float}|null
     */
    public static function gapFor(mixed $topic, string $kind): ?array
    {
        $turn = self::turnGap($topic);

        if ($turn === null) {
            return null;
        }

        $settings = self::settings();

        if ($kind === self::GAP_INTERRUPT) {
            $seconds = max(0.05, (float) ($settings['interrupt_gap_ms'] ?? 250) / 1000);

            return ['min' => $seconds, 'max' => $seconds];
        }

        if ($kind === self::GAP_LONG) {
            $long = (array) ($settings['long_gap_ms'] ?? []);
            $min = max($turn['min'], (float) ($long['min'] ?? 1000) / 1000);

            return ['min' => $min, 'max' => max($min, (float) ($long['max'] ?? 1400) / 1000)];
        }

        return $turn;
    }

    /**
     * The model the talk is written with (`model`, P20): the global voice model is Haiku where `esports.voice.model` is
     * unset, and Haiku stopped after 21 to 28 lines a call and copied whole lines from part to part in the first real
     * runs (2026-10-13), so the talk names its own; empty: the global one.
     */
    public static function model(): ?string
    {
        $model = self::settings()['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * The acts the talk is written in (`acts`, P20), each with its `id`, `brief` and the flags `factcheck`; none: one
     * call for the whole talk (P16).
     *
     * @return list<array<string, mixed>>
     */
    public static function acts(): array
    {
        return array_values(array_filter((array) (self::settings()['acts'] ?? []), fn (mixed $act): bool => is_array($act) && is_string($act['brief'] ?? null)));
    }

    /**
     * How long the talk airs, at least and at most, in seconds (P20: "dann muss die TalkShow auch 30 min gehen"): the
     * format's slot (Shows::slotMinutes()) less `slot_margin_seconds` up to the full slot; without a slot `seconds`.
     *
     * @return array{0: float, 1: float}
     */
    public static function airWindow(): array
    {
        $minutes = Shows::slotMinutes(self::ID);
        $settings = self::settings();

        if ($minutes === null) {
            return [(float) ($settings['seconds']['min'] ?? 360), (float) ($settings['seconds']['max'] ?? 600)];
        }

        return [60.0 * $minutes - max(0.0, (float) ($settings['slot_margin_seconds'] ?? 120)), 60.0 * $minutes];
    }

    /**
     * How long lines air as a rendered talk (P20): their words at `air_words_per_second` (VoiceStudio's measured pace,
     * 2.9 to 3.3 words a second in the P17 renders: 517 words in 190.6 s, 312 in 108.0 s, 268 in 83.5 s, pauses
     * taken out), a sentence pause inside a turn, the mean turn gap (`turn_gap_ms`) at every change of speaker, and
     * the studio's theme and applause (studioSeconds()).
     *
     * @param  list<string>  $lines
     * @param  list<string>  $speakers
     */
    public static function airSeconds(array $lines, array $speakers): float
    {
        if ($lines === []) {
            return 0.0;
        }

        $settings = self::settings();
        $words = count(preg_split('/\s+/u', trim(ScriptGuard::withoutMarkers(implode(' ', $lines))), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $turn = self::turnGap(self::ID) ?? ['min' => 0.45, 'max' => 0.8];
        $changes = 0;

        foreach ($speakers as $index => $speaker) {
            if ($index > 0 && $speaker !== $speakers[$index - 1]) {
                $changes++;
            }
        }

        $sentencePause = (float) config('tv.commentary.voicestudio.pause.sentence', 0.2);

        return $words / max(0.5, (float) ($settings['air_words_per_second'] ?? 3.0))
            + $changes * ($turn['min'] + $turn['max']) / 2
            + (count($lines) - 1 - $changes) * $sentencePause
            + self::studioSeconds();
    }

    /**
     * The seconds the studio adds to a talk (P20 probe, BlockRenderer): the intro's part before the welcome, the outro,
     * two act-break stings and four applause pauses; `studio_seconds`.
     */
    public static function studioSeconds(): float
    {
        return max(0.0, (float) (self::settings()['studio_seconds'] ?? 35));
    }

    /** The cut-ins an episode may have at `interrupt_gap_ms`; the rest get the turn's breath. */
    public static function maxInterruptions(): int
    {
        return max(0, (int) (self::settings()['max_interruptions'] ?? 2));
    }

    /**
     * The host's moves of resources/data/voice/talkshow-host-moves.json, by group.
     *
     * @return array<string, list<string>>
     */
    public static function hostMoveGroups(): array
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/talkshow-host-moves.json')), true);
        $groups = [];

        foreach (is_array($data) ? (array) ($data['groups'] ?? []) : [] as $group => $moves) {
            $moves = array_values(array_filter((array) $moves, fn (mixed $move): bool => is_string($move) && trim($move) !== ''));

            if ($moves !== []) {
                $groups[(string) $group] = $moves;
            }
        }

        return $groups;
    }

    /**
     * A rotating sample of `$count` host moves for one episode: never one said on air within `repeat_hours`
     * (SpokenMemory), the ones offered longest ago first, one per group before a group gets a second; remembered as
     * offered. Fewer when the pool has fewer fresh ones.
     *
     * @return list<string>
     */
    public static function hostMoves(CarbonImmutable $now, int $count = 10): array
    {
        $memory = new SpokenMemory;
        $offered = array_values(array_filter((array) Cache::get(self::OFFERED_MOVES, []), 'is_string'));
        $rank = fn (string $move): int => ($position = array_search($move, $offered, true)) === false ? -1 : (int) $position;
        $groups = [];

        foreach (self::hostMoveGroups() as $group => $moves) {
            $fresh = array_values(array_filter($moves, fn (string $move): bool => $memory->repeated($move, $now) === []));
            shuffle($fresh);
            usort($fresh, fn (string $a, string $b): int => $rank($a) <=> $rank($b));

            if ($fresh !== []) {
                $groups[$group] = $fresh;
            }
        }

        $keys = array_keys($groups);
        shuffle($keys);
        $picked = [];

        for ($round = 0; count($picked) < $count && $groups !== []; $round++) {
            $added = false;

            foreach ($keys as $group) {
                if (count($picked) < $count && isset($groups[$group][$round])) {
                    $picked[] = $groups[$group][$round];
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        Cache::put(self::OFFERED_MOVES, array_slice([...array_values(array_diff($offered, $picked)), ...$picked], -300), $now->addDays(14));

        return $picked;
    }

    /** Whether the guest can speak: her voice is configured, so the cast has three. */
    public static function available(): bool
    {
        return in_array(self::GUEST, Shows::cast(self::ID), true);
    }

    /** The guest's name on air ("Genossin Gisela"). */
    public static function stageName(): string
    {
        return (string) (self::guest()['stage_name'] ?? 'Genossin Gisela');
    }

    /**
     * @return array<string, mixed>
     */
    public static function guest(): array
    {
        return (array) (self::data()['guest'] ?? []);
    }

    /**
     * The guest's positions and the counters against them.
     *
     * @return list<Position>
     */
    public static function positions(bool $counters = false): array
    {
        /** @var list<Position> $rows */
        $rows = (array) (self::data()[$counters ? 'counters' : 'positions'] ?? []);

        return $rows;
    }

    /**
     * @return list<Topic>
     */
    public static function topics(): array
    {
        /** @var list<Topic> $topics */
        $topics = (array) (self::data()['topics'] ?? []);

        return $topics;
    }

    /**
     * The real people the quote guard watches, each with the spellings that name them.
     *
     * @return list<array{name: string, aliases: list<string>}>
     */
    public static function persons(): array
    {
        /** @var list<array{name: string, aliases: list<string>}> $persons */
        $persons = (array) (self::data()['persons'] ?? []);

        return $persons;
    }

    /** The guest's persona block of the talk show prompt. */
    public static function guestBlock(): string
    {
        $guest = self::guest();
        $style = (array) ($guest['style'] ?? []);
        $list = fn (mixed $rows): string => implode("\n", array_map(fn (mixed $row): string => '- '.$row, array_values(array_filter((array) $rows, 'is_string'))));

        return 'Dritte Stimme: '.self::stageName()." (nur in „Turm und Genossin, der Talk“)\n\n".($guest['role'] ?? '')
            ."\n\nSprechweise\n- Satzrhythmus: ".($style['rhythm'] ?? '')
            ."\n- Füllwörter: ".implode(', ', array_map(fn (string $filler): string => "„{$filler}“", array_values(array_filter((array) ($style['fillers'] ?? []), 'is_string'))))
            ."\n- Humor: ".($style['humor'] ?? '')."\n- Da regt sie sich auf: ".($style['worked_up'] ?? '')
            ."\n\nRunning Gags (erfunden für die Sendung)\n".$list($guest['running_gags'] ?? [])
            ."\n\nDamit ziehen Markus und Sirius sie auf (nur ihre Ideen)\n".$list($guest['teased_by'] ?? [])
            ."\n\nGrenzen\n".$list($guest['rules'] ?? []);
    }

    /**
     * The roast block of the talk show prompt (P20; user 2026-10-13: "Purer Realismus einer echten Radioshow, die
     * sarkastisch, toxisch und zynisch ist und absolut alles und jeden roastet", "liebevolle Schimpfwörter … Trotzdem
     * muss es sinnhaftig bleiben"): the rules, then a rotating sample of `$perRole` roast moves and three affectionate
     * insults per voice from resources/data/voice/talkshow-roasts.json, as tone, never as text to copy; none the talk
     * has said already (`$said`, TalkShowScripts::echoes(): the model copied examples, part after part, P20 run 4).
     *
     * @param  list<string>  $said  the talk's lines so far
     * @param  bool  $evergreen  an evergreen episode: no headline to roast
     */
    public static function roastBlock(int $perRole = 7, array $said = [], bool $evergreen = false): string
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/talkshow-roasts.json')), true);
        $names = [self::HOST => 'Sirius', DuetDirector::MARKUS => 'Markus', self::GUEST => self::stageName()];
        $sample = function (mixed $rows, int $count) use ($said): array {
            $rows = array_values(array_filter((array) $rows, fn (mixed $row): bool => is_string($row) && ! TalkShowScripts::echoes($row, $said)
                && ! array_filter($said, fn (string $line): bool => str_contains(mb_strtolower($line), mb_strtolower($row)))));
            shuffle($rows);

            return array_slice($rows, 0, $count);
        };
        $roles = [];

        foreach ([DuetDirector::MARKUS, self::HOST, self::GUEST] as $speaker) {
            $moves = $sample(is_array($data) ? ($data['moves'][$speaker] ?? []) : [], $perRole);
            $insults = $sample(is_array($data) ? ($data['insults'][$speaker] ?? []) : [], 3);
            $roles[] = $names[$speaker].' (so klingt es, übernimm keinen Satz wörtlich)'."\n".implode("\n", array_map(fn (string $move): string => '- '.$move, $moves))
                .($insults === [] ? '' : "\n- derbe Schimpfwörter, die nur ".$names[$speaker].' benutzt, etwa: '.implode(', ', array_map(fn (string $insult): string => "„{$insult}“", $insults)));
        }

        return "Roasten, bis es wehtut, aber mit Sinn\n\n"
            .'- Alle drei roasten in jedem Akt: einander, den Gastgeber, den Sender, die Hörer und '.($evergreen ? 'das Thema' : 'die Schlagzeile')."; sarkastisch, zynisch, frech und so gemein, dass es beim Zuhören wehtut, und trotzdem mögen sie sich irgendwie.\n"
            ."- Bauweise wie beim Roast der Liga-Partien: ein konkretes Ziel, ein konkretes Detail aus dem Material oder aus dem, was der andere gerade gesagt hat, die Steigerung und die Pointe am Satzende. Jeder Seitenhieb hängt an einer konkreten Behauptung, die man nachvollziehen kann.\n"
            ."- Derbe Schimpfwörter fliegen hin und her, frisch und jedes Mal anders, als Anrede mit „Sie“ („Sie Zinseszins-Wichser“); nie dasselbe zweimal, und jeder nur seine eigenen: Markus' gehen an die Genossin, ihre an Markus, Sirius' an beide.\n"
            ."- Markus ist der großspurige, toxisch-witzige Bitcoiner: Einzeiler wie Ohrfeigen gegen ihre Ideen, ihre T-Konten-Skizze, ihre MMT-Vorbilder, Staat, Zentralbank und Fiatgeld, Herablassung und Running Gags, er fällt ihr ins Wort, lacht sie aus ([laughter]) und seufzt theatralisch ([sigh]). Nicht höflich, kein „mit Verlaub“, kein Lob ohne Stachel.\n"
            ."- Sirius ist der Provokateur: seine Fragen sind gezinkt und sticheln, er roastet beide Gäste mit trockenem Sarkasmus, verdreht hörbar die Augen und führt jedes Ausweichen spöttisch vor, und trotzdem fragt er fast immer. Er verpasst beiden derbe Schimpfwörter, in jedem Teil eins für Markus und eins für die Genossin, gern mitten in der Frage, jedes frisch erfunden und nie eins von hier wörtlich.\n"
            .'- '.self::stageName()." keilt zurück (digitale Tulpenzwiebeln, Tech-Bro, Laser-Augen-Sekte), damit es ein echter Kampf bleibt.\n"
            ."- Kein Kauderwelsch: jeder Satz sagt etwas Konkretes, das man nach einmal Hören wiedergeben kann. Höchstens ein Bild pro Satz, nie zwei Bilder gemischt, kein Vergleich, der beim ersten Hören nicht sitzt, kein Satz, der nur nach Witz klingt.\n"
            ."- Die Grenzen bleiben: geroastet werden Ideen, Rollen und Aussagen, nie Geschlecht, Aussehen, Alter, Herkunft, Krankheit oder Behinderung, keine herablassende Anrede als Frau, keine echten Personen als Ziel, kein erfundenes Zitat, keine Zahl und kein Name außerhalb des Materials; Memes nur aus dem Kanon mit ihrem echten Wortlaut.\n\n"
            .implode("\n\n", $roles);
    }

    /** The dramaturgy block of the talk show prompt: title and rule of every entry of talkshow.json. */
    public static function dramaturgyBlock(): string
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/talkshow.json')), true);
        $rules = is_array($data) ? (array) ($data['rules'] ?? []) : [];

        return "Dramaturgie (wie bei echten Talk- und Streitsendungen)\n\n".implode("\n", array_map(fn (array $rule): string => "- {$rule['title']}: {$rule['rule']}", array_values(array_filter($rules, 'is_array'))));
    }

    /**
     * The live material: the best money headline of the last `headline_hours` not used within `repeat_days`, with
     * the guest's positions and the counters; null without one (or without the guest's voice).
     *
     * @return ShowMaterial|null
     */
    public function material(CarbonImmutable $now): ?array
    {
        if (! self::available()) {
            return null;
        }

        $settings = self::settings();
        $used = Shows::used(self::ID, $now);
        $words = array_values(array_filter(array_map(fn (mixed $word): string => mb_strtolower(trim((string) $word)), (array) (self::data()['headline_words'] ?? [])), fn (string $word): bool => $word !== ''));
        $best = null;

        foreach (CasterNews::query()->whereNull('skipped')->where('published_at', '>=', $now->utc()->subHours(max(1, (int) ($settings['headline_hours'] ?? 24))))
            ->where('published_at', '<=', $now->utc())->orderByDesc('published_at')->orderByDesc('id')->limit(600)->get() as $row) {
            $score = self::score($row->title, $words);

            if ($score === 0 || isset($used['stammtisch:news:'.$row->id]) || NewsFilter::reason($row->title, $row->language) !== null) {
                continue;
            }

            // The headline counts double: a teaser that mentions the ECB in passing is no money story.
            $score = 2 * $score + self::score((string) $row->summary, $words);

            if ($best === null || $score > $best['score']) {
                $best = ['row' => $row, 'score' => $score];
            }
        }

        if ($best === null) {
            return null;
        }

        /** @var CasterNews $row */
        $row = $best['row'];
        $headline = "Die Schlagzeile für den Talk\n- ".$row->source.', '.WorldMemory::relative($row->published_at->getTimestamp(), $now).': Schlagzeile „'
            .RoastFacts::spell(CasterVoice::withoutHashtags($row->title)).'“'.($row->summary !== null && trim($row->summary) !== '' ? ' Anriss: '.RoastFacts::spell(CasterVoice::withoutHashtags($row->summary)) : '');

        return Shows::build($headline."\n\n".self::sheet(), self::names(), [], sha1('stammtisch:news:'.$row->id), ['stammtisch:news:'.$row->id], [],
            $row->link === null || $row->link === '' ? [] : [$row->link]);
    }

    /**
     * The evergreen material for the night stock: the topic used longest ago (remembered as used), its positions first;
     * null when the stock has had `stock_per_day` talk shows within the last day, or without the guest's voice.
     *
     * @return ShowMaterial|null
     */
    public function evergreen(CarbonImmutable $now): ?array
    {
        $topics = self::topics();

        if (! self::available() || $topics === [] || self::stockedToday($now) >= max(0, (int) (self::settings()['stock_per_day'] ?? 2))) {
            return null;
        }

        $last = array_values(array_filter((array) Cache::get(self::USED_TOPICS, []), 'is_string'));
        shuffle($topics);
        usort($topics, fn (array $a, array $b): int => (($i = array_search($a['id'], $last, true)) === false ? -1 : (int) $i) <=> (($j = array_search($b['id'], $last, true)) === false ? -1 : (int) $j));
        $topic = $topics[0];
        Cache::put(self::USED_TOPICS, array_slice([...array_values(array_diff($last, [$topic['id']])), $topic['id']], -50), $now->addDays(30));

        $data = "Das Thema des Talks (zeitlos, ohne aktuellen Anlass)\n- ".$topic['question']
            ."\n- Positionen der Genossin, die dazu zuerst passen: ".implode(', ', $topic['positions'])."\n\n".self::sheet();

        return Shows::build($data, self::names(), [], 'stammtisch:topic:'.$topic['id'], ['stammtisch:topic:'.$topic['id']]);
    }

    /** How many talk shows the night stock got within the last day (whole scripts, not their parts). */
    public static function stockedToday(CarbonImmutable $now): int
    {
        return CasterScript::query()->where('topic', StockScripts::TOPIC_PREFIX.self::ID)->where('created_at', '>=', $now->subDay())
            ->where(fn ($query) => $query->whereNull('part_index')->orWhere('part_index', 1))->count();
    }

    /**
     * The quote guard (see the class comment): QUOTE when a sentence that names a real person of the file carries a
     * quotation (≥ 3 words, in quotation marks or as direct speech after a colon) that is none of that person's
     * positions or verbatim lines and does not stand in `$headline` either.
     *
     * @return list<string>
     */
    public static function quoteViolations(string $text, string $headline = ''): array
    {
        $headlineKey = self::normalize($headline);

        foreach (ScriptGuard::lines($text) as $line) {
            foreach (ScriptGuard::runs($line) as $sentence) {
                $named = self::namedPersons($sentence);

                if ($named === []) {
                    continue;
                }

                foreach (self::quotations($sentence) as $quoted) {
                    $key = self::normalize($quoted);

                    if (count(explode(' ', $key)) < 3 || ($headlineKey !== '' && str_contains($headlineKey, $key))) {
                        continue;
                    }

                    $documented = false;
                    foreach ($named as $person) {
                        foreach (self::linesOf($person) as $allowed) {
                            if (self::matches($key, self::normalize($allowed))) {
                                $documented = true;
                                break 2;
                            }
                        }
                    }

                    if (! $documented) {
                        return [self::QUOTE];
                    }
                }
            }
        }

        return [];
    }

    /**
     * The positions sheet of the prompt (and the material): the guest's positions, then the counters, each with its
     * author, id and source.
     */
    public static function sheet(): string
    {
        $line = fn (array $row): string => "- {$row['id']}, {$row['author']}: {$row['position']}"
            .($row['verbatim'] === [] ? '' : ' Wörtlich belegt: '.implode(' / ', array_map(fn (string $quote): string => '„'.$quote.'“', $row['verbatim'])))
            .' Quelle: '.$row['source_title'].'.';

        return "Was die Genossin vertritt (ihre einzigen Positionen, als ihre eigene Sicht und sinngemäß; die Quelle ist für dich, nicht zum Vorlesen)\n".implode("\n", array_map($line, self::positions()))
            ."\n\nWas Markus und Sirius dagegen wissen (zusätzlich zu ihrer Bitcoin-Sicht, als eigene Sicht; die Quelle ist für dich)\n".implode("\n", array_map($line, self::positions(true)));
    }

    /**
     * The names the talk may use: the guest's stage name and every person of the file.
     *
     * @return list<string>
     */
    private static function names(): array
    {
        $names = [self::stageName(), (string) (self::guest()['short_name'] ?? 'Gisela')];

        foreach (self::persons() as $person) {
            $names[] = $person['name'];
        }

        return $names;
    }

    /**
     * The persons a sentence names (by full name or alias, as written: "knapp" is no Knapp).
     *
     * @return list<string> their full names
     */
    private static function namedPersons(string $sentence): array
    {
        $named = [];

        foreach (self::persons() as $person) {
            foreach ([$person['name'], ...$person['aliases']] as $spelling) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($spelling, '/').'(?![\p{L}\p{N}])/u', $sentence) === 1) {
                    $named[] = $person['name'];
                    break;
                }
            }
        }

        return $named;
    }

    /**
     * What a sentence quotes: every span in quotation marks, and the direct speech after "sagt:", "schreibt:" or "so:".
     *
     * @return list<string>
     */
    private static function quotations(string $sentence): array
    {
        preg_match_all('/[„“”"»«‚‘]([^„“”"»«‚‘’]{2,})[„“”"»«‘’]/u', $sentence, $quotes);
        $spans = $quotes[1];

        if (preg_match('/(?<![\p{L}])(?:sagt|sagte|schreibt|schrieb|meint|meinte|erklärt|erklärte|so|wörtlich)\s*:\s*(.+)$/iu', $sentence, $speech) === 1) {
            $spans[] = $speech[1];
        }

        return $spans;
    }

    /**
     * Every documented line of a person: their positions and counters, and the verbatim quotes the sources print.
     *
     * @return list<string>
     */
    private static function linesOf(string $person): array
    {
        $lines = [];

        foreach ([...self::positions(), ...self::positions(true)] as $row) {
            if ($row['author'] === $person || str_contains($row['position'], $person)) {
                array_push($lines, $row['position'], ...$row['verbatim']);
            }
        }

        return $lines;
    }

    /** A quotation matches a documented line when it stands in it, or shares at least nine in ten of its words with it. */
    private static function matches(string $quoted, string $allowed): bool
    {
        if ($allowed === '' || str_contains($allowed, $quoted)) {
            return $allowed !== '';
        }

        $words = array_values(array_unique(explode(' ', $quoted)));
        $pool = array_fill_keys(explode(' ', $allowed), true);

        return count(array_filter($words, fn (string $word): bool => isset($pool[$word]))) >= 0.9 * count($words);
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text))));
    }

    /**
     * How many headline words a text has.
     *
     * @param  list<string>  $words
     */
    private static function score(string $text, array $words): int
    {
        $lower = mb_strtolower($text);

        return count(array_filter($words, fn (string $word): bool => preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u', $lower) === 1));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        if (self::$data === null) {
            $data = json_decode((string) file_get_contents(resource_path('data/voice/mmt-positions.json')), true, flags: JSON_THROW_ON_ERROR);
            self::$data = is_array($data) ? $data : [];
        }

        return self::$data;
    }
}
