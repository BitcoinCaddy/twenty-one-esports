<?php

/*
 * "Your follows here" and invite DMs (P47, resources/js/followsHere.js and
 * resources/js/inviteDm.js) run in the browser, so they are tested where they
 * run: tests/js/followsHere.test.mjs and tests/js/inviteDm.test.mjs under
 * Node. Each fails if any Node test fails or is skipped.
 */
test('the follow list is read partial-tolerant and split in list order; names come from signed profiles', function () {
    nodeTest('tests/js/followsHere.test.mjs', 6);
});

test('an invite DM is sent only on the click, NIP-17 where it can, NIP-04 only after a yes for that recipient', function () {
    nodeTest('tests/js/inviteDm.test.mjs', 4);
});
