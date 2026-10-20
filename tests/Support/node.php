<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory;

/*
|--------------------------------------------------------------------------
| Running a node:test file from a Pest test
|--------------------------------------------------------------------------
|
| Node picks its default reporter by whether stdout is a terminal: Node 22 prints TAP into a pipe, Node 26 the spec
| lines. The wrappers read the spec summary (`ℹ pass N`), so the reporter is named here, once, and every wrapper
| behaves the same on both. A Factory of its own, so Unit tests (no application) can call it too.
|
| The count of passing cases is part of the call: a file that loses a case (commented out, skipped, registered
| behind a branch that never runs) still ends with `fail 0`, so the green summary alone would not say. The number
| is written where the wrapper is, and a file that gains or loses a case says so by turning red until it is changed.
|
*/

/**
 * Run one node:test file (or directory) from the repository root and expect exactly `$passes` passing cases, none
 * failed, skipped or cancelled.
 *
 * @param  array<string, string>  $env
 */
function nodeTest(string $path, int $passes, int $timeout = 60, array $env = []): ProcessResult
{
    $run = (new Factory)->path(dirname(__DIR__, 2))->env($env)->timeout($timeout)->run(['node', '--test', '--test-reporter=spec', $path]);
    $output = $run->output();
    $counted = preg_match('/^ℹ pass (\d+)$/m', $output, $found) === 1 ? (int) $found[1] : null;

    expect($run->successful())->toBeTrue($output.$run->errorOutput())
        ->and($counted)->toBe($passes, "{$path} passed {$counted} cases, the wrapper expects {$passes}: a case was lost or added.\n".$output)
        ->and($output)->toContain('ℹ fail 0')->toContain('ℹ skipped 0')->toContain('ℹ cancelled 0');

    return $run;
}
