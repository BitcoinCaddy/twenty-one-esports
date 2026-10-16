<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Brings a song to the library's loudness (plan "Musik-Katalog": −15 LUFS, true peak ≤ −1.5 dBTP, 44.1 kHz stereo
 * AAC): two-pass loudnorm (I=-15:TP=-1.5:LRA=11, linear) with a lookahead limiter at −1.5 dB behind it, encoded as
 * AAC 128k .m4a, then measured with ebur128. A result whose true peak is above −1.0 dBTP (the AAC encoder overshoots
 * the limiter by a few tenths) is made again with one more dB of headroom; still above, it is refused.
 *
 * The 68 songs measured above −1 dBTP on 2026-10-16 (up to +2.3) are re-made this way from their originals
 * (twentyone:music-renormalize); new songs come in through twentyone:music-add.
 */
final class LoudnessNormalizer
{
    public const TARGET = 'I=-15:TP=-1.5:LRA=11';

    /** The highest true peak a result may keep, in dBTP. */
    public const MAX_TRUE_PEAK = -1.0;

    /** Limiter ceilings tried in turn, in dB. */
    private const LIMITS = [-1.5, -2.5];

    public function __construct(private string $ffmpeg = 'ffmpeg') {}

    /**
     * Writes the normalised `$output` from `$input` (through a temp file beside it, renamed at the end) and returns
     * what ebur128 measured on it.
     *
     * @return array{lufs: float|null, true_peak: float|null, limit: float}
     */
    public function normalize(string $input, string $output): array
    {
        $measured = $this->firstPass($input);
        // Hidden and without the .m4a ending: neither the stream's glob nor the catalog's scan takes it for a song.
        $temp = dirname($output).'/.'.basename($output).'.normalize-'.getmypid().'.part';

        try {
            foreach (self::LIMITS as $limit) {
                $chain = sprintf('loudnorm=%s:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true,alimiter=limit=%.4f:level=false:latency=true',
                    self::TARGET, $measured['input_i'], $measured['input_tp'], $measured['input_lra'], $measured['input_thresh'], $measured['target_offset'], 10 ** ($limit / 20));

                $encode = Process::timeout(1800)->run(['nice', '-n', '19', $this->ffmpeg, '-y', '-hide_banner', '-nostats', '-i', $input, '-map', '0:a:0',
                    '-filter:a', $chain, '-ar', '44100', '-ac', '2', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', '-f', 'mp4', $temp]);

                if ($encode->failed()) {
                    throw new RuntimeException('ffmpeg could not encode '.basename($input).': '.self::tail($encode->errorOutput()));
                }

                $result = AudioProbe::loudness(Process::timeout(1800)->run(['nice', '-n', '19', $this->ffmpeg, '-hide_banner', '-nostats', '-i', $temp,
                    '-map', '0:a:0', '-filter:a', 'ebur128=peak=true', '-f', 'null', '-'])->errorOutput());

                if ($result['true_peak'] !== null && $result['true_peak'] <= self::MAX_TRUE_PEAK) {
                    if (! rename($temp, $output)) {
                        throw new RuntimeException('Could not write '.$output);
                    }

                    return [...$result, 'limit' => $limit];
                }
            }

            throw new RuntimeException(sprintf('%s: true peak %s dBTP after the limiter, above %.1f', basename($input), $result['true_peak'] ?? 'unknown', self::MAX_TRUE_PEAK));
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * loudnorm's measurement of the input (its JSON block on stderr).
     *
     * @return array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string}
     */
    private function firstPass(string $input): array
    {
        $run = Process::timeout(1800)->run(['nice', '-n', '19', $this->ffmpeg, '-hide_banner', '-nostats', '-i', $input, '-map', '0:a:0',
            '-filter:a', 'loudnorm='.self::TARGET.':print_format=json', '-f', 'null', '-']);
        $stderr = $run->errorOutput();
        // The block is followed by ffmpeg's closing lines.
        $start = strrpos($stderr, '{');
        $end = $start === false ? false : strpos($stderr, '}', $start);
        $data = $run->successful() && $start !== false && $end !== false ? json_decode(substr($stderr, $start, $end - $start + 1), true) : null;
        $keys = ['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'];

        if (! is_array($data) || array_diff($keys, array_keys($data)) !== [] || collect($keys)->contains(fn (string $key): bool => ! is_numeric($data[$key]))) {
            throw new RuntimeException('loudnorm could not measure '.basename($input).': '.self::tail($stderr));
        }

        /** @var array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string} */
        return array_map('strval', array_intersect_key($data, array_flip($keys)));
    }

    private static function tail(string $output): string
    {
        return trim(substr(trim($output), -300));
    }
}
