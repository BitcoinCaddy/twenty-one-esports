<?php

use Illuminate\Support\Facades\Process;

/*
 * The tournament desk shows a message written in another Nostr client (Amethyst …) when its whole room is the desk's
 * players and directors (tournament 27, 2026-10-14: "warum sie ihre eigenen Messages hier nicht lesen können … auf
 * Amethyst kann man sie lesen"). The rules run under Node (tests/js/deskForeignClients.test.mjs).
 */

test('a desk message from another Nostr client shows when its room is the desk', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/deskForeignClients.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 1')->toContain('ℹ fail 0');
});
