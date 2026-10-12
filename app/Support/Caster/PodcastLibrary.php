<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The podcast replay's episodes on the server (plan "TWENTY ONE Radio", P12;
 * `tv.commentary.podcast_replay`): for every enabled source the newest
 * episode of its feed (PodcastFeed::newestAudio(), `title_pattern` picks one
 * show of a feed that carries several), downloaded into a private temp
 * directory, levelled to the voice's loudness (`voicestudio.loudness_lufs`)
 * and encoded once as mono Opus at the voice feed's rate by ffmpeg under
 * `nice`, its pauses found in the same pass (silencedetect: `pause_db` for
 * `pause_seconds`), then cut at pauses into chunks of about `chunk_seconds`
 * (plan()). The download goes in every case.
 *
 * Layout on the spool disk: `<dir>/<source>/<episode id>/chunk-NNN.opus` and
 * `<dir>/<source>/episode.json` naming the episode, its chunks, their lengths
 * and the pauses inside each (seconds into the chunk; the chunk's end is one
 * too). A newer episode replaces the old one once its chunks are complete; an
 * older one stays only while a clip of an airing still plays from it.
 *
 * Bounded: one episode per source (plus one still on air), downloads up to
 * `max_download_bytes`, the whole directory up to `max_bytes` (a new episode
 * that would not fit is dropped), and a disabled source's episodes are
 * deleted. Fails closed: a feed, download or ffmpeg that fails leaves the
 * stored episode as it was, and the source is tried again after
 * `check_minutes`.
 */
class PodcastLibrary
{
    public const STORED = 'stored';

    public const SAME = 'same';

    public const NOT_DUE = 'not_due';

    public const NO_EPISODE = 'no_episode';

    public const FEED_FAILED = 'feed_failed';

    public const DOWNLOAD_FAILED = 'download_failed';

    public const TRANSCODE_FAILED = 'transcode_failed';

    public const TOO_BIG = 'too_big';

    /** When a source's feed was read last (a timestamp, per source). */
    private const CHECKED = 'caster:podcast_replay:checked:';

    /**
     * What the last refresh measured per source (download, transcode, chunks, pauses), for the command's log line.
     *
     * @var array<string, array<string, int|float>>
     */
    public array $measured = [];

    /** The source refreshSource() is working on. */
    private string $measuring = '';

    /** The settings of `tv.commentary.podcast_replay`. */
    public static function settings(): array
    {
        return (array) config('tv.commentary.podcast_replay', []);
    }

    public static function enabled(): bool
    {
        return config('tv.commentary.podcast_replay.enabled') === true;
    }

    /**
     * The enabled sources by key, each with its feed and slot; none while the replay is off.
     *
     * @return array<string, array{name: string, feed: string, title_pattern: string|null, starts_at: string}>
     */
    public static function sources(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $sources = [];

        foreach ((array) (self::settings()['sources'] ?? []) as $key => $source) {
            if (! is_string($key) || preg_match('/^[a-z0-9_-]{1,16}$/', $key) !== 1 || ! is_array($source) || ($source['enabled'] ?? false) !== true
                || ! is_string($source['feed'] ?? null) || ! str_starts_with($source['feed'], 'https://')) {
                continue;
            }

            $sources[$key] = [
                'name' => is_string($source['name'] ?? null) ? $source['name'] : $key,
                'feed' => $source['feed'],
                'title_pattern' => is_string($source['title_pattern'] ?? null) && $source['title_pattern'] !== '' ? $source['title_pattern'] : null,
                'starts_at' => is_string($source['starts_at'] ?? null) ? $source['starts_at'] : '14:03',
            ];
        }

        return $sources;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('tv.commentary.spool.disk', 'local'));
    }

    public function dir(): string
    {
        return trim((string) (self::settings()['dir'] ?? 'caster/podcasts'), '/');
    }

    /**
     * The stored episode of a source: its guid, title, notes, length and chunks (path on the spool disk, seconds,
     * pauses), or null when there is none or its chunks are not all there.
     *
     * @return array{guid: string, title: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>}|null
     */
    public function episode(string $source): ?array
    {
        $file = $this->dir().'/'.$source.'/episode.json';
        $meta = $this->disk()->exists($file) ? json_decode((string) $this->disk()->get($file), true) : null;

        if (! is_array($meta) || ! is_string($meta['guid'] ?? null) || ! is_array($meta['chunks'] ?? null) || $meta['chunks'] === []) {
            return null;
        }

        foreach ($meta['chunks'] as $chunk) {
            if (! is_array($chunk) || ! is_string($chunk['path'] ?? null) || ! $this->disk()->exists($chunk['path'])) {
                return null;
            }
        }

        /** @var array{guid: string, title: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>} $meta */
        return $meta;
    }

    /**
     * Reads every enabled source whose last read is `check_minutes` ago (all with `$force`), stores a newer episode
     * and trims the directory; returns what happened per source.
     *
     * @return array<string, string>
     */
    public function refresh(CarbonImmutable $now, bool $force = false): array
    {
        $outcomes = [];
        $every = 60 * max(1, (int) (self::settings()['check_minutes'] ?? 60));

        foreach (self::sources() as $key => $source) {
            $checked = Cache::get(self::CHECKED.$key);

            if (! $force && is_int($checked) && $now->getTimestamp() - $checked < $every) {
                $outcomes[$key] = self::NOT_DUE;

                continue;
            }

            Cache::put(self::CHECKED.$key, $now->getTimestamp(), 2 * $every);
            $outcomes[$key] = $this->refreshSource($key, $source);
        }

        $this->bound();

        return $outcomes;
    }

    /**
     * One source: the newest episode of its feed, stored when it is not the one already here.
     *
     * @param  array{name: string, feed: string, title_pattern: string|null, starts_at: string}  $source
     */
    public function refreshSource(string $key, array $source): string
    {
        $settings = self::settings();
        $this->measuring = $key;
        $this->measured[$key] = [];

        try {
            $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')->connectTimeout(5)->timeout(60)->get($source['feed']);
        } catch (Throwable) {
            return self::FEED_FAILED;
        }

        if (! $response->successful() || strlen($response->body()) > (int) ($settings['feed_max_bytes'] ?? 12 * 1024 * 1024)) {
            return self::FEED_FAILED;
        }

        $episode = PodcastFeed::newestAudio($response->body(), $source['title_pattern']);

        if ($episode === null) {
            return self::NO_EPISODE;
        }

        if (($this->episode($key)['guid'] ?? null) === $episode['guid']) {
            return self::SAME;
        }

        $id = substr(sha1($episode['guid']), 0, 12);
        $target = $this->dir().'/'.$key.'/'.$id;
        $temp = sys_get_temp_dir().'/twentyone-podcast-replay-'.bin2hex(random_bytes(6));
        $started = microtime(true);

        try {
            File::ensureDirectoryExists($temp, 0700);
            $audio = $temp.'/episode.audio';
            $max = (int) ($settings['max_download_bytes'] ?? 400 * 1024 * 1024);

            try {
                $download = Http::connectTimeout(10)->timeout((int) ($settings['download_timeout_seconds'] ?? 900))->withOptions(['sink' => $audio])->get($episode['url']);
            } catch (Throwable) {
                return self::DOWNLOAD_FAILED;
            }

            clearstatcache(true, $audio);

            if (! $download->successful() || ! is_file($audio) || filesize($audio) === 0) {
                return self::DOWNLOAD_FAILED;
            }

            if (filesize($audio) > $max) {
                return self::TOO_BIG;
            }

            $this->measured[$this->measuring]['download_bytes'] = (int) filesize($audio);
            $this->measured[$this->measuring]['download_seconds'] = round(microtime(true) - $started, 1);

            $this->disk()->deleteDirectory($target);
            $this->disk()->makeDirectory($target);
            $chunks = $this->transcode($audio, $temp, $target);
            File::delete($audio);

            if ($chunks === null) {
                $this->disk()->deleteDirectory($target);

                return self::TRANSCODE_FAILED;
            }

            $meta = [
                'guid' => $episode['guid'],
                'title' => $episode['title'],
                'episode_number' => $episode['episode_number'],
                'published_at' => $episode['published_at'],
                'source' => $key,
                'id' => $id,
                'seconds' => round(array_sum(array_column($chunks, 'seconds')), 3),
                'stored_at' => CarbonImmutable::now()->toIso8601String(),
                'chunks' => $chunks,
            ];
            $this->measured[$this->measuring]['seconds'] = $meta['seconds'];
            $this->measured[$this->measuring]['chunks'] = count($chunks);
            $this->measured[$this->measuring]['bytes'] = array_sum(array_column($chunks, 'bytes'));
            $this->measured[$this->measuring]['total_seconds'] = round(microtime(true) - $started, 1);

            // The library may not outgrow `max_bytes`: what no airing plays from goes first, the episode this one replaces
            // counts as gone unless an airing still plays from it, else the new episode itself is dropped.
            $this->bound(keep: $target);
            $old = $this->episode($key);
            $oldDir = $old === null ? null : dirname($old['chunks'][0]['path']);
            $freed = $oldDir === null || in_array($oldDir, $this->playingDirectories(), true) ? 0 : $this->directoryBytes($oldDir);

            if ($this->bytes() - $freed > (int) ($settings['max_bytes'] ?? 1024 * 1024 * 1024)) {
                $this->disk()->deleteDirectory($target);

                return self::TOO_BIG;
            }

            $this->disk()->put($this->dir().'/'.$key.'/episode.json', (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->bound();

            return self::STORED;
        } finally {
            File::deleteDirectory($temp);
        }
    }

    /**
     * Levels and encodes the episode in one ffmpeg pass that also finds its pauses, then cuts the Opus into chunks at
     * pauses (plan()) without encoding again. Null when ffmpeg fails.
     *
     * @return list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>|null
     */
    private function transcode(string $audio, string $temp, string $target): ?array
    {
        $settings = self::settings();
        $ffmpeg = (string) config('twentyone.stream.ffmpeg', 'ffmpeg');
        $rate = (int) config('tv.commentary.mix.sample_rate', 24000);
        $lufs = (int) config('tv.commentary.voicestudio.loudness_lufs', -16);
        $full = $temp.'/episode.opus';
        $timeout = (int) ($settings['transcode_timeout_seconds'] ?? 1_800);
        $started = microtime(true);

        $filter = '[0:a]aresample=48000,loudnorm=I='.$lufs.':TP=-1.5:LRA=11,aresample='.$rate.',aformat=channel_layouts=mono,asplit=2[voice][probe];'
            .'[probe]silencedetect=noise='.(int) ($settings['pause_db'] ?? -35).'dB:d='.max(0.1, (float) ($settings['pause_seconds'] ?? 0.35)).'[quiet]';

        $encode = Process::timeout($timeout)->env(ChildEnvironment::withoutSecrets())->run([
            'nice', '-n', '10', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'info', '-i', $audio, '-vn',
            '-filter_complex', $filter,
            '-map', '[voice]', '-c:a', 'libopus', '-b:a', ((int) ($settings['opus_kbps'] ?? 32)).'k', '-application', 'voip', '-f', 'ogg', $full,
            '-map', '[quiet]', '-f', 'null', '-',
        ]);

        if (! $encode->successful() || ! is_file($full)) {
            return null;
        }

        $this->measured[$this->measuring]['transcode_seconds'] = round(microtime(true) - $started, 1);
        $pauses = self::pauses($encode->errorOutput());
        $duration = self::duration($encode->errorOutput());

        if ($duration <= 0) {
            return null;
        }

        $plan = self::plan($duration, $pauses, (float) ($settings['chunk_seconds'] ?? 300));
        $times = array_map(fn (array $chunk): string => sprintf('%.3F', $chunk['from']), array_slice($plan, 1));
        $directory = $this->disk()->path($target);
        $list = $temp.'/chunks.csv';

        $cut = Process::timeout($timeout)->env(ChildEnvironment::withoutSecrets())->run([
            'nice', '-n', '10', $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $full, '-c', 'copy', '-map', '0:a',
            '-f', 'segment', '-segment_format', 'ogg', '-segment_list', $list, '-segment_list_type', 'csv',
            ...($times === [] ? [] : ['-segment_times', implode(',', $times)]),
            $directory.'/chunk-%03d.opus',
        ]);
        File::delete($full);

        if (! $cut->successful() || ! is_file($list)) {
            return null;
        }

        $this->measured[$this->measuring]['pauses'] = count($pauses);
        $this->measured[$this->measuring]['max_pause_gap'] = self::maxGap($pauses, $duration);

        return self::chunks((string) file_get_contents($list), $pauses, $target, fn (string $path): int => (int) $this->disk()->size($path));
    }

    /**
     * The chunks ffmpeg's segment list names (file, start, end per line), each with the pauses inside it.
     *
     * @param  list<array{start: float, end: float}>  $pauses
     * @param  callable(string): int  $size
     * @return list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>|null
     */
    public static function chunks(string $csv, array $pauses, string $target, callable $size): ?array
    {
        $chunks = [];

        foreach (preg_split('/\R/', trim($csv)) ?: [] as $line) {
            $fields = str_getcsv($line, escape: '');

            if (count($fields) < 3 || preg_match('/^chunk-\d{3}\.opus$/', basename((string) $fields[0])) !== 1) {
                continue;
            }

            [$from, $to] = [(float) $fields[1], (float) $fields[2]];
            $seconds = round($to - $from, 3);

            if ($seconds <= 0) {
                continue;
            }

            $inside = [];
            foreach ($pauses as $pause) {
                $middle = ($pause['start'] + $pause['end']) / 2;

                if ($middle > $from + 0.5 && $middle < $to - 0.5) {
                    $inside[] = round($middle - $from, 3);
                }
            }

            $path = $target.'/'.basename((string) $fields[0]);
            $chunks[] = ['path' => $path, 'seconds' => $seconds, 'bytes' => $size($path), 'pauses' => [...$inside, $seconds]];
        }

        return $chunks === [] ? null : $chunks;
    }

    /**
     * The pauses silencedetect logged: where each starts and ends (seconds into the episode).
     *
     * @return list<array{start: float, end: float}>
     */
    public static function pauses(string $log): array
    {
        preg_match_all('/silence_(start|end):\s*(-?[\d.]+)/', $log, $matches, PREG_SET_ORDER);
        $pauses = [];
        $start = null;

        foreach ($matches as $match) {
            if ($match[1] === 'start') {
                $start = max(0.0, (float) $match[2]);
            } elseif ($start !== null) {
                $pauses[] = ['start' => $start, 'end' => (float) $match[2]];
                $start = null;
            }
        }

        return $pauses;
    }

    /** The length ffmpeg reached in the log (the last `time=` of the encode, else the input's `Duration:`), 0 when none. */
    public static function duration(string $log): float
    {
        $seconds = fn (string $h, string $m, string $s): float => 3600 * (int) $h + 60 * (int) $m + (float) $s;

        if (preg_match_all('/time=(\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', $log, $times, PREG_SET_ORDER) > 0) {
            $last = end($times);

            return $seconds($last[1], $last[2], $last[3]);
        }

        return preg_match('/Duration:\s*(\d+):(\d{2}):(\d{2}(?:\.\d+)?)/', $log, $match) === 1 ? $seconds($match[1], $match[2], $match[3]) : 0.0;
    }

    /**
     * Where the chunks of an episode of `$duration` start and end: each about `$chunk` seconds, cut at the pause
     * whose middle lies nearest its target within 80 to 120 % of `$chunk` after the cut before (straight at the
     * target when no pause lies there); a rest under 40 % of `$chunk` joins the chunk before it.
     *
     * @param  list<array{start: float, end: float}>  $pauses
     * @return list<array{from: float, to: float}>
     */
    public static function plan(float $duration, array $pauses, float $chunk): array
    {
        $chunk = max(30.0, $chunk);
        $middles = array_map(fn (array $pause): float => ($pause['start'] + $pause['end']) / 2, $pauses);
        $plan = [];
        $from = 0.0;

        while ($duration - $from > 1.4 * $chunk) {
            $target = $from + $chunk;
            $candidates = array_values(array_filter($middles, fn (float $middle): bool => $middle >= $from + 0.8 * $chunk && $middle <= $from + 1.2 * $chunk));
            usort($candidates, fn (float $a, float $b): int => abs($a - $target) <=> abs($b - $target));
            $to = round($candidates[0] ?? $target, 3);
            $plan[] = ['from' => $from, 'to' => $to];
            $from = $to;
        }

        $plan[] = ['from' => $from, 'to' => round($duration, 3)];

        return $plan;
    }

    /**
     * The longest stretch without a pause (seconds), the edges of the episode included.
     *
     * @param  list<array{start: float, end: float}>  $pauses
     */
    public static function maxGap(array $pauses, float $duration): float
    {
        $previous = 0.0;
        $gap = 0.0;

        foreach ($pauses as $pause) {
            $middle = ($pause['start'] + $pause['end']) / 2;
            $gap = max($gap, $middle - $previous);
            $previous = $middle;
        }

        return round(max($gap, $duration - $previous), 1);
    }

    /**
     * The episode directories a clip in the spool plays from (an airing on air or still to come).
     *
     * @return list<string>
     */
    private function playingDirectories(): array
    {
        return CasterClip::query()->where('path', 'like', $this->dir().'/%')->pluck('path')
            ->map(fn (string $path): string => dirname($path))->unique()->values()->all();
    }

    private function directoryBytes(string $directory): int
    {
        return (int) array_sum(array_map(fn (string $file): int => (int) $this->disk()->size($file), $this->disk()->allFiles($directory)));
    }

    /** Bytes of every file in the library. */
    public function bytes(): int
    {
        return (int) array_sum(array_map(fn (string $file): int => (int) $this->disk()->size($file), $this->disk()->allFiles($this->dir())));
    }

    /**
     * Deletes what the library no longer needs: the episodes of sources that are off or gone, and every episode
     * directory that is neither its source's current one, nor `$keep`, nor played from by a clip in the spool.
     */
    public function bound(?string $keep = null): void
    {
        $sources = self::sources();
        $playing = $this->playingDirectories();

        foreach ($this->disk()->directories($this->dir()) as $sourceDir) {
            $key = basename($sourceDir);
            $current = isset($sources[$key]) ? $this->episode($key) : null;
            $currentDir = $current === null ? null : dirname($current['chunks'][0]['path']);

            foreach ($this->disk()->directories($sourceDir) as $episodeDir) {
                if ($episodeDir !== $currentDir && $episodeDir !== $keep && ! in_array($episodeDir, $playing, true)) {
                    $this->disk()->deleteDirectory($episodeDir);
                }
            }

            if (! isset($sources[$key]) && $this->disk()->directories($sourceDir) === []) {
                $this->disk()->deleteDirectory($sourceDir);
            }
        }
    }
}
