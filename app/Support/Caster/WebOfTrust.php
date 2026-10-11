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
 * Recomputed daily (`caster:wot`) as a whole and stored as scalars
 * (pubkey => level). A failed read keeps yesterday's levels: an empty answer
 * never replaces a known graph.
 */
class WebOfTrust
{
    /** Follow lists read for level 2 at most (the largest level-1 sets are cut, not the anchors). */
    public const MAX_LEVEL_ONE_LISTS = 3000;

    /** Level-2 pubkeys stored at most (about 5 MB of rows): the ones most level-1 accounts follow. */
    public const MAX_LEVEL_TWO = 50_000;

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
        $levels = array_fill_keys($anchors, 1);

        foreach ($anchors as $anchor) {
            foreach ($follows[$anchor] ?? [] as $followed) {
                $levels[$followed] = 1;
            }
        }

        $followers = [];
        foreach (array_keys($levels) as $levelOne) {
            foreach (array_unique($follows[$levelOne] ?? []) as $followed) {
                if (! isset($levels[$followed])) {
                    $followers[$followed] = ($followers[$followed] ?? 0) + 1;
                }
            }
        }

        // Level 2 is capped (the table stays bounded): the most-followed first.
        arsort($followers);
        $levelTwo = 0;
        foreach ($followers as $pubkey => $count) {
            if ($count < $minFollowers || $levelTwo >= self::MAX_LEVEL_TWO) {
                break;
            }

            $levels[$pubkey] = 2;
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
     * Read the graph from the relays and store it. null when the anchors'
     * own lists could not be read (yesterday's levels stay).
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

        $levelOne = [];
        foreach ($anchorLists as $list) {
            foreach ($list as $pubkey) {
                $levelOne[$pubkey] = true;
            }
        }

        $others = array_slice(array_values(array_diff(array_keys($levelOne), $anchors)), 0, self::MAX_LEVEL_ONE_LISTS);
        $follows = [...$anchorLists, ...$this->follows($others)];

        $excluded = [];
        foreach ($this->reader->fetch(array_map(fn (array $chunk): array => ['kinds' => [10000, 1984], 'authors' => $chunk, 'limit' => 500], array_chunk($anchors, self::AUTHORS_PER_FILTER)), perAuthor: 50) as $event) {
            foreach ($event->tagsNamed('p') as $tag) {
                if (isset($tag[0]) && NostrKeys::isHexPubkey($tag[0])) {
                    $excluded[] = $tag[0];
                }
            }
        }

        $levels = self::levels($anchors, $follows, $excluded, (int) config('tv.commentary.chat.wot_level_two_min_followers', 2));

        DB::transaction(function () use ($levels, $now): void {
            WotLevel::query()->delete();

            foreach (array_chunk(array_map(fn (string $pubkey, int $level): array => ['pubkey' => $pubkey, 'level' => $level, 'computed_at' => $now], array_keys($levels), $levels), 500) as $rows) {
                WotLevel::query()->insert($rows);
            }
        });

        return count($levels);
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
