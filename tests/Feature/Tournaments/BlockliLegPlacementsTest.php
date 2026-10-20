<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| Blockli round robin: points per game reach the final places and the champion
|--------------------------------------------------------------------------
|
| FormatOptions::$legPoints changes the table, the group places (LegPointsTest), and also what the prize split and
| the share card read: TournamentPlacements::table() and TournamentChampion::tableLeader() pass `perLeg` too. One
| finished round robin of four where the two ways disagree about the winner: per pairing Player 4 leads, per game
| Player 1. The default (per pairing) keeps today's order.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

/**
 * Finished Blockli round robin of four whose results are stored with their legs (as twoLegsFinished() stores them).
 *
 * Each entry is the pairing "low-high" => the two legs as [points of the lower number, points of the higher number]:
 *   1-2 2–0, 1-3 2–0, 1-4 ½–1½, 2-3 2–0, 2-4 1–1 (split), 3-4 ½–1½
 * Per pairing: P1 2, P2 1½, P3 0, P4 2½. Per game: P1 4½, P2 3, P3 ½, P4 4.
 */
function legPlacementsTournament(bool $legPoints): Tournament
{
    $tournament = blockliTournament(TournamentFormat::RoundRobin, ['legPoints' => $legPoints], 4);

    $legs = [
        '1-2' => [[1, 0], [1, 0]], '1-3' => [[1, 0], [1, 0]], '1-4' => [[0, 1], [0.5, 0.5]],
        '2-3' => [[1, 0], [1, 0]], '2-4' => [[1, 0], [0, 1]], '3-4' => [[0, 1], [0.5, 0.5]],
    ];

    foreach (TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->with('slots.participant')->get() as $match) {
        [$a, $b] = [(int) substr($match->slots[0]->participant->name, -1), (int) substr($match->slots[1]->participant->name, -1)];
        $played = $legs[min($a, $b).'-'.max($a, $b)];
        $played = $a < $b ? $played : array_map(fn (array $leg): array => [$leg[1], $leg[0]], $played);
        $points = [array_sum(array_column($played, 0)), array_sum(array_column($played, 1))];

        app(TournamentRunner::class)->store($match, [
            'winner' => $points[0] === $points[1] ? null : ($points[0] > $points[1] ? 0 : 1), 'games_won' => $points, 'points' => [],
            'forfeit' => false, 'decided' => 'legs', 'label' => 'x', 'by' => 'players', 'legs' => $played,
        ]);
    }

    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The places as [place => [player numbers]]. */
function legPlacementsOf(Tournament $tournament): array
{
    $names = TournamentParticipant::query()->where('tournament_id', $tournament->id)->pluck('name', 'id');

    return collect(app(TournamentPlacements::class)->of($tournament))
        ->mapWithKeys(fn (array $row): array => [$row['place'] => array_map(fn (int $id): int => (int) substr($names[$id], -1), $row['participants'])])->all();
}

test('the prize places and the champion follow points per game where the organizer chose so, and per pairing otherwise', function (bool $legPoints, array $places, int $champion) {
    $tournament = legPlacementsTournament($legPoints);
    expect($tournament->status)->toBe(TournamentStatus::Finished);

    $name = TournamentParticipant::query()->whereKey(app(TournamentChampion::class)->of($tournament)?->id)->value('name');

    expect(legPlacementsOf($tournament))->toBe($places)
        ->and($name)->toBe("Player {$champion}");
})->with([
    'per pairing (the default, as before)' => [false, [1 => [4], 2 => [1], 3 => [2], 4 => [3]], 4],
    'per game' => [true, [1 => [1], 2 => [4], 3 => [2], 4 => [3]], 1],
]);
