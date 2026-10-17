<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Who made the music on air and how to tip them (user 2026-10-15: the stream slides show the artist of the track that
 * plays, with their Nostr avatar and a QR code to send them sats). Read from the music catalog (MusicCatalog,
 * resources/data/music/catalog.json): per track its artists in credit order (the first is named) and where the credit
 * comes from, per artist the Nostr identity found: npub, picture, lud16, and the QR target with its kind. The artist's
 * own identity wins over a track's own target (a Wavlake track's page).
 *
 * The QR target, in this order (user 2026-10-15: "wenn du zu einem Artist npub keine Lightning Adresse findest, dann
 * verlinke wenigstens zu seinem Nostr Profil mit njump oder Youtube Seite"): the lightning address (`lud16`), the
 * njump profile (`njump`), the YouTube channel (`youtube`), the Wavlake page (`wavlake`). A track without an artist
 * or without any target has no credit: the card stays off rather than show a guess.
 *
 * The house artist (user 2026-10-15: "alle instrumentals, die unbekannt sind von DJ Bitcoin und auch die vocals in
 * music, die noch keinen Artist haben … theben@getalby.com als Lightning Adresse"): the catalog names HOUSE_ARTIST
 * for each such track with credit_source `house`, tipped at HOUSE_LUD16; where a file lies decides nothing (user
 * 2026-10-16: "Es im Katalog nur noch um den Artists"). A track the catalog marks `none` (added without a credit)
 * stays without one, never the house artist in silence.
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
        return self::all()[self::relative($file)] ?? null;
    }

    /**
     * @return array{artist: string, title: string|null, target: string, kind: string, picture: string|null}
     */
    public static function house(): array
    {
        $identity = MusicCatalog::artists()[MusicCatalog::slug(self::HOUSE_ARTIST)] ?? null;
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

        $artists = MusicCatalog::artists();
        $credits = [];

        foreach (MusicCatalog::tracks() as $file => $track) {
            $first = $track['artists'][0] ?? null;

            if (($track['credit_source'] ?? null) === 'house') {
                $credits[$file] = self::house();

                continue;
            }

            if (! in_array($track['credit_source'] ?? null, ['wavlake-index', 'plebrap-index', 'manual'], true) || ! is_string($first)) {
                continue;
            }

            $own = ['target' => $track['qr_target'] ?? null, 'kind' => is_string($track['qr_kind'] ?? null) ? $track['qr_kind'] : null];
            $identity = is_array($artists[$first] ?? null) ? $artists[$first] : null;
            $credit = self::credit((string) ($identity['name'] ?? $first), is_string($track['title'] ?? null) ? $track['title'] : null, $identity, $own);

            if ($credit !== null) {
                $credits[$file] = $credit;
            }
        }

        return self::$byFile = $credits;
    }

    /**
     * The playlist a file belongs to, null for none (`twentyone.stream.music.playlists`): artists merged into one
     * artist of the cycle (user 2026-10-17: "PlebRap als einen Künstler ansehen und alle Tracks davon als Künstler
     * rotieren"), by the track's credit source or by any of its credited artists. The first playlist that takes the
     * track wins.
     */
    public static function playlist(string $file): ?string
    {
        $track = MusicCatalog::track(self::relative($file));

        if ($track === null) {
            return null;
        }

        foreach ((array) config('twentyone.stream.music.playlists', []) as $name => $rule) {
            $artists = array_map(fn ($artist): ?string => MusicCatalog::artistId((string) $artist), (array) ($rule['artists'] ?? []));

            if ((isset($rule['credit_source']) && ($track['credit_source'] ?? null) === $rule['credit_source'])
                || array_intersect(array_filter($artists), (array) ($track['artists'] ?? [])) !== []) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * The credit a new track of this artist would get from the artist's own identity (the nightly Wavlake sync adds
     * tracks under the artists it lists), null without a usable target.
     *
     * @return array{artist: string, title: string|null, target: string, kind: string, picture: string|null}|null
     */
    public static function forArtist(string $name): ?array
    {
        $id = MusicCatalog::artistId($name);
        $identity = $id === null ? null : (MusicCatalog::artists()[$id] ?? null);

        return is_array($identity) ? self::credit((string) ($identity['name'] ?? $name), null, $identity, ['target' => null, 'kind' => null]) : null;
    }

    /** Forget what was read (tests, and a daemon that reloads the files). */
    public static function flush(): void
    {
        self::$byFile = null;
        MusicCatalog::flush();
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

    private static function usable(string $target, string $kind): bool
    {
        return $kind === 'lud16' ? str_contains($target, '@') : str_starts_with($target, 'https://');
    }

    private static function relative(string $file): string
    {
        $dir = rtrim((string) config('twentyone.stream.music.dir'), '/').'/';

        return str_starts_with($file, $dir) ? substr($file, strlen($dir)) : ltrim($file, '/');
    }
}
