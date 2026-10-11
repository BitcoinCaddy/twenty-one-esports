<?php

namespace App\Console\Commands;

use App\Support\Caster\CasterFetcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The TV commentary fetcher, meant for Markus Turm's workstation (plan
 * "KI-Stimme der Liga", P6): claims scripts from the server
 * (`TV_COMMENTARY_SERVER`, bearer `TV_COMMENTARY_TOKEN`), voices them with
 * the local VoiceStudio and uploads the clips, until stopped. Waits
 * `idle_seconds` while the server has nothing, `full_seconds` while its
 * spool is full (a reaction still gets through), longer while it or
 * VoiceStudio is unreachable. Keeps no audio on disk
 * (CasterFetcher). Prints a line only when its state changes, so a fetcher
 * that runs for days does not grow a log.
 */
#[Signature('caster:fetch {--once : Claim and voice at most one script, then exit}')]
#[Description('Voice TV commentary scripts with the local VoiceStudio and upload the clips (workstation)')]
class CasterFetchCommand extends Command
{
    /** Waits after an outcome, in multiples of `idle_seconds`; FULL waits `full_seconds` instead. */
    private const WAITS = [CasterFetcher::VOICED => 0, CasterFetcher::IDLE => 1, CasterFetcher::RELEASED => 3, CasterFetcher::OFFLINE => 6];

    private bool $stopping = false;

    public function handle(CasterFetcher $fetcher): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        $idle = max(1, (int) config('tv.commentary.voicestudio.idle_seconds', 10));
        $last = null;

        do {
            $outcome = $fetcher->runOnce();

            if ($outcome !== $last) {
                $this->line(now()->toDateTimeString().' '.$outcome);
                $last = $outcome;
            }

            if ($this->option('once')) {
                break;
            }

            for ($waited = 0; $waited < self::waitSeconds($outcome, $idle) && ! $this->stopping; $waited++) {
                sleep(1);
            }
        } while (! $this->stopping);

        return self::SUCCESS;
    }

    /**
     * Seconds to wait after an outcome. A full spool is asked again soon
     * (`full_seconds`, a cheap claim call): it still hands out a new chat
     * reaction or live moment, which should be voiced within seconds.
     */
    public static function waitSeconds(string $outcome, int $idle): int
    {
        if ($outcome === CasterFetcher::FULL) {
            return max(1, (int) config('tv.commentary.voicestudio.full_seconds', 5));
        }

        return (self::WAITS[$outcome] ?? 1) * $idle;
    }
}
