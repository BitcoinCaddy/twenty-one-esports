<?php

/*
|--------------------------------------------------------------------------
| A temp directory of its own for every test process
|--------------------------------------------------------------------------
|
| sys_get_temp_dir() is /tmp, shared with the other 23 workers of a parallel run,
| the stream running on this machine and whatever else writes there. A test that
| looks at the temp directory (CasterFetchTest "leaves no file behind on the
| workstation" compares two listings) failed whenever a neighbour created a file
| in between: a music-timeline JSON file of MusicTeasersTest, then the viewers.sock in a tos directory
| of TwentyOneStreamPart3Test (seen in the full parallel runs of 2026-10-23), and
| the tests leave their directories behind there for good.
|
| PHP reads TMPDIR once and keeps the answer for the rest of the process, so this
| has to run before anything asks: tests/Pest.php requires it first. The directory
| is short on purpose (a unix socket path may not pass 108 bytes), is named after the
| process and is removed when the process ends; a child process (ffmpeg, node, a
| second php) inherits TMPDIR and stays inside it too. Not for tests/Integration,
| which starts its own app server: those processes keep the host's /tmp.
*/

if (! isset($GLOBALS['__private_temp_dir'])) {
    $base = rtrim((string) (getenv('TMPDIR') ?: '/tmp'), '/');
    $dir = $base.'/esports-test-'.getmypid();

    if ((is_dir($dir) || @mkdir($dir, 0700, true)) && putenv('TMPDIR='.$dir)) {
        $_ENV['TMPDIR'] = $_SERVER['TMPDIR'] = $dir;
        $GLOBALS['__private_temp_dir'] = $dir;

        register_shutdown_function(function () use ($dir): void {
            if (! is_dir($dir)) {
                return;
            }

            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($entries as $entry) {
                $entry->isDir() && ! $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }

            @rmdir($dir);
        });
    }
}
