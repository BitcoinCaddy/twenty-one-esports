<?php

use App\Enums\PongEndReason;
use App\Models\PongMatch;
use App\Support\Memes\MemeMoments;
use App\Support\Pong\PongRules;

/*
|--------------------------------------------------------------------------
| Meme moments (plan "KI-Stimme der Liga", P5)
|--------------------------------------------------------------------------
|
| Each moment type from a plain result, then from real Proof of Pong matches:
| the Elo stored before the match (upset), the match log replayed with each
| rally's points (comeback, only when it adds up to the stored score), the
| final score (deuce, close game) and the winner's earlier wins (first win).
|
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array{kind: 'points', score: array{0: int, 1: int}, target: int, ratings: array{0: int, 1: int}|null, path: list<array{0: int, 1: int}>|null, first_win: bool}
 */
function memeResult(array $overrides = []): array
{
    /** @var array{kind: 'points', score: array{0: int, 1: int}, target: int, ratings: array{0: int, 1: int}|null, path: list<array{0: int, 1: int}>|null, first_win: bool} */
    return [...['kind' => 'points', 'score' => [21, 10], 'target' => 21, 'ratings' => null, 'path' => null, 'first_win' => false], ...$overrides];
}

/**
 * A Proof of Pong log in which side `$first` scores `$lead` goals, then the other side scores until it wins; and the
 * final score [left, right] the referee would have stored (the halving rally counts two).
 *
 * @return array{log: list<list<int|string|null>>, score: array{0: int, 1: int}}
 */
function memePongLog(int $seed, int $first, int $lead): array
{
    $rules = PongRules::fromConfig((array) config('esports.pong'));
    $score = [0, 0];
    $log = [];

    for ($rally = 1; $rules->winner($score) === null; $rally++) {
        $scorer = $rally <= $lead ? $first : 1 - $first;
        $score[$scorer] += $rules->eventOf($seed, $rally) === PongRules::HALVING ? 2 : 1;
        // The log names the side that missed.
        $log[] = ['goal', $rally, 100, 1 - $scorer, 0, null, 'miss'];
    }

    return ['log' => $log, 'score' => $score];
}

it('finds nothing in a plain win', function () {
    expect(MemeMoments::detect(memeResult()))->toBe([]);
});

it('finds an upset from the Elo before the match, from the configured gap on', function () {
    config(['esports.voice.memes.upset_elo' => 150]);

    expect(MemeMoments::detect(memeResult(['ratings' => [1000, 1150]])))->toBe(['upset'])
        ->and(MemeMoments::detect(memeResult(['ratings' => [1000, 1149]])))->toBe([])
        // The favourite winning is no upset.
        ->and(MemeMoments::detect(memeResult(['ratings' => [1300, 1000]])))->toBe([]);
});

it('finds a comeback from the running score, from the configured deficit on', function () {
    config(['esports.voice.memes.comeback_points' => 6]);
    $path = [[0, 1], [0, 2], [0, 3], [0, 4], [0, 5], [0, 6], [21, 6]];

    expect(MemeMoments::detect(memeResult(['path' => $path])))->toBe(['comeback'])
        ->and(MemeMoments::detect(memeResult(['path' => array_slice($path, 1, 4)])))->toBe([])
        ->and(MemeMoments::deficit($path))->toBe(6);
});

it('finds a deuce from 20 all on, and a close game only below it', function () {
    expect(MemeMoments::detect(memeResult(['score' => [22, 20]])))->toBe(['pong_deuce'])
        ->and(MemeMoments::detect(memeResult(['score' => [25, 23]])))->toBe(['pong_deuce'])
        ->and(MemeMoments::detect(memeResult(['score' => [21, 19]])))->toBe(['close_game'])
        ->and(MemeMoments::detect(memeResult(['score' => [21, 18]])))->toBe([]);
});

it('finds a first win, and orders several moments strongest first', function () {
    expect(MemeMoments::detect(memeResult(['first_win' => true])))->toBe(['first_win'])
        ->and(MemeMoments::detect(memeResult(['score' => [22, 20], 'first_win' => true, 'ratings' => [900, 1200]])))->toBe(['upset', 'pong_deuce', 'first_win']);
});

it('finds nothing for a result that is no win', function () {
    expect(MemeMoments::detect(memeResult(['score' => [20, 20], 'first_win' => true])))->toBe([]);
});

it('reads an upset and a first win from a rated live Proof of Pong match', function () {
    $match = PongMatch::factory()->finished(1, [19, 21])->create([
        'left_rating_before' => 1210, 'right_rating_before' => 1000, 'rated' => true,
    ]);

    expect(MemeMoments::ofPongMatch($match))->toBe(['upset', 'first_win', 'close_game']);

    // Unrated, the stored Elo is no measure.
    $match->update(['rated' => false]);
    expect(MemeMoments::ofPongMatch($match->refresh()))->toBe(['first_win', 'close_game']);
});

it('reads no first win once the winner won a live match before', function () {
    $earlier = PongMatch::factory()->finished(0, [21, 5])->create();
    $match = PongMatch::factory()->finished(0, [21, 9])->create(['left_id' => $earlier->left_id]);

    expect(MemeMoments::ofPongMatch($earlier))->toBe(['first_win'])
        ->and(MemeMoments::ofPongMatch($match))->toBe([]);
});

it('reads a comeback from the match log replayed with each rally\'s points', function () {
    ['log' => $log, 'score' => $score] = memePongLog(7, 1, 8);
    $match = PongMatch::factory()->finished(0, $score)->create(['seed' => 7, 'log' => $log]);

    $result = MemeMoments::ofPong($match);

    expect($result['path'])->not->toBeNull()
        ->and(MemeMoments::deficit($result['path']))->toBeGreaterThanOrEqual(8)
        ->and(MemeMoments::ofPongMatch($match))->toContain('comeback');
});

it('claims no comeback when the log does not add up to the stored score', function () {
    ['log' => $log, 'score' => $score] = memePongLog(7, 1, 8);
    $match = PongMatch::factory()->finished(0, [$score[0], $score[1] + 1])->create(['seed' => 7, 'log' => $log]);

    expect(MemeMoments::ofPong($match)['path'])->toBeNull()
        ->and(MemeMoments::ofPongMatch($match))->not->toContain('comeback');
});

it('has no moment for a match that did not end on the score', function (PongEndReason $reason) {
    $match = PongMatch::factory()->finished(0, [21, 19], $reason)->create();

    expect(MemeMoments::ofPong($match))->toBeNull()
        ->and(MemeMoments::ofPongMatch($match))->toBe([]);
})->with([PongEndReason::Resign, PongEndReason::Forfeit]);
