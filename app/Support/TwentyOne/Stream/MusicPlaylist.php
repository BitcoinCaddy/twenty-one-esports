<?php

namespace App\Support\TwentyOne\Stream;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * The order the stream plays its music in, as an ffconcat list.
 *
 * Files are named `<title>__v<n>.m4a`; several versions of one title exist.
 * Rule (docs/plans/…stream-live-scene.md, "Musik"): a random order in which
 * two consecutive files never share a title, also across the end of the list,
 * because ffmpeg loops it (`-stream_loop -1`). Each pass plays every file
 * once, in a fresh order.
 *
 * The stream plays the artist cycle (ArtistCycle, plan "Musik-Katalog"):
 * one track of every artist per round, then the next round. `interleave()`
 * and `priorityRuns()` are the older orders MusicTimeline still offers.
 */
final class MusicPlaylist
{
    /** The one turn per round that every file without a credit shares. */
    public const UNKNOWN_ARTIST = 'Unbekannt';

    /** Random walks tried per pass before giving up. */
    private const ATTEMPTS = 50;

    /**
     * The title part of `<title>__v<n>.m4a`; the whole name without extension otherwise.
     */
    public static function title(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);

        return explode('__v', $name)[0];
    }

    /**
     * `$passes` passes over `$files`, joined so no title repeats back to back,
     * including from the last entry to the first.
     *
     * Each pass is built greedily: pick a random title other than the previous
     * one, except when a title must be placed now because the rest could not
     * separate its versions any more (then it is forced). This never dead-ends
     * when an order exists at all.
     *
     * @param  list<string>  $files
     * @return list<string>
     *
     * @throws InvalidArgumentException when no valid order exists (one title has more than half the files)
     */
    public static function order(array $files, int $passes, Randomizer $random = new Randomizer): array
    {
        $byTitle = [];

        foreach ($files as $file) {
            $byTitle[self::title($file)][] = $file;
        }

        $largest = $byTitle === [] ? 0 : max(array_map('count', $byTitle));

        if ($files === [] || count($byTitle) < 2 || $largest > intdiv(count($files), 2)) {
            throw new InvalidArgumentException('The music files cannot be ordered without repeating a title back to back.');
        }

        // The last pass may be boxed in by both ends (it follows the previous
        // pass and wraps to the first); then the whole list is drawn again.
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $order = self::build($byTitle, max(1, $passes), $random);

            if ($order !== null) {
                return $order;
            }
        }

        throw new InvalidArgumentException('No music order found without repeating a title back to back.');
    }

    /**
     * @param  array<string, list<string>>  $byTitle
     * @return list<string>|null
     */
    private static function build(array $byTitle, int $passes, Randomizer $random): ?array
    {
        $order = [];

        for ($pass = 0; $pass < $passes; $pass++) {
            $candidate = null;

            for ($try = 0; $try < self::ATTEMPTS && $candidate === null; $try++) {
                $previous = $order === [] ? null : self::title($order[count($order) - 1]);
                $candidate = self::pass($byTitle, $previous, $random);

                // The list wraps: its last title must differ from its first.
                if ($candidate !== null && $pass === $passes - 1
                    && self::title($candidate[count($candidate) - 1]) === self::title(($order === [] ? $candidate : $order)[0])) {
                    $candidate = null;
                }
            }

            if ($candidate === null) {
                return null;
            }

            array_push($order, ...$candidate);
        }

        return $order;
    }

    /**
     * The vocal order with `$perVocal` instrumentals after every vocal track
     * (vocal, 3 instrumentals, vocal, … with 3). Instrumentals are drawn from a
     * shuffled bag that refills when empty, so all of them come round before
     * one repeats. No two neighbours ever share a title (several tunes exist
     * both sung and instrumental, and as several versions); where it can, a
     * run also avoids a title twice and the instrumental before the vocal.
     * The list wraps like `order()`.
     *
     * A slot no instrumental fits (possible only with at most two titles,
     * all of which are also sung) stays empty rather than failing.
     *
     * @param  list<string>  $vocals  a valid `order()`
     * @param  list<string>  $instrumentals
     * @return list<string>
     */
    public static function interleave(array $vocals, array $instrumentals, Randomizer $random = new Randomizer, int $perVocal = 1): array
    {
        if ($instrumentals === [] || $vocals === []) {
            return $vocals;
        }

        $perVocal = max(1, $perVocal);
        $count = count($vocals);
        $bag = [];
        $lastInstrumental = null;
        $list = [];

        foreach ($vocals as $index => $vocal) {
            $list[] = $vocal;
            $previous = self::title($vocal);
            $next = self::title($vocals[($index + 1) % $count]);
            $run = [];

            for ($slot = 0; $slot < $perVocal; $slot++) {
                $neighbours = $slot === $perVocal - 1 ? [$previous, $next] : [$previous];
                // Strict first: no title twice in one run, nor the last instrumental before this
                // vocal; then only the neighbours, which must always differ.
                $strict = [...$neighbours, ...$run, ...($slot === 0 && $lastInstrumental !== null ? [$lastInstrumental] : [])];
                $pick = null;

                foreach ([$strict, $neighbours] as $avoid) {
                    foreach ([false, true] as $refill) {
                        if ($refill || $bag === []) {
                            $bag = [...$bag, ...$random->shuffleArray($instrumentals)];
                        }

                        foreach ($bag as $key => $file) {
                            if (! in_array(self::title($file), $avoid, true)) {
                                $pick = $file;
                                unset($bag[$key]);
                                $bag = array_values($bag);

                                break 3;
                            }
                        }
                    }
                }

                // No instrumental fits (few titles, all also sung): the slot stays empty rather than
                // repeating a title back to back, and the daemon never dies over the music.
                if ($pick === null) {
                    continue;
                }

                $list[] = $pick;
                $previous = self::title($pick);
                $run[] = $previous;
                $lastInstrumental = $previous;
            }
        }

        return $list;
    }

    /**
     * The artist a file is by for the artist cycle: its credited artist (MusicCredits::forFile(); a PlebRap
     * collaboration counts under its first artist, the house artist DJ Bitcoin is one artist like any other), or
     * UNKNOWN_ARTIST for a file without a credit: all of those share one turn per round.
     */
    public static function artist(string $file): string
    {
        $artist = MusicCredits::forFile($file)['artist'] ?? null;

        return is_string($artist) && $artist !== '' ? $artist : self::UNKNOWN_ARTIST;
    }

    /**
     * A pass over the priority music with the rest in between (user 2026-10-13: "PlebRap 3 bis 5 Lieder, dann nur
     * einmal eins der anderen, dann wieder PlebRap mit 3 bis 5 Liedern"): every priority file once in a random order
     * (none again before all played), cut into runs of `$min` to `$max`, each run followed by exactly one file of
     * `$rest`, drawn from a shuffled bag. No neighbours share a title where the rest allows it, also not the rest
     * file and the run around it; a rest file that cannot avoid both neighbours is still played (the music never stops).
     * Without priority files the rest plays in a no-repeat order (order()); without rest files only the runs.
     *
     * @param  list<string>  $priority
     * @param  list<string>  $rest
     * @return list<string>
     */
    public static function priorityRuns(array $priority, array $rest, int $min, int $max, Randomizer $random = new Randomizer): array
    {
        if ($priority === []) {
            return self::order($rest, 1, $random);
        }

        $min = max(1, $min);
        $max = max($min, $max);
        $runs = $random->shuffleArray($priority);
        $bag = [];
        $list = [];

        while ($runs !== []) {
            $run = array_splice($runs, 0, $random->getInt($min, $max));
            array_push($list, ...$run);

            if ($rest === []) {
                continue;
            }

            $previous = self::title($run[count($run) - 1]);
            $next = $runs === [] ? null : self::title($runs[0]);

            if ($bag === []) {
                $bag = $random->shuffleArray($rest);
            }

            $key = array_key_first(array_filter($bag, fn (string $file): bool => ! in_array(self::title($file), [$previous, $next], true))) ?? array_key_first($bag);
            $list[] = $bag[$key];
            unset($bag[$key]);
            $bag = array_values($bag);
        }

        return $list;
    }

    /**
     * No control characters: a newline would end the ffconcat `file` line
     * and start a directive of its own.
     */
    public static function isSafePath(string $file): bool
    {
        return preg_match('/\p{C}/u', $file) === 0;
    }

    /**
     * An ffconcat file for `-f concat -safe 0` with absolute paths; the first
     * file starts `$inpoint` seconds into its sound (MusicTimeline: a new
     * encoder goes on where the music is). `$points` (MusicTimeline, P17)
     * trims a file's silent edges: it plays from `in` to `out` (null: to its end).
     *
     * The entries are written `$passes` times over (the inpoint only once):
     * ffmpeg's `-stream_loop -1` cannot loop a concat list that has an
     * inpoint or outpoint (ffmpeg 6.1.1 on prod, measured 2026-10-14: the
     * seek back to the start fails and the input ends after one pass). The
     * encoder's music then ended after the list's 14 h, and with it the audio
     * track of every segment, while the video went on for seven hours.
     *
     * @param  list<string>  $files
     * @param  list<array{in: float, out: float|null}>  $points  by the index of `$files`
     */
    public static function ffconcat(array $files, float $inpoint = 0.0, array $points = [], int $passes = 1): string
    {
        $lines = ['ffconcat version 1.0'];

        foreach ($files as $file) {
            if (! self::isSafePath($file)) {
                throw new InvalidArgumentException('A music path with a control character cannot go into an ffconcat list.');
            }
        }

        for ($pass = 0; $pass < max(1, $passes); $pass++) {
            foreach ($files as $index => $file) {
                $lines[] = "file '".str_replace("'", "'\\''", $file)."'";

                $in = (float) ($points[$index]['in'] ?? 0.0) + ($pass === 0 && $index === 0 ? $inpoint : 0.0);
                $out = $points[$index]['out'] ?? null;

                if ($in > 0) {
                    $lines[] = 'inpoint '.sprintf('%.3F', $in);
                }

                if ($out !== null && $out > $in) {
                    $lines[] = 'outpoint '.sprintf('%.3F', $out);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * One pass: every file once, never the same title twice in a row.
     *
     * @param  array<string, list<string>>  $byTitle
     * @return list<string>|null null when this random walk ran into a dead end
     */
    private static function pass(array $byTitle, ?string $previous, Randomizer $random): ?array
    {
        $remaining = array_map(fn (array $versions): array => $random->shuffleArray($versions), $byTitle);
        $left = array_sum(array_map('count', $remaining));
        $pass = [];

        while ($left > 0) {
            $allowed = array_keys(array_filter($remaining, fn (array $versions, string $title): bool => $versions !== [] && $title !== $previous, ARRAY_FILTER_USE_BOTH));
            // A title holding more than half of what is left must go now, or it cannot be separated.
            $forced = array_values(array_filter($allowed, fn (string $title): bool => 2 * count($remaining[$title]) > $left));

            if ($allowed === []) {
                return null;
            }

            $title = $forced !== [] ? $forced[0] : $allowed[$random->getInt(0, count($allowed) - 1)];

            $pass[] = array_shift($remaining[$title]);
            $previous = $title;
            $left--;
        }

        return $pass;
    }
}
