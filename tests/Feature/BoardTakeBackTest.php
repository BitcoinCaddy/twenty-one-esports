<?php

/*
| Taking a move back (BoardGameService::takeBack(); DerCaddy and the players, 2026-10-15: "21 Sekunden nach dem Move
| kann man zurücknehmen, wenn der Gegner noch nicht gespielt hat", no accept step): for `take_back_seconds` after the
| move, while the opponent has not answered it. The game and both clocks come back as before the move, the time since
| it counted as the mover's. Proven with the three men's morris of FixtureBoardGame, blitz 5+3, first move 30 s.
*/

use App\Enums\BoardGameStatus;
use App\Events\BoardGameUpdated;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\FixtureBoardGame;

// Clocks are compared to the millisecond: time only moves when a test travels.
beforeEach(fn () => $this->freezeTime());

function takeBackGame(): BoardGame
{
    FixtureBoardGame::play();

    return app(BoardGameService::class)->start(FixtureBoardGame::SLUG, User::factory()->create(), User::factory()->create());
}

/**
 * Plays the moves in turn, each by the player whose move it is.
 *
 * @param  list<string>  $moves
 */
function takeBackPlay(BoardGame $game, array $moves): BoardGame
{
    foreach ($moves as $move) {
        $game->refresh();
        $game = app(BoardGameService::class)->move($game, $game->player($game->turn), $move, $game->ply + 1);
    }

    return $game->refresh();
}

function takeBackRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

function takeBackNow(): int
{
    return (int) now()->getTimestampMs();
}

test('the player who just moved takes it back within 21 seconds: position, turn, record and both clocks are as before the move', function () {
    $service = app(BoardGameService::class);
    // Both first moves made: the clocks run from here.
    $game = takeBackPlay(takeBackGame(), ['b2', 'a1']);
    $before = $game->only(['position', 'turn', 'ply', 'white_ms', 'black_ms', 'turn_started_ms', 'deadline_ms']);

    // White thinks 10 s: 300 − 10 + 3 = 293 s on its clock, and 21 s to take the move back.
    $this->travel(10)->seconds();
    $game = takeBackPlay($game, ['c3']);
    expect($game->white_ms)->toBe(293_000)
        ->and($service->takeBackUntil($game))->toBe(takeBackNow() + 21_000)
        ->and($service->snapshot($game)['takeBackUntil'])->toBe(takeBackNow() + 21_000);

    $this->travel(15)->seconds();
    Event::fake([BoardGameUpdated::class]);
    $game = $service->takeBack($game, $game->white)->refresh();

    expect($game->only(array_keys($before)))->toBe($before)
        ->and($game->moves()->pluck('move')->all())->toBe(['b2', 'a1'])
        ->and($game->version)->toBe(5)
        ->and($game->status)->toBe(BoardGameStatus::Active)
        // The 25 s since White's turn began are White's: 275 s left. Black's clock lost nothing.
        ->and($service->clocks($game, takeBackNow()))->toBe(['w' => 275_000, 'b' => 300_000])
        ->and($service->snapshot($game)['pieces'])->toBe(['a1' => ['side' => 'b', 'kind' => 'man'], 'b2' => ['side' => 'w', 'kind' => 'man']]);
    Event::assertDispatched(BoardGameUpdated::class, fn (BoardGameUpdated $event) => $event->state['ply'] === 2 && $event->state['turn'] === 'w');

    // White plays on as if nothing happened: the move takes the 30 s since its turn began off its clock.
    $this->travel(5)->seconds();
    $game = takeBackPlay($game, ['c1']);
    expect($game->white_ms)->toBe(300_000 - 30_000 + 3_000)
        ->and($game->moves()->pluck('move')->all())->toBe(['b2', 'a1', 'c1']);
});

test('no take-back after 21 seconds, once the opponent answered, for the opponent or a spectator, after the game, or with the switch off', function () {
    $service = app(BoardGameService::class);

    $late = takeBackPlay(takeBackGame(), ['b2', 'a1', 'c3']);
    $this->travel(21)->seconds();
    expect(takeBackRefusal(fn () => $service->takeBack($late, $late->white)))->toBe('take_back_closed');

    $answered = takeBackPlay(takeBackGame(), ['b2', 'a1', 'c3', 'c1']);
    expect(takeBackRefusal(fn () => $service->takeBack($answered, $answered->white)))->toBe('take_back_closed');

    $theirs = takeBackPlay(takeBackGame(), ['b2', 'a1', 'c3']);
    expect(takeBackRefusal(fn () => $service->takeBack($theirs, $theirs->black)))->toBe('take_back_closed')
        ->and(takeBackRefusal(fn () => $service->takeBack($theirs, User::factory()->create())))->toBe('not_a_player');

    // Three in a row ends the game with the move: over is over.
    $won = takeBackPlay(takeBackGame(), ['a1', 'b1', 'a2', 'b2', 'a3']);
    expect($won->status)->toBe(BoardGameStatus::Finished)
        ->and(takeBackRefusal(fn () => $service->takeBack($won, $won->white)))->toBe('game_over')
        ->and($service->takeBackUntil($won))->toBeNull();

    expect($service->takeBackUntil(takeBackGame()))->toBeNull('nothing to take back before a move');

    config(['esports.board_games.take_back_seconds' => 0]);
    $off = takeBackPlay(takeBackGame(), ['b2', 'a1', 'c3']);
    expect(takeBackRefusal(fn () => $service->takeBack($off, $off->white)))->toBe('take_back_closed')
        ->and($service->snapshot($off)['takeBackUntil'])->toBeNull();

    foreach ([$late, $answered, $theirs, $off] as $game) {
        expect($game->refresh()->moves()->count())->toBe($game->ply);
    }
});

test('a first move taken back gives the window for it back as it stood, and the take-back closes with that window', function () {
    $service = app(BoardGameService::class);
    $game = takeBackGame();
    $started = takeBackNow();

    // White's first move after 20 s of its 30: the take-back closes with the first-move window, 10 s later.
    $this->travel(20)->seconds();
    $game = takeBackPlay($game, ['b2']);
    expect($service->takeBackUntil($game))->toBe($started + 30_000);

    $this->travel(5)->seconds();
    $game = $service->takeBack($game, $game->white)->refresh();
    expect($game->only(['position', 'turn', 'ply', 'white_ms', 'turn_started_ms', 'deadline_ms']))->toBe([
        'position' => '......... w', 'turn' => 'w', 'ply' => 0, 'white_ms' => 300_000, 'turn_started_ms' => $started, 'deadline_ms' => $started + 30_000,
    ])->and($game->moves()->count())->toBe(0);

    // Black's first move, taken back: Black's own first-move window, as it stood, and no clock running yet.
    $game = takeBackPlay($game, ['b2']);
    $blackStarted = takeBackNow();
    $this->travel(4)->seconds();
    $game = takeBackPlay($game, ['a1']);
    $this->travel(3)->seconds();
    $game = $service->takeBack($game, $game->black)->refresh();
    expect($game->only(['ply', 'turn', 'black_ms', 'turn_started_ms', 'deadline_ms']))->toBe([
        'ply' => 1, 'turn' => 'b', 'black_ms' => 300_000, 'turn_started_ms' => $blackStarted, 'deadline_ms' => $blackStarted + 30_000,
    ])->and($game->clocksRunning())->toBeFalse();
});

test('the take-back never outlasts the mover\'s own time, so it never flags', function () {
    $service = app(BoardGameService::class);

    // White moves with 2 s left: 5 s on the clock after the increment, so 2 s to take the move back.
    $game = takeBackPlay(takeBackGame(), ['b2', 'a1']);
    $this->travel(298)->seconds();
    $game = takeBackPlay($game, ['c3']);
    expect($game->white_ms)->toBe(5_000)
        ->and($service->takeBackUntil($game))->toBe(takeBackNow() + 2_000);

    $this->travel(1)->seconds();
    $game = $service->takeBack($game, $game->white)->refresh();
    expect($service->clocks($game, takeBackNow()))->toBe(['w' => 1_000, 'b' => 300_000])
        ->and($game->status)->toBe(BoardGameStatus::Active);

    $late = takeBackPlay(takeBackGame(), ['b2', 'a1']);
    $this->travel(298)->seconds();
    $late = takeBackPlay($late, ['c3']);
    $this->travel(2)->seconds();
    expect(takeBackRefusal(fn () => $service->takeBack($late, $late->white)))->toBe('take_back_closed')
        ->and($late->refresh()->ply)->toBe(3);
});

test('a move sent from a board that missed the take-back is refused', function () {
    $service = app(BoardGameService::class);
    $game = takeBackPlay(takeBackGame(), ['b2', 'a1', 'c3']);
    $service->takeBack($game, $game->white);

    // Black's board still shows c3 and answers it: White is to move again.
    expect(takeBackRefusal(fn () => $service->move($game->refresh(), $game->black, 'c1', 4)))->toBe('not_your_turn')
        ->and(takeBackRefusal(fn () => $service->move($game->refresh(), $game->white, 'c1', 4)))->toBe('out_of_sync')
        ->and($game->refresh()->ply)->toBe(2);
});

test('the page takes the move back for the player who made it and refuses everyone else, with the server\'s state', function () {
    $game = takeBackPlay(takeBackGame(), ['b2']);

    $refused = Livewire::actingAs($game->black)->test('pages::board.show', ['boardGame' => $game])->call('takeBack');
    expect($refused->effects['returns'][0])->toMatchArray(['ok' => false, 'error' => 'take_back_closed']);

    $page = Livewire::actingAs($game->white)->test('pages::board.show', ['boardGame' => $game])->call('takeBack');
    expect($page->effects['returns'][0])->toMatchArray(['ok' => true, 'error' => null])
        ->and($page->effects['returns'][0]['state']['ply'])->toBe(0)
        ->and($page->effects['returns'][0]['state']['takeBackUntil'])->toBeNull()
        ->and($page->effects['returns'][0]['state']['pieces'])->toBe([]);
});
