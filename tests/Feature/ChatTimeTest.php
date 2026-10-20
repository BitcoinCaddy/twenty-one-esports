<?php

/*
 * The time beside a chat message, one rule for every chat (resources/js/chatTime.js; DerCaddy, 2026-10-17: "bei
 * älteren Nachrichten nicht den Wochentag bzw bei Nachrichten die älter als eine Woche sind, das Datum"): the
 * time today, the weekday within the week, the date from seven days on. The rules run under Node: the rule itself
 * (chatTime.test.mjs), the new day at midnight (chatDay.test.mjs), and what each chat does with it
 * (chatTimeWiring.test.mjs).
 */
test('a chat message shows the time today, the weekday this week and the date before', function (string $file) {
    $run = nodeTest($file);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ fail 0')->toContain('ℹ skipped 0')->not->toContain('ℹ pass 0');
})->with([
    'the rule' => 'tests/js/chatTime.test.mjs',
    'the new day' => 'tests/js/chatDay.test.mjs',
    'each chat' => 'tests/js/chatTimeWiring.test.mjs',
]);
