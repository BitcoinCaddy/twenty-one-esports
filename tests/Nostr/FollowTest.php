<?php

/*
 * Following a player (P45, resources/js/follow.js) runs in the browser, so it
 * is tested where it runs: tests/js/follow.test.mjs under Node, with fake
 * relays and throwaway keys. It fails if any Node test fails or is skipped.
 */
test('following refuses a list it could not read, keeps every follow, and checks the signed list', function () {
    nodeTest('tests/js/follow.test.mjs', 17);
});
