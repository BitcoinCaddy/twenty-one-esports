<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\MusicCredits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Draws the QR code of every music credit's target (MusicCredits) into resources/stream/qr/music, as the stream's
 * other codes are made (resources/stream/qr/README.md: qrencode, EC level M, no margin, modules #17120A on white),
 * and removes codes no credit uses any more. Run after the music indexes change, commit the files: the daemon never
 * encodes at render time (NowPlaying shows no card for a target without its code).
 */
#[Signature('twentyone:music-qr {--qrencode=qrencode : The qrencode binary}')]
#[Description('Draw the QR codes of the music artists the stream slides credit (resources/stream/qr/music)')]
class TwentyOneMusicQrCommand extends Command
{
    public function handle(): int
    {
        MusicCredits::flush();
        $dir = resource_path('stream/qr/music');
        File::ensureDirectoryExists($dir);
        $wanted = [];
        $drawn = 0;

        foreach ([...MusicCredits::all(), MusicCredits::house()] as $credit) {
            $path = MusicCredits::qrFile($credit['target'], $credit['kind']);

            if (isset($wanted[$path])) {
                continue;
            }

            $wanted[$path] = true;

            if (is_file($path)) {
                continue;
            }

            $run = Process::run([(string) $this->option('qrencode'), '-t', 'SVG', '--svg-path', '-l', 'M', '-m', '0', '-s', '1',
                '--foreground=17120A', '--background=FFFFFF', '-o', $path, MusicCredits::qrPayload($credit['target'], $credit['kind'])]);

            if ($run->failed()) {
                $this->error('qrencode failed for '.$credit['artist'].': '.trim($run->errorOutput()));

                return self::FAILURE;
            }

            $drawn++;
        }

        $removed = 0;

        foreach (File::files($dir) as $file) {
            if ($file->getExtension() === 'svg' && ! isset($wanted[$file->getPathname()])) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        $this->info(sprintf('Music QR codes: %d drawn, %d kept, %d removed.', $drawn, count($wanted) - $drawn, $removed));

        return self::SUCCESS;
    }
}
