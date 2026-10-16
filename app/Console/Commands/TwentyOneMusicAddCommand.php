<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\Stream\AudioProbe;
use App\Support\TwentyOne\Stream\Chromaprint;
use App\Support\TwentyOne\Stream\LoudnessNormalizer;
use App\Support\TwentyOne\Stream\MusicCatalog;
use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Adds a new song to the music library and the catalog (plan "Musik-Katalog", P3): refuses a file that cannot be read
 * as audio, a song the catalog already holds (Chromaprint, Chromaprint::SAME_RECORDING) and a banned artist
 * (twentyone.stream.music.banned_artists); brings it to the library's loudness (LoudnessNormalizer) into the source's
 * folder, enters it with its credit and draws the credit's QR code.
 *
 * The name stays the user's (user: never rename music files); only the ending becomes .m4a, and a name without a
 * version gets `__v1`, because the stream plays `*__v*.m4a` only (TwentyOneStreamCommand::musicFiles()). Without
 * --artist the song is entered with credit_source `none`: no card, never the house artist in silence. Commit
 * catalog.json, fingerprints.json and the new code afterwards.
 */
#[Signature('twentyone:music-add
    {file : The song to add (any format ffmpeg reads)}
    {--artist= : The artist, by name or catalog id; a new name is added to the catalog}
    {--title= : The title (default: the file name)}
    {--source=vocals : vocals, instrumental, plebrap or wavlake}
    {--npub= : A new artist\'s npub}
    {--lud16= : A new artist\'s lightning address}
    {--dir= : The music folder (default: twentyone.stream.music.dir)}')]
#[Description('Add a song to the music library: check, normalise to -15 LUFS / -1.5 dBTP, enter it in the catalog with its credit, draw the QR')]
class TwentyOneMusicAddCommand extends Command
{
    public function handle(AudioProbe $probe, LoudnessNormalizer $normalizer): int
    {
        $file = (string) $this->argument('file');
        $dir = rtrim((string) ($this->option('dir') ?: config('twentyone.stream.music.dir')), '/');
        $source = (string) $this->option('source');
        $artist = trim((string) $this->option('artist'));

        if (! in_array($source, MusicCatalog::SOURCES, true)) {
            return $this->refuse('Unknown source '.$source.' (vocals, instrumental, plebrap, wavlake).');
        }

        if ($dir === '' || ! is_dir($dir)) {
            return $this->refuse('No music folder at '.$dir);
        }

        if (! is_file($file) || ! is_readable($file)) {
            return $this->refuse('Cannot read '.$file);
        }

        foreach (array_filter(array_map('trim', explode(',', (string) config('twentyone.stream.music.banned_artists', '')))) as $banned) {
            if (str_contains(mb_strtolower(basename($file).' '.$artist), mb_strtolower($banned))) {
                return $this->refuse('Banned artist: '.$banned);
            }
        }

        $measured = $probe->measure(['in' => $file], loudness: false)['in'] ?? null;

        if ($measured === null) {
            return $this->refuse('Not readable as audio: '.$file);
        }

        MusicCredits::flush();
        $duplicate = self::duplicateOf($measured['fingerprint']);

        if ($duplicate !== null) {
            return $this->refuse(sprintf('Already in the library: %s (similarity %.3f)', $duplicate['file'], $duplicate['similarity']));
        }

        $name = self::libraryName($file);
        $relative = MusicCatalog::folderOf($source).$name;
        $target = $dir.'/'.$relative;

        if (file_exists($target)) {
            return $this->refuse('A file of that name exists already: '.$relative);
        }

        if (! is_dir(dirname($target))) {
            return $this->refuse('No folder for '.$source.' at '.dirname($target));
        }

        try {
            $loudness = $normalizer->normalize($file, $target);
        } catch (RuntimeException $e) {
            return $this->refuse($e->getMessage());
        }

        $result = $probe->measure(['out' => $target])['out'] ?? null;

        if ($result === null) {
            @unlink($target);

            return $this->refuse('The normalised file cannot be read back; nothing added.');
        }

        $tracks = MusicCatalog::tracks();
        $artists = MusicCatalog::artists();
        $prints = [];

        foreach ($tracks as $track) {
            $prints[(string) $track['id']] = MusicCatalog::fingerprints()[(string) $track['id']] ?? [];
        }

        $artistIds = [];

        if ($artist !== '') {
            $id = MusicCatalog::artistId($artist) ?? MusicCatalog::slug($artist);
            $artists[$id] ??= array_filter(['name' => $artist, 'npub' => $this->option('npub'), 'lud16' => $this->option('lud16')], fn (mixed $value): bool => is_string($value) && $value !== '');
            $artistIds = [$id];
        }

        $id = MusicCatalog::newId($relative, array_fill_keys(array_keys($prints), true));
        $tracks[$relative] = [
            'id' => $id,
            'source' => $source,
            'artists' => $artistIds,
            'title' => (string) ($this->option('title') ?: MusicPlaylist::title($name)),
            'duration' => $result['duration'],
            'bitrate' => $result['bitrate'],
            'lufs' => $result['lufs'],
            'true_peak' => $result['true_peak'],
            'fingerprint' => Chromaprint::hash($result['fingerprint']),
            'sha1' => (string) sha1_file($target),
            'size' => (int) filesize($target),
            'mtime' => (int) filemtime($target),
            'credit_source' => $artistIds === [] ? 'none' : 'manual',
        ];
        $prints[$id] = $result['fingerprint'];

        MusicCatalog::write($tracks, $artists, $prints);
        MusicCredits::flush();

        $this->info(sprintf('Added %s: %.1f LUFS, true peak %.1f dBTP (limiter %.1f dB), %.1f s.', $relative, $result['lufs'] ?? $loudness['lufs'], $result['true_peak'] ?? $loudness['true_peak'], $loudness['limit'], $result['duration']));

        $credit = MusicCredits::forFile($relative);

        if ($credit === null) {
            $without = count(array_filter(array_keys(MusicCatalog::tracks()), fn (string $file): bool => MusicCredits::forFile($file) === null));
            $this->warn(sprintf('No credit (no artist, or an artist without npub, lud16 or link): no card on air. %d tracks without credit in the catalog.', $without));

            return self::SUCCESS;
        }

        $error = TwentyOneMusicQrCommand::draw($credit['target'], $credit['kind']);

        if ($error !== null) {
            $this->error('The song is added, but its QR code failed: '.$error.' Run php artisan twentyone:music-qr.');

            return self::FAILURE;
        }

        $this->info(sprintf('Credit: %s, %s %s; QR %s.', $credit['artist'], $credit['kind'], $credit['target'], basename(MusicCredits::qrFile($credit['target'], $credit['kind']))));

        return self::SUCCESS;
    }

    /**
     * The library name of an upload: the user's name with the ending .m4a, `__v1` added when it carries no version.
     */
    public static function libraryName(string $file): string
    {
        $stem = pathinfo($file, PATHINFO_FILENAME);

        return (preg_match('/__v\d+$/', $stem) === 1 ? $stem : $stem.'__v1').'.m4a';
    }

    /**
     * The catalog track the fingerprint matches, null when none does.
     *
     * @param  list<int>  $fingerprint
     * @return array{file: string, similarity: float}|null
     */
    private static function duplicateOf(array $fingerprint): ?array
    {
        $files = [];
        $prints = ["\0new" => $fingerprint];

        foreach (MusicCatalog::tracks() as $file => $track) {
            $items = MusicCatalog::fingerprints()[(string) $track['id']] ?? null;

            if ($items !== null) {
                $prints[(string) $track['id']] = $items;
                $files[(string) $track['id']] = $file;
            }
        }

        $best = null;

        foreach (Chromaprint::candidates($prints, 4) as $candidate) {
            if ($candidate[0] !== "\0new" && $candidate[1] !== "\0new") {
                continue;
            }

            $other = $candidate[0] === "\0new" ? $candidate[1] : $candidate[0];
            $match = Chromaprint::compare($prints[$candidate[0]], $prints[$candidate[1]], 3, $candidate['offset']);

            if ($match['similarity'] >= Chromaprint::SAME_RECORDING && ($best === null || $match['similarity'] > $best['similarity'])) {
                $best = ['file' => $files[$other], 'similarity' => $match['similarity']];
            }
        }

        return $best;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
