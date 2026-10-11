<?php

namespace App\Support\Caster;

/**
 * The voice input of one encoder run: a named pipe ffmpeg reads raw PCM from
 * (`-f s16le -ar <rate> -ac 1 -i <fifo>`), written by the stream daemon from
 * the CommentaryTimeline, silence included, `lead` seconds ahead of the wall
 * clock so a slow daemon tick never starves the mix.
 *
 * The pipe is opened read-write ("r+"): on Linux that never blocks, whether
 * ffmpeg has opened its end yet or not, and writes are non-blocking; what the
 * pipe cannot take now waits in `pending` (capped at a few seconds, older
 * audio is dropped rather than piling up).
 */
final class VoiceFeed
{
    /** Pending PCM beyond this many seconds is dropped (ffmpeg is gone or stalled). */
    private const MAX_PENDING_SECONDS = 5;

    /** @var resource|null */
    private $handle = null;

    private ?int $nextSample = null;

    private string $pending = '';

    private function __construct(
        public readonly string $path,
        private readonly int $rate,
        private readonly float $lead,
    ) {}

    /** A new pipe at `$path`; null when it cannot be made (the encoder then runs without voice). */
    public static function create(string $path, int $rate, float $lead): ?self
    {
        if (file_exists($path)) {
            @unlink($path);
        }

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0700, true)) {
            return null;
        }

        return function_exists('posix_mkfifo') && @posix_mkfifo($path, 0600) ? new self($path, $rate, $lead) : null;
    }

    /** Write the voice up to `$now + lead`. */
    public function pump(CommentaryTimeline $timeline, float $now): void
    {
        if ($this->handle === null) {
            $handle = @fopen($this->path, 'r+');

            if ($handle === false) {
                return;
            }

            stream_set_blocking($handle, false);
            $this->handle = $handle;
        }

        $target = (int) round(($now + $this->lead) * $this->rate);
        $this->nextSample ??= (int) round($now * $this->rate);

        if ($target > $this->nextSample) {
            $this->pending .= $timeline->samples($this->nextSample, $target);
            $this->nextSample = $target;
        }

        $max = self::MAX_PENDING_SECONDS * $this->rate * Pcm::BYTES_PER_SAMPLE;
        if (strlen($this->pending) > $max) {
            $this->pending = substr($this->pending, -$max);
        }

        while ($this->pending !== '') {
            $written = @fwrite($this->handle, $this->pending);

            if ($written === false || $written === 0) {
                break;
            }

            $this->pending = (string) substr($this->pending, $written);
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }

        @unlink($this->path);
    }
}
