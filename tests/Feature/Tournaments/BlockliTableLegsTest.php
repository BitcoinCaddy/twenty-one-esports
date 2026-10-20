<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Blockli in a round-robin table: one game or two, points per pairing or per game
|--------------------------------------------------------------------------
|
| DerCaddy, 2026-10-18, on the group stage of a Blockli tournament: "zwei Spiele gegen jeden (das sollte man
| vorher einstellen können!) und die Punkte passen dann auch nicht: Wenn man zwei Spiele gegeneinander spielt,
| sollte man dann auch zwei Punkte bekommen können." The organizer chooses (FormatOptions::$legs, $legPoints);
| by default a pairing plays two games and counts once, as before. A knockout always plays two and a decider.
| The helpers (blockliTournament() and the rest) are tests/Support/blockli.php.
|
*/

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
});

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

test('a Blockli tournament stored before the choice existed keeps two games counted per pairing, whatever the defaults become', function () {
    $stored = collect(FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray())->except(['legs', 'legPoints'])->all();
    $tournament = Tournament::factory()->create(['game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::RoundRobin, 'options' => $stored]);

    expect($tournament->formatOptions())->legs->toBe(2)->legPoints->toBeFalse()
        ->and($tournament->formatOptions()->perLeg())->toBeFalse()
        ->and(TournamentRunner::stagePlaysTwoLegs($tournament, TournamentFormat::RoundRobin))->toBeTrue();
});

test('one game per pairing in a round robin: the game decides the pairing, no second leg', function () {
    $tournament = blockliTournament(TournamentFormat::RoundRobin, ['legs' => 1]);
    blockliLose($tournament, 1);
    $match = blockliMatch($tournament);

    expect(BoardGame::query()->count())->toBe(1)
        ->and($match->result['winner'])->toBe(0)
        ->and($match->result['decided'] ?? null)->not->toBe('legs')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(blockliTable($tournament))->toBe([['Player 1' => ['1', 1, 0, 0], 'Player 2' => ['0', 0, 0, 1]], false, false]);
});

test('a knockout keeps its two legs whatever the round-robin setting says', function () {
    $tournament = blockliTournament(TournamentFormat::SingleElimination, ['legs' => 1]);
    blockliLose($tournament, 1);

    expect(blockliLive($tournament)->tournament_game)->toBe(2)
        ->and(blockliMatch($tournament)->result)->toBeNull();
});

test('only the round-robin stage plays one game: a two-stage final and a Swiss stage keep two legs', function (TournamentFormat $format, array $options, array $twoLegsByStage) {
    $tournament = blockliTournament($format, ['legs' => 1, ...$options], 4);

    $byStage = $tournament->matches()->where('bracket', '!=', 'bye')->with('round.stage')->get()
        ->groupBy(fn ($match): string => $match->round->stage->format->value)
        ->map(fn ($matches, string $stage): array => $matches->map(fn ($match): bool => TournamentRunner::twoLegs($match, $tournament))->push(TournamentRunner::stagePlaysTwoLegs($tournament, TournamentFormat::from($stage)))->unique()->values()->all())
        ->sortKeys()->all();

    expect($byStage)->toBe(array_map(fn (bool $twoLegs): array => [$twoLegs], collect($twoLegsByStage)->sortKeys()->all()));
})->with([
    'two stages: the groups one game, the final two' => [TournamentFormat::TwoStage, ['groupSize' => 4, 'advance' => 2], ['single-elimination' => true, 'round-robin' => false]],
    'Swiss, whatever legs is still stored from a round-robin draft' => [TournamentFormat::Swiss, [], ['swiss' => true]],
]);

test('points per game: each game is in the table once it ends, and a pairing gives up to two points', function () {
    $tournament = blockliTournament(TournamentFormat::RoundRobin, ['legPoints' => true]);

    blockliLose($tournament, 1);
    expect(blockliMatch($tournament)->result)->toBeNull('the pairing is still open')
        ->and(blockliTable($tournament))->toBe([['Player 1' => ['1', 1, 0, 0], 'Player 2' => ['0', 0, 0, 1]], true, true]);

    blockliLose($tournament, 1);

    expect(blockliMatch($tournament)->result['legs'])->toEqual([[1.0, 0.0], [1.0, 0.0]])
        ->and(blockliTable($tournament))->toBe([['Player 1' => ['2', 2, 0, 0], 'Player 2' => ['0', 0, 0, 2]], true, true]);

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('Every game counts: a win 1 point, a draw ½, up to 2 points per pairing.', false);
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
        ->call('pickLegs', 1)->assertSet('options.legs', 1)
        ->call('pickLegs', 2)->call('pickLegPoints', true)->assertSet('options.legPoints', true)
        ->call('create')
        ->assertHasNoErrors();

    $tournament = Tournament::query()->sole();
    expect($tournament->format)->toBe(TournamentFormat::TwoStage)
        ->and($tournament->formatOptions())->legs->toBe(2)->legPoints->toBeTrue();

    Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="legs-option"')->assertSeeHtml('data-test="leg-points-option"');

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

test('picking a leg setting changes nothing in the database until the editor saves (user, 2026-10-12)', function (string $pick, int|bool $value, string $key) {
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    Livewire::actingAs($admin)->test('pages::admin.tournament-create')
        ->set('name', 'Blockli Gruppen')->call('pickGame', 'blockli/correspondence')->set('players', '8')->call('select', TournamentFormat::TwoStage->value)
        ->call('create')->assertHasNoErrors();

    $tournament = Tournament::query()->sole();
    $stored = fn (): array => Tournament::query()->sole()->formatOptions()->toArray();
    expect($stored())->toMatchArray(['legs' => 2, 'legPoints' => false]);

    $edit = Livewire::actingAs($admin)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
        ->call($pick, $value)->assertSet("options.{$key}", $value);

    expect($stored())->toMatchArray(['legs' => 2, 'legPoints' => false]);

    $edit->call('save')->assertSet('error', '');

    expect($stored())->toMatchArray([$key => $value]);
})->with([
    'points per game' => ['pickLegPoints', true, 'legPoints'],
    'one game per pairing' => ['pickLegs', 1, 'legs'],
]);

test('the Pairings chip names, per stage, how a pairing is played and decided', function (TournamentFormat $format, array $options, string|array $says) {
    $tournament = blockliTournament($format, $options, 4);

    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSeeInOrder((array) $says, false);
})->with([
    'knockout: a third game decides 1:1' => [TournamentFormat::SingleElimination, [], 'at 1:1 a third game decides'],
    'Swiss: 1:1 is a draw' => [TournamentFormat::Swiss, [], 'counted as one pairing: 1:1 is a draw'],
    'round robin, points per game' => [TournamentFormat::RoundRobin, ['legPoints' => true], 'counted game by game: a win 1, a draw ½ each'],
    'round robin, one game' => [TournamentFormat::RoundRobin, ['legs' => 1], 'one game per pairing; the colours even out over the rounds'],
    'two stages: groups and final named apart' => [TournamentFormat::TwoStage, ['groupSize' => 4, 'advance' => 2, 'legs' => 1], [
        'data-fact="pairings-groups"', 'Group stage', 'one game per pairing; the colours even out over the rounds',
        'data-fact="pairings-final"', 'Final stage', 'two games with the colours swapped; at 1:1 a third game decides',
    ]],
]);

test('a per-game table adds no database reads to the tournament page, and a pairing under way costs it two at most', function () {
    $queries = function (bool $legPoints, int $n): int {
        $tournament = blockliTournament(TournamentFormat::RoundRobin, ['legPoints' => $legPoints], $n);
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new TournamentView($tournament->refresh()))->stages();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // Eight players: 28 pairings under way, 6 for four. Measured 65 and 21 on both rules (the finished games and the players of each
    // pairing, read once); before the legs were read once per pairing, a per-game table cost 120 with eight.
    [$perGame, $perPairing, $small] = [$queries(true, 8), $queries(false, 8), $queries(false, 4)];

    expect($perGame)->toBe($perPairing)
        ->and($perPairing - $small)->toBeLessThanOrEqual(2 * (28 - 6));
});
