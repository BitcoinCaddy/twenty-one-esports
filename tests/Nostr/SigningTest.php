<?php

use App\Support\Nostr\SignerMessages;

/*
 * Browser signing (resources/js/signing.js) runs where it lives:
 * tests/js/signing.test.mjs under Node. It fails if any Node test fails or is
 * skipped.
 */
test('signing hands the signer a plain copy, tells declined, unreachable, wrong key and other failures apart, and signs a preview only unchanged', function () {
    $run = nodeTest('tests/js/signing.test.mjs');

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ skipped 0');
});

test('every signer message is translated, and the reason placeholder survives translation', function () {
    app()->setLocale('de');
    $german = SignerMessages::labels();
    app()->setLocale('en');
    $english = SignerMessages::labels();

    foreach ($english as $key => $text) {
        expect($german[$key])->not->toBe($text, "{$key} has no German text");
    }

    expect($german['signerFailed'])->toContain(':reason')
        ->and($english['signerFailed'])->toContain(':reason');
});
