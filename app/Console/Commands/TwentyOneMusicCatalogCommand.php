<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\AudioProbe;
use App\Support\TwentyOne\Stream\Chromaprint;
use App\Support\TwentyOne\Stream\MusicCatalog;
use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Writes the music catalog (MusicCatalog) from the music folder: every .m4a straight in it or in one of its folders
 * (all but disabled/, where switched-off songs wait), measured with ffprobe, ebur128 and fpcalc at nice 19, two files
 * at a time. A file whose size and mtime did not change keeps its measurements. The folders only hold files: the
 * catalog records no source or genre (user 2026-10-16: "Es im Katalog nur noch um den Artists").
 *
 * Credits come from the former indexes while they exist (wavlake-index.json, plebrap-index.json, artists.json under
 * resources/data/music), then from the catalog itself (a credit added with twentyone:music-add stays, and so does the
 * house artist DJ Bitcoin where the catalog names it), else none.
 */
#[Signature('twentyone:music-catalog {--dir= : The music folder (default: twentyone.stream.music.dir)}')]
#[Description('Scan the music folder and write resources/data/music/catalog.json (tracks, artists, credits, loudness, fingerprints)')]
class TwentyOneMusicCatalogCommand extends Command
{
    public function handle(AudioProbe $probe): int
    {
        $dir = rtrim((string) ($this->option('dir') ?: config('twentyone.stream.music.dir')), '/');

        if ($dir === '' || ! is_dir($dir)) {
            $this->error('No music folder at '.$dir);

            return self::FAILURE;
        }

        MusicCatalog::flush();
        $previous = MusicCatalog::tracks();
        $previousPrints = MusicCatalog::fingerprints();
        $files = self::scan($dir);

        $tracks = [];
        $prints = [];
        $toMeasure = [];

        foreach ($files as $relative => $path) {
            $old = $previous[$relative] ?? null;
            $stat = ['size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];

            if ($old !== null && ($old['size'] ?? null) === $stat['size'] && ($old['mtime'] ?? null) === $stat['mtime'] && isset($previousPrints[(string) $old['id']])) {
                $tracks[$relative] = $old;
                $prints[$relative] = $previousPrints[(string) $old['id']];

                continue;
            }

            $toMeasure[$relative] = $path;
        }

        $this->line(sprintf('%d files, %d to measure, %d unchanged.', count($files), count($toMeasure), count($files) - count($toMeasure)));
        $unreadable = [];
        $done = 0;

        foreach (array_chunk($toMeasure, 10, true) as $chunk) {
            foreach ($probe->measure($chunk) as $relative => $measured) {
                if ($measured === null) {
                    $unreadable[] = $relative;

                    continue;
                }

                $path = $chunk[$relative];
                $prints[$relative] = $measured['fingerprint'];
                $tracks[$relative] = [
                    'duration' => $measured['duration'],
                    'bitrate' => $measured['bitrate'],
                    'lufs' => $measured['lufs'],
                    'true_peak' => $measured['true_peak'],
                    'fingerprint' => Chromaprint::hash($measured['fingerprint']),
                    'sha1' => (string) sha1_file($path),
                    'size' => (int) filesize($path),
                    'mtime' => (int) filemtime($path),
                ];
            }

            $done += count($chunk);
            $this->line(sprintf('  measured %d/%d', $done, count($toMeasure)));
        }

        $ids = $this->assignIds($tracks, $prints, $previous, $previousPrints);
        [$tracks, $artists] = $this->credit($tracks, $ids, $previous);

        $byId = [];
        foreach ($tracks as $relative => $track) {
            $byId[(string) $track['id']] = $prints[$relative];
        }

        MusicCatalog::write($tracks, $artists, $byId);
        MusicCredits::flush();

        $this->info(sprintf('Catalog: %d tracks, %d artists; credits %s.', count($tracks), count($artists), self::counts(array_column($tracks, 'credit_source'))));

        foreach ($unreadable as $relative) {
            $this->warn('Unreadable, left out: '.$relative);
        }

        return self::SUCCESS;
    }

    /**
     * The music files under `$dir` by their path relative to it, straight in it or one folder down (not disabled/):
     * .m4a only (a SHA256SUMS or a cover is no track), no hidden file (a temp file of twentyone:music-add), without
     * control characters in the name (MusicPlaylist::isSafePath()).
     *
     * @return array<string, string>
     */
    public static function scan(string $dir): array
    {
        $files = [];
        $folders = array_filter(scandir($dir) ?: [], fn (string $name): bool => $name[0] !== '.' && $name !== 'disabled' && is_dir($dir.'/'.$name));

        foreach (['', ...array_map(fn (string $name): string => $name.'/', $folders)] as $folder) {
            foreach (is_dir($dir.'/'.$folder) ? (scandir($dir.'/'.$folder) ?: []) : [] as $name) {
                $path = $dir.'/'.$folder.$name;

                if ($name[0] !== '.' && is_file($path) && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'm4a' && MusicPlaylist::isSafePath($name)) {
                    $files[$folder.$name] = $path;
                }
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * The id of every track: the previous one of the same file; else that of a vanished track with the same bytes or
     * the same recording (a moved file); else a new one.
     *
     * @param  array<string, array<string, mixed>>  $tracks
     * @param  array<string, list<int>>  $prints
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array<string, list<int>>  $previousPrints
     * @return array<string, string>
     */
    private function assignIds(array $tracks, array $prints, array $previous, array $previousPrints): array
    {
        $ids = [];
        $taken = [];

        foreach ($tracks as $relative => $track) {
            if (isset($previous[$relative])) {
                $ids[$relative] = (string) $previous[$relative]['id'];
                $taken[$ids[$relative]] = true;
            }
        }

        $vanished = array_diff_key($previous, $tracks);

        foreach ($tracks as $relative => $track) {
            if (isset($ids[$relative])) {
                continue;
            }

            foreach ($vanished as $oldFile => $old) {
                $oldId = (string) $old['id'];
                $sameBytes = ($old['sha1'] ?? null) === ($track['sha1'] ?? false);
                $sameRecording = isset($previousPrints[$oldId]) && Chromaprint::compare($prints[$relative], $previousPrints[$oldId], 5)['similarity'] >= Chromaprint::SAME_RECORDING;

                if (! isset($taken[$oldId]) && ($sameBytes || $sameRecording)) {
                    $ids[$relative] = $oldId;
                    $taken[$oldId] = true;
                    $this->line('  moved: '.$oldFile.' -> '.$relative);

                    continue 2;
                }
            }

            $ids[$relative] = MusicCatalog::newId($relative, $taken);
            $taken[$ids[$relative]] = true;
        }

        return $ids;
    }

    /**
     * Every track with its id, source, title, artists and credit source; the artists the catalog knows.
     *
     * @param  array<string, array<string, mixed>>  $tracks
     * @param  array<string, string>  $ids
     * @param  array<string, array<string, mixed>>  $previous
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function credit(array $tracks, array $ids, array $previous): array
    {
        $artists = MusicCatalog::artists();

        // The former artists.json wins for the artists it names while it exists.
        foreach (self::read('artists.json') as $name => $identity) {
            if (is_array($identity)) {
                $id = MusicCatalog::slug((string) $name);
                $artists[$id] = ['name' => (string) $name, ...$identity];
            }
        }

        $artistId = function (string $name) use (&$artists): string {
            $id = MusicCatalog::slug($name);
            $artists[$id] ??= ['name' => $name];

            return $id;
        };

        $indexed = [];

        foreach ((array) (self::read('wavlake-index.json')['tracks'] ?? []) as $track) {
            if (is_array($track) && is_string($track['file'] ?? null) && is_string($track['artist'] ?? null)) {
                $target = is_string($track['qr_target'] ?? null) ? $track['qr_target'] : '';
                $kind = self::kindOfRoute((string) ($track['zap_route'] ?? ''), $target);
                $indexed[$track['file']] = array_filter([
                    'artists' => [$artistId($track['artist'])],
                    'title' => is_string($track['title'] ?? null) ? $track['title'] : null,
                    'credit_source' => 'wavlake-index',
                    'qr_target' => $kind !== null ? $target : null,
                    'qr_kind' => $kind,
                    'wavlake_track_id' => is_string($track['track_id'] ?? null) ? $track['track_id'] : null,
                ], fn (mixed $value, string $key): bool => $value !== null || $key === 'title', ARRAY_FILTER_USE_BOTH);
            }
        }

        foreach ((array) (self::read('plebrap-index.json')['tracks'] ?? []) as $track) {
            if (is_array($track) && is_string($track['file'] ?? null) && is_array($track['artists'] ?? null) && is_string($track['artists'][0] ?? null)) {
                $indexed[$track['file']] = [
                    'artists' => array_values(array_map(fn (mixed $name): string => $artistId((string) $name), $track['artists'])),
                    'title' => is_string($track['title'] ?? null) ? $track['title'] : null,
                    'credit_source' => 'plebrap-index',
                ];
            }
        }

        $previousById = [];
        foreach ($previous as $old) {
            $previousById[(string) $old['id']] = $old;
        }

        $result = [];

        foreach ($tracks as $relative => $track) {
            $id = $ids[$relative];
            $old = $previousById[$id] ?? null;
            $credit = $indexed[$relative] ?? null;

            if ($credit === null && $old !== null && in_array($old['credit_source'] ?? null, ['wavlake-index', 'plebrap-index', 'manual', 'house'], true)) {
                $credit = array_intersect_key($old, array_flip(['artists', 'title', 'credit_source', 'qr_target', 'qr_kind', 'wavlake_track_id']));
            }

            $credit ??= ['artists' => [], 'title' => MusicPlaylist::title($relative), 'credit_source' => 'none'];
            $measured = array_diff_key($track, array_flip(['id', 'source', 'artists', 'title', 'credit_source', 'qr_target', 'qr_kind', 'wavlake_track_id']));
            $result[$relative] = [
                'id' => $id,
                'artists' => $credit['artists'],
                'title' => $credit['title'] ?? MusicPlaylist::title($relative),
                ...$measured,
                ...array_diff_key($credit, array_flip(['artists', 'title'])),
            ];
        }

        return [$result, $artists];
    }

    /** A track's zap route in the Wavlake index as a target kind (as MusicCredits read it before the catalog). */
    private static function kindOfRoute(string $route, string $target): ?string
    {
        return match (true) {
            $route === 'nostr-lud16' && str_contains($target, '@') => 'lud16',
            str_starts_with($target, 'https://njump.me/') => 'njump',
            str_starts_with($target, 'https://www.youtube.com/') || str_starts_with($target, 'https://youtube.com/') => 'youtube',
            str_starts_with($target, 'https://wavlake.com/') => 'wavlake',
            default => null,
        };
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function counts(array $values): string
    {
        $counts = array_count_values(array_map('strval', $values));
        ksort($counts);

        return implode(', ', array_map(fn (string $key, int $count): string => $key.' '.$count, array_keys($counts), $counts));
    }

    /**
     * A former index under resources/data/music, [] once it is gone.
     *
     * @return array<string, mixed>
     */
    private static function read(string $name): array
    {
        $path = resource_path('data/music/'.$name);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }
}
