<?php

namespace App\Support\Caster;

use InvalidArgumentException;

/**
 * Raw audio in memory: 16-bit little-endian mono PCM as a byte string, the
 * format VoiceStudio answers in (WAV, 24 kHz, measured 2026-10-11) and the
 * stream's voice input reads. Nothing here touches the disk.
 */
final class Pcm
{
    public const BYTES_PER_SAMPLE = 2;

    /**
     * The PCM samples and rate of a WAV file (PCM, 16 bit, mono).
     *
     * @return array{pcm: string, rate: int}
     *
     * @throws InvalidArgumentException when it is not such a WAV
     */
    public static function fromWav(string $wav): array
    {
        if (strlen($wav) < 44 || ! str_starts_with($wav, 'RIFF') || substr($wav, 8, 4) !== 'WAVE') {
            throw new InvalidArgumentException('not a WAV file');
        }

        $offset = 12;
        $rate = null;

        while ($offset + 8 <= strlen($wav)) {
            $id = substr($wav, $offset, 4);
            $header = unpack('Vsize', substr($wav, $offset + 4, 4));
            $size = is_array($header) ? (int) $header['size'] : 0;
            $body = $offset + 8;

            if ($id === 'fmt ') {
                $fmt = strlen($wav) >= $body + 16 ? unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', substr($wav, $body, 16)) : false;

                if (! is_array($fmt) || $fmt['format'] !== 1 || $fmt['channels'] !== 1 || $fmt['bits'] !== 16) {
                    throw new InvalidArgumentException('only 16-bit mono PCM');
                }

                $rate = $fmt['rate'];
            } elseif ($id === 'data') {
                if ($rate === null) {
                    throw new InvalidArgumentException('data before fmt');
                }

                // A streamed WAV may carry a size of 0 or 0xFFFFFFFF: the rest of the file then.
                $length = $size === 0 || $size === 0xFFFFFFFF ? strlen($wav) - $body : min($size, strlen($wav) - $body);

                return ['pcm' => substr($wav, $body, $length - $length % self::BYTES_PER_SAMPLE), 'rate' => $rate];
            }

            $offset = $body + $size + ($size % 2);
        }

        throw new InvalidArgumentException('no data chunk');
    }

    /** The PCM as a WAV file (16 bit, mono). */
    public static function toWav(string $pcm, int $rate): string
    {
        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * self::BYTES_PER_SAMPLE, self::BYTES_PER_SAMPLE, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    /**
     * The PCM scaled so its speech (samples above `$gate`) has an RMS of
     * `$targetDbfs`, never louder than a peak of -1 dBFS: one level for every
     * chunk and speaker (user 2026-10-12: "Sirius ist viel zu laut im
     * Gegensatz zu Markus").
     */
    public static function levelled(string $pcm, float $targetDbfs, int $gate = 300): string
    {
        $samples = unpack('v*', $pcm) ?: [];
        $sum = 0.0;
        $count = 0;
        $peak = 1;

        foreach ($samples as $index => $raw) {
            $value = $raw >= 32768 ? $raw - 65536 : $raw;
            $samples[$index] = $value;
            $peak = max($peak, abs($value));

            if (abs($value) > $gate) {
                $sum += $value * $value;
                $count++;
            }
        }

        if ($count === 0) {
            return $pcm;
        }

        $rms = sqrt($sum / $count);
        $gain = min((32767 * 10 ** (-1 / 20)) / $peak, (32767 * 10 ** ($targetDbfs / 20)) / max(1.0, $rms));

        return pack('v*', ...array_map(fn (int $value): int => max(-32768, min(32767, (int) round($value * $gain))) & 0xFFFF, $samples));
    }

    public static function seconds(string $pcm, int $rate): float
    {
        return strlen($pcm) / self::BYTES_PER_SAMPLE / max(1, $rate);
    }

    public static function silence(float $seconds, int $rate): string
    {
        return str_repeat("\0\0", max(0, (int) round($seconds * $rate)));
    }

    /**
     * The audio from `$padMs` before its first loud window to `$padMs` after
     * its last one (10 ms windows with a peak at or above `$threshold`), the
     * kept quiet tail faded out over at most `$fadeMs`, and silence added
     * where VoiceStudio left less than `$padMs` at either end: every chunk
     * starts and ends with at least `$padMs` of quiet, so no soft onset or
     * word ending is cut (user 2026-10-12: "Sirius-Audios werden am Anfang
     * und Ende irgendwie abgeschnitten"). The former tail trim cut at the
     * last window above 400 plus a 70 ms fade and never padded the start.
     * Empty when no window is loud.
     */
    public static function trimEdges(string $pcm, int $rate, int $threshold, int $padMs, int $fadeMs): string
    {
        $samples = intdiv(strlen($pcm), self::BYTES_PER_SAMPLE);
        $window = max(1, intdiv($rate, 100));
        [$first, $end] = [null, null];

        for ($start = 0; $start < $samples; $start += $window) {
            if (self::peak($pcm, $start, $window) >= $threshold) {
                $first = $start;
                break;
            }
        }

        if ($first === null) {
            return '';
        }

        for ($start = ($samples - 1) - (($samples - 1) % $window); $start >= $first; $start -= $window) {
            if (self::peak($pcm, $start, $window) >= $threshold) {
                $end = min($samples, $start + $window);
                break;
            }
        }

        $end ??= min($samples, $first + $window);
        $pad = max(0, (int) round($rate * $padMs / 1000));
        $from = max(0, $first - $pad);
        $to = min($samples, $end + $pad);
        $kept = self::fadeOut(substr($pcm, $from * self::BYTES_PER_SAMPLE, ($to - $from) * self::BYTES_PER_SAMPLE), min($to - $end, (int) round($rate * $fadeMs / 1000)));

        // A cut lead gets a 10 ms fade in against a click; the quiet before the onset stays otherwise untouched.
        $kept = $from > 0 ? self::fadeIn($kept, min($first - $from, intdiv($rate, 100))) : $kept;

        return self::silence(($pad - ($first - $from)) / $rate, $rate).$kept.self::silence(($pad - ($to - $end)) / $rate, $rate);
    }

    /** The highest absolute sample of the `$length` samples from `$start`. */
    private static function peak(string $pcm, int $start, int $length): int
    {
        $peak = 0;

        foreach (unpack('s*', substr($pcm, $start * self::BYTES_PER_SAMPLE, $length * self::BYTES_PER_SAMPLE)) ?: [] as $sample) {
            $peak = max($peak, $sample < 0 ? -$sample : $sample);
        }

        return $peak;
    }

    /** The PCM scaled by `$gainDb`, clipped at full scale. */
    public static function gained(string $pcm, float $gainDb): string
    {
        if ($gainDb === 0.0 || $pcm === '') {
            return $pcm;
        }

        $gain = 10 ** ($gainDb / 20);

        return pack('v*', ...array_map(fn (int $raw): int => max(-32768, min(32767, (int) round(($raw >= 32768 ? $raw - 65536 : $raw) * $gain))) & 0xFFFF, unpack('v*', $pcm) ?: []));
    }

    /**
     * `$over` mixed into `$base` from sample `$atSample` on, at `$gainDb`,
     * clipped at full scale: the overlap is summed, the rest of either one
     * kept as it is (the result is as long as the longer reach). A start
     * past the end of `$base` pads it with silence.
     */
    public static function overlay(string $base, string $over, int $atSample, float $gainDb = 0.0): string
    {
        $at = max(0, $atSample) * self::BYTES_PER_SAMPLE;
        $over = self::gained($over, $gainDb);

        if ($at > strlen($base)) {
            $base .= str_repeat("\0", $at - strlen($base));
        }

        $overlap = min(strlen($base) - $at, strlen($over));
        $overlap -= $overlap % self::BYTES_PER_SAMPLE;

        if ($overlap <= 0) {
            return $base.$over;
        }

        $a = unpack('v*', substr($base, $at, $overlap)) ?: [];
        $b = unpack('v*', substr($over, 0, $overlap)) ?: [];
        $mixed = [];

        foreach ($a as $index => $raw) {
            $other = $b[$index] ?? 0;
            $mixed[] = max(-32768, min(32767, ($raw >= 32768 ? $raw - 65536 : $raw) + ($other >= 32768 ? $other - 65536 : $other))) & 0xFFFF;
        }

        return substr($base, 0, $at).pack('v*', ...$mixed).substr($base, $at + $overlap).substr($over, $overlap);
    }

    /** The first `$samplesToFade` samples ramped up linearly from silence. */
    public static function fadeIn(string $pcm, int $samplesToFade): string
    {
        $samples = intdiv(strlen($pcm), self::BYTES_PER_SAMPLE);
        $samplesToFade = min($samples, max(0, $samplesToFade));

        if ($samplesToFade === 0) {
            return $pcm;
        }

        $head = unpack('s*', substr($pcm, 0, $samplesToFade * self::BYTES_PER_SAMPLE)) ?: [];
        $faded = '';
        $i = 0;

        foreach ($head as $sample) {
            $faded .= pack('v', ((int) round($sample * ($i++ / $samplesToFade))) & 0xFFFF);
        }

        return $faded.substr($pcm, $samplesToFade * self::BYTES_PER_SAMPLE);
    }

    /** The last `$samplesToFade` samples ramped down linearly to silence. */
    public static function fadeOut(string $pcm, int $samplesToFade): string
    {
        $samples = intdiv(strlen($pcm), self::BYTES_PER_SAMPLE);
        $samplesToFade = min($samples, max(0, $samplesToFade));

        if ($samplesToFade === 0) {
            return $pcm;
        }

        $head = substr($pcm, 0, ($samples - $samplesToFade) * self::BYTES_PER_SAMPLE);
        $tail = unpack('s*', substr($pcm, ($samples - $samplesToFade) * self::BYTES_PER_SAMPLE)) ?: [];
        $faded = '';
        $i = 0;

        foreach ($tail as $sample) {
            $faded .= pack('v', ((int) round($sample * (1 - ++$i / $samplesToFade))) & 0xFFFF);
        }

        return $head.$faded;
    }
}
