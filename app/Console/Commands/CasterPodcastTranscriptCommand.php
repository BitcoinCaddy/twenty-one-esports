<?php

namespace App\Console\Commands;

use App\Support\Caster\PodcastTranscriber;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Transcribes the newest EINUNDZWANZIG podcast episode on Markus Turm's
 * workstation and uploads the text to the server (PodcastTranscriber, user
 * 2026-10-12). Started by `caster:fetch` at most every
 * `tv.commentary.podcast.check_minutes` outside the quiet hours; skips an
 * episode the server has. After a failure (Whisper down, a broken download)
 * it logs and waits until the next day, unless `--force`. One run at a time.
 */
#[Signature('caster:podcast-transcript {--force : Try again today after a failure}')]
#[Description('Transcribe the newest podcast episode with the local Whisper and upload the transcript (workstation)')]
class CasterPodcastTranscriptCommand extends Command
{
    /** Set after a failure until the end of the day (Berlin): the next try is tomorrow. */
    public const RETRY_KEY = 'caster:podcast:retry-tomorrow';

    private const LOCK = 'caster:podcast:transcribing';

    public function handle(PodcastTranscriber $transcriber): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('force') && Cache::has(self::RETRY_KEY)) {
            $this->line('Waiting until tomorrow after a failed try.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK, 4 * 3_600);

        if (! $lock->get()) {
            $this->line('A transcription is already running.');

            return self::SUCCESS;
        }

        try {
            $outcome = $transcriber->run($now);
        } finally {
            $lock->release();
        }

        $this->line(sprintf('%s %s%s%s', $outcome, $transcriber->episode ?? '-',
            $outcome === PodcastTranscriber::TRANSCRIBED ? sprintf(' (%.0f s, %d bytes)', $transcriber->seconds, $transcriber->bytes) : '',
            $transcriber->reason === null ? '' : ' ('.$transcriber->reason.')'));

        if (in_array($outcome, [PodcastTranscriber::FAILED, PodcastTranscriber::UNAVAILABLE, PodcastTranscriber::OFFLINE, PodcastTranscriber::NO_EPISODE], true)) {
            Log::warning('Podcast transcript failed, next try tomorrow', ['outcome' => $outcome, 'reason' => $transcriber->reason]);
            $berlin = $now->setTimezone('Europe/Berlin');
            Cache::put(self::RETRY_KEY, true, max(60, (int) $berlin->diffInSeconds($berlin->addDay()->startOfDay())));
        }

        return self::SUCCESS;
    }
}
