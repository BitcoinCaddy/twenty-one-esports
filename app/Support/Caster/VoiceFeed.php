<?php

namespace App\Support\Caster;

/**
 * The voice input of one encoder run: a named pipe ffmpeg reads raw PCM from
 * (`-f s16le -ar <rate> -ac 2 -i <fifo>`), written by the stream daemon from
 * the CommentaryTimeline, silence included, `lead` seconds ahead of the wall
 * clock so a slow daemon tick never starves the mix.
 *
 * Left is the voice, right the music duck of news mode
 * (CommentaryTimeline::musicDuck(), FfmpegCommands::voiceMix()): in the same
 * frames as the voice, the duck can never drift from the audio it belongs
 * to, however far ahead the feed writes, and a second pipe that could stall
 * the encoder does not exist. Pending audio is only ever dropped in whole
 * frames, so left and right never swap.
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

    /** Bytes written so far, modulo the frame: a write may end inside a frame. */
    private int $writtenInFrame = 0;

    /** Bytes per frame: one s16 sample of voice, one of the music duck. */
    private const FRAME_BYTES = 2 * Pcm::BYTES_PER_SAMPLE;

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
            $this->pending .= Pcm::interleave($timeline->samples($this->nextSample, $target), $timeline->musicDuck($this->nextSample, $target));
            $this->nextSample = $target;
        }

        $max = self::MAX_PENDING_SECONDS * $this->rate * self::FRAME_BYTES;
        if (strlen($this->pending) > $max) {
            // The rest of a frame already begun is kept; behind it only whole frames are dropped.
            $head = (self::FRAME_BYTES - $this->writtenInFrame) % self::FRAME_BYTES;
            $drop = strlen($this->pending) - $max;
            $drop += (self::FRAME_BYTES - $drop % self::FRAME_BYTES) % self::FRAME_BYTES;
            $this->pending = substr($this->pending, 0, $head).substr($this->pending, $head + $drop);
        }

        while ($this->pending !== '') {
            $written = @fwrite($this->handle, $this->pending);

            if ($written === false || $written === 0) {
                break;
            }

            $this->pending = (string) substr($this->pending, $written);
            $this->writtenInFrame = ($this->writtenInFrame + $written) % self::FRAME_BYTES;
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
