<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;

/*
|--------------------------------------------------------------------------
| A running Blockli tournament, shared by the tests that play its pairings
|--------------------------------------------------------------------------
|
| The caller turns Blockli on (Tests\Support\BlockliOn::play()) and fakes the queue first. Players are "Player 1"
| (the highest seed) to "Player n"; every game of the first round is live at once.
|
*/

/**
 * A drawn, running Blockli tournament of `$n` players (correspondence, the only mode its tournaments play).
 *
 * @param  array<string, mixed>  $options  FormatOptions input, e.g. ['legs' => 1]
 * @param  array<string, mixed>  $user  attributes of every player's user
 */
function blockliTournament(TournamentFormat $format = TournamentFormat::SingleElimination, array $options = [], int $n = 2, array $user = []): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => $format,
        'options' => FormatOptions::fromArray($options, GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'blockli-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach (range(1, $n) as $index) {
        $player = User::factory()->create($user);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The one live game of a tournament. */
function blockliLive(Tournament $tournament): BoardGame
{
    return BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
}

/** The one match of a two-player tournament. */
function blockliMatch(Tournament $tournament): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->with('slots.participant', 'round.stage', 'boardGame', 'tournament')->sole();
}

/** The user in a slot of the match (0 = the higher seed). */
function blockliSlotUser(Tournament $tournament, int $slot): int
{
    return (int) blockliMatch($tournament)->slots[$slot]->participant->memberIds()[0];
}

/** The live game ends: the player of `$loserSlot` resigns. */
function blockliLose(Tournament $tournament, int $loserSlot): BoardGame
{
    $game = blockliLive($tournament);
    $loser = User::query()->findOrFail(blockliSlotUser($tournament, $loserSlot));
    app(BoardGameService::class)->resign($game, $loser);

    return $game->refresh();
}

/**
 * The table of the first round-robin part as the tournament page shows it: name => [points, wins, draws, losses],
 * then the part's `two_legs` and `per_leg` flags.
 *
 * @return array{0: array<string, array{0: string, 1: int, 2: int, 3: int}>, 1: bool, 2: bool}
 */
function blockliTable(Tournament $tournament): array
{
    $part = (new TournamentView($tournament->refresh()))->stages()[0]['parts'][0];

    return [collect($part['rows'])->mapWithKeys(fn (array $row): array => [$row['name'] => [$row['points'], $row['wins'], $row['ties'], $row['losses']]])->all(), $part['two_legs'], $part['per_leg']];
}
