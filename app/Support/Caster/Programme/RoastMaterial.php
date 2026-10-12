<?php

namespace App\Support\Caster\Programme;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\CasterNews;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\NewsFilter;
use App\Support\Caster\PersonaCards;
use App\Support\Caster\PodcastFeed;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\WorldMemory;
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The real material of the eight roast formats (plan "TWENTY ONE Radio",
 * P10; Roasts): each builder reads its source, keeps only what the source
 * says and hands it over as data with every number in words
 * (RoastFacts::spell()). A source that is down, empty or unreadable gives
 * null: the format is skipped, never filled in.
 *
 * Downloads go through download(): cached `roasts.cache_minutes` on success,
 * `roasts.retry_seconds` on failure, logged once per source and ten minutes.
 *
 * @phpstan-import-type RubricMaterial from Rubrics
 */
class RoastMaterial
{
    private const USED = 'caster:roast:used:';

    private const TREASURY_SNAPSHOT = 'caster:roast:treasury_snapshot';

    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /** Words that make a Nostr post one we do not air (children, school, family details), on top of PersonaCards::sensitive(). */
    private const MINOR_WORDS = ['schule', 'schüler', 'schülerin', 'klasse', 'hausaufgaben', 'mama', 'papa', 'mein sohn', 'meine tochter', 'kinder', 'kind',
        'jahre alt', 'geburtstag', 'krankenhaus', 'adresse', 'telefonnummer'];

    /** Words of an altcoin story; a headline needs one of them. */
    private const ALTCOIN_WORDS = ['altcoin', 'ethereum', 'ether', 'solana', 'xrp', 'ripple', 'cardano', 'dogecoin', 'doge', 'shiba', 'memecoin', 'meme coin',
        'token', 'tokens', 'airdrop', 'defi', 'nft', 'nfts', 'stablecoin', 'tether', 'usdt', 'usdc', 'binance', 'bnb', 'tron', 'avalanche', 'polkadot',
        'chainlink', 'litecoin', 'pepe', 'bonk', 'sui', 'aptos', 'ton', 'toncoin', 'hyperliquid', 'pump.fun', 'presale', 'ico', 'web3', 'layer-2', 'l2', 'staking'];

    /** Words of a hollow political phrase; a quoted headline with more of them ranks higher. */
    private const PHRASE_WORDS = ['müssen', 'muss', 'klar', 'zeichen', 'signal', 'verantwortung', 'zusammen', 'gemeinsam', 'zukunft', 'respekt', 'wende',
        'weg', 'herausforderung', 'entschlossen', 'konsequent', 'handeln', 'chance', 'aufbruch', 'zusammenhalt', 'priorität', 'alles tun', 'nicht hinnehmen',
        'werden', 'dürfen', 'brauchen', 'endlich', 'stark', 'sicher', 'fair', 'ehrlich', 'solidarität', 'transformation', 'zeitenwende', 'offensive'];

    public function __construct(private readonly ?RelayReader $reader = null) {}

    /**
     * The newest statements of the ECB and the Fed: title, date and the first sentences of the release, verbatim (English).
     *
     * @return RubricMaterial|null
     */
    public function centralBanks(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.roasts.sources.central_banks', []);
        $blocks = [];
        $sources = [];
        $links = [];

        foreach ((array) ($settings['feeds'] ?? []) as $feed) {
            if (! is_array($feed) || ! is_string($feed['url'] ?? null)) {
                continue;
            }

            $xml = $this->download('cb:'.md5($feed['url']), $feed['url']);
            $items = $xml === null ? [] : array_values(array_filter(NewsFeeds::parse($xml), fn (array $item): bool => $item['link'] !== null
                && ($item['published_at'] === null || $item['published_at']->greaterThan($now->subDays(max(1, (int) ($settings['max_days'] ?? 45)))))));

            if ($items === []) {
                continue;
            }

            // The decision itself first (a rate decision, an FOMC statement), else the newest release.
            $preferred = (string) ($feed['prefer'] ?? '');
            $only = (string) ($feed['only'] ?? '');
            $candidates = $only === '' ? $items : array_values(array_filter($items, fn (array $item): bool => str_contains((string) $item['link'], $only)));
            $item = ($preferred === '' ? null : collect($candidates)->first(fn (array $item): bool => preg_match($preferred, $item['title']) === 1)) ?? ($candidates[0] ?? null);

            if ($item === null) {
                continue;
            }

            $page = $this->download('cb:page:'.md5((string) $item['link']), (string) $item['link']);
            $sentences = $page === null ? [] : self::leadSentences($page, (string) ($feed['start'] ?? '<main'), max(1, (int) ($settings['sentences'] ?? 4)));
            $name = (string) ($feed['name'] ?? 'Zentralbank');
            $date = $item['published_at'] === null ? '' : ', veröffentlicht '.self::spokenDate($item['published_at']);
            $lines = ["{$name}{$date}, Titel (Englisch): „".RoastFacts::spell($item['title'], true).'“'];

            foreach ($sentences as $sentence) {
                $lines[] = '- wörtlich: „'.RoastFacts::spell($sentence, true).'“';
            }

            $blocks[] = implode("\n", $lines);
            $sources[] = (string) ($feed['short'] ?? $name);
            $links[] = (string) $item['link'];
        }

        if ($blocks === []) {
            return null;
        }

        return self::material(implode("\n\n", $blocks), [], $sources, sha1(implode(',', $links)), $links);
    }

    /**
     * One waste case of the Bund der Steuerzahler's Schwarzbuch (resources/data/voice/schwarzbuch.json), the one aired
     * longest ago (never aired first).
     *
     * @return RubricMaterial|null
     */
    public function wasteCase(CarbonImmutable $now): ?array
    {
        $file = resource_path('data/voice/schwarzbuch.json');
        $cases = is_file($file) ? (array) (json_decode((string) file_get_contents($file), true)['cases'] ?? []) : [];
        $cases = array_values(array_filter($cases, fn (mixed $case): bool => is_array($case) && is_string($case['id'] ?? null) && is_string($case['facts'] ?? null) && trim((string) $case['facts']) !== ''));

        if ($cases === []) {
            return null;
        }

        $used = $this->used('schwarzbuch', $now, 90);
        usort($cases, fn (array $a, array $b): int => [($used['case:'.$a['id']] ?? 0), $a['id']] <=> [($used['case:'.$b['id']] ?? 0), $b['id']]);
        $case = $cases[0];

        $data = implode("\n", array_filter([
            'Fall aus dem Schwarzbuch des Bundes der Steuerzahler: „'.RoastFacts::spell((string) $case['title']).'“',
            ($case['subtitle'] ?? '') !== '' ? 'Unterzeile: '.RoastFacts::spell((string) $case['subtitle']) : null,
            'Was passiert ist: '.RoastFacts::spell((string) $case['facts']),
            ($case['verdict'] ?? '') !== '' ? 'Der Bund der Steuerzahler meint: '.RoastFacts::spell((string) $case['verdict']) : null,
        ]));

        return self::material($data, [], ['Schwarzbuch'], 'case:'.$case['id'], ['case:'.$case['id']]);
    }

    /**
     * Altcoin and crypto hype headlines of the last `max_hours` from the crypto feeds, Bitcoin-only stories left out:
     * up to `items` headlines from at least two outlets, none aired within two days.
     *
     * @return RubricMaterial|null
     */
    public function altcoinHype(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.roasts.sources.altcoins', []);
        $used = $this->used('altcoin_hype', $now, 2);
        $byOutlet = [];

        foreach ((array) ($settings['feeds'] ?? []) as $feed) {
            if (! is_array($feed) || ! is_string($feed['url'] ?? null)) {
                continue;
            }

            $xml = $this->download('alt:'.md5($feed['url']), $feed['url']);

            foreach ($xml === null ? [] : NewsFeeds::parse($xml) as $item) {
                $key = 'alt:'.NewsFeeds::hash($item['title']);

                if (! isset($used[$key]) && ($item['published_at'] === null || $item['published_at']->greaterThan($now->subHours(max(1, (int) ($settings['max_hours'] ?? 48)))))
                    && self::isAltcoinStory($item['title'].' '.($item['summary'] ?? ''))) {
                    $byOutlet[(string) ($feed['source'] ?? 'Krypto-Presse')][] = $item + ['key' => $key];
                }
            }
        }

        if (count($byOutlet) < 2) {
            return null;
        }

        // In turn from each outlet, newest first.
        $picked = [];
        $max = max(2, (int) ($settings['items'] ?? 4));
        for ($round = 0; count($picked) < $max && $round < 10; $round++) {
            foreach ($byOutlet as $outlet => $items) {
                if (isset($items[$round]) && count($picked) < $max) {
                    $picked[] = ['outlet' => $outlet] + $items[$round];
                }
            }
        }

        $lines = array_map(fn (array $item): string => '- '.$item['outlet'].': „'.RoastFacts::spell($item['title'], true).'“'
            .($item['summary'] !== null ? ' (Anriss: '.RoastFacts::spell(mb_substr($item['summary'], 0, 300), true).')' : ''), $picked);

        return self::material(implode("\n", $lines), [], array_values(array_unique(array_column($picked, 'outlet'))), sha1(implode(',', array_column($picked, 'key'))), array_column($picked, 'key'));
    }

    /**
     * The league's chess week (`league_days`): the shortest decisive games, who resigned most, who left a game
     * (abandoned), and the longest losing streak. Players by their display name as /matches shows them. null with
     * fewer than `min_games` finished games.
     *
     * @return RubricMaterial|null
     */
    public function leaguePillory(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.roasts.sources.league', []);
        $games = ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Finished)->whereNotNull('white_id')->whereNotNull('black_id')
            ->whereNotNull('ended_at')->where('ended_at', '>=', $now->subDays(max(1, (int) ($settings['days'] ?? 7))))->where('ended_at', '<=', $now)
            ->orderBy('ended_at')->orderBy('id')->limit(2000)->get()
            ->filter(fn (ChessGame $game): bool => ! in_array($game->end_reason instanceof ChessEndReason ? $game->end_reason->value : null, ['aborted', 'voided', 'forfeit'], true)
                && in_array($game->result, ['1-0', '0-1', '1/2-1/2'], true))->values();

        if ($games->count() < max(1, (int) ($settings['min_games'] ?? 3))) {
            return null;
        }

        $name = fn (?User $user): ?string => $user === null || SiteModeration::isHidden($user->pubkey) ? null : SceneFacts::spoken($user->displayName());
        $decisive = [];
        $resigned = [];
        $abandoned = [];
        $streak = [];
        $worst = [];

        foreach ($games as $game) {
            $white = $name($game->white);
            $black = $name($game->black);

            if ($white === null || $black === null) {
                continue;
            }

            $reason = $game->end_reason instanceof ChessEndReason ? $game->end_reason->value : '';
            [$winner, $loser] = match ($game->result) {
                '1-0' => [$white, $black],
                '0-1' => [$black, $white],
                default => [null, null],
            };

            foreach ([$white, $black] as $player) {
                $streak[$player] = $player === $loser ? ($streak[$player] ?? 0) + 1 : 0;
                $worst[$player] = max($worst[$player] ?? 0, $streak[$player]);
            }

            if ($winner === null || $loser === null) {
                continue;
            }

            $moves = intdiv((int) $game->ply + 1, 2);
            $decisive[] = ['winner' => $winner, 'loser' => $loser, 'moves' => $moves, 'how' => SceneFacts::END_REASONS[$reason] ?? null];

            if ($reason === ChessEndReason::Resignation->value) {
                $resigned[$loser] = ($resigned[$loser] ?? 0) + 1;
            }

            if ($reason === ChessEndReason::Abandoned->value) {
                $abandoned[] = $loser;
            }
        }

        if ($decisive === []) {
            return null;
        }

        usort($decisive, fn (array $a, array $b): int => $a['moves'] <=> $b['moves']);
        arsort($resigned);
        arsort($worst);
        $lines = ['Die TWENTY ONE Liga, Schach der letzten sieben Tage:'];

        foreach (array_slice($decisive, 0, 3) as $game) {
            $lines[] = '- Kürzeste Niederlage: '.$game['loser'].' verliert gegen '.$game['winner'].' nach '.RoastFacts::spell((string) $game['moves']).($game['moves'] === 1 ? ' Zug' : ' Zügen')
                .($game['how'] !== null ? ' durch '.$game['how'] : '');
        }

        foreach (array_slice($resigned, 0, 2, true) as $player => $count) {
            $lines[] = '- '.$player.' hat '.($count === 1 ? 'eine Partie' : RoastFacts::spell((string) $count).' Partien').' aufgegeben';
        }

        foreach (array_slice(array_count_values($abandoned), 0, 2, true) as $player => $count) {
            $lines[] = '- '.$player.' hat '.($count === 1 ? 'eine Partie' : RoastFacts::spell((string) $count).' Partien').' durch Verlassen des Bretts verloren (Verbindung weg, nicht zurückgekommen)';
        }

        foreach (array_slice(array_filter($worst, fn (int $count): bool => $count >= 3), 0, 1, true) as $player => $count) {
            $lines[] = '- Längste Niederlagenserie: '.$player.' mit '.RoastFacts::spell((string) $count).' Niederlagen am Stück';
        }

        $names = array_values(array_unique([...array_column(array_slice($decisive, 0, 3), 'winner'), ...array_column(array_slice($decisive, 0, 3), 'loser'),
            ...array_keys(array_slice($resigned, 0, 2, true)), ...array_keys(array_slice(array_count_values($abandoned), 0, 2, true)),
            ...array_keys(array_slice(array_filter($worst, fn (int $count): bool => $count >= 3), 0, 1, true))]));
        $data = implode("\n", $lines);

        return self::material($data, $names, [], sha1($data), []);
    }

    /**
     * The emptiest political phrases of the week: headlines (and teasers) of `outlets` from the last `days` that quote
     * someone („…“), ranked by phrase words, up to `items` of different stories, none aired within a week.
     *
     * @return RubricMaterial|null
     */
    public function emptyPhrases(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.roasts.sources.phrases', []);
        $used = $this->used('phrasendrescher', $now, 7);
        $candidates = [];

        foreach (CasterNews::query()->whereNull('skipped')->whereIn('source', (array) ($settings['outlets'] ?? ['tagesschau', 'Spiegel']))
            ->whereIn('section', [CasterNews::GERMANY, CasterNews::WORLD])
            ->where('published_at', '>=', $now->utc()->subDays(max(1, (int) ($settings['days'] ?? 7))))->where('published_at', '<=', $now->utc())
            ->orderByDesc('published_at')->orderByDesc('id')->limit(800)->get() as $row) {
            $text = $row->title.' '.($row->summary ?? '');
            $quotes = array_values(array_filter(RoastFacts::quotes($text), fn (string $quote): bool => count(preg_split('/\s+/u', $quote, -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 3));

            if ($quotes === [] || isset($used['news:'.$row->id]) || NewsFilter::reason($row->title, $row->language) !== null) {
                continue;
            }

            $lower = mb_strtolower(implode(' ', $quotes));
            $score = count(array_filter(self::PHRASE_WORDS, fn (string $word): bool => preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u', $lower) === 1));
            $candidates[] = ['row' => $row, 'score' => $score];
        }

        usort($candidates, fn (array $a, array $b): int => [$b['score'], $b['row']->published_at->getTimestamp()] <=> [$a['score'], $a['row']->published_at->getTimestamp()]);
        $picked = [];

        foreach ($candidates as $candidate) {
            if (count($picked) < max(1, (int) ($settings['items'] ?? 3))
                && array_filter($picked, fn (CasterNews $taken): bool => NewsBulletin::sameStory($taken->title, $candidate['row']->title)) === []) {
                $picked[] = $candidate['row'];
            }
        }

        if (count($picked) < max(1, (int) ($settings['min_items'] ?? 2))) {
            return null;
        }

        $lines = array_map(fn (CasterNews $row): string => '- '.$row->source.', '.WorldMemory::relative($row->published_at->getTimestamp(), $now).': Schlagzeile „'
            .RoastFacts::spell(CasterVoice::withoutHashtags($row->title)).'“'.($row->summary !== null ? ' Anriss: '.RoastFacts::spell($row->summary) : ''), $picked);

        return self::material(implode("\n", $lines), [], array_values(array_unique(array_map(fn (CasterNews $row): string => $row->source, $picked))),
            sha1(implode(',', array_map(fn (CasterNews $row): string => (string) $row->id, $picked))), array_map(fn (CasterNews $row): string => 'news:'.$row->id, $picked));
    }

    /**
     * Up to `items` public kind 1 posts of the last `hours` from the EINUNDZWANZIG relays: no reply, `min_words` to
     * `max_words` words, nothing sensitive or about children, no hidden author, an author with a kind 0 name, and
     * never a post aired before. Authors by that name only.
     *
     * @return RubricMaterial|null
     */
    public function nostrFinds(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.roasts.sources.nostr', []);
        $relays = array_values(array_filter((array) ($settings['relays'] ?? []), 'is_string'));

        if ($relays === [] || $this->reader === null) {
            return null;
        }

        $used = $this->used('nostr_fundbuero', $now, 3650);
        $deadline = microtime(true) + max(2, (int) ($settings['seconds'] ?? 12));
        $events = $this->reader->fetchCounted([['kinds' => [1], 'since' => $now->subHours(max(1, (int) ($settings['hours'] ?? 24)))->getTimestamp(), 'limit' => 300]], $relays, [], 1, $deadline)['events'];
        $eligible = array_values(array_filter($events, fn (SignedEvent $event): bool => ! isset($used['nostr:'.$event->id]) && self::airablePost($event, $settings)));

        if ($eligible === []) {
            return null;
        }

        $names = [];
        $authors = array_values(array_unique(array_map(fn (SignedEvent $event): string => $event->pubkey, $eligible)));
        foreach ($this->reader->fetchCounted([['kinds' => [0], 'authors' => array_slice($authors, 0, 100), 'limit' => 100]], $relays, [], 1, $deadline + 4)['events'] as $profile) {
            $meta = json_decode($profile->content, true);
            $name = is_array($meta) ? SceneFacts::spoken(is_string($meta['display_name'] ?? null) && trim($meta['display_name']) !== '' ? $meta['display_name'] : (is_string($meta['name'] ?? null) ? $meta['name'] : null)) : null;

            if ($name !== null && mb_strlen($name) <= 40 && ! PersonaCards::sensitive($name)) {
                $names[$profile->pubkey] = $name;
            }
        }

        $picked = array_slice(array_values(array_filter($eligible, fn (SignedEvent $event): bool => isset($names[$event->pubkey]))), 0, max(1, (int) ($settings['items'] ?? 3)));

        if ($picked === []) {
            return null;
        }

        $lines = array_map(fn (SignedEvent $event): string => '- '.$names[$event->pubkey].' schrieb '.WorldMemory::relative($event->createdAt, $now).': „'
            .RoastFacts::spell(self::plainPost($event->content)).'“', $picked);

        return self::material(implode("\n", $lines), array_values(array_unique(array_map(fn (SignedEvent $event): string => $names[$event->pubkey], $picked))), [],
            sha1(implode(',', array_map(fn (SignedEvent $event): string => $event->id, $picked))), array_map(fn (SignedEvent $event): string => 'nostr:'.$event->id, $picked));
    }

    /**
     * Public companies with bitcoin on the balance sheet (CoinGecko's public treasury list): the total, the biggest
     * holders, who bought or sold since the snapshot of a day ago, and who sits on a loss (bought above today's value).
     *
     * @return RubricMaterial|null
     */
    public function corporateStackers(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.roasts.sources.treasuries', []);
        $body = $this->download('treasuries', (string) ($settings['url'] ?? ''));
        $json = $body === null ? null : json_decode($body, true);
        $companies = is_array($json) ? array_values(array_filter((array) ($json['companies'] ?? []), fn (mixed $company): bool => is_array($company)
            && is_string($company['name'] ?? null) && is_numeric($company['total_holdings'] ?? null))) : [];

        if ($companies === [] || ! is_numeric($json['total_holdings'] ?? null)) {
            return null;
        }

        $holdings = [];
        foreach ($companies as $company) {
            $holdings[SceneFacts::spoken((string) $company['name']) ?? (string) $company['name']] = (int) round((float) $company['total_holdings']);
        }

        // Daily snapshots (eight days): compared with the newest one at least twenty hours old.
        $snapshots = array_values(array_filter((array) Cache::get(self::TREASURY_SNAPSHOT, []), fn (mixed $snapshot): bool => is_array($snapshot)
            && is_int($snapshot['at'] ?? null) && is_array($snapshot['holdings'] ?? null) && $snapshot['at'] > $now->subDays(8)->getTimestamp()));
        $older = array_values(array_filter($snapshots, fn (array $snapshot): bool => $snapshot['at'] <= $now->subHours(20)->getTimestamp()));
        $compare = $older === [] ? null : end($older)['holdings'];
        $last = $snapshots === [] ? null : end($snapshots);
        if ($last === null || $last['at'] <= $now->subHours(20)->getTimestamp()) {
            Cache::put(self::TREASURY_SNAPSHOT, [...$snapshots, ['at' => $now->getTimestamp(), 'holdings' => $holdings]], $now->addDays(9));
        }

        $lines = ['Börsennotierte Firmen mit Bitcoin in der Bilanz (Quelle CoinGecko): zusammen '.RoastFacts::spell((string) (int) round((float) $json['total_holdings'])).' Bitcoin bei '
            .RoastFacts::spell((string) count($companies)).' Firmen'];
        $names = [];

        foreach (array_slice($companies, 0, max(1, (int) ($settings['top'] ?? 5))) as $index => $company) {
            $name = SceneFacts::spoken((string) $company['name']) ?? (string) $company['name'];
            $names[] = $name;
            $entry = (float) ($company['total_entry_value_usd'] ?? 0);
            $current = (float) ($company['total_current_value_usd'] ?? 0);
            $lines[] = '- Platz '.RoastFacts::spell((string) ($index + 1)).': '.$name.' mit '.RoastFacts::spell((string) $holdings[$name]).' Bitcoin'
                .($entry > 0 && $current > 0 ? ($current < $entry ? ', aktuell unter dem Einstandswert (gekauft für '.self::billions($entry).', heute '.self::billions($current).')'
                    : ', aktuell über dem Einstandswert (gekauft für '.self::billions($entry).', heute '.self::billions($current).')') : '');
        }

        if ($compare !== null) {
            $changes = [];
            foreach ($holdings as $name => $held) {
                $before = (int) ($compare[$name] ?? 0);
                if ($before > 0 && $held !== $before) {
                    $changes[$name] = $held - $before;
                }
            }
            uasort($changes, fn (int $a, int $b): int => abs($b) <=> abs($a));

            foreach (array_slice($changes, 0, 4, true) as $name => $delta) {
                $names[] = $name;
                $lines[] = '- Seit gestern: '.$name.($delta > 0 ? ' hat '.RoastFacts::spell((string) $delta).' Bitcoin dazugekauft' : ' hat '.RoastFacts::spell((string) -$delta).' Bitcoin verkauft');
            }
        }

        $underwater = array_values(array_filter($companies, fn (array $company): bool => (float) ($company['total_entry_value_usd'] ?? 0) > (float) ($company['total_current_value_usd'] ?? 0)
            && (float) ($company['total_current_value_usd'] ?? 0) > 0));
        foreach (array_slice($underwater, 0, 3) as $company) {
            $name = SceneFacts::spoken((string) $company['name']) ?? (string) $company['name'];
            if (! in_array($name, $names, true)) {
                $names[] = $name;
                $lines[] = '- Unter Wasser: '.$name.' hat für '.self::billions((float) $company['total_entry_value_usd']).' gekauft, heute '.self::billions((float) $company['total_current_value_usd']);
            }
        }

        $data = implode("\n", $lines);

        return self::material($data, array_values(array_unique($names)), ['CoinGecko'], sha1($data), []);
    }

    /**
     * The newest German inflation rate from the Destatis page (the headline figures and the latest press release title)
     * and two real price comparisons (resources/data/voice/preise.json), each with its source. Without the Destatis
     * figure the prices alone (a curated, sourced set), without both null.
     *
     * @return RubricMaterial|null
     */
    public function inflation(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.roasts.sources.inflation', []);
        $page = $this->download('destatis', (string) ($settings['url'] ?? ''));
        $cpi = $page === null ? null : self::destatisFigures($page);

        $file = resource_path('data/voice/preise.json');
        $prices = is_file($file) ? (array) (json_decode((string) file_get_contents($file), true)['prices'] ?? []) : [];
        $prices = array_values(array_filter($prices, fn (mixed $price): bool => is_array($price) && is_string($price['id'] ?? null) && is_array($price['then'] ?? null) && is_array($price['now'] ?? null)));
        $used = $this->used('inflationsbeichte', $now, 30);
        usort($prices, fn (array $a, array $b): int => [($used['price:'.$a['id']] ?? 0), $a['id']] <=> [($used['price:'.$b['id']] ?? 0), $b['id']]);
        $picked = [];
        foreach ($prices as $price) {
            // Two different things (two Maß years are one thing).
            if (count($picked) < 2 && array_filter($picked, fn (array $taken): bool => $taken['item'] === $price['item']) === []) {
                $picked[] = $price;
            }
        }

        if ($cpi === null && $picked === []) {
            return null;
        }

        $lines = [];
        $sources = [];
        if ($cpi !== null) {
            $lines[] = 'Statistisches Bundesamt (Destatis): Inflationsrate im '.$cpi['month'].' '.RoastFacts::spell($cpi['rate']).' Prozent'.($cpi['provisional'] ? ' (vorläufig)' : '');
            if ($cpi['energy'] !== null) {
                $lines[] = '- Verbraucherpreise Energie: '.RoastFacts::spell($cpi['energy']).' Prozent zum Vorjahresmonat';
            }
            if ($cpi['food'] !== null) {
                $lines[] = '- Verbraucherpreise Nahrungsmittel: '.RoastFacts::spell($cpi['food']).' Prozent zum Vorjahresmonat';
            }
            $sources[] = 'Bundesamt';
        }

        foreach ($picked as $price) {
            $lines[] = '- Preisvergleich: '.$price['item'].' kostete '.RoastFacts::spell((string) $price['then']['year']).' '.RoastFacts::spell((string) $price['then']['euro']).' Euro und '
                .RoastFacts::spell((string) $price['now']['year']).' '.RoastFacts::spell((string) $price['now']['euro']).' Euro (Quelle: '.RoastFacts::spell((string) $price['source']).')';
        }

        $data = implode("\n", $lines);
        $keys = array_map(fn (array $price): string => 'price:'.$price['id'], $picked);

        return self::material($data, [], $sources, sha1(($cpi['month'] ?? '').implode(',', $keys)), $keys);
    }

    /**
     * Remembers the keys a format aired (posts, cases, headlines, prices) for its own `used()` window.
     *
     * @param  list<string>  $keys
     */
    public function markUsed(string $format, array $keys, CarbonImmutable $now): void
    {
        if ($keys === []) {
            return;
        }

        $used = (array) Cache::get(self::USED.$format, []);
        foreach ($keys as $key) {
            $used[$key] = $now->getTimestamp();
        }

        // The newest 2000 keys, kept ten years (the Nostr finds must never come again).
        arsort($used);
        Cache::put(self::USED.$format, array_slice($used, 0, 2000, true), $now->addYears(10));
    }

    /**
     * The keys a format aired within `$days` (key => timestamp).
     *
     * @return array<string, int>
     */
    private function used(string $format, CarbonImmutable $now, int $days): array
    {
        $used = Cache::get(self::USED.$format);

        return is_array($used) ? array_filter($used, fn (mixed $at): bool => is_int($at) && $at > $now->subDays($days)->getTimestamp()) : [];
    }

    /**
     * A source's body (cached `cache_minutes`), or null when it failed (cached `retry_seconds`, so a dead source is not
     * hammered every run).
     */
    public function download(string $key, string $url): ?string
    {
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        $settings = (array) config('tv.commentary.programme.roasts', []);
        $cacheKey = 'caster:roast:source:'.$key;
        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            return is_string($stored['body'] ?? null) ? $stored['body'] : null;
        }

        try {
            $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')->connectTimeout(4)
                ->timeout(max(1, (int) ($settings['timeout_seconds'] ?? 8)))->get($url);
            $body = $response->successful() && trim($response->body()) !== '' ? mb_substr($response->body(), 0, 3_000_000) : null;
            $why = $body === null ? 'status '.$response->status() : null;
        } catch (Throwable $exception) {
            $body = null;
            $why = $exception::class;
        }

        if ($body === null && Cache::add('caster:roast:source-failed-logged:'.$key, true, 600)) {
            Log::warning('Caster roast source failed', ['source' => $key, 'url' => $url, 'reason' => $why]);
        }

        Cache::put($cacheKey, ['body' => $body], $body === null ? max(1, (int) ($settings['retry_seconds'] ?? 120)) : 60 * max(1, (int) ($settings['cache_minutes'] ?? 30)));

        return $body;
    }

    /**
     * The first `$count` sentences of a release page after `$start` (`<main`, `id="article"`): paragraphs of sixty
     * characters and more, split at sentence ends, each six to forty words, no "For release at" line.
     *
     * @return list<string>
     */
    public static function leadSentences(string $html, string $start, int $count): array
    {
        $at = strpos($html, $start);
        $html = $at === false ? $html : substr($html, $at);
        preg_match_all('/<p[^>]*>(.*?)<\/p>/su', $html, $paragraphs);
        $sentences = [];

        foreach ($paragraphs[1] as $paragraph) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            if (mb_strlen($text) < 60 || preg_match('/^(for release at|note:|share\b)/iu', $text) === 1 || str_contains($text, '-->')) {
                continue;
            }

            foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', $text) ?: [] as $sentence) {
                $words = count(preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: []);

                if ($words >= 6 && $words <= 40 && count($sentences) < $count) {
                    $sentences[] = trim($sentence);
                }
            }

            if (count($sentences) >= $count) {
                break;
            }
        }

        return $sentences;
    }

    /**
     * The Destatis figures: the newest press release title "Inflationsrate im <Monat> <Jahr> (voraussichtlich|bei)
     * ±x,x %" and the key figures for energy and food; null when the page does not say it.
     *
     * @return array{month: string, rate: string, provisional: bool, energy: string|null, food: string|null}|null
     */
    public static function destatisFigures(string $html): ?array
    {
        $text = (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('/<(script|style)\b.*?<\/\1>/su', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = str_replace("\u{00AD}", '', $text);

        if (preg_match('/Inflationsrate im ((?:'.implode('|', self::MONTHS).') \d{4}) (voraussichtlich |bei )?([+\-−]?\s?\d+,\d) ?%/u', $text, $match) !== 1) {
            return null;
        }

        $figure = function (string $label) use ($text): ?string {
            return preg_match('/([+\-−]\s?\d+,\d) ?% Verbraucherpreise '.$label.'/u', $text, $found) === 1 ? str_replace(' ', '', $found[1]) : null;
        };

        return ['month' => RoastFacts::spell($match[1]), 'rate' => str_replace(' ', '', $match[3]), 'provisional' => trim($match[2]) === 'voraussichtlich', 'energy' => $figure('Energie'), 'food' => $figure('Nahrungsmittel')];
    }

    /** Whether a headline (with its teaser) is about altcoins or crypto hype and not about Bitcoin alone. */
    public static function isAltcoinStory(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach (self::ALTCOIN_WORDS as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a kind 1 post may air: no reply (`e` tag), a public text of `min_words` to `max_words` words (links and
     * references out), nothing sensitive or about children, the author not hidden.
     *
     * @param  array<array-key, mixed>  $settings
     */
    public static function airablePost(SignedEvent $event, array $settings = []): bool
    {
        if ($event->kind !== 1 || $event->tagsNamed('e') !== [] || SiteModeration::isHidden($event->pubkey)) {
            return false;
        }

        $text = self::plainPost($event->content);
        $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $lower = mb_strtolower($text);

        if ($words < (int) ($settings['min_words'] ?? 8) || $words > (int) ($settings['max_words'] ?? 70) || PersonaCards::sensitive($text)) {
            return false;
        }

        foreach (self::MINOR_WORDS as $word) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u', $lower) === 1) {
                return false;
            }
        }

        return true;
    }

    /** A post's text without links, nostr references, hashtags and line breaks, at most 300 characters. */
    public static function plainPost(string $content): string
    {
        $text = (string) preg_replace(['~https?://\S+~u', '/nostr:\S+/u', '/(?:npub|note|nevent|nprofile)1\w+/u'], ' ', $content);
        $text = trim((string) preg_replace('/\s+/u', ' ', CasterVoice::withoutHashtags($text)));

        return mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 300)).' …' : $text;
    }

    /** A dollar amount in billions, spoken ("sechzig Komma fünf Milliarden Dollar"). */
    private static function billions(float $usd): string
    {
        return RoastFacts::spell(number_format($usd / 1_000_000_000, 1, ',', '')).' Milliarden Dollar';
    }

    /** A date as the voice says it: "am achten Oktober zweitausendsechsundzwanzig" (Berlin). */
    private static function spokenDate(CarbonImmutable $at): string
    {
        return RoastFacts::spell(PodcastFeed::spokenDay($at->getTimestamp()));
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $sources
     * @param  list<string>  $keys
     * @return RubricMaterial
     */
    private static function material(string $data, array $names, array $sources, string $hash, array $keys): array
    {
        return ['data' => $data, 'names' => $names, 'sources' => $sources, 'hash' => $hash, 'keys' => $keys, 'same_story' => false];
    }
}
