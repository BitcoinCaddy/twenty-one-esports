<?php

namespace App\Support\Caster\GameMoments;

use App\Support\Caster\SceneFacts;

/**
 * What a tournament under way gives the caster, read from its frame on the
 * live slides (TournamentLiveSlides):
 *
 * - `tournament_field`: a bracket tournament running: how many of its
 *   entrants are still in (the slide's standing); tension from FEW_LEFT.
 * - `tournament_final`: two are left, the final's names.
 * - `tournament_upset`: the biggest seed upset so far (the slide's
 *   `upset`: the winner seeded TournamentLiveSlides::UPSET_GAP or more
 *   places lower). Seeds follow the players' rating at the draw, so this is
 *   the upset by Elo; a casual cup seeds at random and has none.
 */
final class TournamentMoments
{
    /** From this many left, the field is tense. */
    public const FEW_LEFT = 4;

    /**
     * @param  array<string, mixed>  $frame  TournamentLiveSlides::all() entry
     * @return list<array<string, string|int>>
     */
    public static function fromFrame(array $frame): array
    {
        $name = SceneFacts::spoken((string) ($frame['name'] ?? ''));

        if ($name === null) {
            return [];
        }

        $base = ['tournament' => $name];
        $moments = [];
        $standing = is_array($frame['standing'] ?? null) ? $frame['standing'] : null;
        $bracket = (($frame['board']['kind'] ?? null) === 'bracket');

        if (($frame['phase'] ?? null) === 'running' && $bracket && $standing !== null) {
            $left = (int) ($standing['count'] ?? 0);
            $of = (int) ($standing['of'] ?? 0);
            $faces = array_values(array_filter(array_map(fn (mixed $face): ?string => is_array($face) ? SceneFacts::spoken((string) ($face['name'] ?? '')) : null, (array) ($standing['faces'] ?? []))));

            if ($left === 2 && count($faces) === 2) {
                $moments[] = [...$base, 'kind' => 'tournament_final', 'mood' => Mood::TENSION, 'finalist_a' => $faces[0], 'finalist_b' => $faces[1]];
            } elseif ($left >= 2 && $left < $of) {
                $moments[] = array_filter([...$base, 'kind' => 'tournament_field', 'mood' => $left <= self::FEW_LEFT ? Mood::TENSION : null,
                    'still_in' => Words::number($left).' von '.$of, 'still_in_count' => $left, 'entrants' => $of], fn (mixed $value): bool => $value !== null);
            }
        }

        $upset = is_array($frame['upset'] ?? null) ? $frame['upset'] : null;
        if ($upset !== null && ($frame['cup'] ?? false) !== true) {
            $winner = SceneFacts::spoken((string) ($upset['winner']['name'] ?? ''));
            $loser = SceneFacts::spoken((string) ($upset['loser']['name'] ?? ''));
            $winnerSeed = $upset['winner']['seed'] ?? null;
            $loserSeed = $upset['loser']['seed'] ?? null;

            if ($winner !== null && $loser !== null && is_int($winnerSeed) && is_int($loserSeed) && $winnerSeed > $loserSeed) {
                $moments[] = [...$base, 'kind' => 'tournament_upset', 'mood' => Mood::UPSET, 'lead' => $winner, 'upset_winner' => $winner, 'upset_loser' => $loser,
                    'winner_seed' => $winnerSeed, 'loser_seed' => $loserSeed];
            }
        }

        return $moments;
    }
}
