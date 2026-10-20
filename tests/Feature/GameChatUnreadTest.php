<?php

/*
 * The game chat's "new" badge on a phone (resources/js/gameChat.js, pages/games/partials/chat.blade.php; DerCaddy,
 * 2026-10-18: "Die letzte Nachricht ist von mir, trotzdem steht da neu"): the opponent's messages since the chat
 * was last read on this device, none before a message of one's own. The rules run under Node
 * (tests/js/gameChatUnread.test.mjs).
 */

test('the badge counts the opponent\'s messages since the chat was last read here, none before one\'s own', function () {
    nodeTest('tests/js/gameChatUnread.test.mjs', 4);
});
