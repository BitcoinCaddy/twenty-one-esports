<?php

use App\Enums\TournamentFormat;
use App\Support\Tournaments\Engine\Advancement;
use App\Support\Tournaments\Engine\BracketBuilder;
use App\Support\Tournaments\Engine\BracketMatch;
use App\Support\Tournaments\Engine\Entrant;
use App\Support\Tournaments\Engine\MatchResult;
use App\Support\Tournaments\Engine\Standings;
use App\Support\Tournaments\FormatOptions;

/*
|--------------------------------------------------------------------------
| Points per game for two-leg pairings in a round-robin table
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-10-18: "Wenn man zwei Spiele gegeneinander spielt, sollte man dann auch zwei Punkte bekommen
| können." A Blockli pairing plays two games; with FormatOptions::$legPoints every game counts on its own (a win
| 1, a draw ½), so a pairing is worth up to 2 points, and the table, the group's places and the final placings
| all count that way. Off by default: the pairing counts once, as before.
|
*/

/** A pairing of two legs: each leg's points for slot 0 and slot 1. */
function legPairing(array ...$legs): MatchResult
{
    $a = array_sum(array_column($legs, 0));
    $b = array_sum(array_column($legs, 1));

    return new MatchResult($a === $b ? null : ($a > $b ? 0 : 1), [$a, $b], [], null, false, $legs);
}

/**
 * A group of four in which counting per game changes who is second: 2 wins its pairings narrowly (1½–½), 3 splits
 * with the strongest and wins its last pairing 2–0.
 *
 * @return list<array{0: int, 1: int, 2: MatchResult}>
 */
function legGroup(): array
{
    return [
        [1, 2, legPairing([1, 0], [1, 0])],
        [1, 3, legPairing([1, 0], [0, 1])],
        [1, 4, legPairing([1, 0], [1, 0])],
        [2, 3, legPairing([1, 0], [0.5, 0.5])],
        [2, 4, legPairing([1, 0], [0.5, 0.5])],
        [3, 4, legPairing([1, 0], [1, 0])],
    ];
}

test('per game, a pairing is worth up to two points: 2–0 two, 1½–½ one and a half, a split one each', function () {
    $points = fn (bool $perLeg): array => collect(Standings::table([1, 2, 3, 4], legGroup(), perLeg: $perLeg))
        ->mapWithKeys(fn ($row) => [$row->entrant => [$row->points, $row->wins, $row->ties, $row->losses]])->sortKeys()->all();

    // Per pairing (as before): 1 wins two pairings and splits one, 2 wins two, 3 one.
    expect($points(false))->toBe([1 => [2.5, 2, 1, 0], 2 => [2.0, 2, 0, 1], 3 => [1.5, 1, 1, 1], 4 => [0.0, 0, 0, 3]])
        // Per game: wins, draws and losses count games.
        ->and($points(true))->toBe([1 => [5.0, 5, 0, 1], 2 => [3.0, 2, 2, 2], 3 => [3.5, 3, 1, 2], 4 => [0.5, 0, 1, 5]])
        ->and(array_column(Standings::table([1, 2, 3, 4], legGroup(), perLeg: true), 'entrant'))->toBe([1, 3, 2, 4])
        ->and(array_column(Standings::table([1, 2, 3, 4], legGroup()), 'entrant'))->toBe([1, 2, 3, 4]);
});

test('a pairing decided without its games counts for both of them; a bye stays a bye', function () {
    $games = [[1, 2, MatchResult::win(0)], [1, 3, MatchResult::doubleLoss()], [2, null, MatchResult::win(0)]];
    $rows = collect(Standings::table([1, 2, 3], $games, bye: 0.0, perLeg: true))->keyBy('entrant');

    expect([$rows[1]->points, $rows[1]->wins, $rows[1]->losses])->toBe([2.0, 2, 2])
        ->and([$rows[2]->points, $rows[2]->losses, $rows[2]->byes])->toBe([0.0, 2, 1])
        ->and([$rows[3]->points, $rows[3]->losses])->toBe([0.0, 2]);
});

test('the group\'s places follow points per game: the second place moves on', function (bool $legPoints, int $second) {
    $entrants = array_map(fn (int $id): Entrant => new Entrant($id, 2000 - $id), range(1, 4));
    $options = new FormatOptions(groupSize: 4, advance: 2, legPoints: $legPoints);
    $bracket = BracketBuilder::build(TournamentFormat::TwoStage, $entrants, $options, 'seed');
    $group = collect(legGroup())->mapWithKeys(fn (array $game): array => [$game[0].'-'.$game[1] => $game[2]]);
    $results = [];

    for ($guard = 0; $guard < 50; $guard++) {
        $state = Advancement::resolve($bracket, $results, $options);
        $ready = array_filter($state, fn (array $match, string $key): bool => $match['status'] === 'ready' && $bracket->match($key)->stage === 1, ARRAY_FILTER_USE_BOTH);

        if ($ready === []) {
            break;
        }

        foreach ($ready as $key => $match) {
            [$a, $b] = $match['entrants'];
            $results[$key] = $group->has("{$a}-{$b}") ? $group["{$a}-{$b}"] : legPairing(...array_map(fn (array $leg): array => [$leg[1], $leg[0]], $group["{$b}-{$a}"]->legs));
        }
    }

    $final = collect($bracket->matches)->first(fn (BracketMatch $match): bool => $match->stage === 2);
    $state = Advancement::resolve($bracket, $results, $options);

    expect(collect($state[$final->key]['entrants'])->sort()->values()->all())->toBe([1, $second]);
})->with([
    'per pairing (as before): 2 is second' => [false, 2],
    'per game: 3 is second' => [true, 3],
]);
