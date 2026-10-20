<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chat\ChatInbox;
use App\Support\Series\RoomMembers;
use App\Support\Tournaments\TournamentDesk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| "Your chats" (DerCaddy, 2026-10-18)
|--------------------------------------------------------------------------
|
| "Nachrichten in Chats sollten auch noch besser zu sehen sein … ein Reiter Deine Chats, wo relevante Chats
| angezeigt werden, in denen ich mich beteiligt habe bzw. jemand mir geschrieben hat." The league names the chats
| (App\Support\Chat\ChatInbox), the browser fills in their messages (resources/js/chatInbox.js, under Node in
| tests/js/chatInbox.test.mjs). In the account menu right below "Your matches and events".
|
*/

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.chat.relays' => ['wss://chat.example']]);
    BlockliOn::play();
});

test('the chats of the player\'s games that run or ended within two weeks, with what the browser needs to find their messages', function () {
    [$me, $anna, $bert] = User::factory()->count(3)->create();
    $board = app(BoardGameService::class)->start('blockli', $me, $anna);
    $chess = ChessGame::factory()->create(['white_id' => $bert->id, 'black_id' => $me->id]);
    $ended = BoardGame::query()->findOrFail(app(BoardGameService::class)->start('blockli', $bert, $me)->id);
    $ended->forceFill(['status' => BoardGameStatus::Finished, 'ended_at' => now()->subDays(3)])->save();
    $old = BoardGame::query()->findOrFail(app(BoardGameService::class)->start('blockli', $anna, $me)->id);
    $old->forceFill(['status' => BoardGameStatus::Finished, 'ended_at' => now()->subDays(20)])->save();
    app(BoardGameService::class)->start('blockli', $anna, $bert);

    $chats = collect(ChatInbox::for($me))->keyBy('key');

    expect($chats->keys()->sort()->values()->all())->toBe(['board:'.$board->id, 'board:'.$ended->id, 'chess:'.$chess->id])
        ->and($chats['board:'.$board->id])->toMatchArray([
            'kind' => 'game', 'match' => 'board:'.$board->id, 'opponent' => $anna->pubkey, 'me' => $me->pubkey,
            'title' => $anna->displayName(), 'subtitle' => 'Blockli, running', 'href' => route('board.show', $board),
            'names' => [$anna->pubkey => $anna->displayName()],
        ])
        ->and($chats['chess:'.$chess->id])->toMatchArray(['match' => (string) $chess->id, 'opponent' => $bert->pubkey, 'href' => route('games.show', ['game' => $chess])])
        ->and($chats['board:'.$ended->id]['subtitle'])->toBe('Blockli, over')
        ->and($chats['board:'.$ended->id]['settled'])->toBe($ended->ended_at->getTimestamp());
});

test('the desk of a tournament the player plays in, with its members and its direction', function () {
    $tournament = runningChess(TournamentFormat::RoundRobin, 4, TournamentResultsMode::Players);
    $player = $tournament->participants()->first()->user;

    $desk = collect(ChatInbox::for($player))->firstWhere('kind', 'desk');

    expect($desk)->not->toBeNull()
        ->and($desk)->toMatchArray(['key' => 'desk:'.$tournament->id, 'desk' => $tournament->id, 'title' => $tournament->name, 'href' => route('tournaments.show', $tournament).'#desk'])
        ->and($desk['members'])->toContain($player->pubkey)
        ->and($desk['managers'])->toBe(array_values(array_column(array_filter(TournamentDesk::members($tournament), fn (array $member): bool => $member['manager']), 'pubkey')))
        ->and($desk['managers'])->toContain($tournament->creator->pubkey)->not->toContain($player->pubkey)
        ->and($desk['names'][$player->pubkey])->toBe($player->displayName());

    expect(collect(ChatInbox::for(User::factory()->create()))->firstWhere('kind', 'desk'))->toBeNull('a stranger is no member of the desk');
});

test('the room of a running series with its members, its sides and the opponents', function () {
    $match = SeriesMatch::factory()->accepted()->create([
        'challenger_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
        'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id,
    ]);
    $members = RoomMembers::of($match);
    $mine = $members[0];
    $player = User::query()->where('pubkey', $mine['pubkey'])->sole();
    $opponents = array_values(array_column(array_filter($members, fn (array $member): bool => $member['side'] !== $mine['side']), 'pubkey'));

    $room = collect(ChatInbox::for($player))->firstWhere('kind', 'room');

    expect($room)->toMatchArray(['key' => 'room:'.$match->number, 'match' => (string) $match->number, 'href' => route('matches.room', $match), 'title' => 'Match #'.$match->number])
        ->and($room['members'])->toEqualCanonicalizing(array_column($members, 'pubkey'))
        ->and($room['opponents'])->toEqualCanonicalizing($opponents)
        // The room page reads the same members (RoomMembers): the chats agree on who is in the room.
        ->and(Livewire::actingAs($player)->test('pages::matches.room', ['match' => $match])->instance()->chatConfig()['members'])->toBe($members);

    // The account menu, desktop and phone: right below "Your matches and events", where this series waits.
    $this->actingAs($player)->get(route('rules'))->assertOk()
        ->assertSeeInOrder(['data-test="account-upcoming"', 'data-test="account-chats"', 'data-test="account-clan'], false)
        ->assertSeeInOrder(['data-test="mobile-upcoming"', 'data-test="mobile-chats"'], false);
});

test('several rooms and games load their members at once, and the page answers a roundtrip', function () {
    $lineup = Lineup::factory()->mode('2v2')->ready()->create();
    $player = $lineup->activeSeats()[0]->user;
    $room = fn () => SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => Lineup::factory()->mode('2v2')->ready()->create()->id]);
    $queries = function () use ($player): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ChatInbox::for($player);

        return count(DB::getQueryLog());
    };
    $room();
    $room();
    foreach (User::factory()->count(3)->create() as $opponent) {
        app(BoardGameService::class)->start('blockli', $player, $opponent);
    }

    // More than one room: their lineups come eager-loaded (lazy loading throws outside production), so one more
    // room costs no query.
    $two = $queries();
    $room();

    expect($queries())->toBe($two)
        ->and(collect(ChatInbox::for($player))->countBy('kind')->all())->toEqual(['room' => 3, 'game' => 3]);

    Livewire::actingAs($player)->test('pages::me.chats')->call('$refresh')->assertOk();
});

test('the page hands the browser the chats and the relays, and the account menu leads there below the matches', function () {
    [$me, $anna] = User::factory()->count(2)->create();
    $board = app(BoardGameService::class)->start('blockli', $me, $anna);

    $html = $this->actingAs($me)->get(route('me.chats'))->assertOk()->assertSee('data-test="me-chats"', false)->assertSee('x-data="chatInbox(', false)->getContent();
    $config = Livewire::actingAs($me)->test('pages::me.chats')->instance()->config();

    expect($config)->toMatchArray(['me' => $me->pubkey, 'relays' => ['wss://chat.example']])
        ->and(array_column($config['chats'], 'key'))->toBe(['board:'.$board->id])
        ->and($config['labels'])->toHaveKeys(['you', 'unread', 'loading', 'none', 'quiet'])
        // In the account menu (desktop and phone), right below "Your matches and events".
        ->and(strpos($html, 'data-test="account-chats"'))->toBeGreaterThan(0)
        ->and(strpos($html, 'data-test="mobile-chats"'))->toBeGreaterThan(0)
        ->and(strpos($html, 'href="'.route('me.chats').'"'))->toBeGreaterThan(0);

    $this->get(route('me.chats'))->assertOk();
    auth()->logout();
    $this->get(route('me.chats'))->assertRedirect();
});

test('a player without chats is told what brings one here', function () {
    $this->actingAs(User::factory()->create())->get(route('me.chats'))->assertOk()->assertSee('data-test="chats-empty"', false);
});

test('the browser finds each chat\'s newest message and what is new', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', '--test-reporter=spec', 'tests/js/chatInbox.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ skipped 0');
});
