<?php

use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\SeriesMatch;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\LobbyWords;
use App\Support\Tournaments\LeagueLobby;
use App\Support\Tournaments\TournamentMatchMaker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| League lobbies for every tournament match (plan "Refactor und Design-Revamp", P5)
|--------------------------------------------------------------------------
|
| User decision 2026-10-10: all tournament matches, 1v1 cups too, get the
| league's lobby name and password at the draw (LobbyWords, as lobby
| tournaments already do), so the directors can join any match as
| spectators; casual 1v1 keeps the lobby its host shares in the chat. The
| higher seed hosts. Name and password reach only the two sides (the match
| room) and the directors and admins (the match page), never anyone else.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** A running cup of four in `$game` 1v1, round 1 open; its first match and the series the league opens for it. */
function leagueLobbyCup(string $game = 'rocket-league'): array
{
    $cup = runningCup(4, TournamentFormat::DoubleElimination, ['grandFinal' => 'single', 'bestOf' => 3, 'finalBestOf' => 3], $game, '1v1');
    cupTick();
    $match = TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')->where('bracket', '!=', 'bye')
        ->with(['slots.participant', 'round'])->orderBy('id')->firstOrFail();
    $series = SeriesMatch::query()->where('tournament_match_id', $match->id)->first()
        ?? app(TournamentMatchMaker::class)->createCupSeries($cup, $match, $match->slots[0]->participant, $match->slots[1]->participant);

    return [$cup, $match, $series->refresh()];
}

test('a Rocket League cup match gets the league lobby at the draw, hosted by the higher seed', function () {
    [, $match, $series] = leagueLobbyCup();
    [$a, $b] = [$match->slots[0]->participant, $match->slots[1]->participant];
    $higher = $a->seed < $b->seed ? 'challenger' : 'challenged';

    expect($series->lobby_name)->toBe(LobbyWords::matchName($series->number))
        ->and($series->lobby_name)->toMatch('/^21-[a-z]{3,8}-\d+$/')
        ->and($series->lobby_password)->toMatch('/^[a-z]{3,8}-[a-z]{3,8}-\d{2}$/')
        ->and($series->host_side)->toBe($higher)
        ->and($series->lobby_updated_by_id)->toBeNull()
        ->and(LeagueLobby::isLeagueMade($series))->toBeTrue()
        // Stored encrypted, never in the row as text.
        ->and(DB::table('series_matches')->where('id', $series->id)->value('lobby_password'))->not->toContain($series->lobby_password)
        // Hidden from the model's array (the room fingerprint, any JSON).
        ->and($series->toArray())->not->toHaveKey('lobby_password');
});

test('the higher seed hosts whichever slot it sits in', function () {
    [, $match] = leagueLobbyCup();
    [$a, $b] = [$match->slots[0]->participant, $match->slots[1]->participant];

    $a->seed = 4;
    $b->seed = 1;
    expect(LeagueLobby::hostSide($a, $b))->toBe('challenged');
    $a->seed = 2;
    expect(LeagueLobby::hostSide($a, $b))->toBe('challenged');
    $b->seed = 3;
    expect(LeagueLobby::hostSide($a, $b))->toBe('challenger');
    $a->seed = null;
    $b->seed = null;
    expect(LeagueLobby::hostSide($a, $b))->toBe('challenger');
});

test('an EA Sports FC cup match gets no lobby: its players meet by EA ID', function () {
    [, , $series] = leagueLobbyCup('ea-sports-fc-26');

    expect($series->lobby_name)->toBeNull()
        ->and($series->lobby_password)->toBeNull()
        ->and(LeagueLobby::isLeagueMade($series))->toBeFalse();
});

test('a tournament series of lineups gets the league lobby too', function () {
    [$tournament] = directedSeries();
    $series = SeriesMatch::query()->whereHas('tournamentMatch', fn ($query) => $query->where('tournament_id', $tournament->id))->sole();

    expect($series->lobby_name)->toBe(LobbyWords::matchName($series->number))
        ->and($series->lobby_password)->not->toBeNull()
        ->and(LeagueLobby::isLeagueMade($series))->toBeTrue();
});

test('a casual 1v1 outside a tournament keeps the lobby its host shares', function () {
    [, , $series] = leagueLobbyCup();
    $series->forceFill(['tournament_match_id' => null])->save();

    expect(LeagueLobby::isLeagueMade($series->refresh()))->toBeFalse();
});

test('the room shows the league lobby to both players from the start, name and password in plain text with copy buttons', function () {
    [, , $series] = leagueLobbyCup();

    foreach (SeriesMatch::SIDES as $side) {
        $player = User::query()->findOrFail($series->rosterSide($side)[0]);
        $html = Livewire::actingAs($player)->test('pages::matches.room', ['match' => $series])->html();

        expect($html)->toContain('data-test="room-lobby-pin" data-league="1"')
            ->toContain('data-test="room-lobby-name">'.$series->lobby_name.'<')
            ->toContain('data-test="room-lobby-password">'.$series->lobby_password.'<')
            ->toContain('data-test="room-lobby-copy-name"')->toContain('data-test="room-lobby-copy-password"')
            // Never behind a show toggle.
            ->not->toContain('••••');
    }
});

test('the host opens the league lobby, the guest joins it, and no card goes through the chat', function () {
    [, , $series] = leagueLobbyCup();
    $series->forceFill(['ready_at_challenger' => now(), 'ready_at_challenged' => now(), 'start_at' => now()->subMinute()])->save();
    $host = User::query()->findOrFail($series->rosterSide($series->host_side)[0]);
    $guest = User::query()->findOrFail($series->rosterSide(SeriesMatch::otherSide($series->host_side))[0]);

    Livewire::actingAs($guest)->test('pages::matches.room', ['match' => $series])->assertDontSeeHtml('data-test="league-lobby-open"');
    Livewire::actingAs($host)->test('pages::matches.room', ['match' => $series])
        ->assertSeeHtml('data-test="league-lobby-open"')
        ->call('casualLeagueLobbyOpen')->assertSet('error', '');

    expect($series->refresh()->lobby_shared_at)->not->toBeNull();

    Livewire::actingAs($guest)->test('pages::matches.room', ['match' => $series])
        ->assertSeeHtml('data-test="casual-joined"')
        ->call('casualJoined')->assertSet('error', '');

    expect($series->refresh()->joined_at)->not->toBeNull();
});

test('a casual room without a league lobby refuses the league action', function () {
    [, , $series] = leagueLobbyCup();
    $series->forceFill(['tournament_match_id' => null])->save();
    $host = User::query()->findOrFail($series->rosterSide($series->host_side)[0]);

    Livewire::actingAs($host)->test('pages::matches.room', ['match' => $series])->call('casualLeagueLobbyOpen')->assertNotFound();
});

test('the match page shows the league lobby to the directors and admins only', function () {
    [$cup, , $series] = leagueLobbyCup();
    $director = organizer();
    $cup->forceFill(['created_by_id' => $director->id])->save();
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $stranger = User::factory()->create();
    $page = fn (?User $user): string => ($user === null ? $this : $this->actingAs($user))->get(route('matches.show', $series))->assertOk()->getContent();

    // The guest first: actingAs() stays for the requests after it.
    expect($page(null))->not->toContain('data-test="match-league-lobby"')->not->toContain($series->lobby_password)
        ->and($page($stranger))->not->toContain('data-test="match-league-lobby"')->not->toContain($series->lobby_password)
        ->and($page($director))->toContain('data-test="match-league-lobby"')->toContain($series->lobby_password)
        ->and($page($admin))->toContain('data-test="match-league-lobby"')->toContain($series->lobby_password);
});
