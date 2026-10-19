<?php

use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlockliOn;

/*
 * The game chat's "new" badge on a phone (resources/js/gameChat.js, pages/games/partials/chat.blade.php; DerCaddy,
 * 2026-10-18: "Die letzte Nachricht ist von mir, trotzdem steht da neu"): the opponent's messages since the chat
 * was last read on this device, none before a message of one's own. The rules run under Node
 * (tests/js/gameChatUnread.test.mjs).
 */

test('the badge counts the opponent\'s messages since the chat was last read here, none before one\'s own', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', '--test-reporter=spec', 'tests/js/gameChatUnread.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 4')->toContain('ℹ skipped 0');
});

test('the sheet reads its badge from the chat\'s read marker and marks what it shows as read', function () {
    BlockliOn::play();
    $game = app(BoardGameService::class)->start('blockli', $anna = User::factory()->create(), User::factory()->create());

    $this->actingAs($anna)->get(route('board.show', $game))->assertOk()
        ->assertSee('x-effect="open && messages.length && markRead()"', false)
        ->assertSee('x-show="unread > 0"', false)
        ->assertSee('x-effect="wide && messages.length && markRead(true)"', false)
        ->assertDontSee('seen = messages.length', false);
});
