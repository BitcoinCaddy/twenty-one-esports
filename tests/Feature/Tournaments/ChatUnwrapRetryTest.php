<?php

/*
 * The chats (match room, game chat, tournament desk; resources/js/roomChat.js)
 * open every gift wrap with the player's signer. A wrap the signer could not
 * open just now (a remote signer's timeout, a closed extension prompt) is
 * tried again and never cached as null: cached, it was gone for this reader
 * for good (tournament 27, 2026-10-12: "messages do not arrive"). The rules
 * run under Node (tests/js/chatUnwrapRetry.test.mjs).
 */

test('a wrap the signer could not open is retried and never cached as unreadable', function () {
    nodeTest('tests/js/chatUnwrapRetry.test.mjs', 4);
});
