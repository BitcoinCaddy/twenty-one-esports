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
 * Finds the duplicates of the music library (plan "Musik-Katalog", user 2026-10-16: "Duplikate automatisch
 * aussortieren … Die andere wandert nach disabled/, gelöscht wird nichts"): the same recording under another name or in
 * another folder (Chromaprint similarity at or above Chromaprint::SAME_RECORDING, across all four sources), and the
 * same normalised title by the same artist (reported for a look, not moved).
 *
 * Per cluster one keeper: the credited copy over the house artist's over an uncredited one, then the higher bitrate
 * (in steps of 16 kb/s), then the longer (whole seconds), then the plain name over a "- single" or "(set 2)" variant.
 * The others get an `mv` line into disabled/ under the same relative name. The command itself moves and deletes
 * nothing; the user runs the lines inside the music folder.
 *
 * Files already under disabled/ are fingerprinted too: a library file that matches one is reported as already moved
 * (e.g. the PlebRap songs once copied under instrumental/ as 92__v1 … 129__v1), never as a keeper.
 */
#[Signature('twentyone:music-duplicates
    {--dir= : The music folder (default: twentyone.stream.music.dir)}
    {--json= : Write the report as JSON to this file}
    {--mv= : Write the mv lines as a shell script to this file}')]
#[Description('Report duplicate songs (Chromaprint, same title and artist) and print the mv lines that move the losers into disabled/')]
class TwentyOneMusicDuplicatesCommand extends Command
{
    public function handle(AudioProbe $probe): int
    {
        // The vote table over ~420 fingerprints outgrows the CLI default of 128 MB (measured 2026-10-16 at 422 files).
        if ((int) ini_get('memory_limit') !== -1 && (int) ini_get('memory_limit') < 1024) {
            ini_set('memory_limit', '1G');
        }

        $dir = rtrim((string) ($this->option('dir') ?: config('twentyone.stream.music.dir')), '/');

        if ($dir === '' || ! is_dir($dir)) {
            $this->error('No music folder at '.$dir);

            return self::FAILURE;
        }

        MusicCredits::flush();
        $files = [...TwentyOneMusicCatalogCommand::scan($dir), ...self::disabled($dir)];
        [$meta, $prints] = $this->fingerprints($files, $probe);

        $pairs = [];
        $scores = [];

        foreach (Chromaprint::candidates($prints, 4) as $candidate) {
            [$a, $b] = [$candidate[0], $candidate[1]];
            $match = Chromaprint::compare($prints[$a], $prints[$b], 3, $candidate['offset']);
            $scores[] = $match['similarity'];

            if ($match['similarity'] >= Chromaprint::SAME_RECORDING) {
                $pairs[] = ['a' => $a, 'b' => $b, 'similarity' => round($match['similarity'], 3), 'offset' => $match['offset']];
            }
        }

        $report = [
            'dir' => $dir,
            'threshold' => Chromaprint::SAME_RECORDING,
            'files' => count(array_filter($meta, fn (array $m): bool => ! $m['disabled'])),
            'disabled_files' => count(array_filter($meta, fn (array $m): bool => $m['disabled'])),
            'scored_pairs' => count($scores),
            'histogram' => self::histogram($scores),
            'clusters' => [],
            'already_moved' => [],
            'title_matches' => [],
            'mv' => [],
        ];

        foreach (self::clusters($pairs) as $members) {
            $active = array_values(array_filter($members, fn (string $file): bool => ! $meta[$file]['disabled']));
            $disabled = array_values(array_diff($members, $active));

            foreach ($disabled as $gone) {
                foreach ($active as $file) {
                    $report['already_moved'][] = ['file' => $file, 'disabled' => $gone, 'similarity' => self::similarity($pairs, $prints, $file, $gone)];
                }
            }

            if (count($active) < 2) {
                continue;
            }

            usort($active, fn (string $x, string $y): int => self::rank($y, $meta[$y]) <=> self::rank($x, $meta[$x]) ?: strcmp($x, $y));
            $keeper = array_shift($active);
            $report['clusters'][] = [
                'keeper' => self::describe($keeper, $meta[$keeper]),
                'losers' => array_map(fn (string $loser): array => [...self::describe($loser, $meta[$loser]), 'similarity' => self::similarity($pairs, $prints, $keeper, $loser)], $active),
            ];

            foreach ($active as $loser) {
                $target = 'disabled/'.$loser;
                $report['mv'][] = (dirname($target) !== 'disabled' ? 'mkdir -p '.escapeshellarg(dirname($target)).' && ' : '')
                    .'mv -n -- '.escapeshellarg($loser).' '.escapeshellarg($target);
            }
        }

        $report['title_matches'] = self::titleMatches($meta, $pairs, $prints);
        $this->print($report);

        if (is_string($this->option('json')) && $this->option('json') !== '') {
            file_put_contents($this->option('json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        if (is_string($this->option('mv')) && $this->option('mv') !== '') {
            file_put_contents($this->option('mv'), "#!/bin/sh\n# Run inside the music folder. Moves the duplicate losers into disabled/; deletes nothing.\nset -e\n"
                .implode("\n", $report['mv']).($report['mv'] === [] ? '' : "\n"));
        }

        return self::SUCCESS;
    }

    /**
     * The .m4a files under disabled/ (any depth), by their path relative to the music folder.
     *
     * @return array<string, string>
     */
    private static function disabled(string $dir): array
    {
        if (! is_dir($dir.'/disabled')) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir.'/disabled', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'm4a') {
                $files[substr($file->getPathname(), strlen($dir) + 1)] = $file->getPathname();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Per file what the keeper rule weighs, and its fingerprint: from the catalog where its size and mtime match,
     * measured (without loudness) otherwise.
     *
     * @param  array<string, string>  $files
     * @return array{0: array<string, array{disabled: bool, credit: int, credit_source: string, artist: string|null, title: string, bitrate: int, duration: float}>, 1: array<string, list<int>>}
     */
    private function fingerprints(array $files, AudioProbe $probe): array
    {
        $catalog = MusicCatalog::tracks();
        $stored = MusicCatalog::fingerprints();
        $meta = [];
        $prints = [];
        $toMeasure = [];

        foreach ($files as $relative => $path) {
            $track = $catalog[$relative] ?? null;

            if ($track !== null && ($track['size'] ?? null) === filesize($path) && ($track['mtime'] ?? null) === filemtime($path) && isset($stored[(string) $track['id']])) {
                $prints[$relative] = $stored[(string) $track['id']];
                $meta[$relative] = self::meta($relative, $track, (int) ($track['bitrate'] ?? 0), (float) ($track['duration'] ?? 0));

                continue;
            }

            $toMeasure[$relative] = $path;
        }

        if ($toMeasure !== []) {
            $this->line(sprintf('Fingerprinting %d files the catalog does not cover…', count($toMeasure)));
        }

        foreach ($probe->measure($toMeasure, loudness: false) as $relative => $measured) {
            if ($measured === null) {
                $this->warn('Unreadable, left out: '.$relative);

                continue;
            }

            $prints[$relative] = $measured['fingerprint'];
            $meta[$relative] = self::meta($relative, $catalog[$relative] ?? null, $measured['bitrate'], $measured['duration']);
        }

        return [$meta, $prints];
    }

    /**
     * @param  array<string, mixed>|null  $track
     * @return array{disabled: bool, credit: int, credit_source: string, artist: string|null, title: string, bitrate: int, duration: float}
     */
    private static function meta(string $relative, ?array $track, int $bitrate, float $duration): array
    {
        $disabled = str_starts_with($relative, 'disabled/');
        $source = is_string($track['credit_source'] ?? null) ? $track['credit_source'] : null;
        $credited = isset(MusicCredits::all()[$relative]);
        $house = ! $credited && ! $disabled && MusicCredits::forFile($relative) !== null;
        $first = $track['artists'][0] ?? null;

        return [
            'disabled' => $disabled,
            // Credited 2, the house artist's 1, none 0.
            'credit' => $credited ? 2 : ($house ? 1 : 0),
            'credit_source' => $source ?? ($credited ? 'credited' : ($house ? 'house' : 'none')),
            'artist' => is_string($first) ? $first : null,
            'title' => is_string($track['title'] ?? null) ? $track['title'] : MusicPlaylist::title($relative),
            'bitrate' => $bitrate,
            'duration' => $duration,
        ];
    }

    /**
     * What the keeper rule sorts by, larger first: credit, bitrate in steps of 16 kb/s, whole seconds, the plain name.
     *
     * @param  array{credit: int, bitrate: int, duration: float}  $meta
     * @return list<int>
     */
    private static function rank(string $file, array $meta): array
    {
        return [$meta['credit'], intdiv($meta['bitrate'] + 8, 16), (int) round($meta['duration']), self::isVariant($file) ? 0 : 1];
    }

    /** A "- single", "(set 2)" or "(single)" variant of a name. */
    private static function isVariant(string $file): bool
    {
        return preg_match('/\s-\s*single\b|\(\s*set\s*\d+\s*\)|\(\s*single\s*\)/i', basename($file)) === 1;
    }

    /**
     * @param  array{credit_source: string, bitrate: int, duration: float}  $meta
     * @return array{file: string, credit_source: string, bitrate: int, duration: float}
     */
    private static function describe(string $file, array $meta): array
    {
        return ['file' => $file, 'credit_source' => $meta['credit_source'], 'bitrate' => $meta['bitrate'], 'duration' => $meta['duration']];
    }

    /**
     * Connected groups of the matching pairs.
     *
     * @param  list<array{a: string, b: string, similarity: float, offset: int}>  $pairs
     * @return list<list<string>>
     */
    private static function clusters(array $pairs): array
    {
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };

        foreach ($pairs as $pair) {
            $parent[$find($pair['a'])] = $find($pair['b']);
        }

        $groups = [];
        foreach (array_keys($parent) as $file) {
            $groups[$find((string) $file)][] = (string) $file;
        }

        $groups = array_map(function (array $members): array {
            sort($members, SORT_STRING);

            return $members;
        }, array_values($groups));
        usort($groups, fn (array $x, array $y): int => strcmp($x[0], $y[0]));

        return $groups;
    }

    /**
     * @param  list<array{a: string, b: string, similarity: float, offset: int}>  $pairs
     * @param  array<string, list<int>>  $prints
     */
    private static function similarity(array $pairs, array $prints, string $x, string $y): float
    {
        foreach ($pairs as $pair) {
            if (($pair['a'] === $x && $pair['b'] === $y) || ($pair['a'] === $y && $pair['b'] === $x)) {
                return $pair['similarity'];
            }
        }

        return round(Chromaprint::compare($prints[$x], $prints[$y])['similarity'], 3);
    }

    /**
     * Same normalised title by the same artist in the library (not under disabled/), with their similarity: a pair
     * below the threshold is a different recording (a live set, an edit) for the user to judge.
     *
     * @param  array<string, array{disabled: bool, artist: string|null, title: string}>  $meta
     * @param  list<array{a: string, b: string, similarity: float, offset: int}>  $pairs
     * @param  array<string, list<int>>  $prints
     * @return list<array{files: list<string>, title: string, artist: string, similarity: float, same_recording: bool}>
     */
    private static function titleMatches(array $meta, array $pairs, array $prints): array
    {
        $groups = [];

        foreach ($meta as $file => $m) {
            if (! $m['disabled'] && $m['artist'] !== null) {
                $groups[$m['artist']."\0".self::normalTitle($m['title'], $m['artist'])][] = $file;
            }
        }

        $matches = [];

        foreach ($groups as $key => $files) {
            if (count($files) < 2) {
                continue;
            }

            [$artist, $title] = explode("\0", $key, 2);

            // Numbered instrumentals carry no title; versions of one title (`<title>__v2`) are the stream's own takes.
            if ($title === '' || ctype_digit(str_replace(' ', '', $title)) || count(array_unique(array_map(fn (string $file): string => MusicPlaylist::title($file), $files))) === 1) {
                continue;
            }

            sort($files, SORT_STRING);
            $similarity = self::similarity($pairs, $prints, $files[0], $files[1]);
            $matches[] = ['files' => $files, 'title' => $title, 'artist' => $artist, 'similarity' => $similarity, 'same_recording' => $similarity >= Chromaprint::SAME_RECORDING];
        }

        return $matches;
    }

    private static function normalTitle(string $title, string $artist): string
    {
        $title = mb_strtolower($title);
        $title = preg_replace('/\s-\s*single\b|\(\s*set\s*\d+\s*\)|\(\s*single\s*\)/u', ' ', $title) ?? $title;
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
        // A vocal's stem carries its artist ("Amelia - Joe Martin"): the slug's words at the end go.
        $tail = ' '.str_replace('-', ' ', $artist);
        $title = trim($title);

        return str_ends_with(' '.$title, $tail) ? trim(substr(' '.$title, 0, -strlen($tail))) : $title;
    }

    /**
     * Pair counts per similarity band of 0.05.
     *
     * @param  list<float>  $scores
     * @return array<string, int>
     */
    private static function histogram(array $scores): array
    {
        $bands = [];

        foreach ($scores as $score) {
            $low = floor($score * 20) / 20;
            $band = sprintf('%.2f-%.2f', $low, $low + 0.05);
            $bands[$band] = ($bands[$band] ?? 0) + 1;
        }

        ksort($bands);

        return $bands;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function print(array $report): void
    {
        $this->info(sprintf('%d library files, %d under disabled/; %d candidate pairs scored, threshold %.2f.',
            $report['files'], $report['disabled_files'], $report['scored_pairs'], $report['threshold']));

        foreach ((array) $report['histogram'] as $band => $count) {
            $this->line(sprintf('  similarity %s: %d pairs', $band, $count));
        }

        $this->newLine();
        $this->info(sprintf('%d clusters, %d files to move:', count((array) $report['clusters']), count((array) $report['mv'])));

        foreach ((array) $report['clusters'] as $cluster) {
            $this->line('  keep  '.$cluster['keeper']['file'].sprintf(' (%s, %d kb/s, %.1f s)', $cluster['keeper']['credit_source'], $cluster['keeper']['bitrate'], $cluster['keeper']['duration']));

            foreach ($cluster['losers'] as $loser) {
                $this->line('  move  '.$loser['file'].sprintf(' (%s, %d kb/s, %.1f s, similarity %.3f)', $loser['credit_source'], $loser['bitrate'], $loser['duration'], $loser['similarity']));
            }
        }

        $this->newLine();
        $this->info(sprintf('%d library files match a file already under disabled/:', count((array) $report['already_moved'])));

        foreach ((array) $report['already_moved'] as $moved) {
            $this->line(sprintf('  %s = %s (%.3f)', $moved['file'], $moved['disabled'], $moved['similarity']));
        }

        $this->newLine();
        $this->info(sprintf('%d same title by the same artist:', count((array) $report['title_matches'])));

        foreach ((array) $report['title_matches'] as $match) {
            $this->line(sprintf('  %s: %s (%.3f%s)', $match['artist'], implode(' | ', $match['files']), $match['similarity'], $match['same_recording'] ? ', same recording' : ', different recording'));
        }

        $this->newLine();
        $this->info('mv lines (run inside the music folder):');

        foreach ((array) $report['mv'] as $line) {
            $this->line($line);
        }
    }
}
