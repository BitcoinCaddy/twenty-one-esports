<?php

namespace App\Support\Caster;

/**
 * The server's copy of the Anchor's imaging library (plan "TWENTY ONE
 * Radio", P11), uploaded by the workstation (caster:imaging-upload) and
 * played by Imaging. Content-addressed and resumable: every jingle is stored
 * once under `files/<sha1>.opus` (POST api/caster/imaging/files/{hash}), then
 * the manifest names them (POST api/caster/imaging); a manifest whose files
 * are not all here is refused with the missing hashes, so the workstation
 * uploads exactly those and sends the manifest again.
 *
 * Bounded (tv.commentary.imaging): `max_items` per manifest, `max_file_bytes`
 * per file, `max_bytes` for the directory, `max_seconds` per item. A new
 * manifest replaces the old one atomically and deletes every file it no longer
 * names, so the directory never holds more than one library. Fails closed:
 * anything that is not an Ogg Opus file with the hash it claims is refused.
 */
final class ImagingStore
{
    /**
     * @param  array{dir?: string, max_items?: int, max_file_bytes?: int, max_bytes?: int, max_seconds?: float|int}  $settings
     */
    public function __construct(private readonly array $settings) {}

    public static function fromConfig(): self
    {
        /** @var array{dir?: string, max_items?: int, max_file_bytes?: int, max_bytes?: int, max_seconds?: float|int} $settings */
        $settings = (array) config('tv.commentary.imaging', []);

        return new self($settings);
    }

    public function dir(): string
    {
        return rtrim((string) ($this->settings['dir'] ?? storage_path('app/stream/imaging')), '/');
    }

    /**
     * Stores one jingle under its hash; an identical file already stored is kept.
     *
     * @throws InvalidClip when it is no Ogg Opus file, is too big or does not have the hash it claims
     * @throws SpoolFull when the library would outgrow `max_bytes`
     */
    public function storeFile(string $hash, string $opus): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $hash) !== 1 || ! hash_equals($hash, sha1($opus))) {
            throw new InvalidClip('hash');
        }

        if (! str_starts_with($opus, 'OggS') || ! str_contains(substr($opus, 0, 200), 'OpusHead')) {
            throw new InvalidClip('format');
        }

        if (strlen($opus) > (int) ($this->settings['max_file_bytes'] ?? 1_500_000)) {
            throw new InvalidClip('size');
        }

        $file = $this->dir().'/files/'.$hash.'.opus';

        if (is_file($file)) {
            return;
        }

        if ($this->bytes() + strlen($opus) > (int) ($this->settings['max_bytes'] ?? 120_000_000)) {
            throw new SpoolFull;
        }

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        $temporary = $file.'.part';
        file_put_contents($temporary, $opus, LOCK_EX);
        rename($temporary, $file);
    }

    /**
     * Takes a manifest: a list of the items it names that are not stored yet
     * (nothing written then), or [] once it replaced the library.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<string> the missing hashes
     *
     * @throws InvalidClip when an item is malformed, an id repeats or there are too many items
     */
    public function storeManifest(array $items): array
    {
        if ($items === [] || ! array_is_list($items) || count($items) > (int) ($this->settings['max_items'] ?? 600)) {
            throw new InvalidClip('items');
        }

        $clean = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)
                || ! is_string($item['id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $item['id']) !== 1
                || ! is_string($item['category'] ?? null) || preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $item['category']) !== 1
                || ! is_string($item['hash'] ?? null) || preg_match('/^[0-9a-f]{40}$/', $item['hash']) !== 1
                || ! is_numeric($item['seconds'] ?? null) || (float) $item['seconds'] <= 0 || (float) $item['seconds'] > (float) ($this->settings['max_seconds'] ?? 40)
                || isset($seen[$item['id']])) {
                throw new InvalidClip('item');
            }

            $seen[$item['id']] = true;
            $line = is_string($item['line'] ?? null) && preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $item['line']) === 1 ? $item['line'] : $item['id'];
            $clean[] = ['id' => $item['id'], 'line' => $line, 'category' => $item['category'], 'file' => 'files/'.$item['hash'].'.opus', 'seconds' => round((float) $item['seconds'], 3), 'hash' => $item['hash'],
                'text' => is_string($item['text'] ?? null) ? mb_substr($item['text'], 0, 300) : null];
        }

        $missing = array_values(array_unique(array_map(fn (array $item): string => $item['hash'], array_filter($clean, fn (array $item): bool => ! is_file($this->dir().'/'.$item['file'])))));

        if ($missing !== []) {
            return $missing;
        }

        $manifest = $this->dir().'/manifest.json';
        file_put_contents($manifest.'.part', (string) json_encode(['uploaded_at' => now()->toIso8601String(), 'items' => $clean], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($manifest.'.part', $manifest);

        $keep = array_flip(array_map(fn (array $item): string => $item['file'], $clean));

        foreach (glob($this->dir().'/files/*') ?: [] as $file) {
            if (! isset($keep['files/'.basename($file)])) {
                unlink($file);
            }
        }

        return [];
    }

    /** Bytes of all stored files. */
    public function bytes(): int
    {
        return (int) array_sum(array_map(fn (string $file): int => (int) filesize($file), glob($this->dir().'/files/*') ?: []));
    }
}
