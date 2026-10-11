<?php

namespace App\Support\Caster\GameMoments;

use App\Support\Caster\SceneFacts;
use App\Support\Matches\ScoreAttempts;
use App\Support\TwentyOne\Stream\BlockfillSlides;
use App\Support\TwentyOne\Stream\TmnfSlides;
use Carbon\CarbonImmutable;

/**
 * What a score game's week gives the caster (Blockfill, Trackmania), read
 * from the board its slides show (BlockfillSlides, TmnfSlides):
 *
 * - `score_leader`: who is first and how far ahead of second; within
 *   CLOSE_SHARE of the leader's time close, from DOMINANT_SHARE dominance.
 * - `score_tie`: two share first place.
 * - `score_new_top`: a new first place in the last minutes (the slide's
 *   "New #1" moment), with the one it took the place from.
 * - `score_bests`: runs of today that beat their player's own week best
 *   (Blockfill's `best` and `top` badges).
 * - `score_attempts`: Blockfill runs sent in today.
 */
final class ScoreMoments
{
    /** A gap of up to 1 % of the leader's time is close. */
    public const CLOSE_SHARE = 0.01;

    /** A gap of 10 % of the leader's time or more is dominance. */
    public const DOMINANT_SHARE = 0.10;

    public const TIMEZONE = 'Europe/Berlin';

    /**
     * @return list<array<string, string|int>>
     */
    public function blockfill(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $data = app(BlockfillSlides::class)->data();

        if ($data === null || ($data['week'] ?? null) !== BlockfillSlides::RUNNING) {
            return [];
        }

        $rows = array_map(fn (array $row): array => ['place' => (int) $row['place'], 'name' => (string) $row['name'], 'ms' => (int) $row['ms']],
            array_values(array_filter((array) ($data['board'] ?? []), fn (mixed $row): bool => is_array($row) && is_int($row['ms'] ?? null))));
        $moments = self::board('Blockfill', $rows);
        $base = ['game' => 'Blockfill'];

        $moment = is_array($data['moment'] ?? null) ? $data['moment'] : null;
        $top = $moment === null ? null : SceneFacts::spoken((string) ($moment['name'] ?? ''));
        if ($top !== null) {
            $before = is_array($moment['before'] ?? null) && ($moment['before']['own'] ?? false) !== true ? SceneFacts::spoken((string) ($moment['before']['name'] ?? '')) : null;
            $moments[] = array_filter([...$base, 'kind' => 'score_new_top', 'mood' => Mood::TENSION, 'lead' => $top, 'new_top' => $top, 'dethroned' => $before], fn (mixed $value): bool => $value !== null);
        }

        $today = $now->setTimezone(self::TIMEZONE)->startOfDay();
        $bests = [];
        foreach ((array) ($data['fresh'] ?? []) as $run) {
            $name = is_array($run) ? SceneFacts::spoken((string) ($run['name'] ?? '')) : null;

            if ($name !== null && in_array($run['badge'] ?? null, ['best', 'top'], true) && (int) ($run['at'] ?? 0) >= $today->getTimestamp()) {
                $bests[$name] = true;
            }
        }
        if ($bests !== []) {
            $moments[] = [...$base, 'kind' => 'score_bests', 'lead' => (string) array_key_first($bests), 'best_by' => (string) array_key_first($bests), 'bests_today' => Words::count(count($bests), 'eine neue Bestzeit', 'neue Bestzeiten')];
        }

        $attempts = ScoreAttempts::stacker('all')->where('created_at', '>=', $today->utc())->count();
        if ($attempts >= 2) {
            $moments[] = [...$base, 'kind' => 'score_attempts', 'attempts_today' => Words::count($attempts, 'ein Versuch', 'Versuche'), 'attempt_count' => $attempts];
        }

        return $moments;
    }

    /**
     * @return list<array<string, string|int>>
     */
    public function tmnf(): array
    {
        $data = app(TmnfSlides::class)->data();

        if ($data === null || ($data['state'] ?? null) !== 'running') {
            return [];
        }

        $rows = [];
        foreach ((array) ($data['lanes'] ?? []) as $lane) {
            $ms = is_array($lane) ? self::milliseconds((string) ($lane['time'] ?? '')) : null;

            if ($ms !== null) {
                $rows[] = ['place' => (int) $lane['place'], 'name' => (string) $lane['name'], 'ms' => $ms];
            }
        }

        $moments = self::board('Trackmania', $rows);
        $leader = is_array($data['leader'] ?? null) ? $data['leader'] : null;
        $name = $leader === null ? null : SceneFacts::spoken((string) ($leader['name'] ?? ''));

        if ($name !== null && ($leader['new'] ?? false) === true) {
            $moments[] = ['game' => 'Trackmania', 'kind' => 'score_new_top', 'mood' => Mood::TENSION, 'lead' => $name, 'new_top' => $name];
        }

        return $moments;
    }

    /**
     * The leader and the gap to second of a board (fastest first: `ms` lower is better), or the tie at the top.
     *
     * @param  list<array{place: int, name: string, ms: int}>  $rows
     * @return list<array<string, string|int>>
     */
    public static function board(string $game, array $rows): array
    {
        $first = collect($rows)->where('place', 1)->values();
        $second = collect($rows)->firstWhere('place', 2);
        $base = ['game' => $game];

        if ($first->count() >= 2) {
            $a = SceneFacts::spoken($first[0]['name']);
            $b = SceneFacts::spoken($first[1]['name']);

            return $a === null || $b === null ? [] : [[...$base, 'kind' => 'score_tie', 'mood' => Mood::CLOSE, 'tied_a' => $a, 'tied_b' => $b]];
        }

        $leader = $first->first();
        $leaderName = is_array($leader) ? SceneFacts::spoken($leader['name']) : null;
        $secondName = is_array($second) ? SceneFacts::spoken($second['name']) : null;

        if (! is_array($leader) || ! is_array($second) || $leaderName === null || $secondName === null || $second['ms'] <= $leader['ms']) {
            return [];
        }

        $gap = $second['ms'] - $leader['ms'];
        $share = $gap / max(1, $leader['ms']);

        return [array_filter([...$base, 'kind' => 'score_leader', 'mood' => match (true) {
            $share <= self::CLOSE_SHARE => Mood::CLOSE,
            $share >= self::DOMINANT_SHARE => Mood::DOMINANCE,
            default => null,
        }, 'lead' => $leaderName, 'score_leader' => $leaderName, 'score_second' => $secondName, 'score_gap' => Words::seconds($gap), 'gap_ms' => $gap], fn (mixed $value): bool => $value !== null)];
    }

    /** "0:16.130" or "16.13" in milliseconds; anything else null. */
    public static function milliseconds(string $time): ?int
    {
        if (preg_match('/^(?:(\d+):)?(\d+)\.(\d{1,3})$/', trim($time), $match) !== 1) {
            return null;
        }

        return ((int) $match[1] * 60 + (int) $match[2]) * 1000 + (int) str_pad($match[3], 3, '0');
    }
}
