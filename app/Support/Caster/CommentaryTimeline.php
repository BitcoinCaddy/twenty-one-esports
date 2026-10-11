<?php

namespace App\Support\Caster;

/**
 * The commentary clip on air, on the wall clock: its PCM (s16le mono at
 * `$rate`), where it starts, where each sentence ends and, for a takeover, the
 * sentence end it is cut at. samples() hands out any stretch of the wall clock
 * as PCM (silence outside the clip), addressed by absolute sample numbers
 * (unix seconds × rate), so every encoder that reads the voice gets the same,
 * gapless stream however its ticks fall.
 */
final class CommentaryTimeline
{
    private ?string $pcm = null;

    private int $startSample = 0;

    /** @var list<float> seconds into the clip */
    private array $ends = [];

    private ?float $cutAt = null;

    public function __construct(public readonly int $rate = 24000) {}

    /**
     * @param  list<float>  $sentenceEnds  seconds into the clip
     */
    public function start(string $pcm, array $sentenceEnds, float $at): void
    {
        $this->pcm = substr($pcm, 0, strlen($pcm) - strlen($pcm) % Pcm::BYTES_PER_SAMPLE);
        $this->startSample = (int) round($at * $this->rate);
        $this->ends = $sentenceEnds;
        $this->cutAt = null;
    }

    public function clear(): void
    {
        $this->pcm = null;
        $this->ends = [];
        $this->cutAt = null;
    }

    public function hasClip(): bool
    {
        return $this->pcm !== null;
    }

    public function startsAt(): ?float
    {
        return $this->pcm === null ? null : $this->startSample / $this->rate;
    }

    /** When the clip stops: its end, or the sentence end it was cut at. */
    public function endsAt(): ?float
    {
        if ($this->pcm === null) {
            return null;
        }

        $end = ($this->startSample + intdiv(strlen($this->pcm), Pcm::BYTES_PER_SAMPLE)) / $this->rate;

        return $this->cutAt === null ? $end : min($end, $this->cutAt);
    }

    public function isPlaying(float $now): bool
    {
        $end = $this->endsAt();

        return $end !== null && $now < $end;
    }

    /** The first sentence end at or after `$now` (wall clock), or the clip's end. */
    public function nextSentenceEnd(float $now): ?float
    {
        $start = $this->startsAt();

        if ($start === null) {
            return null;
        }

        foreach ($this->ends as $end) {
            if ($start + $end >= $now) {
                return min($start + $end, (float) $this->endsAt());
            }
        }

        return $this->endsAt();
    }

    /** Stop the clip at `$at` (wall clock), which should be a sentence end. */
    public function cutAt(float $at): void
    {
        if ($this->pcm !== null) {
            $this->cutAt = $at;
        }
    }

    public function isCut(): bool
    {
        return $this->cutAt !== null;
    }

    /** The PCM of the wall-clock samples [$from, $to). */
    public function samples(int $from, int $to): string
    {
        $count = max(0, $to - $from);

        if ($count === 0) {
            return '';
        }

        if ($this->pcm === null) {
            return str_repeat("\0\0", $count);
        }

        $endSample = (int) round((float) $this->endsAt() * $this->rate);
        $clipFrom = max($from, $this->startSample);
        $clipTo = min($to, $endSample);

        if ($clipFrom >= $clipTo) {
            return str_repeat("\0\0", $count);
        }

        return str_repeat("\0\0", $clipFrom - $from)
            .substr($this->pcm, ($clipFrom - $this->startSample) * Pcm::BYTES_PER_SAMPLE, ($clipTo - $clipFrom) * Pcm::BYTES_PER_SAMPLE)
            .str_repeat("\0\0", $to - $clipTo);
    }
}
