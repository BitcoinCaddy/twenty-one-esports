<?php

/*
 * Player-to-player DMs (P45, resources/js/directMessage.js) are written,
 * encrypted and sent in the browser, so they are tested where they run:
 * tests/js/directMessage.test.mjs under Node. It fails if any Node test
 * fails or is skipped.
 */
test('a DM goes NIP-17 to a recipient with a DM relay list, NIP-04 only to one without, a signer without NIP-44 is refused, not downgraded', function () {
    nodeTest('tests/js/directMessage.test.mjs', 15);
});
