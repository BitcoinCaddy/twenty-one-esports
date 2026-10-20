<?php

/*
 * The casual room countdown (resources/js/casualClock.js) counts to the
 * server's deadline on a device whose clock runs off: tests/js/casualClock.test.mjs
 * under Node. The room passes the server time (CasualPlayTest).
 */
test('the casual room countdown corrects the device clock by the server offset', function () {
    nodeTest('tests/js/casualClock.test.mjs', 6);
});
