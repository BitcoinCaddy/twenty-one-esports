<?php

namespace App\Console\Commands;

use App\Support\Caster\CasterFetcher;
use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

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
 *
 * Outside the quiet hours it also starts `caster:podcast-transcript` in the
 * background at most every `tv.commentary.podcast.check_minutes`
 * (podcastDue()), so a new podcast episode is transcribed within the hour
 * while the voicing goes on; the first check after the quiet hours is the
 * daily retry after a failure.
 */
#[Signature('caster:fetch {--once : Claim and voice at most one script, then exit}')]
#[Description('Voice TV commentary scripts with the local VoiceStudio and upload the clips (workstation)')]
class CasterFetchCommand extends Command
{
    /** Waits after an outcome, in multiples of `idle_seconds`; FULL waits `full_seconds` instead. */
    private const WAITS = [CasterFetcher::VOICED => 0, CasterFetcher::STOCKED => 0, CasterFetcher::IDLE => 1, CasterFetcher::RELEASED => 3, CasterFetcher::OFFLINE => 6, CasterFetcher::STOPPED => 0];

    /** VoiceStudio down (P18): waits growing by this factor per run in a row, at most `MAX_VOICE_DOWN_WAIT` seconds. */
    private const MAX_VOICE_DOWN_WAIT = 120;

    private bool $stopping = false;

    /** The transcription started in the background, kept so it is not stopped with the object. */
    private ?InvokedProcess $podcast = null;

    private const PODCAST_CHECKED = 'caster:podcast:checked';

    public function handle(CasterFetcher $fetcher): int
    {
        self::raiseMemoryLimit();

        // A shutdown (systemd sends SIGTERM, then SIGKILL after TimeoutStopSec) or Ctrl-C: the fetcher stops claiming and
        // calling VoiceStudio at once, the run in progress releases what it holds, and the command exits 0 (P18).
        $this->trap([SIGTERM, SIGINT], function () use ($fetcher): void {
            $this->stopping = true;
            $fetcher->stop();
        });

        $idle = max(1, (int) config('tv.commentary.voicestudio.idle_seconds', 10));
        $last = null;
        $downRuns = 0;

        do {
            if (! $this->option('once') && self::podcastDue(CarbonImmutable::now(), $this->podcast?->running() ?? false)) {
                $this->podcast = Process::path(base_path())->timeout(4 * 3_600)->start([PHP_BINARY, 'artisan', 'caster:podcast-transcript']);
            }

            $outcome = $fetcher->runOnce();

            if ($outcome !== $last) {
                $this->line(now()->toDateTimeString().' '.$outcome);
                $last = $outcome;
            }

            if ($this->option('once') || $outcome === CasterFetcher::STOPPED) {
                break;
            }

            $downRuns = $outcome === CasterFetcher::VOICE_DOWN ? $downRuns + 1 : 0;
            $wait = $outcome === CasterFetcher::VOICE_DOWN ? self::voiceDownWait($downRuns, $idle) : self::waitSeconds($outcome, $idle);

            for ($waited = 0; $waited < $wait && ! $this->stopping && ! $fetcher->stopping(); $waited++) {
                sleep(1);
            }
        } while (! $this->stopping && ! $fetcher->stopping());

        if ($this->stopping || $fetcher->stopping()) {
            $this->line(now()->toDateTimeString().' stopped');
        }

        return self::SUCCESS;
    }

    /**
     * The wait after the `$runs`-th run in a row that found VoiceStudio down: `idle_seconds`, doubling, at most
     * MAX_VOICE_DOWN_WAIT (a boot before VoiceStudio is up never becomes a tight loop, and its start is noticed
     * within two minutes).
     */
    public static function voiceDownWait(int $runs, int $idle): int
    {
        return (int) min(self::MAX_VOICE_DOWN_WAIT, $idle * 2 ** max(0, min(10, $runs - 1)));
    }

    /**
     * Raises PHP's memory limit to `voicestudio.memory_limit` (default 512M) when it is lower, never lowers it: a block
     * is rendered in memory, and the talk show's ten-minute blocks under one bed peaked at 121 MB in the P20 remix
     * (2026-10-13) against the CLI default of 128M the systemd unit runs with. -1 (no limit) stays.
     */
    public static function raiseMemoryLimit(): void
    {
        $wanted = (string) config('tv.commentary.voicestudio.memory_limit', '512M');
        $current = (string) ini_get('memory_limit');

        if ($current !== '-1' && self::bytes($wanted) > self::bytes($current)) {
            ini_set('memory_limit', $wanted);
        }
    }

    /** A php.ini size ("128M", "1G", "-1") in bytes; -1 is PHP_INT_MAX. */
    private static function bytes(string $size): int
    {
        $size = trim($size);

        if ($size === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $size;

        return match (strtoupper(substr($size, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Whether the background transcription should start now: the switch is on, no quiet hours, none running, and
     * none started within `check_minutes` (the check is remembered at once).
     */
    public static function podcastDue(CarbonImmutable $now, bool $running): bool
    {
        $minutes = (int) config('tv.commentary.podcast.check_minutes', 60);

        return config('tv.commentary.podcast.transcribe') === true && $minutes > 0 && ! $running && ! QuietHours::active($now)
            && Cache::add(self::PODCAST_CHECKED, $now->getTimestamp(), $minutes * 60);
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
