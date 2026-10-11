<?php

use Symfony\Component\Finder\Finder;

/*
 * Pest loads every test file into one process, so a top-level function or
 * constant declared in two files is a fatal "Cannot redeclare" that stops the
 * whole suite before a single test runs. Parallel branches hit it twice on
 * 2026-10-11 (BLOCKLI_BOXES in BlockliTest and BlockliCreditTest; liveAdmin()
 * and liveTournament() in BlockliLiveModesTest and the tournament live tests),
 * both only seen in the full gate. This names every clash at once.
 */

/**
 * Top-level declarations per name across tests/: function names and global constants.
 *
 * @return array<string, list<string>>
 */
function testHelperDeclarations(): array
{
    $seen = [];

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $file) {
        $source = $file->getContents();

        preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $source, $functions);
        preg_match_all('/^const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $source, $constants);
        preg_match_all('/^define\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/m', $source, $defines);

        foreach ($functions[1] as $name) {
            $seen['function '.strtolower($name)][] = $file->getRelativePathname();
        }

        foreach ([...$constants[1], ...$defines[1]] as $name) {
            $seen['const '.$name][] = $file->getRelativePathname();
        }
    }

    return $seen;
}

test('no two test files declare the same top-level function or constant', function () {
    $clashes = array_filter(testHelperDeclarations(), fn (array $files): bool => count(array_unique($files)) > 1);

    expect(array_map(fn (array $files): string => implode(', ', array_unique($files)), $clashes))->toBe([]);
});

test('the scan sees the helpers this file declares, so an empty result is not a blind scan', function () {
    expect(testHelperDeclarations())->toHaveKey('function testhelperdeclarations');
});
