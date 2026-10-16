<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The music catalog (plan "Musik-Katalog", user 2026-10-16: "Katalog als JSON im Repo"): one entry per track and per
 * artist in resources/data/music/catalog.json, the source the credits (MusicCredits), the now-playing card and the QR
 * codes read. `php artisan twentyone:music-catalog` writes it from the music folder, `twentyone:music-add` adds a song.
 *
 * A track: a stable `id` (kept across runs by file, a moved file keeps it over its fingerprint), its `file` relative to
 * the music folder, the `artists` (ids, in credit order), `title`, `source` (vocals, instrumental, plebrap, wavlake:
 * the folder), `duration`, `bitrate` (kb/s), `lufs`, `true_peak` (dBTP), the Chromaprint `fingerprint` hash (the items
 * live in fingerprints.json beside it), `sha1` of the bytes, `size` and `mtime` (a file with both unchanged is not
 * measured again) and `credit_source`: where the credit comes from (wavlake-index, plebrap-index, manual: added with
 * an artist; house: the house rule; none: no credit, never the house artist in silence). A Wavlake track also carries
 * the target the index found for it (`qr_target`, `qr_kind`), used when its artist has none.
 *
 * An artist, keyed by id (slug of the name): `name` and every field of the former artists.json (npub, pubkey, lud16,
 * picture, qr_target, qr_kind, youtube, evidence, confidence).
 *
 * File names stay as the user uploaded them (user: never rename music files): the catalog points at files, it
 * neither renames nor moves anything.
 */
final class MusicCatalog
{
    public const SOURCES = ['vocals', 'instrumental', 'plebrap', 'wavlake'];

    public const CREDIT_SOURCES = ['wavlake-index', 'plebrap-index', 'manual', 'house', 'none'];

    /** @var array{tracks: array<string, array<string, mixed>>, artists: array<string, array<string, mixed>>}|null */
    private static ?array $data = null;

    /** @var array<string, list<int>>|null */
    private static ?array $fingerprints = null;

    public static function path(): string
    {
        return (string) config('twentyone.stream.music.catalog', resource_path('data/music/catalog.json'));
    }

    public static function fingerprintsPath(): string
    {
        return dirname(self::path()).'/fingerprints.json';
    }

    /**
     * Every track, keyed by its file relative to the music folder.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tracks(): array
    {
        return self::data()['tracks'];
    }

    /**
     * Every artist, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function artists(): array
    {
        return self::data()['artists'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function track(string $relative): ?array
    {
        return self::tracks()[$relative] ?? null;
    }

    /** The id of the artist with this name (or id), null when the catalog has none. */
    public static function artistId(string $nameOrId): ?string
    {
        $artists = self::artists();

        if (isset($artists[$nameOrId])) {
            return $nameOrId;
        }

        foreach ($artists as $id => $artist) {
            if (strcasecmp((string) ($artist['name'] ?? ''), trim($nameOrId)) === 0) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * The fingerprint items per track id.
     *
     * @return array<string, list<int>>
     */
    public static function fingerprints(): array
    {
        if (self::$fingerprints !== null) {
            return self::$fingerprints;
        }

        $path = self::fingerprintsPath();
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $prints = [];

        foreach (is_array($data) ? (array) ($data['fingerprints'] ?? []) : [] as $id => $encoded) {
            if (is_string($encoded) && ($items = Chromaprint::decode($encoded)) !== []) {
                $prints[(string) $id] = $items;
            }
        }

        return self::$fingerprints = $prints;
    }

    /** Forget what was read (tests, commands that write the catalog). */
    public static function flush(): void
    {
        self::$data = null;
        self::$fingerprints = null;
    }

    /** The source a file belongs to by its folder, null outside the four (disabled/, anything else). */
    public static function sourceOf(string $relative): ?string
    {
        if (! str_contains($relative, '/')) {
            return 'vocals';
        }

        $folder = explode('/', $relative, 2)[0];

        return in_array($folder, ['instrumental', 'plebrap', 'wavlake'], true) && substr_count($relative, '/') === 1 ? $folder : null;
    }

    /** The folder (relative to the music folder) a source's files live in, '' for the vocals. */
    public static function folderOf(string $source): string
    {
        return $source === 'vocals' ? '' : $source.'/';
    }

    public static function slug(string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : substr(sha1($name), 0, 12);
    }

    /**
     * A new track id: stable for the file it was first seen as, unique in the catalog.
     *
     * @param  array<string, true>  $taken
     */
    public static function newId(string $relative, array $taken): string
    {
        for ($salt = 0; ; $salt++) {
            $id = substr(sha1($relative.($salt === 0 ? '' : "\0".$salt)), 0, 12);

            if (! isset($taken[$id])) {
                return $id;
            }
        }
    }

    /**
     * Writes the catalog and the fingerprints, each atomically (a temp file renamed over the old one), in a stable
     * order: tracks by file, artists and fingerprints by id.
     *
     * @param  array<string, array<string, mixed>>  $tracks  by file
     * @param  array<string, array<string, mixed>>  $artists  by id
     * @param  array<string, list<int>>  $fingerprints  by track id
     */
    public static function write(array $tracks, array $artists, array $fingerprints): void
    {
        ksort($tracks, SORT_STRING);
        ksort($artists, SORT_STRING);
        $ids = array_flip(array_map(fn (array $track): string => (string) $track['id'], $tracks));
        $fingerprints = array_intersect_key($fingerprints, $ids);
        ksort($fingerprints, SORT_STRING);

        $catalog = [
            '_readme' => 'Music catalog of the stream (MusicCatalog): tracks by file relative to the music folder, artists by id. Written by php artisan twentyone:music-catalog and twentyone:music-add; commit it after a change. Fingerprint items in fingerprints.json.',
            'tracks' => array_map(fn (string $file, array $track): array => ['file' => $file, ...$track], array_keys($tracks), $tracks),
            'artists' => $artists,
        ];

        self::put(self::path(), (string) json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");

        // One line per track keeps a diff to the tracks that changed.
        $lines = array_map(fn (string $id, array $items): string => '    '.json_encode($id).': "'.Chromaprint::encode($items).'"', array_keys($fingerprints), $fingerprints);
        self::put(self::fingerprintsPath(), "{\n  \"_readme\": \"Chromaprint fingerprints (fpcalc -raw, first 120 s) per track id, little-endian uint32, base64.\",\n  \"fingerprints\": {\n".implode(",\n", $lines)."\n  }\n}\n");

        self::flush();
    }

    /**
     * @return array{tracks: array<string, array<string, mixed>>, artists: array<string, array<string, mixed>>}
     */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = self::path();
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $tracks = [];

        foreach (is_array($data) ? (array) ($data['tracks'] ?? []) : [] as $track) {
            if (is_array($track) && is_string($track['file'] ?? null) && is_string($track['id'] ?? null)) {
                $file = $track['file'];
                unset($track['file']);
                $tracks[$file] = $track;
            }
        }

        $artists = array_filter(is_array($data) ? (array) ($data['artists'] ?? []) : [], 'is_array');

        /** @var array<string, array<string, mixed>> $artists */
        return self::$data = ['tracks' => $tracks, 'artists' => $artists];
    }

    private static function put(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temp = $path.'.tmp-'.getmypid();

        if (file_put_contents($temp, $contents) === false || ! rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException('Could not write '.$path);
        }
    }
}
