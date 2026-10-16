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
 * Instrumentals (a folder of their own, same naming) alternate with the
 * vocal tracks: `interleave()` puts one after every vocal track.
 *
 * The source rotation (`rotate()`) also spaces artists (plan "Musik-Katalog"
 * P5): with an artist gap, no artist plays again within that many tracks,
 * where the files allow it (see `spaced()`).
 */
final class MusicPlaylist
{
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
     * Who a file is by, for the artist spacing (plan "Musik-Katalog" P5): the credited artist first
     * (MusicCredits::forFile(), which names the house artist for an uncredited instrumental or top-level song), then
     * the co-artists the file name lists after its last " - " when that list opens with the credited artist
     * ("01 - Feuer über Fiat - Too Bit to Fail, Hanspanzer"). Empty without a credit: an unknown artist never clashes.
     *
     * @return list<string>
     */
    public static function artists(string $file): array
    {
        $artist = MusicCredits::forFile($file)['artist'] ?? null;

        if (! is_string($artist) || $artist === '') {
            return [];
        }

        $parts = explode(' - ', self::title(basename($file)));
        $named = count($parts) < 2 ? false : preg_split('/\s*,\s*|\s+feat\.\s+/i', trim($parts[count($parts) - 1]));

        return is_array($named) && ($named[0] ?? null) === $artist
            ? array_values(array_unique(array_filter($named, fn (string $name): bool => $name !== '')))
            : [$artist];
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
     * One track from each source in turn (user 2026-10-15: vocal, instrumental, PlebRap, Wavlake Top 40,
     * then the same again). Empty sources are left out. Each source is a shuffled bag that refills when
     * empty, so its files all come round before one of them repeats; a shorter source therefore repeats
     * sooner, and the four sources share the slots equally. A slot no file fits (its title would sit
     * next to the same title) stays empty rather than failing. One source alone is `order()`.
     *
     * The list wraps like `order()`: its last title differs from its first where a swap can do that.
     *
     * With `$artistGap` (plan "Musik-Katalog" P5) no artist of `$artists` plays again within that many tracks, where
     * the sources allow it; `$before` are the tracks played just before the list (MusicTimeline: the end of the last
     * set), so this rule and the title rule hold across a set boundary. Where no file of a source can keep the gap,
     * the rule bends rather than leave the slot empty, and `$bends` counts it. One source alone ignores the gap.
     *
     * @param  list<list<string>>  $sources  in rotation order
     * @param  array<string, list<string>>  $artists  per file, the credited artist first (artists()); a file without one never clashes
     * @param  list<string>  $before
     * @return list<string>
     */
    public static function rotate(array $sources, Randomizer $random = new Randomizer, array $artists = [], int $artistGap = 0, array $before = [], int &$bends = 0): array
    {
        $sources = array_values(array_filter($sources, fn (array $files): bool => $files !== []));

        if ($sources === []) {
            return [];
        }

        if (count($sources) === 1) {
            return self::order($sources[0], 1, $random);
        }

        $rounds = max(array_map('count', $sources));
        $bags = array_map(fn (array $files): array => [], $sources);
        $list = [];
        $previous = $before === [] ? null : self::title($before[count($before) - 1]);
        $recent = $artistGap > 0 ? array_slice($before, -$artistGap) : [];

        for ($round = 0; $round < $rounds; $round++) {
            foreach ($sources as $index => $files) {
                $pick = self::draw($bags[$index], $files, $previous, $random, $recent, $artists, $bends);

                if ($pick === null) {
                    continue;
                }

                $list[] = $pick;
                $previous = self::title($pick);

                if ($artistGap > 0) {
                    $recent = array_slice([...$recent, $pick], -$artistGap);
                }
            }
        }

        return self::unwrap($list, $artists, $artistGap);
    }

    /**
     * The next file of a source whose title is not `$avoid`. The bag refills from a fresh shuffle
     * when it is empty, and once more when everything left would repeat the title; then the slot
     * stays empty and the bag is left for the next round.
     *
     * Among the files left in the bag that fit, the artist decides (`spaced()`, window by window from the whole gap
     * down to the track just before). Only when none keeps even the track just before does the rule bend and the
     * first fitting file plays; the bag is never refilled early for an artist, so every file of a source still comes
     * round before one repeats. Every pick short of the whole gap counts in `$bends` (measured 2026-10-16: an
     * uncredited top-level song is the house artist's, and so is every instrumental, which plays right after it).
     *
     * @param  list<string>  $bag
     * @param  list<string>  $files
     * @param  list<string>  $recent  the last tracks within the artist gap, the latest last
     * @param  array<string, list<string>>  $artists
     */
    private static function draw(array &$bag, array $files, ?string $avoid, Randomizer $random, array $recent = [], array $artists = [], int &$bends = 0): ?string
    {
        for ($refill = 0; $refill < 2; $refill++) {
            if ($bag === []) {
                $bag = $random->shuffleArray($files);
            }

            $fitting = array_keys(array_filter($bag, fn (string $file): bool => $avoid === null || self::title($file) !== $avoid));

            if ($fitting === []) {
                $bag = [];

                continue;
            }

            for ($window = count($recent); $window > 0; $window--) {
                $key = self::spaced($bag, $fitting, array_slice($recent, -$window), $artists);

                if ($key !== null) {
                    $bends += $window < count($recent) ? 1 : 0;

                    return array_splice($bag, $key, 1)[0];
                }
            }

            $bends += $recent === [] ? 0 : 1;

            return array_splice($bag, $fitting[0], 1)[0];
        }

        return null;
    }

    /**
     * The first key of `$fitting` (in bag order) whose file shares no artist with the `$near` tracks, else the first
     * whose credited artist is none of theirs; null when every one has the credited artist of a near track.
     *
     * @param  list<string>  $bag
     * @param  list<int>  $fitting
     * @param  list<string>  $near
     * @param  array<string, list<string>>  $artists
     */
    private static function spaced(array $bag, array $fitting, array $near, array $artists): ?int
    {
        $named = array_merge(...array_map(fn (string $file): array => $artists[$file] ?? [], $near));
        $credited = array_map(fn (string $file): ?string => ($artists[$file] ?? [])[0] ?? null, $near);
        $rules = [
            fn (array $own): bool => array_intersect($own, $named) === [],
            fn (array $own): bool => ! isset($own[0]) || ! in_array($own[0], $credited, true),
        ];

        foreach ($rules as $keeps) {
            foreach ($fitting as $key) {
                if ($keeps($artists[$bag[$key]] ?? [])) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Whether the credited artist at `$position` plays again within `$gap` tracks either side of it.
     *
     * @param  list<string>  $list
     * @param  array<string, list<string>>  $artists
     */
    private static function crowded(array $list, int $position, array $artists, int $gap): bool
    {
        $artist = ($artists[$list[$position]] ?? [])[0] ?? null;

        if ($artist === null) {
            return false;
        }

        for ($index = max(0, $position - $gap); $index <= min(count($list) - 1, $position + $gap); $index++) {
            if ($index !== $position && (($artists[$list[$index]] ?? [])[0] ?? null) === $artist) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the list would open with the title it closes on, swap the last file with an earlier
     * one that fits both its new neighbours. Leaves the list when no swap does. With an artist gap, a swap that
     * would bring an artist closer to itself than the gap is not taken.
     *
     * @param  list<string>  $list
     * @param  array<string, list<string>>  $artists
     * @return list<string>
     */
    private static function unwrap(array $list, array $artists = [], int $artistGap = 0): array
    {
        $last = count($list) - 1;

        if ($last < 2 || self::title($list[0]) !== self::title($list[$last])) {
            return $list;
        }

        $opening = self::title($list[0]);
        $beforeEnd = self::title($list[$last - 1]);

        for ($index = 1; $index < $last; $index++) {
            $title = self::title($list[$index]);
            $prev = self::title($list[$index - 1]);
            $next = self::title($list[$index + 1]);

            if ($title === $beforeEnd || $title === $opening || $opening === $prev || $opening === $next) {
                continue;
            }

            $swapped = [...array_slice($list, 0, $index), $list[$last], ...array_slice($list, $index + 1, $last - $index - 1), $list[$index]];

            if ($artistGap > 0 && (self::crowded($swapped, $index, $artists, $artistGap) || self::crowded($swapped, $last, $artists, $artistGap))) {
                continue;
            }

            return $swapped;
        }

        return $list;
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
