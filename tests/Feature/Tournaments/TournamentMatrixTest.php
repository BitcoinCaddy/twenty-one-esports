<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentMatrix;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;

/*
 * The live page's cross table with traffic lights (user 2026-10-15): every pairing of a group, its score from the
 * row's side, and red where the direction should follow up.
 */

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

function matrixTournament(): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::RoundRobin, 'name' => 'Matrix Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 3, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'matrix-cup-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach (['Anna', 'Bert', 'Cleo'] as $index => $name) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - 10 * $index, 'seed' => $index + 1, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The cells of the overview by "row-column" names. */
function matrixCells(Tournament $tournament, CarbonImmutable $now): array
{
    $overview = (new TournamentMatrix($tournament->refresh()))->overview($now);
    $names = collect($overview['groups'][0]['players'])->pluck('name', 'id');
    $cells = [];

    foreach ($overview['groups'][0]['cells'] as $row => $columns) {
        foreach ($columns as $column => $cell) {
            $cells[$names[$row].'-'.$names[$column]] = $cell;
        }
    }

    return $cells;
}

test('a fresh round shows its started games green and the others waiting; no first move after six hours turns red with the name to move', function () {
    $tournament = matrixTournament();
    $active = BoardGame::query()->where('status', BoardGameStatus::Active)->get();
    $now = CarbonImmutable::now();
    $overview = (new TournamentMatrix($tournament))->overview($now);

    expect($overview['groups'])->toHaveCount(1)
        ->and(collect($overview['groups'][0]['players'])->pluck('name')->all())->toBe(['Anna', 'Bert', 'Cleo'])
        ->and($active)->not->toBeEmpty()
        ->and($overview['counts']['green'])->toBe($active->count())
        ->and($overview['attention'])->toBe([]);

    $late = matrixCells($tournament, $now->addHours(7));
    $red = collect($late)->filter(fn (array $cell): bool => $cell['state'] === TournamentMatrix::RED);

    expect($red)->toHaveCount(2 * $active->count())
        ->and($red->first()['reason'])->toMatch('/^No first move for 7 h: (Anna|Bert|Cleo) to move$/');
});

test('a won first leg reads 1 and 0 from each side, yellow with the second game under way; the decided pairing is done with its result', function () {
    $tournament = matrixTournament();
    $game = BoardGame::query()->where('status', BoardGameStatus::Active)->orderBy('id')->firstOrFail();
    $match = TournamentMatch::query()->with('slots.participant')->findOrFail($game->tournament_match_id);
    [$a, $b] = [$match->slots[0]->participant->name, $match->slots[1]->participant->name];
    $loser = User::query()->findOrFail((int) $match->slots[1]->participant->memberIds()[0]);
    app(BoardGameService::class)->resign($game, $loser);

    $cells = matrixCells($tournament, CarbonImmutable::now());
    expect($cells["{$a}-{$b}"])->toMatchArray(['state' => TournamentMatrix::YELLOW, 'score' => '1', 'reason' => 'Game 2 under way'])
        ->and($cells["{$b}-{$a}"])->toMatchArray(['state' => TournamentMatrix::YELLOW, 'score' => '0']);

    app(BoardGameService::class)->resign(BoardGame::query()->where('tournament_match_id', $match->id)->where('status', BoardGameStatus::Active)->sole(), $loser);
    $cells = matrixCells($tournament, CarbonImmutable::now());
    expect($cells["{$a}-{$b}"])->toMatchArray(['state' => TournamentMatrix::DONE, 'score' => '2', 'reason' => null])
        ->and($cells["{$b}-{$a}"]['score'])->toBe('0');
});

test('a move deadline under six hours turns red, a move pending for over twelve hours yellow, a held result red', function () {
    $tournament = matrixTournament();
    $game = BoardGame::query()->where('status', BoardGameStatus::Active)->orderBy('id')->firstOrFail();
    $now = CarbonImmutable::now();
    // A first move made: the game is under way.
    $game->forceFill(['ply' => 3, 'turn_started_ms' => $now->subHours(13)->getTimestampMs(), 'deadline_ms' => $now->addHours(11)->getTimestampMs()])->save();
    $key = strtoupper(TournamentMatch::query()->findOrFail($game->tournament_match_id)->key);
    $cell = fn () => collect(matrixCells($tournament, $now))->firstWhere('key', $key);

    expect($cell())->toMatchArray(['state' => TournamentMatrix::YELLOW])
        ->and($cell()['reason'])->toMatch('/on the move for 13 h$/');

    $game->forceFill(['deadline_ms' => $now->addHours(5)->getTimestampMs()])->save();
    expect($cell()['state'])->toBe(TournamentMatrix::RED)
        ->and($cell()['reason'])->toMatch('/^Move deadline in 5 h: (Anna|Bert|Cleo) to move$/');

    TournamentMatch::query()->findOrFail($game->tournament_match_id)->forceFill(['held' => ['was' => [], 'reason' => 'correction', 'at' => now()->toIso8601String(), 'user_id' => null, 'name' => 'Admin']])->save();
    expect($cell())->toMatchArray(['state' => TournamentMatrix::RED, 'reason' => 'Result on hold: decide it on the control']);
});

test('the live page shows the matrix to the admins with a cell per pairing and the counts, and a knockout tournament has none', function () {
    $tournament = matrixTournament();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $this->actingAs($admin)->get(route('tournaments.live', $tournament))->assertOk()
        ->assertSee('data-test="live-matrix"', false)
        ->assertSee('All pairings at a glance');

    $html = $this->actingAs($admin)->get(route('tournaments.live', $tournament))->getContent();
    expect(substr_count($html, 'data-test="matrix-cell"'))->toBe(6);

    $knockout = Tournament::factory()->create(['game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'status' => TournamentStatus::Running]);
    expect((new TournamentMatrix($knockout))->overview()['groups'])->toBe([]);
});
