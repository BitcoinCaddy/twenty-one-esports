<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\MusicCatalog;
use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The nightly Wavlake sync (plan "Wavlake-Sync", user 2026-10-17: "Automatisches Abholen von neuen Tracks von Wavlake
 * von unseren Artists ... jede Nacht einmal ein Sync"): for every artist of `twentyone.stream.music.wavlake_sync.artists`
 * it lists the Wavlake tracks, takes the ones it has not met yet (by Wavlake track id: the state file and the
 * catalog's `wavlake_track_id`), newest first and at most `per_run` a night, downloads each over https from the
 * allowed hosts within `max_bytes`, and hands it to twentyone:music-add --overlay: normalised, refused as a duplicate
 * of anything in either catalog, credited to the listed artist. Titles go in as Wavlake names them (user 2026-10-17:
 * "Alles automatisch"); the run's report is the log line and `last_run` in the state file.
 *
 * After a night that added a track the stream daemon restarts (supervisor brings it back) to play it; the artist
 * cycle keeps its order over a restart unless the library changed, which it then has.
 */
#[Signature('twentyone:music-wavlake-sync
    {--limit= : Downloads this run (default: wavlake_sync.per_run)}
    {--dry-run : List what is new, download nothing}')]
#[Description('Fetch new tracks of our Wavlake artists into the overlay music catalog (normalised, no duplicates), then restart the stream')]
class TwentyOneMusicWavlakeSyncCommand extends Command
{
    private const API = 'https://wavlake.com/api/v1/content';

    private const CATALOG_API = 'https://catalog.wavlake.com/v1/artists';

    public function handle(): int
    {
        $config = (array) config('twentyone.stream.music.wavlake_sync');
        $dir = rtrim((string) config('twentyone.stream.music.dir'), '/');

        if (MusicCatalog::overlayPath() === null || $dir === '' || ! is_dir($dir)) {
            $this->error('No overlay catalog or no music folder (twentyone.stream.music.overlay, .dir).');

            return self::FAILURE;
        }

        $statePath = (string) $config['state'];
        $state = self::readState($statePath);
        $known = $state['tracks'];
        $catalogIds = array_flip(array_filter(array_map(fn (array $track): ?string => isset($track['wavlake_track_id']) ? (string) $track['wavlake_track_id'] : null, MusicCatalog::tracks())));
        $maxTries = max(1, (int) ($config['max_tries'] ?? 3));
        $limit = max(0, (int) ($this->option('limit') ?? $config['per_run'] ?? 25));
        $report = ['at' => now()->toIso8601String(), 'added' => [], 'duplicates' => 0, 'failed' => [], 'artists_failed' => [], 'waiting' => 0];
        $new = [];

        foreach ((array) $config['artists'] as $slug => $name) {
            $titles = self::titlesOf((string) $name);

            try {
                foreach ($this->tracks((string) $slug) as $track) {
                    $entry = $known[$track['id']] ?? null;
                    // A title the artist already has in the catalog (the imports before the sync carry no Wavlake id)
                    // is known without a download; the fingerprint check in music-add catches the rest.
                    $done = isset($catalogIds[$track['id']]) || self::hasTitle($titles, $track['title'])
                        || ($entry !== null && ($entry['status'] !== 'failed' || ($entry['tries'] ?? 0) >= $maxTries));

                    if (! $done) {
                        $new[] = [...$track, 'artist' => (string) $name];
                    }
                }
            } catch (Throwable $e) {
                $report['artists_failed'][] = $slug.': '.$e->getMessage();
                $this->warn("{$slug}: ".$e->getMessage());
            }
        }

        usort($new, fn (array $a, array $b): int => strcmp($b['released'], $a['released']));
        $report['waiting'] = max(0, count($new) - $limit);

        if ($this->option('dry-run')) {
            foreach (array_slice($new, 0, $limit) as $track) {
                $this->line("{$track['artist']}: {$track['title']} ({$track['released']})");
            }
            $this->info(sprintf('%d new, %d this run.', count($new), min($limit, count($new))));

            return self::SUCCESS;
        }

        $tmp = dirname($statePath).'/tmp';
        File::ensureDirectoryExists($tmp);

        foreach (array_slice($new, 0, $limit) as $track) {
            $file = $tmp.'/'.self::fileName($track['title'], $track['artist']).'.mp3';
            [$status, $message] = $this->fetchAndAdd($track, $file, $dir, $config);
            @unlink($file);

            $known[$track['id']] = ['status' => $status, 'artist' => $track['artist'], 'title' => $track['title'], 'at' => now()->toIso8601String(),
                'tries' => $status === 'failed' ? ($known[$track['id']]['tries'] ?? 0) + 1 : 0, 'message' => mb_substr($message, 0, 300)];

            match ($status) {
                'added' => $report['added'][] = "{$track['artist']}: {$track['title']}",
                'duplicate' => $report['duplicates']++,
                default => $report['failed'][] = "{$track['artist']}: {$track['title']}: ".mb_substr($message, 0, 160),
            };
            $this->line(sprintf('%s %s: %s', $status, $track['artist'], $track['title']));
            self::writeState($statePath, ['tracks' => $known, 'last_run' => $report]);
        }

        self::writeState($statePath, ['tracks' => $known, 'last_run' => $report]);
        $summary = sprintf('music: Wavlake sync added %d, %d duplicates, %d failed, %d waiting for later nights%s', count($report['added']), $report['duplicates'], count($report['failed']), $report['waiting'],
            $report['added'] === [] ? '' : ': '.implode('; ', $report['added']));
        Log::info($summary, ['failed' => $report['failed'], 'artists_failed' => $report['artists_failed']]);
        $this->info($summary);

        if ($report['added'] !== [] && ($config['restart_stream'] ?? false)) {
            Process::run(['pkill', '-TERM', '-f', 'artisan twentyone:stream$']);
            $this->info('Stream restarted to play the new tracks.');
        }

        // Failed only when Wavlake answered for none of the artists.
        return count($report['artists_failed']) === count((array) $config['artists']) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The normalised titles of the catalog tracks that play under this artist or playlist (MusicPlaylist::artist()).
     *
     * @return array<string, true>
     */
    private static function titlesOf(string $name): array
    {
        $titles = [];

        foreach (MusicCatalog::tracks() as $file => $track) {
            if (is_string($track['title'] ?? null) && MusicPlaylist::artist($file) === $name) {
                $titles[self::normalise($track['title'])] = true;
            }
        }

        unset($titles['']);

        return $titles;
    }

    /**
     * Whether a Wavlake title is one of these: the whole title, or one part of "Title - Artists" (the PlebRap
     * playlist names its tracks so).
     *
     * @param  array<string, true>  $titles
     */
    private static function hasTitle(array $titles, string $title): bool
    {
        foreach ([$title, ...explode(' - ', $title)] as $part) {
            if (isset($titles[self::normalise($part)])) {
                return true;
            }
        }

        return false;
    }

    private static function normalise(string $title): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($title));
    }

    /**
     * Every track of a Wavlake artist: id, title, media URL and release date.
     *
     * @return list<array{id: string, title: string, url: string, released: string}>
     */
    private function tracks(string $slug): array
    {
        $artistId = (string) (self::json(self::CATALOG_API.'/'.rawurlencode($slug).'/url')['id'] ?? '');

        if (preg_match('/^[0-9a-f-]{36}$/', $artistId) !== 1) {
            throw new RuntimeException('no Wavlake artist id');
        }

        $tracks = [];
        $albums = array_values(array_filter((array) (self::json(self::API.'/artist/'.$artistId)['albums'] ?? []), fn (mixed $album): bool => is_array($album) && preg_match('/^[0-9a-f-]{36}$/', (string) ($album['id'] ?? '')) === 1));
        $broken = 0;

        foreach ($albums as $album) {
            // Wavlake answers single albums with 500 (sabu, Under The Mulga Groove, 2026-10-17): that album waits for
            // another night, the artist's other albums are read.
            try {
                $listed = (array) (self::json(self::API.'/album/'.$album['id'])['tracks'] ?? []);
            } catch (Throwable $e) {
                $broken++;
                $this->warn("{$slug}: album {$album['id']}: ".mb_substr($e->getMessage(), 0, 120));

                continue;
            }

            foreach ($listed as $track) {
                if (is_string($track['id'] ?? null) && is_string($track['title'] ?? null) && is_string($track['mediaUrl'] ?? null) && trim($track['title']) !== '') {
                    $tracks[] = ['id' => $track['id'], 'title' => trim($track['title']), 'url' => $track['mediaUrl'], 'released' => (string) ($track['releaseDate'] ?? '')];
                }
            }
        }

        if ($albums !== [] && $broken === count($albums)) {
            throw new RuntimeException('no album readable');
        }

        return $tracks;
    }

    /**
     * Downloads a track (https, allowed hosts on every hop, at most max_bytes) and adds it to the overlay.
     *
     * @param  array{id: string, title: string, url: string, released: string, artist: string}  $track
     * @param  array<string, mixed>  $config
     * @return array{0: 'added'|'duplicate'|'failed', 1: string}
     */
    private function fetchAndAdd(array $track, string $file, string $dir, array $config): array
    {
        $hosts = (array) $config['hosts'];
        $max = (int) $config['max_bytes'];
        $allowed = fn (UriInterface|string $uri): bool => is_string($host = parse_url((string) $uri, PHP_URL_HOST)) && parse_url((string) $uri, PHP_URL_SCHEME) === 'https' && in_array(strtolower($host), $hosts, true);

        if (! $allowed($track['url'])) {
            return ['failed', 'media URL not on an allowed host: '.$track['url']];
        }

        try {
            Http::timeout(120)->withOptions([
                'sink' => $file,
                'allow_redirects' => ['max' => 3, 'protocols' => ['https'], 'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($allowed): void {
                    if (! $allowed($uri)) {
                        throw new RuntimeException('redirect to a host that is not allowed: '.$uri->getHost());
                    }
                }],
                'on_headers' => function (ResponseInterface $response) use ($max): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $max) {
                        throw new RuntimeException('larger than '.$max.' bytes');
                    }
                },
            ])->get($track['url'])->throw();
        } catch (Throwable $e) {
            return ['failed', 'download: '.$e->getMessage()];
        }

        if (! is_file($file) || filesize($file) === 0 || filesize($file) > $max) {
            return ['failed', 'download empty or larger than '.$max.' bytes'];
        }

        // Its own buffer: the summary of this run stays in this command's output.
        $buffer = new BufferedOutput;
        $code = $this->getApplication()?->find('twentyone:music-add')->run(new ArrayInput(['file' => $file, '--artist' => $track['artist'], '--title' => $track['title'], '--dir' => $dir, '--overlay' => true, '--wavlake-id' => $track['id']]), $buffer) ?? self::FAILURE;
        $output = trim($buffer->fetch());
        MusicCredits::flush();

        return match (true) {
            $code === self::SUCCESS => ['added', $output],
            str_contains($output, 'Already in the library') => ['duplicate', $output],
            default => ['failed', $output],
        };
    }

    /**
     * The download's name, which music-add keeps (plus `__v1`): "Title - Artist", only characters the stream's
     * concat list takes safely (MusicPlaylist::isSafePath()).
     */
    public static function fileName(string $title, string $artist): string
    {
        $clean = fn (string $text): string => trim((string) preg_replace(['/[\x00-\x1F\x7F\/\\\\`\'"]+/u', '/\s+/u'], ['', ' '], $text), " .\t");
        $name = mb_substr($clean($title), 0, 100).' - '.$clean($artist);

        return MusicPlaylist::isSafePath($name) ? $name : 'track '.substr(sha1($name), 0, 8).' - '.$clean($artist);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $url): array
    {
        // Wavlake answers a quick series of requests with 429 (measured 2026-10-17 on the first listing): a pause
        // before every request, and a longer one before each retry of a 429.
        usleep((int) config('twentyone.stream.music.wavlake_sync.pause_ms', 400) * 1000);
        $body = Http::timeout(30)->retry(3, fn (int $attempt): int => $attempt * 5000, fn (Throwable $e): bool => $e instanceof RequestException && $e->response->status() === 429, throw: false)
            ->acceptJson()->get($url)->throw()->json();

        return is_array($body) ? (array) ($body['data'] ?? $body) : [];
    }

    /**
     * @return array{tracks: array<string, array<string, mixed>>}
     */
    private static function readState(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return ['tracks' => array_filter(is_array($data) ? (array) ($data['tracks'] ?? []) : [], 'is_array')];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function writeState(string $path, array $state): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temp = $path.'.tmp-'.getmypid();
        file_put_contents($temp, (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        rename($temp, $path);
    }
}
