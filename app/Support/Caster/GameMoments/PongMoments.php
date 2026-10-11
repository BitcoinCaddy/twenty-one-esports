<?php

namespace App\Support\Caster\GameMoments;

use App\Models\PongMatch;
use App\Support\Pong\PongRules;

/**
 * What a won Proof of Pong match gives the caster, read from its score and
 * the referee's log (`hit` and `goal` entries per rally):
 *
 * - `pong_score`: the final score; within CLOSE_POINTS close, from
 *   DOMINANT_POINTS apart dominance.
 * - `pong_comeback`: the winner was COMEBACK_POINTS or more behind.
 * - `pong_match_points`: the winner fought off match points (a point at
 *   which the loser's next point would have won the match).
 * - `pong_lead_changes`: the lead changed hands LEAD_CHANGES times or more.
 * - `pong_rally`: the longest rally had LONG_RALLY hits or more.
 *
 * The point-by-point moments come from the log's goals only when they add
 * up to the stored score (a void goal past the tick cap is logged too);
 * otherwise only the score is said (fail closed).
 */
final class PongMoments
{
    public const CLOSE_POINTS = 2;

    public const DOMINANT_POINTS = 10;

    public const COMEBACK_POINTS = 5;

    public const LEAD_CHANGES = 2;

    public const LONG_RALLY = 10;

    /**
     * @return list<array<string, string|int>>
     */
    public function of(PongMatch $match, string $left, string $right): array
    {
        if ($match->winner_id === null) {
            return [];
        }

        $rules = PongRules::fromConfig((array) config('esports.pong', []))->toArray();

        return self::fromLog([$match->score_left, $match->score_right], (array) $match->log, $match->winner_id === $match->left_id ? 0 : 1, [$left, $right], $rules['points_to_win'], $rules['win_by']);
    }

    /**
     * @param  array{0: int, 1: int}  $score
     * @param  array<int, mixed>  $log
     * @param  0|1  $winner
     * @param  array{0: string, 1: string}  $names
     * @return list<array<string, string|int>>
     */
    public static function fromLog(array $score, array $log, int $winner, array $names, int $pointsToWin, int $winBy): array
    {
        $loser = 1 - $winner;
        $base = ['game' => 'Proof of Pong', 'a' => $names[0], 'b' => $names[1]];
        $margin = $score[$winner] - $score[$loser];
        $moments = [array_filter([...$base, 'kind' => 'pong_score', 'mood' => match (true) {
            $margin <= self::CLOSE_POINTS => Mood::CLOSE,
            $margin >= self::DOMINANT_POINTS => Mood::DOMINANCE,
            default => null,
        }, 'lead' => $names[$winner], 'pong_winner' => $names[$winner], 'pong_loser' => $names[$loser], 'pong_score' => $score[$winner].' zu '.$score[$loser]], fn (mixed $value): bool => $value !== null)];

        // Goals per rally: the log has them in the order of the contacts, which is not always the order they fall in
        // when a rally plays several balls, so the score is read between rallies only.
        $rallies = [];
        $hits = [];

        foreach ($log as $entry) {
            if (is_array($entry) && in_array($entry[0] ?? null, ['hit', 'goal'], true)) {
                $rally = (int) ($entry[1] ?? 0);

                if ($entry[0] === 'hit') {
                    $hits[$rally] = ($hits[$rally] ?? 0) + 1;
                } else {
                    // The side of a goal entry is the side that missed; the other one scores.
                    $rallies[$rally][] = 1 - (int) ($entry[3] ?? 0);
                }
            }
        }

        ksort($rallies);
        $running = [0, 0];
        $worst = 0;
        $changes = 0;
        $leader = null;
        $saved = 0;

        foreach ($rallies as $scorers) {
            // A match point for the loser: their next point would have won, and this rally's one goal was the winner's.
            if (count($scorers) === 1 && $scorers[0] === $winner && $pointsToWin <= $running[$loser] + 1 && $winBy <= $running[$loser] + 1 - $running[$winner]) {
                $saved++;
            }

            foreach ($scorers as $scorer) {
                $running[$scorer === 1 ? 1 : 0]++;
            }

            $worst = min($worst, $running[$winner] - $running[$loser]);
            $now = $running[0] === $running[1] ? $leader : ($running[0] > $running[1] ? 0 : 1);
            $changes += $leader !== null && $now !== $leader ? 1 : 0;
            $leader = $now;
        }

        if ($running !== $score) {
            return $moments;
        }

        if (-$worst >= self::COMEBACK_POINTS) {
            $moments[] = [...$base, 'kind' => 'pong_comeback', 'mood' => Mood::COMEBACK, 'lead' => $names[$winner], 'comeback_by' => $names[$winner], 'deficit' => Words::count(-$worst, 'einen Punkt', 'Punkte'), 'deficit_points' => -$worst];
        }

        if ($saved > 0) {
            $moments[] = [...$base, 'kind' => 'pong_match_points', 'mood' => Mood::COMEBACK, 'lead' => $names[$winner], 'saver' => $names[$winner], 'match_points_saved' => Words::count($saved, 'einen Matchball', 'Matchbälle')];
        }

        if ($changes >= self::LEAD_CHANGES) {
            $moments[] = [...$base, 'kind' => 'pong_lead_changes', 'mood' => Mood::TENSION, 'lead_changes' => Words::count($changes, 'einen Führungswechsel', 'Führungswechsel'), 'lead_change_count' => $changes];
        }

        $longest = $hits === [] ? 0 : max($hits);
        if ($longest >= self::LONG_RALLY) {
            $moments[] = [...$base, 'kind' => 'pong_rally', 'rally_hits' => Words::count($longest, 'einen Schlag', 'Schläge'), 'rally_hit_count' => $longest];
        }

        return $moments;
    }
}
