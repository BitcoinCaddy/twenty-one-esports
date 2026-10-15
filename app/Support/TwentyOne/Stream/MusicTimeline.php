<?php

namespace App\Support\TwentyOne\Stream;

use Closure;
use Random\Randomizer;
use Throwable;

/**
 * The stream's music as one timeline on the wall clock, kept in a file, so a
 * new encoder (a mode switch, a watchdog restart, a deploy) goes on with the
 * track that is playing, at the second it is at, instead of a fresh shuffle
 * from its first track.
 *
 * The timeline is a row of sets. A set plays every vocal track once, in a
 * random order, with `perVocal` instrumentals after each (MusicPlaylist:
 * no title twice in a row, instrumentals from a bag that runs empty before
 * one repeats). When a set has played, the next one is a new shuffle; its
 * first track never has the title the last one had.
 *
 * Starts are cumulative real durations (ffprobe, kept per file by size and
 * mtime; `fallbackSeconds` when a file cannot be read). A changed set of
 * files (added, removed) starts a new timeline at once. Played entries older
 * than an hour are dropped, so the file stays small.
 *
 * Dead air between tracks (plan "TWENTY ONE Radio" P17): the tracks carry up
 * to three seconds of digital silence at their ends (measured 2026-10-12:
 * 107__v1 3.08 s at its end, 97__v1 0.65 s at its start and 2.11 s at its
 * end), and with no voice on air the stream fell silent at every change
 * (live 2026-10-12 around 23:28:54 and 23:33:22, -58 and -88 dBFS). A probe
 * may report a file's silent lead and tail besides its length; an entry then
 * plays from its lead (ffconcat `inpoint`) to the end of its sound
 * (`outpoint`), and its length on the timeline is what sounds. Edges are
 * probed again for at most `edgeProbes` kept files per call (a probe decodes
 * both ends), so a library probed before the edges existed is caught up over
 * a few encoder starts instead of holding one back; until then a file plays
 * whole, as before.
 */
final class MusicTimeline
{
    /** How far back played entries are kept (seconds). */
    private const KEEP_PLAYED = 3600;

    /** Shuffles tried for a set whose first title must differ from the last one played. */
    private const ATTEMPTS = 20;

    /** A file keeps at least this much sound after its silent edges are trimmed, else it plays whole. */
    private const MIN_SOUND_SECONDS = 10.0;

    /** @var array<string, list<int|float>> per file: size, mtime, seconds, and the silent lead and tail once probed (the probes to keep) */
    private array $probed = [];

    /**
     * @param  Closure(string): (float|array{seconds: float, lead?: float, tail?: float}|null)  $probe  a file's duration in seconds (with its silent edges), null when unreadable
     * @param  int  $edgeProbes  kept files at most whose edges are probed again in one call
     */
    public function __construct(
        private string $path,
        private Closure $probe,
        private float $fallbackSeconds = 180,
        private int $edgeProbes = 25,
    ) {}

    /**
     * The entries from now on, covering at least `$hours`; the first one starts
     * `inpoint` seconds into its file.
     *
     * With `$priority` files (PlebRap, user 2026-10-13) a set is MusicPlaylist::priorityRuns() instead: every priority
     * file once in runs of `$run` min to max, one of the vocals and instrumentals after each run.
     *
     * With `$sources` (user 2026-10-15: vocal, instrumental, PlebRap, Wavlake, one each in turn) a set is
     * MusicPlaylist::rotate() and the priority run is not used. Empty source lists are left out.
     *
     * @param  list<string>  $vocals
     * @param  list<string>  $instrumentals
     * @param  list<string>  $priority
     * @param  array{0: int, 1: int}  $run
     * @param  list<list<string>>  $sources
     * @return array{files: list<string>, inpoint: float, restarted: bool, points: list<array{in: float, out: float|null}>}
     */
    public function from(array $vocals, array $instrumentals, int $perVocal, float $now, float $hours, Randomizer $random = new Randomizer, array $priority = [], array $run = [3, 5], array $sources = []): array
    {
        $sources = array_values(array_filter($sources, fn (array $files): bool => $files !== []));
        $state = $this->read();
        $fingerprint = self::fingerprint($vocals, $instrumentals, $perVocal, $priority, $run, $sources);
        $pool = $sources === [] ? [...$priority, ...$vocals, ...$instrumentals] : array_merge(...$sources);
        $durations = $this->durations($pool, $state['durations'] ?? []);
        $restarted = ($state['fingerprint'] ?? null) !== $fingerprint || ($state['entries'] ?? []) === [];

        /** @var list<array{0: string, 1: float, 2: float, 3: float|null}> $entries */
        $entries = $restarted ? [] : $state['entries'];
        $anchor = $restarted ? $now : (float) $state['anchor'];

        // Drop what played more than an hour ago, moving the anchor with it.
        while ($entries !== [] && $anchor + $entries[0][1] < $now - self::KEEP_PLAYED) {
            $anchor += array_shift($entries)[1];
        }

        $end = $anchor + array_sum(array_column($entries, 1));

        // A timeline that ended long ago (the stream was off) starts again now.
        if ($end < $now) {
            [$entries, $anchor, $end] = [[], $now, $now];
        }

        while ($end < $now + 3600 * $hours) {
            $last = $entries === [] ? null : MusicPlaylist::title($entries[count($entries) - 1][0]);

            foreach ($this->set($vocals, $instrumentals, $perVocal, $last, $random, $priority, $run, $sources) as $file) {
                ['seconds' => $seconds, 'lead' => $lead, 'trimmed' => $trimmed] = $durations[$file];
                $entries[] = [$file, $seconds, $lead, $trimmed ? round($lead + $seconds, 3) : null];
                $end += $seconds;
            }
        }

        $start = $anchor;
        $index = 0;

        while ($start + $entries[$index][1] <= $now) {
            $start += $entries[$index][1];
            $index++;
        }

        $this->write(['fingerprint' => $fingerprint, 'anchor' => $anchor, 'entries' => $entries, 'durations' => $this->probed]);

        $rest = array_slice($entries, $index);

        return [
            'files' => array_column($rest, 0),
            'inpoint' => round($now - $start, 3),
            'restarted' => $restarted,
            'points' => array_map(fn (array $entry): array => ['in' => $entry[2], 'out' => $entry[3]], $rest),
        ];
    }

    /**
     * The tracks the timeline file at `$path` plays within [`$from`, `$to`] on the wall clock, in order, each with its
     * start and end as it sounds (the silent edges trimmed); empty when the file is missing or unreadable, so a reader
     * that plans around the music (SceneDirector's music breaks) plans nothing rather than around a guess.
     *
     * @return list<array{file: string, start: float, end: float}>
     */
    public static function tracks(string $path, float $from, float $to): array
    {
        $state = (new self($path, fn (string $file): ?float => null))->read();

        if ($state === []) {
            return [];
        }

        $tracks = [];
        $start = (float) $state['anchor'];

        foreach ($state['entries'] as [$file, $seconds]) {
            $end = $start + (float) $seconds;

            if ($end > $from && $start < $to) {
                $tracks[] = ['file' => (string) $file, 'start' => round($start, 3), 'end' => round($end, 3)];
            }

            if ($start >= $to) {
                break;
            }

            $start = $end;
        }

        return $tracks;
    }

    /**
     * One set: every vocal once with instrumentals after each; a first title
     * other than `$last` where a shuffle allows it.
     *
     * @param  list<string>  $vocals
     * @param  list<string>  $instrumentals
     * @param  list<string>  $priority
     * @param  array{0: int, 1: int}  $run
     * @param  list<list<string>>  $sources
     * @return list<string>
     */
    private function set(array $vocals, array $instrumentals, int $perVocal, ?string $last, Randomizer $random, array $priority = [], array $run = [3, 5], array $sources = []): array
    {
        $set = [];

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $set = $sources !== []
                ? MusicPlaylist::rotate($sources, $random)
                : ($priority !== []
                    ? MusicPlaylist::priorityRuns($priority, [...$vocals, ...$instrumentals], (int) $run[0], (int) $run[1], $random)
                    : MusicPlaylist::interleave(MusicPlaylist::order($vocals, 1, $random), $instrumentals, $random, $perVocal));

            if ($set !== [] && ($last === null || MusicPlaylist::title($set[0]) !== $last)) {
                return $set;
            }
        }

        return $set;
    }

    /**
     * The duration of every file, from the kept probes where size and mtime
     * still match, probed again otherwise.
     *
     * With its silent edges known, a file sounds from its lead for its length less both edges, as long as at least
     * MIN_SOUND_SECONDS are left; a fallback length is a guess and never trimmed.
     *
     * @param  list<string>  $files
     * @param  array<string, mixed>  $known
     * @return array<string, array{seconds: float, lead: float, trimmed: bool}>
     */
    private function durations(array $files, array $known): array
    {
        $durations = [];
        $kept = [];
        $edgeBudget = $this->edgeProbes;

        foreach ($files as $file) {
            $size = (int) @filesize($file);
            $mtime = (int) @filemtime($file);
            $entry = $known[$file] ?? null;
            $valid = is_array($entry) && ($entry[0] ?? null) === $size && ($entry[1] ?? null) === $mtime && is_numeric($entry[2] ?? null) && $entry[2] > 0;
            $hasEdges = $valid && is_numeric($entry[3] ?? null) && is_numeric($entry[4] ?? null);
            $edges = null;

            if ($valid && ($hasEdges || $edgeBudget <= 0)) {
                $seconds = (float) $entry[2];
                $edges = $hasEdges ? [(float) $entry[3], (float) $entry[4]] : null;
            } else {
                if ($valid) {
                    $edgeBudget--;
                }

                try {
                    $probe = ($this->probe)($file);
                } catch (Throwable) {
                    $probe = null;
                }

                $seconds = is_array($probe) ? $probe['seconds'] : $probe;

                if ($seconds !== null && $seconds > 0) {
                    // Edges that could not be read count as none: the file plays whole and is not probed again for them.
                    $edges = is_array($probe) && is_numeric($probe['lead'] ?? null) && is_numeric($probe['tail'] ?? null)
                        ? [max(0.0, (float) $probe['lead']), max(0.0, (float) $probe['tail'])] : [0.0, 0.0];
                } else {
                    $seconds = $this->fallbackSeconds;
                }
            }

            $kept[$file] = $edges === null ? [$size, $mtime, $seconds] : [$size, $mtime, $seconds, $edges[0], $edges[1]];
            [$lead, $tail] = $edges ?? [0.0, 0.0];

            $durations[$file] = $lead + $tail > 0 && $seconds - $lead - $tail >= self::MIN_SOUND_SECONDS
                ? ['seconds' => round($seconds - $lead - $tail, 3), 'lead' => round($lead, 3), 'trimmed' => true]
                : ['seconds' => $seconds, 'lead' => 0.0, 'trimmed' => false];
        }

        $this->probed = $kept;

        return $durations;
    }

    /**
     * @param  list<string>  $vocals
     * @param  list<string>  $instrumentals
     * @param  list<string>  $priority
     * @param  array{0: int, 1: int}  $run
     * @param  list<list<string>>  $sources
     */
    private static function fingerprint(array $vocals, array $instrumentals, int $perVocal, array $priority = [], array $run = [3, 5], array $sources = []): string
    {
        sort($vocals);
        sort($instrumentals);
        sort($priority);

        if ($sources !== []) {
            $named = $sources;

            foreach ($named as &$files) {
                sort($files);
            }

            return sha1(json_encode(['rotate', $named], JSON_THROW_ON_ERROR));
        }

        // Without priority music the fingerprint stays what it was, so a deploy does not restart a running timeline.
        return sha1(json_encode($priority === [] ? [$vocals, $instrumentals, $perVocal] : [$vocals, $instrumentals, $perVocal, $priority, $run], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        try {
            $state = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
        } catch (Throwable) {
            $state = null;
        }

        if (! is_array($state) || ! is_numeric($state['anchor'] ?? null) || ! is_array($state['entries'] ?? null)) {
            return [];
        }

        foreach ($state['entries'] as $entry) {
            if (! is_array($entry) || ! is_string($entry[0] ?? null) || ! is_numeric($entry[1] ?? null) || $entry[1] <= 0) {
                return [];
            }
        }

        // An entry kept from before the edges (P17) has no lead and plays whole.
        $state['entries'] = array_map(fn (array $entry): array => [
            $entry[0], (float) $entry[1],
            is_numeric($entry[2] ?? null) ? max(0.0, (float) $entry[2]) : 0.0,
            is_numeric($entry[3] ?? null) ? (float) $entry[3] : null,
        ], array_values($state['entries']));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function write(array $state): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }

        PlaylistWriter::writeAtomically($this->path, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
