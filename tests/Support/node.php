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
*/

/**
 * Run one node:test file (or directory) from the repository root.
 *
 * @param  array<string, string>  $env
 */
function nodeTest(string $path, int $timeout = 60, array $env = []): ProcessResult
{
    return (new Factory)->path(dirname(__DIR__, 2))->env($env)->timeout($timeout)->run(['node', '--test', '--test-reporter=spec', $path]);
}
