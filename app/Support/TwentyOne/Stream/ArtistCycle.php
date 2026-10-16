<?php

namespace App\Support\TwentyOne\Stream;

use Random\Randomizer;

/**
 * The stream music's artist cycle (plan "Musik-Katalog", user 2026-10-16: "Wir cyclen dann ab nun immer durch unsere
 * Artists, bis wir wieder beim ersten Artist ankommen in der BG Music"; "Jede Runde neu gemischt"; "DJ Bitcoin: Ein
 * Artist wie jeder andere"). A round plays one track of every artist, the artists in a random order; the next round
 * starts only when every artist has played. No artist plays again within `gap` tracks, so a round never opens with
 * the artist that closed the one before. An artist's tracks go round a bag: none repeats before all of them played.
 *
 * The state is what has played, not what is queued, so MusicTimeline keeps it in its file and a restart or a deploy
 * goes on with it (user 2026-10-16: "deploy und stream restarts dürfen ab jetzt auch nicht mehr die Playlist
 * Reihenfolge irgendwie neustarten"). A changed library never resets it: a new track joins the open part of its
 * artist's bag, a new artist joins the current round while at least MIN_PENDING_FOR_NEW artists are still to play in
 * it (else the next round), and a removed track or artist drops out.
 */
final class ArtistCycle
{
    /** The state's format; another one is migrated from the timeline's history (MusicTimeline). */
    public const VERSION = 1;

    /** A new artist joins the current round only while at least this many artists are left in it. */
    public const MIN_PENDING_FOR_NEW = 3;

    /** @var array<string, string> */
    private array $artistOf = [];

    /**
     * @param  array<string, list<string>>  $byArtist  every artist's tracks
     * @param  array<string, list<string>>  $played  per known artist, the tracks of its bag played (the latest last)
     * @param  list<string>  $pending  the artists still to play in the current round
     * @param  list<string>  $recent  the artists played last, the latest last
     */
    public function __construct(
        private array $byArtist,
        private array $played = [],
        private array $pending = [],
        private array $recent = [],
        private ?string $title = null,
        private int $gap = 1,
    ) {
        $this->gap = max(1, $gap);
        $known = $this->played;
        $this->played = [];

        foreach ($byArtist as $artist => $files) {
            $artist = (string) $artist;
            $this->played[$artist] = array_values(array_intersect($known[$artist] ?? [], $files));

            foreach ($files as $file) {
                $this->artistOf[$file] = $artist;
            }
        }

        $this->pending = array_values(array_intersect($pending, array_keys($this->played)));
        $new = array_keys(array_diff_key($this->played, $known));

        if ($known !== [] && count($this->pending) >= self::MIN_PENDING_FOR_NEW) {
            array_push($this->pending, ...$new);
        }

        $this->recent = array_slice($recent, -$this->gap);
    }

    /**
     * A cycle from its kept state, null when there is none of this format (then it is migrated).
     *
     * @param  array<string, list<string>>  $byArtist
     */
    public static function fromState(array $byArtist, mixed $state, int $gap): ?self
    {
        if (! is_array($state) || ($state['version'] ?? null) !== self::VERSION) {
            return null;
        }

        $strings = fn (mixed $list): array => array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
        $played = [];

        foreach (is_array($state['played'] ?? null) ? $state['played'] : [] as $artist => $files) {
            $played[(string) $artist] = $strings($files);
        }

        return new self($byArtist, $played, $strings($state['pending'] ?? null), $strings($state['recent'] ?? null), is_string($state['title'] ?? null) ? $state['title'] : null, $gap);
    }

    /**
     * A new cycle that has heard `$history` (the tracks played lately, the oldest first): they count as played, so
     * none of them comes back before its artist's other tracks.
     *
     * @param  array<string, list<string>>  $byArtist
     * @param  list<string>  $history
     */
    public static function seeded(array $byArtist, array $history, int $gap): self
    {
        $cycle = new self($byArtist, [], [], [], null, $gap);

        foreach ($history as $file) {
            $cycle->played($file);
        }

        return $cycle;
    }

    /**
     * @return array{version: int, played: array<string, list<string>>, pending: list<string>, recent: list<string>, title: string|null}
     */
    public function toState(): array
    {
        return ['version' => self::VERSION, 'played' => $this->played, 'pending' => $this->pending, 'recent' => $this->recent, 'title' => $this->title];
    }

    /**
     * A track has played: its artist leaves the current round (a new round starts when none was open), the track
     * joins its artist's played part of the bag (the bag starts over when it held nothing else). A file of no artist
     * here (removed meanwhile) only sets the title.
     */
    public function played(string $file): void
    {
        $this->title = MusicPlaylist::title($file);
        $artist = $this->artistOf[$file] ?? null;

        if ($artist === null) {
            return;
        }

        if (in_array($artist, $this->pending, true)) {
            $this->pending = array_values(array_diff($this->pending, [$artist]));
        } elseif ($this->pending === []) {
            $this->pending = array_values(array_diff(array_keys($this->played), [$artist]));
        }

        if (array_diff($this->byArtist[$artist], $this->played[$artist]) === []) {
            $this->played[$artist] = [];
        }

        if (! in_array($file, $this->played[$artist], true)) {
            $this->played[$artist][] = $file;
        }

        $this->recent = array_slice([...$this->recent, $artist], -$this->gap);
    }

    /**
     * The next track: an artist still to play in this round, at random, other than the last `gap` artists and one
     * whose open tracks hold a title other than the last; then a random open track of it with another title. Where
     * too few artists leave no other choice the gap bends, counted in `$bends`; the title gives way only for an
     * artist with that single title.
     */
    public function next(Randomizer $random, int &$bends = 0): string
    {
        if ($this->pending === []) {
            $this->pending = array_keys($this->played);
        }

        $candidates = $random->shuffleArray($this->pending);
        $fits = fn (string $artist): bool => $this->other($this->open($artist)) !== [];
        $artist = self::first($candidates, fn (string $artist): bool => ! in_array($artist, $this->recent, true) && $fits($artist))
            ?? self::first($candidates, $fits)
            ?? $candidates[0];
        $bends += in_array($artist, $this->recent, true) ? 1 : 0;

        $open = $this->other($this->open($artist)) ?: $this->other($this->byArtist[$artist]) ?: $this->open($artist);
        $file = $open[$random->getInt(0, count($open) - 1)];
        $this->played($file);

        return $file;
    }

    /**
     * The artist's tracks not played in its bag yet; when all have, all again but the one played last.
     *
     * @return list<string>
     */
    private function open(string $artist): array
    {
        $files = $this->byArtist[$artist];
        $open = array_values(array_diff($files, $this->played[$artist]));

        if ($open !== []) {
            return $open;
        }

        $last = $this->played[$artist][count($this->played[$artist]) - 1] ?? null;

        return count($files) > 1 ? array_values(array_diff($files, [$last])) : $files;
    }

    /**
     * The tracks whose title is not the one played last.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function other(array $files): array
    {
        return array_values(array_filter($files, fn (string $file): bool => MusicPlaylist::title($file) !== $this->title));
    }

    /**
     * @param  list<string>  $items
     * @param  callable(string): bool  $test
     */
    private static function first(array $items, callable $test): ?string
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return $item;
            }
        }

        return null;
    }
}
