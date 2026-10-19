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
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| Blockli in a round-robin table: one game or two, points per pairing or per game
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-10-18, on the group stage of a Blockli tournament: "zwei Spiele gegen jeden (das sollte man
| vorher einstellen können!) und die Punkte passen dann auch nicht: Wenn man zwei Spiele gegeneinander spielt,
| sollte man dann auch zwei Punkte bekommen können." The organizer chooses (FormatOptions::$legs, $legPoints);
| by default a pairing plays two games and counts once, as before. A knockout always plays two and a decider.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

function tableLegsTournament(TournamentFormat $format, array $options = [], int $n = 2): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => $format,
        'options' => FormatOptions::fromArray($options, GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'blockli-table-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

function tableLegsMatch(Tournament $tournament): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->with('slots.participant', 'round.stage', 'tournament')->sole();
}

/** The live game ends: the player of `$loserSlot` resigns. */
function tableLegsLose(Tournament $tournament, int $loserSlot): BoardGame
{
    $game = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
    app(BoardGameService::class)->resign($game, User::query()->findOrFail(tableLegsMatch($tournament)->slots[$loserSlot]->participant->memberIds()[0]));

    return $game->refresh();
}

/** The table as the tournament page shows it: name => [points, wins, draws, losses], and the part's flags. */
function tableLegsTable(Tournament $tournament): array
{
    $part = (new TournamentView($tournament->refresh()))->stages()[0]['parts'][0];

    return [collect($part['rows'])->mapWithKeys(fn (array $row): array => [$row['name'] => [$row['points'], $row['wins'], $row['ties'], $row['losses']]])->all(), $part['two_legs'], $part['per_leg']];
}

test('the options: two games and points per pairing by default; one game or points per game on choice; a game without two legs keeps neither', function () {
    $blockli = GameProfile::for('blockli', 'correspondence');

    expect(FormatOptions::fromArray([], $blockli))->legs->toBe(2)->legPoints->toBeFalse()
        ->and(FormatOptions::fromArray([], $blockli)->perLeg())->toBeFalse()
        ->and(FormatOptions::fromArray(['legPoints' => true], $blockli)->perLeg())->toBeTrue()
        ->and(FormatOptions::fromArray(['legs' => 1, 'legPoints' => true], $blockli)->perLeg())->toBeFalse('one game has nothing to count apart')
        ->and(FormatOptions::fromArray(['legs' => 5], $blockli)->legs)->toBe(2)
        ->and(FormatOptions::fromArray(['legs' => 1], $blockli)->toArray())->toMatchArray(['legs' => 1, 'legPoints' => false])
        ->and(FormatOptions::fromArray(['legs' => 1, 'legPoints' => true], GameProfile::for('chess', 'blitz')))->legs->toBe(2)->legPoints->toBeFalse();
});

test('one game per pairing in a round robin: the game decides the pairing, no second leg', function () {
    $tournament = tableLegsTournament(TournamentFormat::RoundRobin, ['legs' => 1]);
    tableLegsLose($tournament, 1);
    $match = tableLegsMatch($tournament);

    expect(BoardGame::query()->count())->toBe(1)
        ->and($match->result['winner'])->toBe(0)
        ->and($match->result['decided'] ?? null)->not->toBe('legs')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(tableLegsTable($tournament))->toBe([['Player 1' => ['1', 1, 0, 0], 'Player 2' => ['0', 0, 0, 1]], false, false]);
});

test('a knockout keeps its two legs whatever the round-robin setting says', function () {
    $tournament = tableLegsTournament(TournamentFormat::SingleElimination, ['legs' => 1]);
    tableLegsLose($tournament, 1);
    $second = BoardGame::query()->where('status', BoardGameStatus::Active)->sole();

    expect($second->tournament_game)->toBe(2)
        ->and(tableLegsMatch($tournament)->result)->toBeNull();
});

test('points per game: each game is in the table once it ends, and a pairing gives up to two points', function () {
    $tournament = tableLegsTournament(TournamentFormat::RoundRobin, ['legPoints' => true]);

    tableLegsLose($tournament, 1);
    expect(tableLegsMatch($tournament)->result)->toBeNull('the pairing is still open')
        ->and(tableLegsTable($tournament))->toBe([['Player 1' => ['1', 1, 0, 0], 'Player 2' => ['0', 0, 0, 1]], true, true]);

    tableLegsLose($tournament, 1);
    $match = tableLegsMatch($tournament);

    expect($match->result['legs'])->toEqual([[1.0, 0.0], [1.0, 0.0]])
        ->and(tableLegsTable($tournament))->toBe([['Player 1' => ['2', 2, 0, 0], 'Player 2' => ['0', 0, 0, 2]], true, true]);

    $page = $this->get(route('tournaments.show', $tournament))->assertOk();
    $page->assertSee('Every game counts: a win 1 point, a draw ½, up to 2 points per pairing.', false);
});

test('points per pairing, as before: the pairing enters the table after both games and counts once', function () {
    $tournament = tableLegsTournament(TournamentFormat::RoundRobin);

    tableLegsLose($tournament, 1);
    expect(tableLegsTable($tournament))->toBe([['Player 1' => ['0', 0, 0, 0], 'Player 2' => ['0', 0, 0, 0]], true, false]);

    tableLegsLose($tournament, 0);
    expect(tableLegsMatch($tournament)->result['legs'])->toEqual([[1.0, 0.0], [0.0, 1.0]])
        ->and(tableLegsTable($tournament))->toBe([['Player 1' => ['½', 0, 1, 0], 'Player 2' => ['½', 0, 1, 0]], true, false]);
});

test('the editor offers both settings for Blockli in round-robin groups and stores them; not in a knockout, not for chess', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    // The chooser is an island: a Livewire test reads it on the first render, so the edit page shows what was stored.
    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('name', 'Blockli Gruppen')
        ->call('pickGame', 'blockli/correspondence')
        ->set('players', '8')
        ->call('select', 'two-stage')
        ->call('option', 'legs', 1)
        ->assertSet('options.legs', 1)
        ->call('option', 'legs', 2)
        ->call('option', 'legPoints', true)
        ->assertSet('options.legPoints', true)
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();
    expect($tournament->format)->toBe(TournamentFormat::TwoStage)
        ->and($tournament->formatOptions())->legs->toBe(2)->legPoints->toBeTrue();

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="legs-option"')->assertSeeHtml('data-test="leg-points-option"')
        ->assertSeeHtml('wire:click="option(\'legPoints\', true)" wire:key="legp-1" data-test="leg-points-game"')
        ->assertSeeHtml('aria-checked="true" wire:click="option(\'legPoints\', true)"');

    $tournament->forceFill(['options' => [...$tournament->options, 'legs' => 1]])->save();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertSeeHtml('data-test="legs-option"')->assertDontSeeHtml('data-test="leg-points-option"');

    $tournament->forceFill(['format' => TournamentFormat::SingleElimination])->save();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertDontSeeHtml('data-test="legs-option"');

    $tournament->forceFill(['game' => 'chess', 'mode' => 'blitz', 'format' => TournamentFormat::RoundRobin])->save();
    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament->refresh()])
        ->assertDontSeeHtml('data-test="legs-option"');
});
