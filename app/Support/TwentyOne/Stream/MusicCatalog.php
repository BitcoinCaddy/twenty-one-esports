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
 * the music folder, the `artists` (ids, in credit order), `title`, `duration`, `bitrate` (kb/s), `lufs`, `true_peak`
 * (dBTP), the Chromaprint `fingerprint` hash (the items live in fingerprints.json beside it), `sha1` of the bytes,
 * `size` and `mtime` (a file with both unchanged is not measured again) and `credit_source`: where the credit comes
 * from (wavlake-index, plebrap-index, manual: added with an artist; house: the house artist DJ Bitcoin; none: no
 * credit, never the house artist in silence). A Wavlake track also carries the target the index found for it
 * (`qr_target`, `qr_kind`), used when its artist has none. The catalog knows artists only (user 2026-10-16: "Es im
 * Katalog nur noch um den Artists"): no genre or source, and the folder a file lies in decides nothing.
 *
 * An artist, keyed by id (slug of the name): `name` and every field of the former artists.json (npub, pubkey, lud16,
 * picture, qr_target, qr_kind, youtube, evidence, confidence).
 *
 * File names stay as the user uploaded them (user: never rename music files): the catalog points at files, it
 * neither renames nor moves anything.
 *
 * The overlay (plan "Wavlake-Sync", user 2026-10-17: new tracks of our artists come every night on the server): a
 * second catalog of the same shape under storage (`twentyone.stream.music.overlay`, empty = none) that only
 * `twentyone:music-wavlake-sync` writes (writeOverlay()). Every reader sees both; a file or artist of the repo
 * catalog wins over the overlay's. write() never carries an overlay entry into the repo catalog, so a deploy and the
 * curated catalog stay apart from what the night brings.
 */
final class MusicCatalog
{
    public const CREDIT_SOURCES = ['wavlake-index', 'plebrap-index', 'manual', 'house', 'none'];

    /** @var array{tracks: array<string, array<string, mixed>>, artists: array<string, array<string, mixed>>}|null */
    private static ?array $data = null;

    /** @var array<string, list<int>>|null */
    private static ?array $fingerprints = null;

    /** @var array{tracks: array<string, array<string, mixed>>, artists: array<string, array<string, mixed>>}|null */
    private static ?array $overlay = null;

    public static function path(): string
    {
        return (string) config('twentyone.stream.music.catalog', resource_path('data/music/catalog.json'));
    }

    public static function fingerprintsPath(): string
    {
        return dirname(self::path()).'/fingerprints.json';
    }

    /** The overlay catalog, null when none is configured. */
    public static function overlayPath(): ?string
    {
        $path = (string) config('twentyone.stream.music.overlay', '');

        return $path === '' ? null : $path;
    }

    /**
     * The overlay's own tracks and artists (writeOverlay() takes them back with what it adds).
     *
     * @return array{tracks: array<string, array<string, mixed>>, artists: array<string, array<string, mixed>>}
     */
    public static function overlay(): array
    {
        $path = self::overlayPath();

        return self::$overlay ??= $path === null ? ['tracks' => [], 'artists' => []] : self::read($path);
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

        $prints = [];
        $overlay = self::overlayPath();

        foreach ([self::fingerprintsPath(), ...($overlay === null ? [] : [dirname($overlay).'/fingerprints.json'])] as $path) {
            $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            foreach (is_array($data) ? (array) ($data['fingerprints'] ?? []) : [] as $id => $encoded) {
                if (! isset($prints[(string) $id]) && is_string($encoded) && ($items = Chromaprint::decode($encoded)) !== []) {
                    $prints[(string) $id] = $items;
                }
            }
        }

        return self::$fingerprints = $prints;
    }

    /** Forget what was read (tests, commands that write the catalog). */
    public static function flush(): void
    {
        self::$data = null;
        self::$fingerprints = null;
        self::$overlay = null;
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
        // What the readers merged in from the overlay stays there.
        $overlay = self::overlay();
        $base = self::read(self::path());
        $tracks = array_diff_key($tracks, array_diff_key($overlay['tracks'], $base['tracks']));
        $artists = array_diff_key($artists, array_diff_key($overlay['artists'], $base['artists']));

        self::writeTo(self::path(), self::fingerprintsPath(), $tracks, $artists, $fingerprints);
    }

    /**
     * Writes the overlay (twentyone:music-wavlake-sync, through twentyone:music-add --overlay): its own tracks and
     * artists only, never one the repo catalog holds.
     *
     * @param  array<string, array<string, mixed>>  $tracks  by file
     * @param  array<string, array<string, mixed>>  $artists  by id
     * @param  array<string, list<int>>  $fingerprints  by track id
     */
    public static function writeOverlay(array $tracks, array $artists, array $fingerprints): void
    {
        $path = self::overlayPath() ?? throw new RuntimeException('No overlay catalog configured (twentyone.stream.music.overlay).');
        $base = self::read(self::path());

        self::writeTo($path, dirname($path).'/fingerprints.json', array_diff_key($tracks, $base['tracks']), array_diff_key($artists, $base['artists']), $fingerprints);
    }

    /**
     * @param  array<string, array<string, mixed>>  $tracks  by file
     * @param  array<string, array<string, mixed>>  $artists  by id
     * @param  array<string, list<int>>  $fingerprints  by track id
     */
    private static function writeTo(string $path, string $fingerprintsPath, array $tracks, array $artists, array $fingerprints): void
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

        self::put($path, (string) json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");

        // One line per track keeps a diff to the tracks that changed.
        $lines = array_map(fn (string $id, array $items): string => '    '.json_encode($id).': "'.Chromaprint::encode($items).'"', array_keys($fingerprints), $fingerprints);
        self::put($fingerprintsPath, "{\n  \"_readme\": \"Chromaprint fingerprints (fpcalc -raw, first 120 s) per track id, little-endian uint32, base64.\",\n  \"fingerprints\": {\n".implode(",\n", $lines)."\n  }\n}\n");

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

        $base = self::read(self::path());
        $overlay = self::overlay();

        return self::$data = ['tracks' => $base['tracks'] + $overlay['tracks'], 'artists' => $base['artists'] + $overlay['artists']];
    }

    /**
     * One catalog file: tracks by file, artists by id; empty when the file is missing or unreadable.
     *
     * @return array{tracks: array<string, array<string, mixed>>, artists: array<string, array<string, mixed>>}
     */
    private static function read(string $path): array
    {
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
        return ['tracks' => $tracks, 'artists' => $artists];
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
