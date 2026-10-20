<?php

/*
 * The time beside a chat message, one rule for every chat (resources/js/chatTime.js; DerCaddy, 2026-10-17: "bei
 * älteren Nachrichten nicht den Wochentag bzw bei Nachrichten die älter als eine Woche sind, das Datum"): the
 * time today, the weekday within the week, the date from seven days on. The rules run under Node: the rule itself
 * (chatTime.test.mjs), the new day at midnight (chatDay.test.mjs), and what each chat does with it
 * (chatTimeWiring.test.mjs).
 */
test('a chat message shows the time today, the weekday this week and the date before', function (string $file, int $cases) {
    nodeTest($file, $cases);
})->with([
    'the rule' => ['tests/js/chatTime.test.mjs', 5],
    'the new day' => ['tests/js/chatDay.test.mjs', 5],
    'each chat' => ['tests/js/chatTimeWiring.test.mjs', 2],
]);
