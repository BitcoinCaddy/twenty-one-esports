<?php

namespace App\Support\TwentyOne\Stream;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Throwable;

/**
 * The live programme pushed to YouTube as well (twentyone.stream.youtube): one ffmpeg beside the encoders that
 * copies the playlist of the encoder run on air to the RTMP ingest ({@see FfmpegCommands::relay()}).
 *
 * It follows the run on air: started once that run has a segment, and started again at once when another run
 * takes over. That is every completed mode switch (another directory) and every restarted encoder: a restart
 * writes a new playlist under the same path, and a relay kept across it sent nothing more until the ingest
 * dropped it (measured 2026-10-12 against mediamtx). A relay that exits on its own is started again after a
 * doubling backoff, back to the initial delay after a run of a minute. Nothing here waits on the network or
 * touches an encoder: a dead ingest costs the YouTube picture, never the HLS stream.
 *
 * The key travels only in the target URL: every log line shows the URL with the key masked, and every stderr
 * line of the relay has the key replaced before it is logged (ffmpeg names the URL in its errors).
 */
final class YouTubeRelay
{
    /** A relay run longer than this resets the restart backoff. */
    private const HEALTHY_RUN_SECONDS = 60;

    /** How many stderr lines of an exited relay are logged. */
    private const STDERR_LINES = 10;

    private const MASK = '***';

    private ?InvokedProcess $process = null;

    /** The encoder run the running relay reads. */
    private ?string $runId = null;

    private float $startedAt = 0.0;

    private float $nextStartAt = 0.0;

    /** @var list<string> the last stderr lines, masked */
    private array $stderr = [];

    /**
     * @param  Closure(string): void  $log
     */
    public function __construct(
        private string $key,
        private string $url,
        private FfmpegCommands $ffmpeg,
        private Backoff $backoff,
        private Closure $log,
    ) {}

    /** Whether a stream key is configured: the relay runs, and the encoders set 2 s keyframes. */
    public static function enabled(): bool
    {
        return trim((string) config('twentyone.stream.youtube.key')) !== '';
    }

    /**
     * The relay of the configured key, or null without one.
     *
     * @param  Closure(string): void  $log
     */
    public static function fromConfig(Closure $log): ?self
    {
        if (! self::enabled()) {
            return null;
        }

        return new self(
            trim((string) config('twentyone.stream.youtube.key')),
            rtrim((string) config('twentyone.stream.youtube.url'), '/'),
            FfmpegCommands::fromConfig(),
            new Backoff(
                (int) config('twentyone.stream.youtube.backoff.initial_seconds', 5),
                (int) config('twentyone.stream.youtube.backoff.max_seconds', 120),
            ),
            $log,
        );
    }

    /** The ingest URL as it may be logged. */
    public function target(): string
    {
        return $this->url.'/'.self::MASK;
    }

    /**
     * Once per supervisor tick: the playlist and run id of the encoder run on air, or nulls while no run has a
     * segment. Starts, replaces or reaps the relay; never blocks longer than a stop.
     */
    public function follow(?string $playlist, ?string $runId, float $now): void
    {
        if ($this->process !== null) {
            $this->collectStderr();

            if (! $this->process->running()) {
                $this->reap($now);
            } elseif ($runId !== $this->runId) {
                // Another run is on air (or none is): its playlist is a new one.
                $this->stop();
            }
        }

        if ($this->process === null && $playlist !== null && $runId !== null && $now >= $this->nextStartAt) {
            $this->start($playlist, $runId, $now);
        }
    }

    /**
     * SIGTERM, and SIGKILL if the relay is still there after half a second: the next relay must not push with
     * the same key next to it, and the supervisor loop must not wait on a hung connection.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        $process = $this->process;
        $deadline = microtime(true) + 0.5;

        if ($process->running()) {
            $process->signal(SIGTERM);
        }

        while ($process->running() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        if ($process->running()) {
            $process->signal(SIGKILL);
        }

        ($this->log)('youtube relay stopped run='.$this->runId.', '.$this->exitStatus());
        $this->process = null;
        $this->runId = null;
        $this->stderr = [];
    }

    private function start(string $playlist, string $runId, float $now): void
    {
        try {
            $this->process = Process::forever()->env(ChildEnvironment::withoutSecrets())
                ->start($this->ffmpeg->relay($playlist, $this->url.'/'.$this->key));
        } catch (Throwable $e) {
            $delay = $this->backoff->next();
            $this->nextStartAt = $now + $delay;
            ($this->log)('youtube relay failed to start ('.$this->mask($e->getMessage()).'), retrying in '.$delay.' s');

            return;
        }

        $this->runId = $runId;
        $this->startedAt = $now;
        $this->stderr = [];
        ($this->log)('youtube relay started pid='.$this->process->id().' run='.$runId.' to '.$this->target());
    }

    private function reap(float $now): void
    {
        $ranSeconds = $now - $this->startedAt;
        ($this->log)(sprintf('youtube relay exited %s after %.0f s', $this->exitStatus(), $ranSeconds));

        foreach ($this->stderr as $line) {
            ($this->log)('youtube relay: '.$line);
        }

        if ($ranSeconds > self::HEALTHY_RUN_SECONDS) {
            $this->backoff->reset();
        }

        $delay = $this->backoff->next();
        $this->nextStartAt = $now + $delay;
        ($this->log)('restarting the youtube relay in '.$delay.' s');
        $this->process = null;
        $this->runId = null;
        $this->stderr = [];
    }

    private function collectStderr(): void
    {
        $latest = trim((string) $this->process?->latestErrorOutput());

        if ($latest !== '') {
            $this->stderr = array_slice([...$this->stderr, ...explode("\n", $this->mask($latest))], -self::STDERR_LINES);
        }
    }

    /**
     * How the relay ended; Symfony throws for a signal it did not send itself (OOM killer, `kill -9`).
     */
    private function exitStatus(): string
    {
        if ($this->process === null) {
            return 'not running';
        }

        try {
            $status = 'code='.($this->process->wait()->exitCode() ?? '?');
        } catch (ProcessSignaledException $e) {
            $status = 'by signal '.$e->getSignal();
        }

        $this->collectStderr();

        return $status;
    }

    /** The text with the key replaced, also in its URL-encoded form. */
    private function mask(string $text): string
    {
        return str_replace(array_unique([$this->key, rawurlencode($this->key)]), self::MASK, $text);
    }
}
