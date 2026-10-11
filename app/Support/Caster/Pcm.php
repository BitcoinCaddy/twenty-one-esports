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

    public static function seconds(string $pcm, int $rate): float
    {
        return strlen($pcm) / self::BYTES_PER_SAMPLE / max(1, $rate);
    }

    public static function silence(float $seconds, int $rate): string
    {
        return str_repeat("\0\0", max(0, (int) round($seconds * $rate)));
    }

    /**
     * The audio up to the end of its last loud window (10 ms windows above
     * `$threshold`), plus `$fadeMs` faded out: a quiet tail after the last
     * word made the sentence end sound like a question (plan, "Referenz-Regeln").
     */
    public static function trimTail(string $pcm, int $rate, int $threshold, int $fadeMs): string
    {
        $samples = intdiv(strlen($pcm), self::BYTES_PER_SAMPLE);
        $window = max(1, intdiv($rate, 100));
        $end = 0;

        for ($start = ($samples - 1) - (($samples - 1) % $window); $start >= 0; $start -= $window) {
            $chunk = unpack('s*', substr($pcm, $start * self::BYTES_PER_SAMPLE, $window * self::BYTES_PER_SAMPLE)) ?: [];
            $peak = $chunk === [] ? 0 : max(array_map('abs', $chunk));

            if ($peak >= $threshold) {
                $end = min($samples, $start + $window);
                break;
            }
        }

        if ($end === 0) {
            return '';
        }

        $fade = min($samples - $end, (int) round($rate * $fadeMs / 1000));
        $kept = substr($pcm, 0, ($end + $fade) * self::BYTES_PER_SAMPLE);

        return self::fadeOut($kept, $fade);
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
