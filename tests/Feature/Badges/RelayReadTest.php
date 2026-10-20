<?php

/*
 * The browser's relay reader for "Show on my Nostr profile" (P11 security
 * gate F1): tests/js/relayRead.test.mjs under Node, against fake relays. A
 * relay is read only after EOSE; a relay that is down or silent is not; a
 * forged copy of the right id does not hide the real list; the write relays
 * of the player's relay list must answer.
 */
test('the relay reader counts EOSE only, verifies before it deduplicates, and takes the newest valid list', function () {
    nodeTest('tests/js/relayRead.test.mjs', 17);
});
