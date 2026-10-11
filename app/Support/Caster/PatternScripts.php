<?php

namespace App\Support\Caster;

use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceGuard;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deterministic scene scripts, so airtime never depends on the model (P6
 * yield round, 2026-10-11: 0 of 5 real Haiku scripts passed the checks
 * against a prod snapshot). A script is assembled from
 * resources/data/voice/caster.json (`pattern_scripts`).
 *
 * Data diet (the user, 2026-10-11: "Er liest zu viele Daten vor, die sich
 * auch wiederholen. Das wird langweilig. Mehr Sprüche, mehr Werbung, mehr
 * Stolz, mehr Engagement Farming!"): a script carries at most one fact line,
 * two in a result or champion moment. A fact's atoms (its `slot=value`
 * pairs) are not said again within `fact_memory_minutes` by any script
 * (RecentlySaid), so the same slide data does not come back every few
 * minutes; a topic whose facts were all said lately gets a flavour-only
 * script. The rest of the script is flavour, in one of a few rotating
 * orders: a joke from the verified meme canon (followed by [laughter]), an
 * invitation, league pride, a chat call, an emotion line, an opening. No
 * flavour line comes back within the last `flavour_recent` pattern scripts;
 * at most two OmniVoice markers stay.
 *
 * Names are the exception ("er muss echt nicht immer die Namen vorlesen"): a
 * fact line with a person slot is used only in a result or champion moment,
 * with one person, and not for a person named within `name_memory_minutes`;
 * every other fact line says its fact without a name ("die Nummer eins der
 * Ladder", "{?leader}" requires the fact without saying it).
 *
 * `{slots}` are filled only from the topic's facts (SceneFacts::forTopic());
 * a line with a slot the facts cannot fill is not used, so nothing is said
 * the facts do not carry. A line whose sentence carries more than one comma
 * is not used either (spoken comma chains sound choppy, user 2026-10-11).
 * The script passes ScriptGuard (with its name limit) and the caster's fact
 * guard (VoiceGuard). A line that fails on its own is dropped from the pool
 * and logged once a day: a line without slots (an invitation, a canon line)
 * for the day, a line with slots for this fill only (the facts may be what
 * failed). Counted in VoiceUsage under `caster_pattern`, without tokens.
 */
final class PatternScripts
{
    public const CHANNEL = 'caster_pattern';

    /** The roles of a script's parts. */
    public const FACT = 'fact';

    public const JOKE = 'joke';

    public const INVITE = 'invite';

    public const PRIDE = 'pride';

    public const ENGAGE = 'engage';

    public const EMOTION = 'emotion';

    public const OPENING = 'opening';

    /** Slots whose value is a person: such a line is a name line. */
    public const PERSON_SLOTS = ['white', 'black', 'a', 'b', 'winner', 'loser', 'draw_a', 'draw_b', 'leader', 'second', 'first_entrant', 'champion', 'bf_leader', 'bf_second', 'leader_under'];

    /** Slots that only say which item a line is about; a fact's atoms leave them out when it has others. */
    private const CONTEXT_SLOTS = ['sender', 'website_spoken', 'game', 'mode', 'ladder', 'tournament', 'track', 'clan_name'];

    /** Slots of the league as a whole (SceneFacts::basics()): their facts belong to no item. */
    private const GLOBAL_SLOTS = ['sender', 'website_spoken', 'players', 'clans', 'live_now', 'games_today', 'games_played', 'games'];

    /** Slots that carry the same value under two names. */
    private const SLOT_ALIASES = ['a' => 'white', 'b' => 'black', 'games' => 'games_played'];

    /**
     * The orders a script's parts come in, taking turns; a role without a fresh line is left out.
     *
     * @var list<list<string>>
     */
    private const RECIPES = [
        [self::OPENING, self::FACT, self::JOKE, self::INVITE, self::JOKE],
        [self::JOKE, self::FACT, self::PRIDE, self::JOKE],
        [self::FACT, self::JOKE, self::ENGAGE, self::JOKE],
        [self::EMOTION, self::FACT, self::JOKE, self::INVITE],
        [self::JOKE, self::FACT, self::JOKE, self::ENGAGE],
        [self::FACT, self::JOKE, self::PRIDE, self::EMOTION, self::JOKE],
    ];

    /** A result or champion moment: two facts, then the flavour. */
    private const MOMENT_RECIPE = [self::FACT, self::FACT, self::JOKE, self::EMOTION, self::JOKE];

    /** Roles that top a script up to its minimum length. */
    private const TOP_UP = [self::JOKE, self::ENGAGE, self::PRIDE, self::INVITE, self::EMOTION];

    /** Canon lines per script at most (the user, 2026-10-11: more of the podcast's memes and sayings, not stats). */
    private const MAX_CANON = 2;

    /** A canon wording is spoken as a line of its own only up to this many words. */
    private const CANON_MAX_WORDS = 14;

    /** Canon wordings that would say something untrue or unwanted on this stream (donations, money advice, politics). */
    private const CANON_DENY = '/spend|gebühr|startgeld|\bfee|kauf|steuer|raub|polit|regierung|krieg|nazi|hitler|tod\b|sterb|drogen|sex|religi|kirche|wahl|geld ist|der woche|podcast|folge/iu';

    /** Spoken entity slots (tournament, track, clan, ladder): each said once per script, not again within `entity_memory_minutes`. */
    private const ENTITY_SLOTS = ['tournament', 'last_spot', 'track', 'clan_name', 'ladder'];

    /** Assemblies tried per script before giving up (a line dropped, an opening that repeats). */
    private const TRIES = 4;

    private const SLOT_PATTERN = '/\{(\??)([a-z0-9_]+)\}/';

    private const RECENT_KEY = 'caster:recent:patterns';

    private const DROPPED_KEY = 'caster:patterns:dropped';

    private const SEQUENCE_KEY = 'caster:patterns:sequence';

    private const CANON_RECENT_KEY = 'caster:patterns:canon-recent';

    private const DAY_SECONDS = 86_400;

    /** @var array<string, true> lines dropped during this object's life (a slot line fails with these facts) */
    private array $droppedNow = [];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, string> flavour line id => the canon entry its wording quotes ("Grüße gehen raus …") */
    private array $lineCanon = [];

    /** @var list<string>|null the canon entries a pattern script may speak (canonPool()) */
    private ?array $canonPool = null;

    public function __construct(
        private readonly VoiceCanon $canon,
        private readonly VoiceUsage $usage,
        private readonly CasterVoice $voice,
        private readonly ScriptGuard $scriptGuard = new ScriptGuard,
        private readonly string $dataPath = '',
    ) {}

    /**
     * A scene script for `$topic` in `$phase` from its facts, or null when
     * the pool has nothing fresh that passes.
     *
     * @param  array<array-key, mixed>  $facts  SceneFacts::forTopic()
     * @return array{text: string, lines: list<string>, canon_id: string|null, canon_ids: list<string>, roles: array<string, string>, parts: array<string, string>, part_canons: array<string, string>}|null
     */
    public function write(string $topic, string $phase, array $facts, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();

        try {
            for ($try = 0; $try < self::TRIES; $try++) {
                $script = $this->assemble($topic, $phase, $facts, $try, $now);

                if ($script === null) {
                    return null;
                }

                $violations = $this->violations($script, $facts, $this->recentOpenings());

                if ($violations === []) {
                    $this->remember($topic, $script, $now);
                    $this->usage->recordAttempt(self::CHANNEL);
                    $this->usage->recordOutcome(self::CHANNEL, 'accepted');

                    return ['text' => $script['text'], 'lines' => array_keys($script['parts']), 'canon_id' => $script['canon_id'], 'canon_ids' => $script['canon_ids'], 'roles' => $script['roles'], 'parts' => $script['parts'],
                        'part_canons' => array_filter(array_map($this->partCanon(...), array_combine(array_keys($script['parts']), array_keys($script['parts']))))];
                }

                $this->dropCulprits($script, $facts, $violations);
            }
        } catch (Throwable $exception) {
            if (Cache::add('caster:patterns:failed-logged', true, 600)) {
                Log::warning('Caster pattern script failed', ['exception' => $exception::class]);
            }
        }

        return null;
    }

    /**
     * The slot values a topic's facts offer: `global` for every line, and
     * one set per item (a game, a result, a tournament, a ladder); a fact
     * line takes the first set that fills all its slots. Counts that would
     * need a singular ("1 Partien") are left out.
     *
     * @param  array<array-key, mixed>  $facts
     * @return array{global: array<string, string>, items: list<array<string, string>>}
     */
    public static function slots(array $facts): array
    {
        $global = array_filter([
            'sender' => self::text($facts['sender'] ?? null),
            'website_spoken' => self::text($facts['website_spoken'] ?? null),
            'players' => self::plural($facts['players'] ?? null),
            'clans' => self::plural($facts['clans'] ?? null),
            'live_now' => self::plural($facts['live_now'] ?? null),
            'games_today' => self::plural($facts['games_today'] ?? null),
            'games_played' => self::plural($facts['games_played'] ?? null),
            'games' => self::plural($facts['games_played'] ?? null),
        ], fn (?string $value): bool => $value !== null);
        $items = [];

        foreach (self::listOf($facts['live_games'] ?? null) as $game) {
            $items[] = isset($game['white'])
                ? ['white' => $game['white'], 'black' => $game['black'] ?? null, 'a' => $game['white'], 'b' => $game['black'] ?? null, 'game' => $game['game'] ?? null, 'mode' => $game['mode'] ?? null, 'move' => $game['move'] ?? null]
                : ['a' => $game['a'] ?? null, 'b' => $game['b'] ?? null, 'game' => $game['game'] ?? null];
        }

        foreach (self::listOf($facts['results'] ?? null) as $result) {
            $draw = is_array($result['draw'] ?? null) ? array_values($result['draw']) : null;
            $items[] = $draw !== null
                ? ['draw_a' => $draw[0] ?? null, 'draw_b' => $draw[1] ?? null, 'game' => $result['game'] ?? null, 'mode' => $result['mode'] ?? null]
                : ['winner' => $result['winner'] ?? null, 'loser' => $result['loser'] ?? null, 'game' => $result['game'] ?? null, 'mode' => $result['mode'] ?? null,
                    'moves' => self::plural($result['moves'] ?? null), 'how' => $result['how'] ?? null, 'score' => $result['score'] ?? null];
        }

        foreach (self::listOf($facts['tournaments_open'] ?? null) as $tournament) {
            $left = (int) ($tournament['spots_left'] ?? 0);
            $items[] = [
                'tournament' => $tournament['name'] ?? null, 'game' => $tournament['game'] ?? null, 'mode' => $tournament['mode'] ?? null, 'start' => $tournament['start'] ?? null,
                'places' => self::plural($tournament['places'] ?? null), 'taken' => ($tournament['taken'] ?? 0) > 0 ? (string) $tournament['taken'] : null,
                'spots_left' => self::plural($left), 'last_spot' => $left === 1 ? ($tournament['name'] ?? null) : null,
                'pot_sats' => ($tournament['pot_sats'] ?? 0) > 0 ? (string) $tournament['pot_sats'] : null,
                'first_entrant' => is_array($tournament['roster'] ?? null) && is_string($tournament['roster'][0] ?? null) ? $tournament['roster'][0] : null,
            ];
        }

        foreach (self::listOf($facts['tournaments_under_way'] ?? null) as $tournament) {
            $table = self::listOf($tournament['table'] ?? null);
            $base = [
                'tournament' => $tournament['name'] ?? null, 'game' => $tournament['game'] ?? null, 'state' => $tournament['state'] ?? null,
                'entrants' => self::plural($tournament['entrants'] ?? null), 'champion' => $tournament['champion'] ?? null,
                'played' => ($tournament['played'] ?? 0) > 0 && ($tournament['total'] ?? 0) > 0 ? (string) $tournament['played'] : null, 'total' => ($tournament['played'] ?? 0) > 0 ? self::text($tournament['total'] ?? null) : null,
                'leader' => self::at($table, 'rank', 1, 'name'), 'leader_points' => self::plural(self::at($table, 'rank', 1, 'points')), 'second' => self::at($table, 'rank', 2, 'name'),
            ];
            $items[] = $base;

            foreach (self::listOf($tournament['results'] ?? null) as $result) {
                $items[] = [...$base, 'winner' => $result['winner'] ?? null, 'loser' => $result['loser'] ?? null, 'score' => $result['score'] ?? null, 'round' => self::text($result['round'] ?? null)];
            }
        }

        foreach (self::listOf($facts['ladders'] ?? null) as $ladder) {
            $top = self::listOf($ladder['top'] ?? null);
            $gap = $ladder['gap_first_second'] ?? null;
            $wins = self::at($top, 'rank', 1, 'wins');
            $losses = self::at($top, 'rank', 1, 'losses');
            $items[] = [
                'ladder' => $ladder['ladder'] ?? null, 'leader' => self::at($top, 'rank', 1, 'name'), 'leader_elo' => self::text(self::at($top, 'rank', 1, 'elo')),
                'leader_wins' => $wins !== 1 && $losses !== 1 ? self::text($wins) : null, 'leader_losses' => $wins !== 1 && $losses !== 1 ? self::text($losses) : null,
                'second' => self::at($top, 'rank', 2, 'name'), 'second_elo' => self::text(self::at($top, 'rank', 2, 'elo')),
                'gap' => self::plural($gap), 'tied_elo' => $gap === 0 ? self::text(self::at($top, 'rank', 1, 'elo')) : null,
            ];
        }

        if (is_array($facts['clan_spotlight'] ?? null) && $facts['clan_spotlight'] !== []) {
            $clan = $facts['clan_spotlight'];
            $items[] = ['clan_name' => $clan['name'] ?? null, 'clan_tag' => $clan['tag'] ?? null, 'clan_members' => self::plural($clan['members'] ?? null), 'clan_games' => self::plural($clan['games'] ?? null)];
        }

        if (is_array($facts['tmnf_week'] ?? null) && $facts['tmnf_week'] !== []) {
            $week = $facts['tmnf_week'];
            $board = self::listOf($week['board'] ?? null);
            $items[] = [
                'track' => $week['track'] ?? null, 'author_time' => $week['author_time'] ?? null, 'drivers' => self::plural($week['drivers'] ?? null),
                'leader' => self::at($board, 'place', 1, 'name'), 'leader_time' => self::at($board, 'place', 1, 'time'),
                'second' => self::at($board, 'place', 2, 'name'), 'second_time' => self::at($board, 'place', 2, 'time'),
                'leader_under' => self::at($board, 'place', 1, 'under_author_time') === true ? self::at($board, 'place', 1, 'name') : null,
            ];
        }

        if (is_array($facts['blockfill_week'] ?? null) && $facts['blockfill_week'] !== []) {
            $week = $facts['blockfill_week'];
            $board = self::listOf($week['board'] ?? null);
            $items[] = [
                'bf_players' => self::plural($week['players'] ?? null), 'bf_leader' => self::at($board, 'place', 1, 'name'), 'bf_leader_time' => self::at($board, 'place', 1, 'time'),
                'bf_second' => self::at($board, 'place', 2, 'name'), 'bf_second_time' => self::at($board, 'place', 2, 'time'),
            ];
        }

        // Game moments (GameMoments): each its own item, its fields the slots of the `moment_lines`, and `{mood_<mood>}`
        // the player the moment is about, so a line can be written for one mood only.
        foreach (self::listOf($facts['moments'] ?? null) as $moment) {
            $mood = is_string($moment['mood'] ?? null) ? $moment['mood'] : null;
            $items[] = [...array_diff_key($moment, ['kind' => true, 'mood' => true]), ...($mood !== null && isset($moment['lead']) ? ['mood_'.$mood => $moment['lead']] : [])];
        }

        $items = array_map(fn (array $item): array => array_map('strval', array_filter($item, fn (mixed $value): bool => is_string($value) && $value !== '' || is_int($value))), $items);

        return ['global' => $global, 'items' => $items === [] ? [[]] : $items];
    }

    /**
     * A template with its slots filled, or null when a slot has no value.
     * `{?slot}` requires the value but says nothing ("Auf der Strecke ist
     * schon jemand schneller als die Autorenzeit.{?leader_under}").
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $template, array $values): ?string
    {
        $missing = false;
        $text = (string) preg_replace_callback(self::SLOT_PATTERN, function (array $match) use ($values, &$missing): string {
            if (! isset($values[$match[2]])) {
                $missing = true;

                return '';
            }

            return $match[1] === '?' ? '' : $values[$match[2]];
        }, $template);

        return $missing ? null : trim($text);
    }

    /**
     * The facts a filled template says, as atoms (`item|slot=value`, the
     * context slots left out when there are others), and the persons it
     * names aloud.
     *
     * @param  array<string, string>  $values
     * @return array{atoms: list<string>, persons: list<string>, entities: list<string>}
     */
    public static function atoms(string $template, array $values): array
    {
        preg_match_all(self::SLOT_PATTERN, $template, $slots, PREG_SET_ORDER);
        $item = $values['tournament'] ?? $values['ladder'] ?? $values['track'] ?? $values['clan_name'] ?? '';
        $all = [];
        $facts = [];
        $persons = [];
        $entities = [];

        foreach ($slots as [, $silent, $slot]) {
            if (! isset($values[$slot])) {
                continue;
            }

            $atom = RecentlySaid::normalize((in_array($slot, self::GLOBAL_SLOTS, true) ? '' : $item).'|'.(self::SLOT_ALIASES[$slot] ?? $slot).'='.$values[$slot]);
            $all[$atom] = true;

            if (! in_array($slot, self::CONTEXT_SLOTS, true)) {
                $facts[$atom] = true;
            }

            if ($silent === '' && in_array($slot, self::PERSON_SLOTS, true)) {
                $persons[RecentlySaid::normalize($values[$slot])] = $values[$slot];
            }

            if ($silent === '' && in_array($slot, self::ENTITY_SLOTS, true)) {
                $entities[RecentlySaid::normalize($values[$slot])] = $values[$slot];
            }
        }

        return ['atoms' => array_keys($facts !== [] ? $facts : $all), 'persons' => array_values($persons), 'entities' => array_values($entities)];
    }

    /**
     * The lines of the pool by role (id => template, topic and phase checked):
     * `opening`, `fact`, `invite`, `pride`, `engage`, `emotion`.
     *
     * @return array<string, array<string, string>>
     */
    public function pool(string $topic, string $phase): array
    {
        $data = $this->data();
        $section = (array) ($data['pattern_scripts'] ?? []);
        $pool = [self::OPENING => [], self::FACT => [], self::INVITE => [], self::PRIDE => [], self::ENGAGE => [], self::EMOTION => []];
        $fits = fn (array $topics): bool => in_array('*', $topics, true) || in_array($topic, $topics, true);

        foreach ((array) ($section['openings'] ?? []) as $row) {
            if (in_array($phase, (array) $row['phases'], true) && $fits((array) $row['topics'])) {
                $pool[self::OPENING][(string) $row['id']] = (string) $row['text'];
            }
        }

        foreach ((array) ($section['facts'] ?? []) as $row) {
            if ($fits((array) $row['topics'])) {
                $pool[self::FACT][(string) $row['id']] = (string) $row['text'];
            }
        }

        $patterns = array_column((array) $data['patterns'], 'template', 'id');
        foreach ((array) ($section['use_patterns'] ?? []) as $row) {
            if ($fits((array) $row['topics']) && isset($patterns[$row['id']])) {
                $pool[self::FACT][(string) $row['id']] = (string) $patterns[$row['id']];
            }
        }

        $topics = array_column((array) $data['topics'], 'example_de', 'id');
        foreach ((array) ($section['use_topics'] ?? []) as $row) {
            $text = isset($topics[$row['id']]) ? (string) $topics[$row['id']] : null;
            $role = $row['role'] ?? 'fact';

            if ($text === null) {
                continue;
            }

            if ($role === 'fact' && $fits((array) ($row['topics'] ?? []))) {
                $pool[self::FACT][(string) $row['id']] = $text;
            } elseif (in_array($role, [self::OPENING, self::INVITE], true) && in_array($phase, (array) ($row['phases'] ?? []), true)) {
                $pool[$role][(string) $row['id']] = $text;
            }
        }

        foreach ((array) ($data['moment_lines'] ?? []) as $row) {
            if ($fits((array) ($row['topics'] ?? []))) {
                $facts[(string) $row['id']] = (string) $row['text'];
            }
        }

        $when = (array) ($section['invite_when'][$phase] ?? []);
        foreach ((array) $data['promo'] as $row) {
            if (array_intersect($when, (array) $row['when']) !== []) {
                $pool[self::INVITE][(string) $row['id']] = (string) $row['text'];
            }
        }

        foreach ([self::INVITE, self::PRIDE, self::ENGAGE, self::EMOTION] as $role) {
            foreach ((array) ($section['flavour'][$role] ?? []) as $row) {
                if (in_array($phase, (array) ($row['phases'] ?? []), true)) {
                    $pool[$role][(string) $row['id']] = (string) $row['text'];

                    if (is_string($row['canon'] ?? null)) {
                        $this->lineCanon[(string) $row['id']] = $row['canon'];
                    }
                }
            }
        }

        $out = [...$this->dropped(), ...$this->droppedNow];

        return array_map(fn (array $lines): array => array_diff_key($lines, $out), $pool);
    }

    /**
     * One script: at most one fact line (two in a moment), the flavour around
     * it in the turn's order, no flavour line of the last scripts.
     *
     * @param  array<array-key, mixed>  $facts
     * @return array{text: string, parts: array<string, string>, roles: array<string, string>, canon_id: string|null, canon_ids: list<string>, atoms: list<string>, persons: list<string>, entities: list<string>, names_allowed: int}|null
     */
    private function assemble(string $topic, string $phase, array $facts, int $try, CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.patterns', []);
        $max = (int) ($settings['sentences']['max'] ?? 6);
        $min = (int) ($settings['sentences']['min'] ?? 3);
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 16);
        $sequence = (int) Cache::get(self::SEQUENCE_KEY, 0) + $try;
        $used = $this->recentFlavour();
        $spoken = $this->recentSentences();
        $pool = $this->pool($topic, $phase);
        $slots = self::slots($facts);

        $factLines = $this->factLines($pool[self::FACT], $slots, $phase, $sequence, $now);
        $moment = $factLines !== [] && ($phase === ScenePhase::AFTER_RESULT || $factLines[0]['champion']);
        $recipe = $moment ? self::MOMENT_RECIPE : self::RECIPES[$sequence % count(self::RECIPES)];

        $parts = [];
        $roles = [];
        $count = 0;
        $markers = 0;
        $canonIds = [];
        $laughed = false;
        $canonOffered = false;
        $fits = function (string $line) use (&$count, &$markers, $max, $maxWords): bool {
            $split = ScriptGuard::splitSentences($line, $maxWords);

            return $count + count(ScriptGuard::lines($split)) <= $max && $markers + self::markerCount($line) <= ScriptGuard::MAX_MARKERS && ! self::commaChain($split);
        };
        $add = function (string $id, string $line, string $role) use (&$parts, &$roles, &$count, &$markers, $maxWords): void {
            $parts[$id] = ScriptGuard::splitSentences($line, $maxWords);
            $roles[$id] = $role;
            $count += count(ScriptGuard::lines($parts[$id]));
            $markers += self::markerCount($line);
        };

        $factCount = 0;
        foreach ([...$recipe, ...self::TOP_UP] as $index => $role) {
            $topUp = $index >= count($recipe);

            if ($topUp && $count >= $min) {
                break;
            }

            if ($role === self::FACT) {
                $line = $factLines[$factCount] ?? null;

                if ($line !== null && $fits($line['line'])) {
                    $add($line['id'], $line['line'], self::FACT);
                    $factCount++;
                }

                continue;
            }

            if ($role === self::JOKE) {
                if (count($canonIds) >= self::MAX_CANON) {
                    continue;
                }

                $joke = $this->jokeLine($used, $sequence + count($canonIds) * 101, $canonIds);
                $canonOffered = $canonOffered || $joke !== null;

                if ($joke !== null && $fits($joke['wording'])) {
                    $canonIds[] = $joke['id'];
                    // Laughter after the first joke; the second leaves the marker budget to an emotion line.
                    $wording = ! $laughed && $fits($joke['wording'].' [laughter]') ? $joke['wording'].' [laughter]' : $joke['wording'];
                    $laughed = $laughed || $wording !== $joke['wording'];
                    $add('canon:'.$joke['id'], $wording, self::JOKE);
                }

                continue;
            }

            foreach ($this->rotate(array_diff_key($pool[$role], $used, $parts), $sequence * 7 + $index) as $id => $template) {
                $line = self::fill($template, $slots['global']);

                // No sentence a recent script spoke ("Mehr braucht's nicht." closes two different lines), nor one the league voice just said.
                if ($line !== null && array_intersect_key(array_flip(self::sentenceKeys($line)), $spoken) !== []) {
                    continue;
                }

                // A line that quotes a canon catchphrase counts as one of the script's canon lines.
                $quotes = $this->lineCanon[$id] ?? null;

                if ($line !== null && $fits($line) && ($quotes === null || (count($canonIds) < self::MAX_CANON && ! in_array($quotes, $canonIds, true) && $this->canon->find($quotes) !== null))) {
                    $add($id, $line, $role);
                    if ($quotes !== null) {
                        $canonIds[] = $quotes;
                    }
                    break;
                }
            }
        }

        // Every script carries a canon line while the pool has a fresh one.
        if ($count < $min || ($canonIds === [] && $canonOffered)) {
            return null;
        }

        $said = array_slice($factLines, 0, $factCount);

        return [
            'text' => implode("\n", $parts),
            'parts' => $parts,
            'roles' => $roles,
            'canon_id' => $canonIds[0] ?? null,
            'canon_ids' => $canonIds,
            'atoms' => array_values(array_unique(array_merge(...array_map(fn (array $line): array => $line['atoms'], $said)))),
            'persons' => array_values(array_unique(array_merge(...array_map(fn (array $line): array => $line['persons'], $said)))),
            'entities' => array_values(array_unique(array_merge(...array_map(fn (array $line): array => $line['entities'], $said)))),
            'names_allowed' => $moment ? 1 : 0,
        ];
    }

    /**
     * The fact lines a script may say, best first: the lines of the first
     * item (in turn) with a fresh one, none with an atom said lately. A
     * champion line leads; otherwise a line without a name comes first, and
     * the second line (a moment only) says only new atoms and may name the
     * one person (never one named lately). Lines with two persons, and lines
     * with a person outside a result or champion moment, are not used.
     *
     * @param  array<string, string>  $templates
     * @param  array{global: array<string, string>, items: list<array<string, string>>}  $slots
     * @return list<array{id: string, line: string, atoms: list<string>, persons: list<string>, entities: list<string>, champion: bool}>
     */
    private function factLines(array $templates, array $slots, string $phase, int $sequence, CarbonImmutable $now): array
    {
        $saidFacts = RecentlySaid::active(RecentlySaid::FACTS, $now);
        $saidNames = RecentlySaid::active(RecentlySaid::NAMES, $now);
        $saidEntities = RecentlySaid::active(RecentlySaid::ENTITIES, $now);
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 16);

        foreach ($this->rotate($slots['items'], $sequence) as $item) {
            $lines = [];

            foreach ($this->rotate($templates, $sequence) as $id => $template) {
                $values = [...$slots['global'], ...$item];
                $line = self::fill($template, $values);

                if ($line === null || self::commaChain(ScriptGuard::splitSentences($line, $maxWords))) {
                    continue;
                }

                ['atoms' => $atoms, 'persons' => $persons, 'entities' => $entities] = self::atoms($template, $values);
                $champion = str_contains($template, '{champion}');

                if ($atoms === [] || array_intersect_key(array_flip($atoms), $saidFacts) !== []) {
                    continue;
                }

                // A tournament, track, clan or ladder named lately is not named again (user 2026-10-11: "Turniernamen … 3 bis 4 Mal").
                if (array_intersect_key(array_flip(array_map(RecentlySaid::normalize(...), $entities)), $saidEntities) !== []) {
                    continue;
                }

                if ($persons !== [] && (count($persons) > 1 || ! ($champion || $phase === ScenePhase::AFTER_RESULT) || isset($saidNames[RecentlySaid::normalize($persons[0])]))) {
                    continue;
                }

                $lines[] = ['id' => (string) $id, 'line' => $line, 'atoms' => $atoms, 'persons' => $persons, 'entities' => $entities, 'champion' => $champion];
            }

            if ($lines === []) {
                continue;
            }

            usort($lines, fn (array $x, array $y): int => [! $x['champion'], $x['persons'] !== []] <=> [! $y['champion'], $y['persons'] !== []]);
            $first = $lines[0];

            // The second line of a moment: only new atoms; the name line (the moment itself) before a name-free one.
            $second = null;
            foreach ($lines as $line) {
                if ($line['id'] !== $first['id'] && array_intersect($line['atoms'], $first['atoms']) === [] && ($first['persons'] === [] || $line['persons'] === []) && array_intersect($line['entities'], $first['entities']) === []
                    && ($second === null || ($second['persons'] === [] && $line['persons'] !== []))) {
                    $second = $line;
                }
            }

            return $second === null ? [$first] : [$first, $second];
        }

        return [];
    }

    /**
     * A canon line from canonPool(), none of the last `canon_recent` canon
     * lines of the pattern scripts, nor of the script so far: its wording
     * word for word, with a capital and a full stop.
     *
     * @param  array<string, mixed>  $used
     * @param  list<string>  $taken
     * @return array{id: string, wording: string}|null
     */
    private function jokeLine(array $used, int $sequence, array $taken): ?array
    {
        $recent = array_flip($this->recentCanon());
        $dropped = $this->dropped();
        $ids = array_values(array_filter(
            $this->canonPool(),
            fn (string $id): bool => ! isset($recent[$id]) && ! isset($used['canon:'.$id]) && ! isset($this->droppedNow['canon:'.$id]) && ! isset($dropped['canon:'.$id]) && ! in_array($id, $taken, true),
        ));

        if ($ids === []) {
            return null;
        }

        // A stride through the pool, so consecutive scripts quote far-apart entries.
        $id = $ids[($sequence * 37) % count($ids)];
        $wording = mb_ucfirst(trim((string) $this->canon->find($id)['wording']));

        return ['id' => $id, 'wording' => preg_match('/[.!?…]$/u', $wording) === 1 ? $wording : $wording.'.'];
    }

    /**
     * The canon entries a pattern script may speak: the curated jokes
     * (`flavour.jokes`) and every verified entry of the full canon whose
     * wording stands as a spoken line (at most CANON_MAX_WORDS words and one
     * comma, no bracket, colon, quote, emoji, digit or hash, no real person's
     * name, nothing of CANON_DENY). The fact guard still checks each line;
     * one that fails is dropped for the day.
     *
     * @return list<string>
     */
    public function canonPool(): array
    {
        if ($this->canonPool !== null) {
            return $this->canonPool;
        }

        $persons = array_map('mb_strtolower', array_map('strval', (array) config('esports.voice.persons', [])));
        $ids = array_values(array_filter((array) ($this->data()['pattern_scripts']['flavour']['jokes'] ?? []), fn (mixed $id): bool => is_string($id) && $this->canon->find($id) !== null));

        foreach ($this->canon->entries() as $id => $entry) {
            $wording = $entry['wording'];
            $lower = mb_strtolower($wording);
            $words = count(preg_split('/\s+/u', trim($wording), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($words < 2 || $words > self::CANON_MAX_WORDS || substr_count($wording, ',') > 1 || preg_match('/[#@()\[\]*:;„“”"»«…\/\d]|\p{So}|\p{Cs}/u', $wording) === 1
                || preg_match(self::CANON_DENY, $wording) === 1 || preg_match('/(?<![\p{L}])\p{L}{1,3}\.\s/u', $wording) === 1 || in_array($entry['type'], ['segment', 'clip'], true)
                || array_filter($persons, fn (string $person): bool => str_contains($lower, $person)) !== []) {
                continue;
            }

            $ids[] = $id;
        }

        return $this->canonPool = array_values(array_unique($ids));
    }

    /**
     * The script's violations: ScriptGuard (sentences, words, commas, markers,
     * opening), its name limits, and the fact guard (part by part, each canon
     * line under its own entry, so a script may quote two).
     *
     * @param  array{text: string, parts: array<string, string>, roles: array<string, string>, canon_id: string|null, canon_ids: list<string>, atoms: list<string>, persons: list<string>, entities: list<string>, names_allowed: int}  $script
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $openings
     * @return list<string>
     */
    private function violations(array $script, array $facts, array $openings): array
    {
        $settings = (array) config('tv.commentary.patterns', []);
        $min = (int) ($settings['sentences']['min'] ?? 3);
        $maxChars = (int) config('tv.commentary.scripts.max_chars', 900);
        $names = ScriptGuard::limitNames($script['text'], ScriptGuard::personNames($facts), $script['names_allowed'], [], $min) === $script['text'] ? [] : [ScriptGuard::NAME_COUNT];
        $repeats = ScriptGuard::dedupeEntities($script['text'], ScriptGuard::entityNames($facts), [], $min) === $script['text'] ? [] : [ScriptGuard::NAME_REPEAT];
        $guard = mb_strlen(ScriptGuard::withoutMarkers(str_replace("\n", ' ', $script['text']))) > $maxChars ? [VoiceGuard::TOO_LONG] : [];

        foreach ($script['parts'] as $id => $part) {
            array_push($guard, ...$this->voice->guard()->check(ScriptGuard::withoutMarkers(str_replace("\n", ' ', $part)), $facts, $maxChars, $this->partCanon($id)));
        }

        return array_values(array_unique([
            ...$this->scriptGuard->check($script['text'], $min, (int) ($settings['sentences']['max'] ?? 6), (int) config('tv.commentary.scripts.max_words_per_sentence', 16), $openings),
            ...$names,
            ...$repeats,
            ...$guard,
        ]));
    }

    /** The canon entry a part quotes: a joke ("canon:<id>") or a flavour line that carries one. */
    private function partCanon(string $id): ?string
    {
        return str_starts_with($id, 'canon:') ? substr($id, 6) : ($this->lineCanon[$id] ?? null);
    }

    /**
     * The lines that fail on their own leave the pool (a slot-free line for the day, a slot line for this fill), each logged once a day.
     *
     * @param  array{text: string, parts: array<string, string>, roles: array<string, string>, canon_id: string|null, canon_ids: list<string>, atoms: list<string>, persons: list<string>, entities: list<string>, names_allowed: int}  $script
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $violations
     */
    private function dropCulprits(array $script, array $facts, array $violations): void
    {
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 16);
        $templates = $this->allTemplates();

        foreach ($script['parts'] as $id => $line) {
            $canonId = $this->partCanon($id);
            $own = array_values(array_unique([
                ...array_diff($this->scriptGuard->check($line, 1, 8, $maxWords), [ScriptGuard::SENTENCE_COUNT]),
                ...$this->voice->guard()->check(ScriptGuard::withoutMarkers(str_replace("\n", ' ', $line)), $facts, 900, $canonId),
            ]));

            if ($own === []) {
                continue;
            }

            $static = str_starts_with($id, 'canon:') || ! str_contains((string) ($templates[$id] ?? ''), '{');

            if ($static) {
                Cache::put(self::DROPPED_KEY, [...array_keys($this->dropped()), $id], self::DAY_SECONDS);
            }
            $this->droppedNow[$id] = true;

            if (Cache::add('caster:patterns:dropped-logged:'.$id, true, self::DAY_SECONDS)) {
                Log::info('Caster pattern line dropped', ['line' => $id, 'reasons' => $own, 'for' => $static ? 'day' : 'fill']);
            }
            $this->usage->recordRejection(self::CHANNEL, $own);
        }

        // Nothing failed on its own (the opening repeats a recent one, the count is off): the next try takes other lines.
        if ($violations !== [] && array_diff_key($script['parts'], $this->droppedNow) === $script['parts']) {
            Cache::increment(self::SEQUENCE_KEY);
        }
    }

    /**
     * Every template of the data by id (to tell slot lines from slot-free ones).
     *
     * @return array<string, string>
     */
    private function allTemplates(): array
    {
        $data = $this->data();
        $section = (array) ($data['pattern_scripts'] ?? []);
        $flavour = (array) ($section['flavour'] ?? []);

        return [
            ...array_column((array) ($section['openings'] ?? []), 'text', 'id'),
            ...array_column((array) ($section['facts'] ?? []), 'text', 'id'),
            ...array_column((array) ($data['moment_lines'] ?? []), 'text', 'id'),
            ...array_column((array) $data['patterns'], 'template', 'id'),
            ...array_column((array) $data['topics'], 'example_de', 'id'),
            ...array_column((array) $data['promo'], 'text', 'id'),
            ...array_column([...(array) ($flavour[self::INVITE] ?? []), ...(array) ($flavour[self::PRIDE] ?? []), ...(array) ($flavour[self::ENGAGE] ?? []), ...(array) ($flavour[self::EMOTION] ?? [])], 'text', 'id'),
        ];
    }

    /**
     * The list started at `$offset` (keys kept), so consecutive scripts open differently.
     *
     * @template T
     *
     * @param  array<array-key, T>  $list
     * @return array<array-key, T>
     */
    private function rotate(array $list, int $offset): array
    {
        if ($list === []) {
            return [];
        }

        $offset %= count($list);

        return array_slice($list, $offset, null, true) + array_slice($list, 0, $offset, true);
    }

    /** Whether a sentence of the text carries more than one comma (a decimal comma not counted): spoken, a comma chain sounds choppy. */
    public static function commaChain(string $text): bool
    {
        foreach (ScriptGuard::lines($text) as $line) {
            if (preg_match_all('/(?<!\d),|,(?!\d)/u', ScriptGuard::withoutMarkers($line)) > 1) {
                return true;
            }
        }

        return false;
    }

    private static function markerCount(string $text): int
    {
        return (int) preg_match_all(self::markerPattern(), $text);
    }

    private static function markerPattern(): string
    {
        return '/\s*\[(?:'.implode('|', array_map(fn (string $marker): string => preg_quote($marker, '/'), ScriptGuard::MARKERS)).')\]/u';
    }

    /**
     * @param  array{text: string, parts: array<string, string>, roles: array<string, string>, canon_id: string|null, canon_ids: list<string>, atoms: list<string>, persons: list<string>, entities: list<string>, names_allowed: int}  $script
     */
    private function remember(string $topic, array $script, CarbonImmutable $now): void
    {
        $flavour = array_keys(array_filter($script['roles'], fn (string $role): bool => $role !== self::FACT));
        $sentences = array_merge(...array_map(fn (string $id): array => self::sentenceKeys($script['parts'][$id]), $flavour));
        $row = ['topic' => $topic, 'lines' => array_keys($script['parts']), 'flavour' => $flavour, 'sentences' => $sentences, 'opening' => ScriptGuard::opening($script['text'])];

        Cache::put(self::RECENT_KEY, array_slice([$row, ...$this->recentRows()], 0, max(self::flavourRecent(), self::recentCount())), self::DAY_SECONDS);
        Cache::increment(self::SEQUENCE_KEY);
        RecentlySaid::remember(RecentlySaid::FACTS, $script['atoms'], RecentlySaid::factMinutes(), $now);
        RecentlySaid::remember(RecentlySaid::NAMES, $script['persons'], RecentlySaid::nameMinutes(), $now);
        RecentlySaid::remember(RecentlySaid::ENTITIES, $script['entities'], RecentlySaid::entityMinutes(), $now);
        RecentlySaid::remember(RecentlySaid::FACT_LINES, array_map(fn (string $id): string => str_replace("\n", ' ', $script['parts'][$id]), array_keys(array_filter($script['roles'], fn (string $role): bool => $role === self::FACT))), RecentlySaid::factMinutes(), $now);
        Cache::put(self::CANON_RECENT_KEY, array_slice([...array_reverse($script['canon_ids']), ...$this->recentCanon()], 0, max(1, (int) config('tv.commentary.patterns.canon_recent', 200))), self::DAY_SECONDS);
    }

    /**
     * The canon entries of the last `canon_recent` canon lines of the pattern scripts.
     *
     * @return list<string>
     */
    private function recentCanon(): array
    {
        $stored = Cache::get(self::CANON_RECENT_KEY);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /**
     * The normalised sentences of a line ("[sigh] Herrlich." → "herrlich").
     *
     * @return list<string>
     */
    private static function sentenceKeys(string $line): array
    {
        return array_values(array_filter(array_map(
            fn (string $sentence): string => trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', mb_strtolower(ScriptGuard::withoutMarkers($sentence))))),
            ScriptGuard::lines(ScriptGuard::splitSentences($line, (int) config('tv.commentary.scripts.max_words_per_sentence', 16))),
        )));
    }

    /**
     * The flavour sentences of the last `flavour_recent` pattern scripts and
     * every sentence of the league voice's last scripts (CasterVoice keeps
     * them under `caster:recent:texts`): it copies pattern lines word for word.
     *
     * @return array<string, true>
     */
    private function recentSentences(): array
    {
        $keys = [];

        foreach (array_slice($this->recentRows(), 0, self::flavourRecent()) as $row) {
            foreach ((array) ($row['sentences'] ?? []) as $key) {
                if (is_string($key)) {
                    $keys[$key] = true;
                }
            }
        }

        $voiced = Cache::get('caster:recent:texts');
        foreach (is_array($voiced) ? array_filter($voiced, 'is_string') : [] as $text) {
            foreach (self::sentenceKeys($text) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * The flavour line ids of the last `flavour_recent` pattern scripts.
     *
     * @return array<string, true>
     */
    private function recentFlavour(): array
    {
        $ids = [];

        foreach (array_slice($this->recentRows(), 0, self::flavourRecent()) as $row) {
            foreach ((array) ($row['flavour'] ?? $row['lines'] ?? []) as $id) {
                if (is_string($id)) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * The openings of the last `recent` pattern scripts.
     *
     * @return list<string>
     */
    private function recentOpenings(): array
    {
        return array_values(array_filter(array_map(fn (array $row): string => (string) ($row['opening'] ?? ''), array_slice($this->recentRows(), 0, self::recentCount()))));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRows(): array
    {
        $stored = Cache::get(self::RECENT_KEY);

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    private static function recentCount(): int
    {
        return max(1, (int) config('tv.commentary.patterns.recent', 4));
    }

    private static function flavourRecent(): int
    {
        return max(1, (int) config('tv.commentary.patterns.flavour_recent', 40));
    }

    /**
     * @return array<string, true>
     */
    private function dropped(): array
    {
        $stored = Cache::get(self::DROPPED_KEY);

        return is_array($stored) ? array_fill_keys(array_filter($stored, 'is_string'), true) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return $this->data ??= json_decode((string) file_get_contents($this->dataPath !== '' ? $this->dataPath : resource_path('data/voice/caster.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * A field of the row whose `$key` is `$position` (the leader is the row ranked 1, never just the first row).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function at(array $rows, string $key, int $position, string $field): mixed
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $position) {
                return $row[$field] ?? null;
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : (is_int($value) ? (string) $value : null);
    }

    /** A count that reads right with a plural noun: two or more. */
    private static function plural(mixed $value): ?string
    {
        return is_int($value) && $value >= 2 ? (string) $value : null;
    }
}
