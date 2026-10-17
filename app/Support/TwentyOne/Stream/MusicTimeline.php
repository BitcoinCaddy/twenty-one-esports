<?php

namespace App\Support\TwentyOne\Stream;

use Closure;
use Illuminate\Support\Facades\Log;
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
 *
 * The stream's music is the artist cycle (ArtistCycle, plan "Musik-Katalog",
 * user 2026-10-16): one track of every artist per round, no artist again
 * within `artistGap` tracks (config `twentyone.stream.music.artist_gap`, at
 * least 1: never twice in a row). Its state (what has played: the round, the
 * artists' bags) is kept in the file with the timeline and never starts over:
 * not for a restart, a deploy, a changed library or a changed setting (user
 * 2026-10-16: "deploy und stream restarts dürfen ab jetzt auch nicht mehr die
 * Playlist Reihenfolge irgendwie neustarten"). Where too few artists cannot
 * keep the gap the rule bends, logged once per process; the music never stops.
 * Only the older orders (vocals with instrumentals, priority runs) still start
 * a new timeline when their files change.
 */
final class MusicTimeline
{
    /** How far back played entries are kept (seconds): the history a migration of the artist cycle counts as heard. */
    private const KEEP_PLAYED = 12 * 3600;

    /** Shuffles tried for a set whose first title must differ from the last one played. */
    private const ATTEMPTS = 20;

    /** A file keeps at least this much sound after its silent edges are trimmed, else it plays whole. */
    private const MIN_SOUND_SECONDS = 10.0;

    /** @var array<string, list<int|float>> per file: size, mtime, seconds, and the silent lead and tail once probed (the probes to keep) */
    private array $probed = [];

    /** @var array<string, true> what this process has logged already (warnOnce()) */
    private static array $warned = [];

    /**
     * @param  Closure(string): (float|array{seconds: float, lead?: float, tail?: float}|null)  $probe  a file's duration in seconds (with its silent edges), null when unreadable
     * @param  int  $edgeProbes  kept files at most whose edges are probed again in one call
     * @param  int|null  $artistGap  tracks at least between two of one artist (at least 1); null reads the config
     * @param  (Closure(string): string)|null  $artists  the artist a file is by; null is MusicPlaylist::artist()
     */
    public function __construct(
        private string $path,
        private Closure $probe,
        private float $fallbackSeconds = 180,
        private int $edgeProbes = 25,
        private ?int $artistGap = null,
        private ?Closure $artists = null,
    ) {}

    /**
     * The entries from now on, covering at least `$hours`; the first one starts
     * `inpoint` seconds into its file.
     *
     * With `$priority` files (PlebRap, user 2026-10-13) a set is MusicPlaylist::priorityRuns() instead: every priority
     * file once in runs of `$run` min to max, one of the vocals and instrumentals after each run.
     *
     * With `$sources` (the music folders, whatever they hold) the timeline is the artist cycle over all their files
     * (see cycle()); the folders only list the files, they decide nothing.
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
        $gap = $this->gap();
        $pool = $sources === [] ? [...$priority, ...$vocals, ...$instrumentals] : array_merge(...$sources);
        $fingerprint = self::fingerprint($vocals, $instrumentals, $perVocal, $priority, $run, $sources === [] ? [] : $pool, $gap);
        // Probing new files takes real time (243 new songs: 55 s on prod, 2026-10-16), and the encoder starts only
        // after it: `$now` moves on by that time, or the music starts that far behind the wall clock that the
        // now-playing card and the music breaks follow (measured live 2026-10-17: the card ~56 s ahead of the song).
        $probeStart = microtime(true);
        $durations = $this->durations($pool, $state['durations'] ?? []);
        // (Below a second it is noise: a kept library probes nothing, and tests stay exact.)
        $probed = microtime(true) - $probeStart;
        $now += $probed >= 1.0 ? $probed : 0.0;
        // The artist cycle never starts over for a changed library or setting (user 2026-10-16); the older orders do.
        $restarted = ($state['entries'] ?? []) === [] || ($sources === [] && ($state['fingerprint'] ?? null) !== $fingerprint);

        /** @var list<array{0: string, 1: float, 2: float, 3: float|null}> $entries */
        $entries = $restarted ? [] : $state['entries'];
        $anchor = $restarted ? $now : (float) $state['anchor'];
        $cycle = null;
        $bends = 0;

        // Drop what played more than KEEP_PLAYED ago, moving the anchor with it.
        while ($entries !== [] && $anchor + $entries[0][1] < $now - self::KEEP_PLAYED) {
            $anchor += array_shift($entries)[1];
        }

        if ($sources !== []) {
            [$entries, $anchor, $cycle, $bends] = $this->cycle($entries, $anchor, $now, $hours, $pool, $durations, $state['cycle'] ?? null, $gap, $random);
        } else {
            $end = $anchor + array_sum(array_column($entries, 1));

            // A timeline that ended long ago (the stream was off) starts again now.
            if ($end < $now) {
                [$entries, $anchor, $end] = [[], $now, $now];
            }

            while ($end < $now + 3600 * $hours) {
                foreach ($this->set($vocals, $instrumentals, $perVocal, array_column(array_slice($entries, -1), 0), $random, $priority, $run) as $file) {
                    $entries[] = self::entry($file, $durations);
                    $end += $durations[$file]['seconds'];
                }
            }
        }

        if ($bends > 0) {
            self::warnOnce('bends', sprintf('music: %d new tracks play within %d of the same artist; there are too few artists to space them (artist_gap)', $bends, $gap));
        }

        $start = $anchor;
        $index = 0;

        while ($start + $entries[$index][1] <= $now) {
            $start += $entries[$index][1];
            $index++;
        }

        $this->write(['fingerprint' => $fingerprint, 'anchor' => $anchor, 'entries' => $entries, 'durations' => $this->probed, ...($cycle === null ? [] : ['cycle' => $cycle])]);

        $rest = array_slice($entries, $index);

        return [
            'files' => array_column($rest, 0),
            'inpoint' => round($now - $start, 3),
            'restarted' => $restarted,
            'points' => array_map(fn (array $entry): array => ['in' => $entry[2], 'out' => $entry[3]], $rest),
        ];
    }

    /**
     * The timeline of the artist cycle from now on. What has played stays: the entries up to the one playing now (it
     * goes on at its second, unless its file is gone; then the next track starts now). The cycle's kept state is
     * brought up to now with the tracks that played since it was written, or, kept in an older format (the source
     * rotation, before the cycle), started from the tracks of the last KEEP_PLAYED, which then count as heard. The
     * queue after the playing track is drawn again from that state, so a new track or artist joins as ArtistCycle
     * says and a removed one is gone, and nothing that played comes back before its turn.
     *
     * @param  list<array{0: string, 1: float, 2: float, 3: float|null}>  $entries
     * @param  list<string>  $pool
     * @param  array<string, array{seconds: float, lead: float, trimmed: bool}>  $durations
     * @return array{0: list<array{0: string, 1: float, 2: float, 3: float|null}>, 1: float, 2: array<string, mixed>, 3: int}
     */
    private function cycle(array $entries, float $anchor, float $now, float $hours, array $pool, array $durations, mixed $kept, int $gap, Randomizer $random): array
    {
        $byArtist = $this->byArtist($pool);
        // What plays: the files of the artists that take part (an artist below min_artist_tracks sits out).
        $pool = array_merge(...array_values($byArtist));
        $inPool = array_flip($pool);
        $heard = [];
        $starts = [];
        $start = $anchor;

        foreach ($entries as $entry) {
            $end = $start + $entry[1];

            if ($start > $now || ($end > $now && ! isset($inPool[$entry[0]]))) {
                break;
            }

            $heard[] = $entry;
            $starts[] = $start;
            $start = $end;
        }

        // A pause (the stream was off, or the playing track's file is gone): the next track starts now.
        $end = max($start, $now);
        $anchor += $end - $start;
        $cycle = ArtistCycle::fromState($byArtist, $kept, $gap);

        if ($cycle === null) {
            $cycle = ArtistCycle::seeded($byArtist, array_column($heard, 0), $gap);
        } else {
            $since = is_array($kept) && is_numeric($kept['until'] ?? null) ? (float) $kept['until'] - 0.001 : INF;

            foreach ($heard as $index => $entry) {
                if ($starts[$index] >= $since) {
                    $cycle->played($entry[0]);
                }
            }
        }

        // The library as a key (files, their artists, the gap): while it is unchanged, the queue already drawn stays.
        $library = $byArtist;
        ksort($library);
        $poolKey = sha1(json_encode([array_map(function (array $files): array {
            sort($files);

            return $files;
        }, $library), $gap], JSON_THROW_ON_ERROR));
        $state = [...$cycle->toState(), 'until' => $end, 'pool' => $poolKey];
        $bends = 0;

        // The queue after the playing track stays while the library is the same: an encoder that starts during a
        // make-before-break switch, or a restart, plays the next track the old one would have played (no jump in a
        // song at the handoff), and readers of the timeline see one queue. The kept state covers only what played;
        // the queued tracks are replayed onto a copy to draw on from their end.
        $draw = $cycle;

        if (is_array($kept) && ($kept['pool'] ?? null) === $poolKey && $start >= $now) {
            $draw = clone $cycle;

            foreach (array_slice($entries, count($heard)) as $entry) {
                if (! isset($inPool[$entry[0]])) {
                    break;
                }

                $draw->played($entry[0]);
                $heard[] = $entry;
                $end += $entry[1];
            }
        }

        while ($end < $now + 3600 * $hours) {
            $file = $draw->next($random, $bends);
            $heard[] = self::entry($file, $durations);
            $end += $durations[$file]['seconds'];
        }

        return [$heard, $anchor, $state, $bends];
    }

    /**
     * A timeline entry: the file, its length as it sounds, where it starts and ends in the file (null: its end).
     *
     * @param  array<string, array{seconds: float, lead: float, trimmed: bool}>  $durations
     * @return array{0: string, 1: float, 2: float, 3: float|null}
     */
    private static function entry(string $file, array $durations): array
    {
        ['seconds' => $seconds, 'lead' => $lead, 'trimmed' => $trimmed] = $durations[$file];

        return [$file, $seconds, $lead, $trimmed ? round($lead + $seconds, 3) : null];
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
     * One set of the older orders: every vocal once with instrumentals after
     * each (or the priority runs); a first title other than the last of
     * `$before` where a shuffle allows it.
     *
     * @param  list<string>  $vocals
     * @param  list<string>  $instrumentals
     * @param  list<string>  $before  the tracks the set follows, the latest last
     * @param  list<string>  $priority
     * @param  array{0: int, 1: int}  $run
     * @return list<string>
     */
    private function set(array $vocals, array $instrumentals, int $perVocal, array $before, Randomizer $random, array $priority = [], array $run = [3, 5]): array
    {
        $set = [];
        $last = $before === [] ? null : MusicPlaylist::title($before[count($before) - 1]);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $set = $priority !== []
                ? MusicPlaylist::priorityRuns($priority, [...$vocals, ...$instrumentals], (int) $run[0], (int) $run[1], $random)
                : MusicPlaylist::interleave(MusicPlaylist::order($vocals, 1, $random), $instrumentals, $random, $perVocal);

            if ($set !== [] && ($last === null || MusicPlaylist::title($set[0]) !== $last)) {
                return $set;
            }
        }

        return $set;
    }

    /**
     * The configured artist gap (`twentyone.stream.music.artist_gap`), at least 1; 1 where no config can be read.
     */
    /** The configured `min_artist_tracks`, at least 1; 1 (everyone plays) where no config can be read. */
    private static function minArtistTracks(): int
    {
        try {
            return max(1, (int) config('twentyone.stream.music.min_artist_tracks', 6));
        } catch (Throwable) {
            return 1;
        }
    }

    private function gap(): int
    {
        try {
            return max(1, $this->artistGap ?? (int) config('twentyone.stream.music.artist_gap', 1));
        } catch (Throwable) {
            return 1;
        }
    }

    /**
     * Every artist's files. A file whose credit cannot be read joins MusicPlaylist::UNKNOWN_ARTIST, so the music plays
     * on, and the first such failure is logged.
     *
     * @param  list<string>  $files
     * @return array<string, list<string>>
     */
    private function byArtist(array $files): array
    {
        $resolve = $this->artists ?? MusicPlaylist::artist(...);
        $byArtist = [];

        foreach ($files as $file) {
            try {
                $artist = $resolve($file);
            } catch (Throwable $e) {
                $artist = MusicPlaylist::UNKNOWN_ARTIST;
                self::warnOnce('artists', 'music: credits not readable, '.basename($file).' plays as '.MusicPlaylist::UNKNOWN_ARTIST.': '.$e->getMessage());
            }

            $byArtist[$artist === '' ? MusicPlaylist::UNKNOWN_ARTIST : $artist][] = $file;
        }

        // An artist with only a few songs would repeat them every round (user 2026-10-16: "Wenn wir von einem Artist
        // nur einen einzelnen Track haben, dann wird dieser ganz oft gespielt. Diese Artists schalten wir erstmal ab,
        // so lange wir nicht mehr als 5 Songs von ihnen haben"): they sit out until they have `min_artist_tracks`.
        // Never down to fewer than two artists: then everyone plays.
        $min = self::minArtistTracks();
        $enough = array_filter($byArtist, fn (array $files): bool => count($files) >= $min);

        if (count($enough) < count($byArtist)) {
            self::warnOnce('min-tracks', sprintf('music: %d artists with fewer than %d songs sit out: %s', count($byArtist) - count($enough), $min, implode(', ', array_keys(array_diff_key($byArtist, $enough)))));
        }

        return count($enough) >= 2 ? $enough : $byArtist;
    }

    /**
     * A warning, at most once per `$key` in this process (the daemon builds a timeline at every encoder start).
     */
    private static function warnOnce(string $key, string $message): void
    {
        if (isset(self::$warned[$key])) {
            return;
        }

        self::$warned[$key] = true;

        try {
            Log::warning($message);
        } catch (Throwable) {
            // No logger (a bare unit test): the music goes on regardless.
        }
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
     * @param  list<string>  $cycled  every file of the artist cycle
     * @param  int  $artistGap  the cycle's artist spacing
     */
    private static function fingerprint(array $vocals, array $instrumentals, int $perVocal, array $priority = [], array $run = [3, 5], array $cycled = [], int $artistGap = 1): string
    {
        sort($vocals);
        sort($instrumentals);
        sort($priority);

        if ($cycled !== []) {
            sort($cycled);

            // New with the artist cycle; the cycle never compares it (MusicTimeline::from() keeps its state on a change).
            return sha1(json_encode(['artist-cycle', $cycled, $artistGap], JSON_THROW_ON_ERROR));
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
