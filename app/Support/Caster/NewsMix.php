<?php

namespace App\Support\Caster;

use App\Models\CasterNews;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The balance of the news (plan "TWENTY ONE Radio", P15; user 2026-10-12: "Wir machen zu viel Politik-News, was ist
 * denn mit der blocktrainer.de Quelle geworden", then "etwas zu viel Bitcoin, mehr heise Technik"): every stored
 * headline belongs to one tier — bitcoin (the Bitcoin, Nostr and Lightning feeds), tech (heise and the other tech
 * feeds), money (a general feed's item on inflation, central banks, the euro, banks or crypto taxes) or politics
 * (everything else of the general feeds) — and a bulletin is composed by `tv.commentary.news.quota`: about
 * `shares.bitcoin` Bitcoin, `shares.tech` tech, the rest money before politics, politics at most `politics_max`
 * of the slot type. A tier that is short is filled by the other of Bitcoin and tech first, then money, politics
 * last (still capped): a thin news day makes a shorter bulletin, not a political one.
 *
 * Freshness per feed: a feed's own `fresh_hours`, else its section's (`quota.fresh_hours`), else the bulletin's
 * `fresh_hours`; Blocktrainer writes about one article a day, so twelve hours would never find one. Rows are kept
 * at least that long (`keepHours()`).
 *
 * @phpstan-type Targets array{bitcoin: int, tech: int, other: int, politics_max: int}
 */
final class NewsMix
{
    public const BITCOIN = 'bitcoin';

    public const TECH = 'tech';

    public const MONEY = 'money';

    public const POLITICS = 'politics';

    /** Money policy in a general feed's headline or teaser (the default of `quota.money_pattern`). */
    public const MONEY_PATTERN = '/\b(?:inflation\w*|teuerung\w*|deflation\w*|zentralbank\w*|notenbank\w*|ezb|fed|federal reserve|us-notenbank|leitzins\w*|zinsen|zinswende|zinssenkung\w*|zinserhöhung\w*|geldpolitik\w*|lagarde|powell|bundesbank|digitale[nrs]? euro|e-euro|euro-?raum|euro-?zone|euro-?kurs|bargeld\w*|bank|banken\w*|bankenaufsicht|sparkasse\w*|kryptosteuer\w*|krypto-steuer\w*|steuer\w* auf krypto\w*|krypto\w*|bitcoin\w*|stablecoin\w*)\b/iu';

    /** An altcoin headline (the default of `quota.altcoin_pattern`): the Altcoin-Hype-Detektor's material, not the bulletin's. */
    public const ALTCOIN_PATTERN = '/\b(?:altcoins?|ethereum|ether|eth|xrp|ripple|solana|cardano|dogecoin|doge|shiba(?: inu)?|memecoins?|meme-coins?|pepe|bnb|tron|avalanche|polkadot|litecoin|chainlink|toncoin|hyperliquid|presale|airdrop\w*)\b/iu';

    /** An altcoin headline that is still a market event worth a bulletin (the default of `quota.altcoin_major_pattern`). */
    public const ALTCOIN_MAJOR_PATTERN = '/\b(?:bitcoin|btc|crash\w*|kurssturz|kurseinbruch|liquidation\w*|börsenbeben)\b/iu';

    /**
     * The tier of a headline of `$section`: bitcoin and tech by their feed's section, a general item money when its
     * headline or teaser is about money policy, else politics.
     */
    public static function tier(string $section, string $title, ?string $summary = null): string
    {
        if ($section === CasterNews::BITCOIN) {
            return self::BITCOIN;
        }

        if ($section === CasterNews::TECH) {
            return self::TECH;
        }

        $pattern = (string) config('tv.commentary.news.quota.money_pattern', self::MONEY_PATTERN);

        return @preg_match($pattern, $title.' '.($summary ?? '')) === 1 ? self::MONEY : self::POLITICS;
    }

    /** Whether a Bitcoin-feed headline is altcoin hype (left to the Altcoin-Hype-Detektor) and no major market event. */
    public static function altcoinHype(string $title): bool
    {
        $settings = (array) config('tv.commentary.news.quota', []);

        return @preg_match((string) ($settings['altcoin_pattern'] ?? self::ALTCOIN_PATTERN), $title) === 1
            && @preg_match((string) ($settings['altcoin_major_pattern'] ?? self::ALTCOIN_MAJOR_PATTERN), $title) !== 1;
    }

    /** The place of `$source` among the first outlets of `$tier` (`quota.first`: EINUNDZWANZIG News, Blocktrainer; heise), PHP_INT_MAX for any other. */
    public static function firstRank(string $tier, string $source): int
    {
        $first = array_values((array) (((array) config('tv.commentary.news.quota.first', []))[$tier] ?? []));
        $at = array_search($source, $first, true);

        return $at === false ? PHP_INT_MAX : (int) $at;
    }

    /** How long a headline of `$feed` (in `$section`) stays eligible for a bulletin, a Presseschau and the news talk. */
    public static function freshHours(string $feed, string $section): int
    {
        $feeds = (array) config('tv.commentary.news.feeds', []);
        $own = is_array($feeds[$feed] ?? null) ? ($feeds[$feed]['fresh_hours'] ?? null) : null;

        if (is_numeric($own)) {
            return max(1, (int) $own);
        }

        $bySection = (array) config('tv.commentary.news.quota.fresh_hours', []);

        return max(1, (int) ($bySection[$section] ?? config('tv.commentary.programme.bulletin.fresh_hours', 12)));
    }

    /** How long a row of `$feed` is kept: `news.keep_hours`, longer for a feed that stays fresh longer. */
    public static function keepHours(string $feed, string $section): int
    {
        return max((int) config('tv.commentary.news.keep_hours', 48), self::freshHours($feed, $section));
    }

    /**
     * Limits `$query` to the rows that are fresh by their own feed's window, published by `$now`.
     *
     * @template TModel of CasterNews
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function fresh(Builder $query, CarbonImmutable $now): Builder
    {
        $now = $now->utc();
        $groups = [];

        foreach (array_keys((array) config('tv.commentary.news.feeds', [])) as $feed) {
            $section = (string) (config("tv.commentary.news.feeds.{$feed}.section") ?? CasterNews::GERMANY);
            $groups[self::freshHours((string) $feed, $section)][] = (string) $feed;
        }

        $configured = array_merge([], ...array_values($groups));
        $sections = array_unique([...CasterNews::SECTIONS, ...array_keys((array) config('tv.commentary.news.quota.fresh_hours', []))]);

        return $query->where('published_at', '<=', $now)->where(function (Builder $fresh) use ($groups, $configured, $sections, $now): void {
            foreach ($groups as $hours => $feeds) {
                $fresh->orWhere(fn (Builder $group) => $group->whereIn('feed', $feeds)->where('published_at', '>=', $now->subHours((int) $hours)));
            }

            // A row of a feed no longer configured (or a test's): its section's window.
            foreach ($sections as $section) {
                $fresh->orWhere(fn (Builder $group) => $group->whereNotIn('feed', $configured)->where('section', $section)
                    ->where('published_at', '>=', $now->subHours(self::freshHours('', (string) $section))));
            }
        });
    }

    /**
     * The tier targets of a bulletin of `$type` with `$count` items.
     *
     * @return Targets
     */
    public static function targets(string $type, int $count): array
    {
        $settings = (array) config('tv.commentary.news.quota', []);
        $shares = (array) ($settings['shares'] ?? []);
        $bitcoin = min($count, (int) round($count * (float) ($shares[self::BITCOIN] ?? 0.4)));
        $tech = min($count - $bitcoin, (int) round($count * (float) ($shares[self::TECH] ?? 0.4)));

        return ['bitcoin' => $bitcoin, 'tech' => $tech, 'other' => $count - $bitcoin - $tech,
            'politics_max' => max(0, (int) (((array) ($settings['politics_max'] ?? []))[$type] ?? 1))];
    }

    /**
     * `$wanted` items of the tier pools by the targets of `$type`, round by round (Bitcoin, tech, then money before
     * politics), then the shortfall: Bitcoin and tech for each other, money, politics last; politics never above its
     * cap. `$pick` gives the index in a pool of the next item (a new outlet, never a story already picked) or null.
     *
     * @template TItem of array<string, mixed>
     *
     * @param  array<string, list<TItem>>  $pools  tier => items
     * @param  callable(list<TItem>, list<TItem>): ?int  $pick
     * @return list<TItem>
     */
    public static function compose(array $pools, string $type, int $wanted, callable $pick): array
    {
        $targets = self::targets($type, $wanted);
        $picked = [];
        $taken = [self::BITCOIN => 0, self::TECH => 0, self::MONEY => 0, self::POLITICS => 0];

        $take = function (string $tier) use (&$pools, &$picked, &$taken, $pick, $targets): bool {
            if ($tier === self::POLITICS && $taken[self::POLITICS] >= $targets['politics_max']) {
                return false;
            }

            $choice = $pick($pools[$tier] ?? [], $picked);

            if ($choice === null) {
                return false;
            }

            $picked[] = $pools[$tier][$choice];
            array_splice($pools[$tier], $choice, 1);
            $taken[$tier]++;

            return true;
        };

        // The targets, round by round, so the bulletin alternates.
        do {
            $progress = false;

            foreach ([self::BITCOIN, self::TECH] as $tier) {
                if (count($picked) < $wanted && $taken[$tier] < $targets[$tier] && $take($tier)) {
                    $progress = true;
                }
            }

            if (count($picked) < $wanted && $targets['other'] > $taken[self::MONEY] + $taken[self::POLITICS] && ($take(self::MONEY) || $take(self::POLITICS))) {
                $progress = true;
            }
        } while ($progress && count($picked) < $wanted);

        // The shortfall: Bitcoin and tech fill each other (the one with fewer first), then money, politics last.
        while (count($picked) < $wanted) {
            $order = $taken[self::TECH] < $taken[self::BITCOIN] ? [self::TECH, self::BITCOIN] : [self::BITCOIN, self::TECH];

            if (! ($take($order[0]) || $take($order[1]) || $take(self::MONEY) || $take(self::POLITICS))) {
                break;
            }
        }

        return $picked;
    }

    /**
     * Items in a balanced order for the news talk: Bitcoin, tech and the rest taking turns, politics last.
     *
     * @template TItem of array{section: string, source: string, title: string, summary?: string}
     *
     * @param  list<TItem>  $items
     * @return list<TItem>
     */
    public static function balanced(array $items): array
    {
        $byTier = [self::BITCOIN => [], self::TECH => [], self::MONEY => [], self::POLITICS => []];

        foreach ($items as $item) {
            $byTier[self::tier($item['section'], $item['title'], $item['summary'] ?? null)][] = $item;
        }

        // The first outlets of a tier first (EINUNDZWANZIG News, Blocktrainer; heise).
        foreach ($byTier as $tier => $tierItems) {
            usort($byTier[$tier], fn (array $a, array $b): int => self::firstRank($tier, $a['source']) <=> self::firstRank($tier, $b['source']));
        }

        $ordered = [];

        while (array_filter($byTier) !== []) {
            foreach ([self::BITCOIN, self::TECH, self::MONEY] as $tier) {
                if ($byTier[$tier] !== []) {
                    $ordered[] = array_shift($byTier[$tier]);
                }
            }

            if ($byTier[self::BITCOIN] === [] && $byTier[self::TECH] === [] && $byTier[self::MONEY] === [] && $byTier[self::POLITICS] !== []) {
                $ordered[] = array_shift($byTier[self::POLITICS]);
            }
        }

        return $ordered;
    }
}
