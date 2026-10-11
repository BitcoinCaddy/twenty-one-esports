<?php

namespace App\Support\Caster;

use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceGuard;
use App\Support\Voice\VoiceUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deterministic scene scripts, so airtime never depends on the model (P6
 * yield round, 2026-10-11: 0 of 5 real Haiku scripts passed the checks
 * against a prod snapshot). A script is assembled from
 * resources/data/voice/caster.json (`pattern_scripts`, the cast research's
 * patterns and invitations it allows): an opening, one to three fact lines,
 * one invitation, every second script a canon line (a curated few per
 * phase). `{slots}` are filled only from the topic's facts
 * (SceneFacts::forTopic()), all fact lines of a script from the same item
 * (one game, one result, one ladder); a line with a slot the facts cannot
 * fill is not used, so nothing is said the facts do not carry.
 *
 * No line comes back within the last `recent` pattern scripts, nor within
 * the last `recent` about the same topic; at most one OmniVoice marker
 * stays. The script passes ScriptGuard and the caster's
 * fact guard (VoiceGuard, the same as for the league voice). A line that
 * fails on its own is dropped from the pool and logged once a day: a line
 * without slots (an invitation, a canon line) for the day, a line with slots
 * for this fill only (the facts may be what failed). Counted in VoiceUsage
 * under `caster_pattern`, without tokens: a pattern script costs nothing.
 */
final class PatternScripts
{
    public const CHANNEL = 'caster_pattern';

    /** Assemblies tried per script before giving up (a line dropped, an opening that repeats). */
    private const TRIES = 4;

    private const RECENT_KEY = 'caster:recent:patterns';

    private const DROPPED_KEY = 'caster:patterns:dropped';

    private const SEQUENCE_KEY = 'caster:patterns:sequence';

    private const DAY_SECONDS = 86_400;

    /** @var array<string, true> lines dropped during this object's life (a slot line fails with these facts) */
    private array $droppedNow = [];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

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
     * @return array{text: string, lines: list<string>, canon_id: string|null}|null
     */
    public function write(string $topic, string $phase, array $facts): ?array
    {
        try {
            for ($try = 0; $try < self::TRIES; $try++) {
                $script = $this->assemble($topic, $phase, $facts, $try);

                if ($script === null) {
                    return null;
                }

                $violations = $this->violations($script, $facts, $this->recentOpenings());

                if ($violations === []) {
                    $this->remember($topic, $script);
                    $this->usage->recordAttempt(self::CHANNEL);
                    $this->usage->recordOutcome(self::CHANNEL, 'accepted');

                    return ['text' => $script['text'], 'lines' => array_keys($script['parts']), 'canon_id' => $script['canon_id']];
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

        $items = array_map(fn (array $item): array => array_map('strval', array_filter($item, fn (mixed $value): bool => is_string($value) && $value !== '' || is_int($value))), $items);

        return ['global' => $global, 'items' => $items === [] ? [[]] : $items];
    }

    /**
     * A template with its slots filled, or null when a slot has no value.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $template, array $values): ?string
    {
        $missing = false;
        $text = (string) preg_replace_callback('/\{([a-z0-9_]+)\}/', function (array $match) use ($values, &$missing): string {
            if (! isset($values[$match[1]])) {
                $missing = true;

                return '';
            }

            return $values[$match[1]];
        }, $template);

        return $missing ? null : $text;
    }

    /**
     * The lines of the pool, by role: `opening`, `fact`, `invite` (id => template, topic and phase checked).
     *
     * @return array{opening: array<string, string>, fact: array<string, string>, invite: array<string, string>}
     */
    public function pool(string $topic, string $phase): array
    {
        $data = $this->data();
        $section = (array) ($data['pattern_scripts'] ?? []);
        $openings = [];
        $facts = [];
        $invites = [];
        $fits = fn (array $topics): bool => in_array('*', $topics, true) || in_array($topic, $topics, true);

        foreach ((array) ($section['openings'] ?? []) as $row) {
            if (in_array($phase, (array) $row['phases'], true) && $fits((array) $row['topics'])) {
                $openings[(string) $row['id']] = (string) $row['text'];
            }
        }

        foreach ((array) ($section['facts'] ?? []) as $row) {
            if ($fits((array) $row['topics'])) {
                $facts[(string) $row['id']] = (string) $row['text'];
            }
        }

        $patterns = array_column((array) $data['patterns'], 'template', 'id');
        foreach ((array) ($section['use_patterns'] ?? []) as $row) {
            if ($fits((array) $row['topics']) && isset($patterns[$row['id']])) {
                $facts[(string) $row['id']] = (string) $patterns[$row['id']];
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
                $facts[(string) $row['id']] = $text;
            } elseif ($role === 'opening' && in_array($phase, (array) ($row['phases'] ?? []), true)) {
                $openings[(string) $row['id']] = $text;
            } elseif ($role === 'invite' && in_array($phase, (array) ($row['phases'] ?? []), true)) {
                $invites[(string) $row['id']] = $text;
            }
        }

        $when = (array) ($section['invite_when'][$phase] ?? []);
        foreach ((array) $data['promo'] as $row) {
            if (array_intersect($when, (array) $row['when']) !== []) {
                $invites[(string) $row['id']] = (string) $row['text'];
            }
        }

        $out = [...$this->dropped(), ...$this->droppedNow];

        return ['opening' => array_diff_key($openings, $out), 'fact' => array_diff_key($facts, $out), 'invite' => array_diff_key($invites, $out)];
    }

    /**
     * One script: the opening, the fact lines and the invitation in turn, none
     * of the last `recent` scripts' lines.
     *
     * @param  array<array-key, mixed>  $facts
     * @return array{text: string, parts: array<string, string>, canon_id: string|null}|null
     */
    private function assemble(string $topic, string $phase, array $facts, int $try): ?array
    {
        $settings = (array) config('tv.commentary.patterns', []);
        $max = (int) ($settings['sentences']['max'] ?? 6);
        $min = (int) ($settings['sentences']['min'] ?? 3);
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 16);
        $sequence = (int) Cache::get(self::SEQUENCE_KEY, 0) + $try;
        $used = array_flip(array_merge(...$this->recent($topic)) ?: []);
        $pool = $this->pool($topic, $phase);
        $slots = self::slots($facts);

        $openings = $this->filled($this->rotate(array_diff_key($pool['opening'], $used), $sequence), [$slots['global']]);
        $invites = $this->filled($this->rotate(array_diff_key($pool['invite'], $used), $sequence), [$slots['global']]);
        // One item per script (one game, one result, one ladder): a line like "Dahinter …" or "die Partie" then
        // always means the item the line before named. The items take turns; the first with a fresh line is taken.
        $factLines = [];
        foreach ($this->rotate($slots['items'], $sequence) as $item) {
            $factLines = $this->filled($this->rotate(array_diff_key($pool['fact'], $used), $sequence), [$item], $slots['global']);

            if ($factLines !== []) {
                break;
            }
        }

        if ($factLines === []) {
            return null;
        }

        $parts = [];
        $count = 0;
        $add = function (string $id, string $line) use (&$parts, &$count, $maxWords): void {
            $parts[$id] = ScriptGuard::splitSentences($line, $maxWords);
            $count += count(ScriptGuard::lines($parts[$id]));
        };

        $opening = array_key_first($openings);
        if ($opening !== null) {
            $add($opening, $openings[$opening][0]);
        }

        // Up to three fact lines, one sentence kept for the invitation.
        $factCount = 0;
        foreach ($factLines as $id => [$line]) {
            if ($factCount >= 3 || $count + count(ScriptGuard::lines(ScriptGuard::splitSentences($line, $maxWords))) > $max - 1) {
                continue;
            }

            $add($id, $line);
            $factCount++;
        }

        foreach ($invites as $id => [$line]) {
            if ($count + count(ScriptGuard::lines(ScriptGuard::splitSentences($line, $maxWords))) <= $max) {
                $add($id, $line);
                break;
            }
        }

        $canonId = null;
        if ($sequence % 2 === 1 && $count < $max) {
            $canon = $this->canonLine($phase, $used, $sequence);

            if ($canon !== null) {
                $canonId = $canon['id'];
                $add('canon:'.$canon['id'], $canon['wording']);
            }
        }

        if ($count < $min) {
            return null;
        }

        return ['text' => self::oneMarker(implode("\n", $parts)), 'parts' => $parts, 'canon_id' => $canonId];
    }

    /**
     * The script's violations: ScriptGuard (sentences, words, commas, markers, opening) and the fact guard.
     *
     * @param  array{text: string, parts: array<string, string>, canon_id: string|null}  $script
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $openings
     * @return list<string>
     */
    private function violations(array $script, array $facts, array $openings): array
    {
        $settings = (array) config('tv.commentary.patterns', []);
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $script['text']));

        return array_values(array_unique([
            ...$this->scriptGuard->check($script['text'], (int) ($settings['sentences']['min'] ?? 3), (int) ($settings['sentences']['max'] ?? 6), (int) config('tv.commentary.scripts.max_words_per_sentence', 16), $openings),
            ...$this->voice->guard()->check($spoken, $facts, (int) config('tv.commentary.scripts.max_chars', 900), $script['canon_id']),
        ]));
    }

    /**
     * The lines that fail on their own leave the pool (a slot-free line for the day, a slot line for this fill), each logged once a day.
     *
     * @param  array{text: string, parts: array<string, string>, canon_id: string|null}  $script
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $violations
     */
    private function dropCulprits(array $script, array $facts, array $violations): void
    {
        $maxWords = (int) config('tv.commentary.scripts.max_words_per_sentence', 16);
        $templates = $this->allTemplates();

        foreach ($script['parts'] as $id => $line) {
            $canonId = str_starts_with($id, 'canon:') ? substr($id, 6) : null;
            $own = array_values(array_unique([
                ...array_diff($this->scriptGuard->check($line, 1, 8, $maxWords), [ScriptGuard::SENTENCE_COUNT]),
                ...$this->voice->guard()->check(ScriptGuard::withoutMarkers(str_replace("\n", ' ', $line)), $facts, 900, $canonId),
            ]));

            if ($own === []) {
                continue;
            }

            $static = $canonId !== null || ! str_contains((string) ($templates[$id] ?? ''), '{');

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

        return [
            ...array_column((array) ($section['openings'] ?? []), 'text', 'id'),
            ...array_column((array) ($section['facts'] ?? []), 'text', 'id'),
            ...array_column((array) $data['patterns'], 'template', 'id'),
            ...array_column((array) $data['topics'], 'example_de', 'id'),
            ...array_column((array) $data['promo'], 'text', 'id'),
        ];
    }

    /**
     * A canon line for the phase from the curated list (`pattern_scripts.canon`:
     * entries whose usage fits any moment of that phase), not among the recent
     * scripts' lines; its wording word for word, with a capital and a full stop.
     *
     * @param  array<string, int>  $used
     * @return array{id: string, wording: string}|null
     */
    private function canonLine(string $phase, array $used, int $sequence): ?array
    {
        $ids = array_values(array_filter(
            (array) ($this->data()['pattern_scripts']['canon'][$phase] ?? []),
            fn (mixed $id): bool => is_string($id) && ! isset($used['canon:'.$id]) && $this->canon->find($id) !== null,
        ));

        if ($ids === []) {
            return null;
        }

        $id = $ids[$sequence % count($ids)];
        $wording = mb_ucfirst(trim((string) $this->canon->find($id)['wording']));

        return ['id' => $id, 'wording' => preg_match('/[.!?…]$/u', $wording) === 1 ? $wording : $wording.'.'];
    }

    /**
     * The templates filled with the first slot set that fills them: id => [line, item index].
     *
     * @param  array<string, string>  $templates
     * @param  array<int, array<string, string>>  $items
     * @param  array<string, string>  $global
     * @return array<string, array{0: string, 1: int}>
     */
    private function filled(array $templates, array $items, array $global = []): array
    {
        $filled = [];

        foreach ($templates as $id => $template) {
            foreach ($items as $index => $item) {
                $line = self::fill($template, [...$global, ...$item]);

                if ($line !== null) {
                    $filled[$id] = [$line, $index];
                    break;
                }
            }
        }

        return $filled;
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

    /** Only the first OmniVoice marker stays. */
    private static function oneMarker(string $text): string
    {
        $seen = false;
        $pattern = '/\s*\[(?:'.implode('|', array_map(fn (string $marker): string => preg_quote($marker, '/'), ScriptGuard::MARKERS)).')\]/u';

        return (string) preg_replace_callback($pattern, function (array $match) use (&$seen): string {
            if ($seen) {
                return '';
            }
            $seen = true;

            return $match[0];
        }, $text);
    }

    /**
     * @param  array{text: string, parts: array<string, string>, canon_id: string|null}  $script
     */
    private function remember(string $topic, array $script): void
    {
        $row = ['topic' => $topic, 'lines' => array_keys($script['parts']), 'opening' => ScriptGuard::opening($script['text'])];

        Cache::put(self::RECENT_KEY, array_slice([$row, ...$this->recentRows()], 0, self::recentCount() * count(SceneTopic::ALL)), self::DAY_SECONDS);
        Cache::increment(self::SEQUENCE_KEY);
    }

    /**
     * The line ids of the last `recent` pattern scripts, and of the last `recent` ones about `$topic`.
     *
     * @return list<list<string>>
     */
    private function recent(string $topic): array
    {
        $rows = $this->recentRows();
        $last = array_slice($rows, 0, self::recentCount());
        $topicLast = array_slice(array_values(array_filter($rows, fn (array $row): bool => ($row['topic'] ?? null) === $topic)), 0, self::recentCount());

        return array_map(fn (array $row): array => array_values(array_filter((array) ($row['lines'] ?? []), 'is_string')), [...$last, ...$topicLast]);
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
