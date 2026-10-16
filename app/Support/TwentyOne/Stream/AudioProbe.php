<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;

/**
 * Measures music files for the catalog (MusicCatalog): length and bitrate (ffprobe), loudness and true peak (ffmpeg
 * ebur128) and the Chromaprint fingerprint (fpcalc). Every tool runs at nice 19 and at most two files at a time (plan
 * "Musik-Katalog": the fingerprints of 300 songs cost CPU on the server).
 */
final class AudioProbe
{
    public const PARALLEL = 2;

    public function __construct(
        private string $ffmpeg = 'ffmpeg',
        private string $ffprobe = 'ffprobe',
        private string $fpcalc = 'fpcalc',
    ) {}

    /**
     * The measurements of each file (keyed as given), null for a file that cannot be read as audio. Without
     * `$loudness` the ebur128 pass (the slow one, it decodes the whole file) is left out: lufs and true_peak are null.
     *
     * @param  array<string, string>  $files  key => absolute path
     * @return array<string, array{duration: float, bitrate: int, lufs: float|null, true_peak: float|null, fingerprint: list<int>}|null>
     */
    public function measure(array $files, bool $loudness = true): array
    {
        $results = [];

        foreach (array_chunk($files, self::PARALLEL, true) as $batch) {
            $probes = $this->concurrently($batch, fn (string $path): array => [$this->ffprobe, '-v', 'error', '-show_entries',
                'format=duration,bit_rate:stream=codec_type,bit_rate', '-of', 'json', $path]);
            $levels = $loudness ? $this->concurrently($batch, fn (string $path): array => [$this->ffmpeg, '-hide_banner', '-nostats', '-i', $path,
                '-map', '0:a:0', '-filter:a', 'ebur128=peak=true', '-f', 'null', '-'], stderr: true) : [];
            $prints = $this->concurrently($batch, fn (string $path): array => [$this->fpcalc, '-raw', '-json', $path]);

            foreach (array_keys($batch) as $key) {
                $probe = self::probe($probes[$key] ?? '');
                $print = json_decode($prints[$key] ?? '', true);
                $items = is_array($print) && is_array($print['fingerprint'] ?? null) ? array_values(array_map('intval', $print['fingerprint'])) : [];

                if ($probe === null || $items === []) {
                    $results[$key] = null;

                    continue;
                }

                $results[$key] = [...$probe, ...self::loudness($levels[$key] ?? ''), 'fingerprint' => $items];
            }
        }

        return $results;
    }

    /**
     * Integrated loudness and true peak from ebur128's summary (stderr of `ffmpeg … -filter:a ebur128=peak=true`).
     *
     * @return array{lufs: float|null, true_peak: float|null}
     */
    public static function loudness(string $output): array
    {
        $at = strrpos($output, 'Summary:');
        $summary = $at === false ? '' : substr($output, $at);

        $lufs = preg_match('/I:\s+(-?[\d.]+|-inf) LUFS/', $summary, $i) === 1 && $i[1] !== '-inf' ? round((float) $i[1], 1) : null;
        $peak = preg_match('/True peak:\s+Peak:\s+(-?[\d.]+|-inf) dBFS/', $summary, $p) === 1 && $p[1] !== '-inf' ? round((float) $p[1], 1) : null;

        return ['lufs' => $lufs, 'true_peak' => $peak];
    }

    /**
     * Length and bitrate from ffprobe's JSON, null without an audio stream or a length.
     *
     * @return array{duration: float, bitrate: int}|null
     */
    public static function probe(string $json): ?array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $audio = collect((array) ($data['streams'] ?? []))->first(fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'audio');
        $duration = (float) ($data['format']['duration'] ?? 0);

        if (! is_array($audio) || $duration <= 0) {
            return null;
        }

        $bitrate = (int) ($audio['bit_rate'] ?? $data['format']['bit_rate'] ?? 0);

        return ['duration' => round($duration, 2), 'bitrate' => (int) round($bitrate / 1000)];
    }

    /**
     * Runs one command per file, the batch at once, at nice 19; the output a tool writes (stdout, or stderr for ffmpeg)
     * per key, '' for a failed run.
     *
     * @param  array<string, string>  $batch
     * @param  callable(string): list<string>  $command
     * @return array<string, string>
     */
    private function concurrently(array $batch, callable $command, bool $stderr = false): array
    {
        $results = Process::concurrently(function (Pool $pool) use ($batch, $command): void {
            foreach ($batch as $key => $path) {
                $pool->as((string) $key)->timeout(900)->command(['nice', '-n', '19', ...$command($path)]);
            }
        });

        $byKey = $results->collect();
        $outputs = [];

        foreach (array_keys($batch) as $key) {
            $result = $byKey->get((string) $key);
            $outputs[$key] = $result?->successful() ? ($stderr ? $result->errorOutput() : $result->output()) : '';
        }

        return $outputs;
    }
}
