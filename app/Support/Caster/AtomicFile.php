<?php

namespace App\Support\Caster;

/**
 * Writes a file so that a reader (or a restart after a kill) sees either the
 * old content or the whole new one, never half of it (P18: the workstation is
 * shut down at any moment, user 2026-10-13: "das Runterfahren macht nichts
 * kaputt"): the content goes into a temporary file next to the target, which
 * is renamed over it (rename() is atomic within one file system).
 */
final class AtomicFile
{
    /** Whether the whole content landed under `$path`; on failure the old file stays and no temporary file is left. */
    public static function put(string $path, string $contents): bool
    {
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.part';

        if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
