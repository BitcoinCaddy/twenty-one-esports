<?php

namespace App\Support\TwentyOne\Stream;

use App\Support\Caster\VoiceFeed;
use Illuminate\Contracts\Process\InvokedProcess;
use Symfony\Component\Process\InputStream;

/**
 * One running ffmpeg: which mode it encodes, its run id (the prefix of every
 * file it writes) and, for the scene, the stdin its PNG frames go into.
 */
final class EncoderRun
{
    public float $lastFrameAt = 0.0;

    /** @var list<string> the last stderr lines */
    public array $stderr = [];

    /** The commentary's voice pipe this run reads (plan "KI-Stimme der Liga", P6); null without commentary. */
    public ?VoiceFeed $voice = null;

    /** The newest segment whose tracks were counted (segmentsWithoutAudio()). */
    private ?string $countedSegment = null;

    private int $withoutAudio = 0;

    public function __construct(
        public readonly string $mode,
        public readonly string $runId,
        public readonly InvokedProcess $process,
        public readonly float $startedAt,
        public readonly ?InputStream $frames = null,
    ) {}

    /**
     * Whether this run's own playlist lists a segment of it yet (the moment a
     * make-before-break switch may drop the previous run).
     */
    public function hasSegment(string $hlsDir): bool
    {
        $playlist = rtrim($hlsDir, '/').'/'.$this->mode.'/'.FfmpegCommands::ENCODER_PLAYLIST;

        return is_file($playlist) && str_contains((string) @file_get_contents($playlist), $this->runId.'-seg-');
    }

    /**
     * When this run last wrote its own playlist (ffmpeg rewrites it with
     * every segment), or its start when it has not written one yet.
     */
    public function lastOutputAt(string $hlsDir): float
    {
        $playlist = rtrim($hlsDir, '/').'/'.$this->mode.'/'.FfmpegCommands::ENCODER_PLAYLIST;
        clearstatcache(true, $playlist);
        $modified = $this->hasSegment($hlsDir) ? @filemtime($playlist) : false;

        return $modified === false ? $this->startedAt : max($this->startedAt, (float) $modified);
    }

    /**
     * How many segments in a row, up to the newest, carry fewer tracks than
     * this run's init file declares (SegmentTracks): the audio is gone from
     * them (the music input ended, or the voice pipe starved the mix), and
     * no player can buffer them. Every segment the encoder playlist lists is
     * counted once, in order; a segment or init that cannot be read counts
     * as fine.
     */
    public function segmentsWithoutAudio(string $hlsDir): int
    {
        $dir = rtrim($hlsDir, '/').'/'.$this->mode;
        $playlist = (string) @file_get_contents($dir.'/'.FfmpegCommands::ENCODER_PLAYLIST);
        preg_match_all('/^'.preg_quote($this->runId, '/').'-seg-\S+$/m', $playlist, $matches);
        $listed = $matches[0];
        $counted = $this->countedSegment === null ? false : array_search($this->countedSegment, $listed, true);
        $new = $counted === false ? $listed : array_slice($listed, $counted + 1);

        if ($new === []) {
            return $this->withoutAudio;
        }

        $declared = SegmentTracks::count($dir.'/'.$this->runId.'-init.mp4');

        foreach ($new as $segment) {
            $carried = SegmentTracks::count($dir.'/'.$segment);
            $this->withoutAudio = $declared !== null && $carried !== null && $carried < $declared ? $this->withoutAudio + 1 : 0;
        }

        $this->countedSegment = end($new);

        return $this->withoutAudio;
    }

    /**
     * Queue one PNG frame for ffmpeg's stdin. Symfony writes it out whenever
     * the process is polled (running(), output reads), without blocking.
     */
    public function sendFrame(string $png, float $now): void
    {
        if ($this->frames !== null && ! $this->frames->isClosed()) {
            $this->frames->write($png);
            $this->lastFrameAt = $now;
            // A frame is ~100 kB and the pipe takes 64 kB at a time: poll a few times.
            for ($i = 0; $i < 4 && $this->process->running(); $i++) {
                usleep(2_000);
            }
        }
    }

    public function collectStderr(int $keep): void
    {
        $latest = trim($this->process->latestErrorOutput());

        if ($latest !== '') {
            $this->stderr = array_slice([...$this->stderr, ...explode("\n", $latest)], -$keep);
        }
    }
}
