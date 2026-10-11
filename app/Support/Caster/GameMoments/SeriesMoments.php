<?php

namespace App\Support\Caster\GameMoments;

use App\Models\SeriesMatch;
use App\Support\Caster\SceneFacts;
use App\Support\GameNames;

/**
 * What a series under way gives the caster (a team or 1v1 best-of match):
 * its game score from the live sheet (`live_games`, one winner per map or
 * game as the players enter them):
 *
 * - `series_score`: the score; level is close.
 * - `series_comeback`: a side that was COMEBACK_GAMES or more behind is
 *   level or ahead now.
 * - `series_point`: a side is one win from taking the series.
 */
final class SeriesMoments
{
    public const COMEBACK_GAMES = 2;

    /**
     * @return list<array<string, string|int>>
     */
    public static function of(SeriesMatch $match): array
    {
        $names = ['challenger' => SceneFacts::spoken($match->challenger_name), 'challenged' => SceneFacts::spoken($match->challenged_name)];

        if ($names['challenger'] === null || $names['challenged'] === null) {
            return [];
        }

        $winners = array_values(array_filter(array_map(fn (array $game): ?string => in_array($game['winner'] ?? null, SeriesMatch::SIDES, true) ? $game['winner'] : null, (array) ($match->live_games ?? []))));

        return self::fromWinners($winners, (int) $match->best_of, ['challenger' => $names['challenger'], 'challenged' => $names['challenged']], SceneFacts::spoken(GameNames::game($match->game)) ?? $match->game);
    }

    /**
     * @param  list<string>  $winners  `challenger` or `challenged` per game, in order
     * @param  array{challenger: string, challenged: string}  $names
     * @return list<array<string, string|int>>
     */
    public static function fromWinners(array $winners, int $bestOf, array $names, string $game): array
    {
        if ($winners === []) {
            return [];
        }

        $score = ['challenger' => 0, 'challenged' => 0];
        $worst = ['challenger' => 0, 'challenged' => 0];

        foreach ($winners as $winner) {
            $score[$winner]++;
            $worst['challenger'] = min($worst['challenger'], $score['challenger'] - $score['challenged']);
            $worst['challenged'] = min($worst['challenged'], $score['challenged'] - $score['challenger']);
        }

        $base = ['game' => $game, 'a' => $names['challenger'], 'b' => $names['challenged']];
        $lead = $score['challenger'] === $score['challenged'] ? null : ($score['challenger'] > $score['challenged'] ? 'challenger' : 'challenged');
        $high = max($score);
        $low = min($score);
        $moments = [array_filter([...$base, 'kind' => 'series_score', 'mood' => $lead === null ? Mood::CLOSE : null, 'lead' => $lead === null ? null : $names[$lead],
            'series_leader' => $lead === null ? null : $names[$lead], 'series_score' => $high.' zu '.$low, 'series_level' => $lead === null ? $high.' zu '.$low : null, 'best_of' => $bestOf], fn (mixed $value): bool => $value !== null)];

        foreach (['challenger', 'challenged'] as $side) {
            $other = $side === 'challenger' ? 'challenged' : 'challenger';

            if (-$worst[$side] >= self::COMEBACK_GAMES && $score[$side] >= $score[$other]) {
                $moments[] = [...$base, 'kind' => 'series_comeback', 'mood' => Mood::COMEBACK, 'lead' => $names[$side], 'comeback_by' => $names[$side], 'series_score' => $high.' zu '.$low];
            }
        }

        $needed = intdiv($bestOf, 2) + 1;
        foreach (['challenger', 'challenged'] as $side) {
            if ($bestOf > 1 && $score[$side] === $needed - 1) {
                $moments[] = [...$base, 'kind' => 'series_point', 'mood' => Mood::TENSION, 'lead' => $names[$side], 'series_point' => $names[$side]];
            }
        }

        return $moments;
    }
}
