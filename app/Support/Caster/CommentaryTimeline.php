<?php

namespace App\Support\Caster;

/**
 * The commentary clip on air, on the wall clock: its PCM (s16le mono at
 * `$rate`), where it starts, where each sentence ends and, for a takeover, the
 * sentence end it is cut at. samples() hands out any stretch of the wall clock
 * as PCM (silence outside the clip), addressed by absolute sample numbers
 * (unix seconds × rate), so every encoder that reads the voice gets the same,
 * gapless stream however its ticks fall.
 *
 * News mode (user 2026-10-12: "dann halt so viel ducken im News-Modus, dass
 * man die BG-Music nicht mehr hört"): a clip started as news (the bulletin,
 * its stinger and bed included) opens a news window; musicDuck() hands out,
 * on the same absolute sample clock as samples(), how far the stream music is
 * pulled down: to `newsMusicDb` within `fadeDownSeconds` from the clip's first
 * sample, back up within `fadeUpSeconds` from its end (dB-linear fades). The
 * window outlives clear(), so the fade back up is still written after the
 * director moved on. Silence (all zero) means "no news ducking": whatever goes
 * wrong with the window, the music falls back to the normal sidechain duck.
 */
final class CommentaryTimeline
{
    private ?string $pcm = null;

    private int $startSample = 0;

    /** @var list<float> seconds into the clip */
    private array $ends = [];

    private ?float $cutAt = null;

    /**
     * The news window: its first sample and its last one, null while the news clip is still on the timeline (its end
     * is endsAt() then, so a cut moves it).
     *
     * @var array{from: int, to: int|null}|null
     */
    private ?array $news = null;

    /**
     * @param  float  $newsMusicDb  how far the music is pulled down in news mode (0: no news mode)
     */
    public function __construct(
        public readonly int $rate = 24000,
        private readonly float $newsMusicDb = 0.0,
        private readonly float $fadeDownSeconds = 1.0,
        private readonly float $fadeUpSeconds = 2.0,
    ) {}

    /**
     * @param  list<float>  $sentenceEnds  seconds into the clip
     */
    public function start(string $pcm, array $sentenceEnds, float $at, bool $news = false): void
    {
        $this->closeNews();
        $this->pcm = substr($pcm, 0, strlen($pcm) - strlen($pcm) % Pcm::BYTES_PER_SAMPLE);
        $this->startSample = (int) round($at * $this->rate);
        $this->ends = $sentenceEnds;
        $this->cutAt = null;

        if ($news) {
            $this->news = ['from' => $this->startSample, 'to' => null];
        }
    }

    public function clear(): void
    {
        $this->closeNews();
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

    /**
     * The music duck of the wall-clock samples [$from, $to) as s16le mono:
     * per sample the share of the music taken away (0: none, 32767: all),
     * i.e. 1 − 10^(dB/20) of the news fade at that sample.
     */
    public function musicDuck(int $from, int $to): string
    {
        $count = max(0, $to - $from);
        $window = $this->newsWindow();

        if ($window === null || $count === 0 || $this->newsMusicDb >= 0.0) {
            return str_repeat("\0\0", $count);
        }

        [$start, $end] = $window;
        $down = max(1, (int) round($this->fadeDownSeconds * $this->rate));
        $up = max(1, (int) round($this->fadeUpSeconds * $this->rate));

        if ($to <= $start || $from >= $end + $up) {
            return str_repeat("\0\0", $count);
        }

        $full = pack('v', $this->duckValue(1.0));
        $out = '';

        for ($sample = $from; $sample < $to; $sample++) {
            if ($sample < $start || $sample >= $end + $up) {
                $out .= "\0\0";
            } elseif ($sample >= $start + $down && $sample < $end) {
                $out .= $full;
            } else {
                // Fading: down from the window's start, up from its end; a window shorter than the fade down goes up from where it got to.
                $depth = min(($sample - $start) / $down, 1.0);
                if ($sample >= $end) {
                    $depth = min(($end - $start) / $down, 1.0) * (1.0 - ($sample - $end) / $up);
                }
                $out .= pack('v', $this->duckValue($depth));
            }
        }

        return $out;
    }

    /** The s16 duck of a fade `$depth` (0..1) of the way down to newsMusicDb. */
    private function duckValue(float $depth): int
    {
        $gain = 10 ** (max($this->newsMusicDb, -120.0) * max(0.0, min(1.0, $depth)) / 20);

        return (int) round((1.0 - $gain) * 32767);
    }

    /**
     * [first sample, end sample] of the news window, or null.
     *
     * @return array{int, int}|null
     */
    private function newsWindow(): ?array
    {
        if ($this->news === null) {
            return null;
        }

        $to = $this->news['to'] ?? ($this->pcm === null ? $this->news['from'] : (int) round((float) $this->endsAt() * $this->rate));

        return [$this->news['from'], $to];
    }

    /** The news clip leaves the timeline: its window keeps the end it had. */
    private function closeNews(): void
    {
        if ($this->news !== null && $this->news['to'] === null) {
            $window = $this->newsWindow();
            $this->news['to'] = $window === null ? $this->news['from'] : $window[1];
        }
    }
}
