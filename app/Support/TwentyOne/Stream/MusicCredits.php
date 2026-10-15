<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Who made the music on air and how to tip them (user 2026-10-15: the stream slides show the artist of the track that
 * plays, with their Nostr avatar and a QR code to send them sats). Three files under resources/data/music:
 *
 * - wavlake-index.json: the Wavlake tracks, file => artist and the zap route found for it;
 * - plebrap-index.json: the PlebRap tracks, file => artists in credit order (the first is named);
 * - artists.json: per artist (exactly as named in the indexes) the Nostr identity found: npub, picture, lud16, and
 *   the QR target with its kind. It wins over a track's own target.
 *
 * The QR target, in this order (user 2026-10-15: "wenn du zu einem Artist npub keine Lightning Adresse findest, dann
 * verlinke wenigstens zu seinem Nostr Profil mit njump oder Youtube Seite"): the lightning address (`lud16`), the
 * njump profile (`njump`), the YouTube channel (`youtube`), the Wavlake page (`wavlake`). A track without an artist
 * or without any target has no credit: the card stays off rather than show a guess.
 *
 * The house artist (user 2026-10-15: "alle instrumentals, die unbekannt sind von DJ Bitcoin und auch die vocals in
 * music, die noch keinen Artist haben … theben@getalby.com als Lightning Adresse"): every instrumental and every song
 * straight in the music folder that no index credits is HOUSE_ARTIST, tipped at HOUSE_LUD16.
 *
 * Every target has its code drawn in advance (`php artisan twentyone:music-qr`, qrencode) under
 * resources/stream/qr/music, as the stream's other codes are: the daemon never encodes at render time.
 */
final class MusicCredits
{
    public const KINDS = ['lud16', 'njump', 'youtube', 'wavlake'];

    public const HOUSE_ARTIST = 'DJ Bitcoin';

    public const HOUSE_LUD16 = 'theben@getalby.com';

    /** @var array<string, array{artist: string, title: string|null, target: string, kind: string, picture: string|null}>|null */
    private static ?array $byFile = null;

    /**
     * The credit of a music file (absolute, or relative to the music directory), null without one.
     *
     * @return array{artist: string, title: string|null, target: string, kind: string, picture: string|null}|null
     */
    public static function forFile(string $file): ?array
    {
        $relative = self::relative($file);

        return self::all()[$relative] ?? (self::isHouse($relative) ? self::house() : null);
    }

    /** An uncredited instrumental, or an uncredited song straight in the music folder: the house artist's. */
    public static function isHouse(string $relative): bool
    {
        return str_starts_with($relative, 'instrumental/') || ! str_contains($relative, '/');
    }

    /**
     * @return array{artist: string, title: string|null, target: string, kind: string, picture: string|null}
     */
    public static function house(): array
    {
        $identity = self::read('artists.json')[self::HOUSE_ARTIST] ?? null;
        $picture = is_array($identity) && is_string($identity['picture'] ?? null) && str_starts_with($identity['picture'], 'https://') ? $identity['picture'] : null;

        return ['artist' => self::HOUSE_ARTIST, 'title' => null, 'target' => self::HOUSE_LUD16, 'kind' => 'lud16', 'picture' => $picture];
    }

    /**
     * Every credited file, relative to the music directory.
     *
     * @return array<string, array{artist: string, title: string|null, target: string, kind: string, picture: string|null}>
     */
    public static function all(): array
    {
        if (self::$byFile !== null) {
            return self::$byFile;
        }

        $artists = self::read('artists.json');
        $credits = [];

        foreach ((array) (self::read('wavlake-index.json')['tracks'] ?? []) as $track) {
            if (! is_array($track) || ! is_string($track['file'] ?? null) || ! is_string($track['artist'] ?? null)) {
                continue;
            }

            $own = ['target' => $track['qr_target'] ?? null, 'kind' => self::kindOfRoute((string) ($track['zap_route'] ?? ''), (string) ($track['qr_target'] ?? ''))];
            $credit = self::credit($track['artist'], is_string($track['title'] ?? null) ? $track['title'] : null, $artists[$track['artist']] ?? null, $own);

            if ($credit !== null) {
                $credits[$track['file']] = $credit;
            }
        }

        foreach ((array) (self::read('plebrap-index.json')['tracks'] ?? []) as $track) {
            $first = $track['artists'][0] ?? null;

            if (! is_array($track) || ! is_string($track['file'] ?? null) || ! is_string($first)) {
                continue;
            }

            $credit = self::credit($first, is_string($track['title'] ?? null) ? $track['title'] : null, $artists[$first] ?? null, ['target' => null, 'kind' => null]);

            if ($credit !== null) {
                $credits[$track['file']] = $credit;
            }
        }

        return self::$byFile = $credits;
    }

    /** Forget what was read (tests, and a daemon that reloads the files). */
    public static function flush(): void
    {
        self::$byFile = null;
    }

    /**
     * What the code encodes: a lightning address as a `lightning:` URI (every wallet opens it, Nostr wallets zap the
     * address), anything else as the URL it is.
     */
    public static function qrPayload(string $target, string $kind): string
    {
        return $kind === 'lud16' ? 'lightning:'.$target : $target;
    }

    /** The drawn code of a target (`twentyone:music-qr` writes it). */
    public static function qrFile(string $target, string $kind): string
    {
        return resource_path('stream/qr/music/'.substr(sha1(self::qrPayload($target, $kind)), 0, 16).'.svg');
    }

    /**
     * One credit: the artist's own identity first, then the track's target; null without a usable target.
     *
     * @param  array<string, mixed>|null  $identity
     * @param  array{target: mixed, kind: string|null}  $own
     * @return array{artist: string, title: string|null, target: string, kind: string, picture: string|null}|null
     */
    private static function credit(string $artist, ?string $title, ?array $identity, array $own): ?array
    {
        [$target, $kind] = self::target($identity, $own);

        if ($target === null) {
            return null;
        }

        $picture = is_string($identity['picture'] ?? null) && str_starts_with($identity['picture'], 'https://') ? $identity['picture'] : null;

        return ['artist' => $artist, 'title' => $title, 'target' => $target, 'kind' => $kind, 'picture' => $picture];
    }

    /**
     * @param  array<string, mixed>|null  $identity
     * @param  array{target: mixed, kind: string|null}  $own
     * @return array{0: string|null, 1: string}
     */
    private static function target(?array $identity, array $own): array
    {
        $lud16 = $identity['lud16'] ?? null;

        if (is_string($lud16) && preg_match('/^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i', $lud16) === 1) {
            return [strtolower($lud16), 'lud16'];
        }

        $npub = $identity['npub'] ?? null;

        if (is_string($npub) && preg_match('/^npub1[02-9ac-hj-np-z]{58}$/', $npub) === 1) {
            return ['https://njump.me/'.$npub, 'njump'];
        }

        foreach (['youtube' => 'youtube', 'qr_target' => $identity['qr_kind'] ?? null] as $key => $kind) {
            $value = $identity[$key] ?? null;

            if (is_string($value) && is_string($kind) && in_array($kind, self::KINDS, true) && self::usable($value, $kind)) {
                return [$value, $kind];
            }
        }

        if (is_string($own['target']) && is_string($own['kind']) && self::usable($own['target'], $own['kind'])) {
            return [$own['target'], $own['kind']];
        }

        return [null, 'wavlake'];
    }

    /** A track's zap route in the Wavlake index as a target kind. */
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

    private static function usable(string $target, string $kind): bool
    {
        return $kind === 'lud16' ? str_contains($target, '@') : str_starts_with($target, 'https://');
    }

    private static function relative(string $file): string
    {
        $dir = rtrim((string) config('twentyone.stream.music.dir'), '/').'/';

        return str_starts_with($file, $dir) ? substr($file, strlen($dir)) : ltrim($file, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $name): array
    {
        $path = resource_path('data/music/'.$name);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }
}
