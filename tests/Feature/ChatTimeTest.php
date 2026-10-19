<?php

use Illuminate\Support\Facades\Process;

/*
 * The time beside a chat message, one rule for every chat (resources/js/chatTime.js; DerCaddy, 2026-10-17: "bei
 * älteren Nachrichten nicht den Wochentag bzw bei Nachrichten die älter als eine Woche sind, das Datum"): the
 * time today, the weekday within the week, the date from seven days on. The rules run under Node
 * (tests/js/chatTime.test.mjs); the chats call it in their time().
 */

test('a chat message shows the time today, the weekday this week and the date before', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', '--test-reporter=spec', 'tests/js/chatTime.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 5')->toContain('ℹ skipped 0');
});

test('every chat of the league labels its messages with it', function (string $script) {
    $code = file_get_contents(resource_path('js/'.$script));

    expect($code)->toContain('chatTime(')
        ->and($code)->not->toContain("toLocaleTimeString(config.locale ?? undefined, { hour: '2-digit', minute: '2-digit' })")
        ->and($code)->not->toContain("String(d.getHours()).padStart(2, '0')");
})->with(['roomChat.js', 'gameChat.js', 'gameChannel.js', 'liveChat.js', 'hyper/chat.js', 'hyper/teamChat.js']);
