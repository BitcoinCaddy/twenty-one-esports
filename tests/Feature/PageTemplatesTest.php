<?php

use Symfony\Component\Finder\Finder;

/*
| Rule R21 (plan "Refactor und Design-Revamp", board Regeln): every page has a design template. The mapping in
| tests/Support/page_templates.php comes from the canvas coverage audit; a page file without an entry, or an entry
| without its page file, turns this red until the canvas and the mapping are brought along.
*/

/** @return list<string> page files relative to resources/views/pages, partials excluded */
function pageTemplateFiles(): array
{
    $root = resource_path('views/pages');
    $files = [];

    foreach (Finder::create()->files()->in($root)->name('*.blade.php')->notPath('#(^|/)partials/#') as $file) {
        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
    }
    sort($files);

    return $files;
}

test('every page file has a design template from the canvas coverage (R21)', function () {
    /** @var array<string, array{0: string, 1: string|null}> $mapping */
    $mapping = require base_path('tests/Support/page_templates.php');

    $unmapped = array_values(array_diff(pageTemplateFiles(), array_keys($mapping)));

    expect($unmapped)->toBe([], 'Page files without a template: add them to tests/Support/page_templates.php and the canvas (coverage.md, Code-Mapping) first.');
});

test('every template entry still names a page file', function () {
    $mapping = require base_path('tests/Support/page_templates.php');

    expect(array_values(array_diff(array_keys($mapping), pageTemplateFiles())))->toBe([]);
});

test('every entry names a template, and a board frame unless the canvas excludes the page', function () {
    $mapping = require base_path('tests/Support/page_templates.php');
    $excluded = ['styleguide.blade.php', 'testing/js-throw.blade.php', 'tournaments/⚡tv.blade.php'];

    foreach ($mapping as $page => [$template, $frame]) {
        expect($template)->toBeString()->not->toBe('', $page);
        expect($frame === null)->toBe(in_array($page, $excluded, true), $page);
    }
});
