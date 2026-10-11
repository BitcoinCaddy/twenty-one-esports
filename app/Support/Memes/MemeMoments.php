<?php

namespace App\Support\Memes;

use App\Enums\PongEndReason;
use App\Enums\PongMatchStatus;
use App\Models\PongMatch;
use App\Support\Pong\PongRules;

/**
 * The meme moments of a finished match (plan "KI-Stimme der Liga", P5), read from what the league stored about it,
 * never guessed: an upset (the winner's Elo before the match at least `esports.voice.memes.upset_elo` below the
 * loser's), a comeback (the winner behind by at least `comeback_points` on the way), a Proof of Pong deuce (both
 * sides at 20 or more, "21:20 and the like"), a first win and a close game (won by the smallest lead the rules allow).
 *
 * The moments are VoiceCanon moment types, so a caption can be written for them. detect() works on a plain result
 * (see MemeResult) and returns the moments strongest first; ofPong() builds that result from a live Proof of Pong
 * match. A match that did not end on the score (resigned, forfeited, aborted) has no moment.
 *
 * Fail closed: the running score of a comeback comes from the match log, replayed with each rally's points (the
 * halving rally counts two); only when that replay ends exactly on the stored final score is the path trusted. A log
 * that does not add up (a void ball, a report the referee dropped) is no comeback, never a guessed one.
 *
 * @phpstan-type MemeResult array{kind: 'points', score: array{0: int, 1: int}, target: int, ratings: array{0: int, 1: int}|null, path: list<array{0: int, 1: int}>|null, first_win: bool}
 */
final class MemeMoments
{
    /** The moments this class finds, strongest first: the order a card picks its one moment by. */
    public const TYPES = ['upset', 'comeback', 'pong_deuce', 'first_win', 'close_game'];

    /**
     * The moments of a result, strongest first (empty: nothing worth a meme).
     *
     * `score`, `ratings` and every step of `path` are [winner, loser].
     *
     * @param  MemeResult  $result
     * @return list<string>
     */
    public static function detect(array $result): array
    {
        [$won, $lost] = $result['score'];

        if ($won <= $lost) {
            return [];
        }

        $deuce = $lost >= $result['target'] - 1;
        $found = [
            'upset' => $result['ratings'] !== null && $result['ratings'][1] - $result['ratings'][0] >= self::upsetElo(),
            'comeback' => $result['path'] !== null && self::deficit($result['path']) >= self::comebackPoints(),
            'pong_deuce' => $deuce,
            'first_win' => $result['first_win'],
            'close_game' => ! $deuce && $won - $lost <= 2,
        ];

        return array_values(array_filter(self::TYPES, fn (string $type): bool => $found[$type]));
    }

    /**
     * The result of a live Proof of Pong match as detect() reads it, or null when it has none: not finished, no
     * winner, or ended by anything but the score.
     *
     * @return MemeResult|null
     */
    public static function ofPong(PongMatch $match): ?array
    {
        if ($match->status !== PongMatchStatus::Finished || $match->end_reason !== PongEndReason::Score || $match->winner_id === null) {
            return null;
        }

        $side = $match->winner_id === $match->left_id ? 0 : ($match->winner_id === $match->right_id ? 1 : null);

        if ($side === null) {
            return null;
        }

        $score = [$match->score_left, $match->score_right];
        $before = [$match->left_rating_before, $match->right_rating_before];
        $rules = PongRules::fromConfig((array) config('esports.pong'));
        $path = self::pongPath($match, $rules, $score);

        return [
            'kind' => 'points',
            'score' => [$score[$side], $score[1 - $side]],
            'target' => $rules->pointsToWin,
            'ratings' => $match->rated && $before[0] !== null && $before[1] !== null ? [(int) $before[$side], (int) $before[1 - $side]] : null,
            'path' => $path === null ? null : array_map(fn (array $step): array => [$step[$side], $step[1 - $side]], $path),
            'first_win' => ! PongMatch::query()
                ->where('status', PongMatchStatus::Finished)
                ->where('winner_id', $match->winner_id)
                ->where('id', '<', $match->id)
                ->exists(),
        ];
    }

    /**
     * The moments of a live Proof of Pong match, strongest first.
     *
     * @return list<string>
     */
    public static function ofPongMatch(PongMatch $match): array
    {
        $result = self::ofPong($match);

        return $result === null ? [] : self::detect($result);
    }

    /**
     * The running score [left, right] after every goal of the log, or null when the replay does not end on the
     * stored score (see the class comment).
     *
     * @param  array{0: int, 1: int}  $final
     * @return list<array{0: int, 1: int}>|null
     */
    private static function pongPath(PongMatch $match, PongRules $rules, array $final): ?array
    {
        $score = [0, 0];
        $path = [];

        foreach ($match->log as $entry) {
            if (($entry[0] ?? null) !== 'goal' || ! is_int($entry[1] ?? null) || ! in_array($entry[3] ?? null, [0, 1], true)) {
                continue;
            }

            if ($rules->winner($score) !== null) {
                // Goals after the winning one do not count.
                break;
            }

            // The log names the side that missed; the other one scores.
            $scorer = 1 - (int) $entry[3];
            $score[$scorer] += $rules->eventOf($match->seed, $entry[1]) === PongRules::HALVING ? 2 : 1;
            $path[] = $score;
        }

        return $score === $final ? $path : null;
    }

    /**
     * The most the winner was behind on the way.
     *
     * @param  list<array{0: int, 1: int}>  $path
     */
    public static function deficit(array $path): int
    {
        $most = 0;

        foreach ($path as [$won, $lost]) {
            $most = max($most, $lost - $won);
        }

        return $most;
    }

    private static function upsetElo(): int
    {
        return max(1, (int) config('esports.voice.memes.upset_elo', 150));
    }

    private static function comebackPoints(): int
    {
        return max(1, (int) config('esports.voice.memes.comeback_points', 6));
    }
}
