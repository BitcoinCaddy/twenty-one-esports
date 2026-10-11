<?php

namespace App\Support\Caster;

use App\Models\WotLevel;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The web of trust of the league's trust anchors over the Nostr follow graph
 * (user 2026-10-11: "level 1 oder level 2 des graphen dann npub vertrauen"):
 * level 1 = the anchors themselves and everyone an anchor follows (kind 3,
 * NIP-02); level 2 = everyone at least `wot_level_two_min_followers` distinct
 * level-1 accounts follow (a single account cannot vouch a chain of sock
 * puppets in). Taken out again: every pubkey an anchor mutes (kind 10000,
 * NIP-51) or reports (kind 1984, NIP-56).
 *
 * Recomputed daily (`caster:chat --wot`) as a whole and stored as scalars
 * (pubkey => level). A failed read keeps yesterday's levels: an empty answer
 * never replaces a known graph.
 */
class WebOfTrust
{
    /** Follow lists read for level 2 at most (the largest level-1 sets are cut, not the anchors). */
    public const MAX_LEVEL_ONE_LISTS = 3000;

    /** Level-2 pubkeys stored at most (about 5 MB of rows): the ones most level-1 accounts follow. */
    public const MAX_LEVEL_TWO = 50_000;

    /** Level-1 follow lists read per fetch: each batch is counted and dropped before the next one is read. */
    public const LISTS_PER_FETCH = 100;

    /** Authors per filter of one REQ. */
    private const AUTHORS_PER_FILTER = 100;

    public function __construct(private RelayReader $reader) {}

    /**
     * The levels from the anchors' and level-1 accounts' follow lists.
     *
     * @param  list<string>  $anchors
     * @param  array<string, list<string>>  $follows  author => the pubkeys their kind 3 lists
     * @param  list<string>  $excluded  muted or reported by an anchor
     * @return array<string, int> pubkey => 1 or 2
     */
    public static function levels(array $anchors, array $follows, array $excluded, int $minFollowers = 2): array
    {
        $levelOne = self::levelOne($anchors, $follows);
        $followers = [];

        foreach (array_keys($levelOne) as $author) {
            self::countFollowers($followers, $levelOne, $follows[$author] ?? []);
        }

        return self::assemble($anchors, $levelOne, $followers, $excluded, $minFollowers);
    }

    /**
     * Level 1: the anchors and everyone an anchor follows.
     *
     * @param  list<string>  $anchors
     * @param  array<string, list<string>>  $anchorLists  anchor => the pubkeys their kind 3 lists
     * @return array<string, true>
     */
    public static function levelOne(array $anchors, array $anchorLists): array
    {
        $levelOne = array_fill_keys($anchors, true);

        foreach ($anchors as $anchor) {
            foreach ($anchorLists[$anchor] ?? [] as $followed) {
                $levelOne[$followed] = true;
            }
        }

        return $levelOne;
    }

    /**
     * Count one level-1 account's follows outside level 1. The counter holds
     * every level-2 candidate at once, the largest structure of a run, so a
     * hex pubkey is keyed by its raw 32 bytes. Any other key (the pure
     * levels() takes names) keeps its text; one of exactly 32 bytes would
     * read back as hex, so it is not counted.
     *
     * @param  array<string, int>  $followers  packed pubkey => level-1 followers
     * @param  array<string, true>  $levelOne
     * @param  list<string>  $followed
     */
    public static function countFollowers(array &$followers, array $levelOne, array $followed): void
    {
        foreach (array_unique($followed) as $pubkey) {
            if (! isset($levelOne[$pubkey]) && strlen($pubkey) !== 32) {
                $key = NostrKeys::isHexPubkey($pubkey) ? (string) hex2bin($pubkey) : $pubkey;
                $followers[$key] = ($followers[$key] ?? 0) + 1;
            }
        }
    }

    /**
     * Read the graph from the relays and store it. null when the anchors'
     * own lists could not be read (yesterday's levels stay).
     *
     * Streaming: the level-1 follow lists are read LISTS_PER_FETCH authors
     * at a time and only counted, never kept. Holding all of them at once
     * (up to 3000 kind 3 events, thousands of tags each) hit the 512 MB
     * memory limit on prod for 40,430 pubkeys (2026-10-11).
     *
     * @param  list<string>  $anchors
     */
    public function run(array $anchors, CarbonImmutable $now): ?int
    {
        $anchors = array_values(array_filter(array_unique($anchors), NostrKeys::isHexPubkey(...)));

        if ($anchors === []) {
            return null;
        }

        $anchorLists = $this->follows($anchors);

        if ($anchorLists === []) {
            return null;
        }

        $levelOne = self::levelOne($anchors, $anchorLists);
        $followers = [];

        foreach ($anchorLists as $list) {
            self::countFollowers($followers, $levelOne, $list);
        }
        unset($anchorLists);

        $others = array_slice(array_values(array_diff(array_keys($levelOne), $anchors)), 0, self::MAX_LEVEL_ONE_LISTS);

        foreach (array_chunk($others, self::LISTS_PER_FETCH) as $chunk) {
            foreach ($this->follows($chunk) as $list) {
                self::countFollowers($followers, $levelOne, $list);
            }
        }
        unset($others);

        $excluded = [];
        foreach ($this->reader->fetch(array_map(fn (array $chunk): array => ['kinds' => [10000, 1984], 'authors' => $chunk, 'limit' => 500], array_chunk($anchors, self::AUTHORS_PER_FILTER)), perAuthor: 50) as $event) {
            foreach ($event->tagsNamed('p') as $tag) {
                if (isset($tag[0]) && NostrKeys::isHexPubkey($tag[0])) {
                    $excluded[] = $tag[0];
                }
            }
        }

        $levels = self::assemble($anchors, $levelOne, $followers, $excluded, (int) config('tv.commentary.chat.wot_level_two_min_followers', 2));
        unset($followers, $levelOne);

        DB::transaction(function () use ($levels, $now): void {
            WotLevel::query()->delete();

            foreach (array_chunk($levels, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $pubkey => $level) {
                    $rows[] = ['pubkey' => (string) $pubkey, 'level' => $level, 'computed_at' => $now];
                }
                WotLevel::query()->insert($rows);
            }
        });

        return count($levels);
    }

    /**
     * Level 1, then level 2 from the counted followers (capped, the
     * most-followed first), minus the excluded (never an anchor).
     *
     * @param  list<string>  $anchors
     * @param  array<string, true>  $levelOne
     * @param  array<string, int>  $followers  from countFollowers()
     * @param  list<string>  $excluded
     * @return array<string, int>
     */
    private static function assemble(array $anchors, array $levelOne, array $followers, array $excluded, int $minFollowers): array
    {
        $levels = array_map(fn (): int => 1, $levelOne);

        arsort($followers);
        $levelTwo = 0;
        foreach ($followers as $key => $count) {
            if ($count < $minFollowers || $levelTwo >= self::MAX_LEVEL_TWO) {
                break;
            }

            $key = (string) $key;
            $levels[strlen($key) === 32 ? bin2hex($key) : $key] = 2;
            $levelTwo++;
        }

        foreach ($excluded as $pubkey) {
            if (! in_array($pubkey, $anchors, true)) {
                unset($levels[$pubkey]);
            }
        }

        return $levels;
    }

    /**
     * The newest kind 3 of each author: author => followed pubkeys.
     *
     * @param  list<string>  $authors
     * @return array<string, list<string>>
     */
    private function follows(array $authors): array
    {
        if ($authors === []) {
            return [];
        }

        $filters = array_map(fn (array $chunk): array => ['kinds' => [3], 'authors' => $chunk, 'limit' => count($chunk)], array_chunk($authors, self::AUTHORS_PER_FILTER));
        $lists = [];

        foreach ($this->reader->fetch($filters, perAuthor: 1) as $event) {
            /** @var SignedEvent $event */
            $lists[$event->pubkey] = array_values(array_unique(array_filter(
                array_map(fn (array $tag): string => (string) ($tag[0] ?? ''), $event->tagsNamed('p')),
                NostrKeys::isHexPubkey(...),
            )));
        }

        return $lists;
    }
}
