<?php

use App\Models\CasterNews;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\NewsFilter;
use App\Support\Caster\NewsMix;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsBulletin;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
 * The balance of the news (plan "TWENTY ONE Radio", P15; user 2026-10-12: "Wir machen zu viel Politik-News", then
 * "etwas zu viel Bitcoin, mehr heise Technik"): about 40 % Bitcoin, 40 % tech, the rest money before politics,
 * politics capped; Blocktrainer stays eligible for a week.
 */

beforeEach(function () {
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.bulletin.sirius_chance' => 0.0,
        'tv.commentary.programme.bulletin.league' => false,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 13:55:00', 'Europe/Berlin'));
});

function newsMixRow(string $title, string $source, string $section, int $minutesAgo = 20, string $feed = 'test', ?string $summary = null): CasterNews
{
    return CasterNews::query()->create([
        'hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => $feed, 'source' => $source, 'language' => 'de',
        'title' => $title, 'summary' => $summary, 'link' => null, 'published_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
    ]);
}

/** Three of each tier, distinct stories and outlets per tier. */
function newsMixDesk(): void
{
    newsMixRow('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);
    newsMixRow('Wallet-Anbieter schließt Sicherheitslücke nach Hinweis', 'Blocktrainer', CasterNews::BITCOIN, 30, 'blocktrainer');
    newsMixRow('Ledger-Hack durch offiziellen Distributor: Warum versiegelte Boxen keinen Schutz bieten', 'EINUNDZWANZIG News', CasterNews::BITCOIN, 300);
    newsMixRow('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 14);
    newsMixRow('Neue Grafikkarten verbrauchen deutlich weniger Strom', 'Golem', CasterNews::TECH, 25);
    newsMixRow('Vorratsdatenspeicherung: Provider warnen vor Kosten', 'netzpolitik.org', CasterNews::TECH, 40);
    newsMixRow('EZB senkt den Leitzins erneut um einen Viertelpunkt', 'tagesschau', CasterNews::GERMANY, 15);
    newsMixRow('Inflation in Deutschland steigt im September wieder', 'Spiegel', CasterNews::GERMANY, 16);
    newsMixRow('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'Welt', CasterNews::GERMANY, 10);
    newsMixRow('Koalition legt Entwurf für Pflegereform vor', 'FAZ', CasterNews::GERMANY, 11);
    newsMixRow('Länder streiten über Finanzierung neuer Schulgebäude', 'n-tv', CasterNews::GERMANY, 13);
}

/** A model answer for a bulletin prompt: each item read factually with its outlet, then a commentary. */
function newsMixAnswer(string $prompt): array
{
    preg_match_all('/^(\d+)\. Rubrik: [^|]+\| Quelle: ([^|]+) \| Meldung: (.+)$/mu', $prompt, $items, PREG_SET_ORDER);
    $lines = array_map(fn (array $item): string => $item[1].': Wie '.trim($item[2]).' heute berichtet, lautet die aktuelle Meldung so: '.trim($item[3]).'.', $items);
    $lines[] = 'Kommentar: Ich finde das alles ziemlich absurd, weil der Staat mal wieder zuerst an sich selbst denkt und dann erst an die Leute.';
    $lines[] = 'Und genau deshalb bleibt Bitcoin für mich die ehrlichere Antwort auf diesen ganzen Zirkus aus Berlin und Brüssel.';

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

/** The tiers of the items, in their order. @param list<array<string, mixed>> $items @return list<string> */
function newsMixTiers(array $items): array
{
    return array_map(fn (array $item): string => NewsMix::tier($item['section'], $item['title'], $item['summary'] ?? null), $items);
}

it('sorts a general headline about money policy as money, the rest as politics, the feeds by their section', function () {
    expect(NewsMix::tier(CasterNews::GERMANY, 'EZB senkt den Leitzins erneut'))->toBe(NewsMix::MONEY)
        ->and(NewsMix::tier(CasterNews::GERMANY, 'Bundesregierung plant den digitalen Euro mit Obergrenze'))->toBe(NewsMix::MONEY)
        ->and(NewsMix::tier(CasterNews::WORLD, 'Fed hält die Zinsen stabil'))->toBe(NewsMix::MONEY)
        ->and(NewsMix::tier(CasterNews::GERMANY, 'Koalition streitet über die Rente', 'Die Banken sehen das kritisch.'))->toBe(NewsMix::MONEY)
        ->and(NewsMix::tier(CasterNews::GERMANY, 'Koalition streitet über die Rente'))->toBe(NewsMix::POLITICS)
        ->and(NewsMix::tier(CasterNews::GERMANY, 'Bahn kündigt Milliarden Euro für Sanierung an'))->toBe(NewsMix::POLITICS)
        ->and(NewsMix::tier(CasterNews::TECH, 'Koalition streitet über Chatkontrolle'))->toBe(NewsMix::TECH)
        ->and(NewsMix::tier(CasterNews::BITCOIN, 'Koalition streitet über Steuern'))->toBe(NewsMix::BITCOIN);
});

it('composes a full-hour bulletin of about 40 % Bitcoin, 40 % tech and the rest money before politics', function () {
    newsMixDesk();
    config(['tv.commentary.programme.bulletin.items.news' => ['min' => 5, 'max' => 5]]);

    $items = app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now());

    expect(newsMixTiers($items))->toBe([NewsMix::BITCOIN, NewsMix::TECH, NewsMix::MONEY, NewsMix::BITCOIN, NewsMix::TECH])
        // The association's own news and Blocktrainer first within Bitcoin, heise first within tech.
        ->and(array_column($items, 'source'))->toBe(['EINUNDZWANZIG News', 'heise online', 'tagesschau', 'Blocktrainer', 'Golem']);

    config(['tv.commentary.news.quota.shares' => ['bitcoin' => 0.2, 'tech' => 0.6]]);
    expect(array_count_values(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()))))
        ->toBe([NewsMix::BITCOIN => 1, NewsMix::TECH => 3, NewsMix::MONEY => 1]);
});

it('caps politics at two stories at :00 and one at :30, also on a day of nothing but politics', function () {
    newsMixRow('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'Welt', CasterNews::GERMANY, 10);
    newsMixRow('Koalition legt Entwurf für Pflegereform vor', 'FAZ', CasterNews::GERMANY, 11);
    newsMixRow('Länder streiten über Finanzierung neuer Schulgebäude', 'n-tv', CasterNews::GERMANY, 13);
    newsMixRow('Parteitag wählt neue Doppelspitze in Thüringen', 'Spiegel', CasterNews::GERMANY, 14);
    newsMixRow('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);

    expect(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now())))->toBe([NewsMix::BITCOIN, NewsMix::POLITICS, NewsMix::POLITICS])
        ->and(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::HEADLINES, CarbonImmutable::now())))->toBe([NewsMix::BITCOIN, NewsMix::POLITICS]);

    config(['tv.commentary.news.quota.politics_max.news' => 3]);
    expect(array_count_values(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now())))[NewsMix::POLITICS])->toBe(3);
});

it('fills a short tier in the order Bitcoin and tech for each other, then money, politics last', function () {
    config(['tv.commentary.programme.bulletin.items.news' => ['min' => 6, 'max' => 6]]);
    // No tech today: Bitcoin takes its places before money and politics.
    newsMixRow('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);
    newsMixRow('Wallet-Anbieter schließt Sicherheitslücke nach Hinweis', 'Blocktrainer', CasterNews::BITCOIN, 30);
    newsMixRow('Ledger-Hack durch offiziellen Distributor: Warum versiegelte Boxen keinen Schutz bieten', 'EINUNDZWANZIG News', CasterNews::BITCOIN, 300);
    newsMixRow('Lightning-Zahlungen wachsen bei Händlern in Europa', 'BTC-Echo', CasterNews::BITCOIN, 45);
    newsMixRow('EZB senkt den Leitzins erneut um einen Viertelpunkt', 'tagesschau', CasterNews::GERMANY, 15);
    newsMixRow('Inflation in Deutschland steigt im September wieder', 'Spiegel', CasterNews::GERMANY, 16);
    newsMixRow('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'Welt', CasterNews::GERMANY, 10);
    newsMixRow('Koalition legt Entwurf für Pflegereform vor', 'FAZ', CasterNews::GERMANY, 11);

    expect(array_count_values(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()))))
        ->toBe([NewsMix::BITCOIN => 4, NewsMix::MONEY => 2]);

    // Bitcoin short as well: money next, then politics up to its cap, and no further (a shorter bulletin).
    CasterNews::query()->whereIn('source', ['Blocktrainer', 'EINUNDZWANZIG News'])->delete();
    CasterNews::query()->where('title', 'like', 'Lightning%')->delete();
    newsMixRow('Länder streiten über Finanzierung neuer Schulgebäude', 'n-tv', CasterNews::GERMANY, 13);

    expect(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now())))
        ->toBe([NewsMix::BITCOIN, NewsMix::MONEY, NewsMix::MONEY, NewsMix::POLITICS, NewsMix::POLITICS]);

    // Money beyond its share still goes before politics.
    newsMixRow('Bundesbank warnt vor steigender Teuerung', 'Handelsblatt', CasterNews::GERMANY, 17);
    newsMixRow('Sparkassen erhöhen die Kontogebühren', 'FAZ', CasterNews::GERMANY, 18);

    expect(array_count_values(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()))))
        ->toBe([NewsMix::BITCOIN => 1, NewsMix::MONEY => 4, NewsMix::POLITICS => 1]);
    CasterNews::query()->whereIn('source', ['Handelsblatt'])->delete();
    CasterNews::query()->where('title', 'like', 'Sparkassen%')->delete();

    // Bitcoin missing, tech there: tech takes the Bitcoin places.
    CasterNews::query()->where('section', CasterNews::BITCOIN)->delete();
    newsMixRow('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 14);
    newsMixRow('Neue Grafikkarten verbrauchen deutlich weniger Strom', 'Golem', CasterNews::TECH, 25);
    newsMixRow('Vorratsdatenspeicherung: Provider warnen vor Kosten', 'netzpolitik.org', CasterNews::TECH, 40);
    newsMixRow('Forscher knacken Passwortmanager über manipulierte Browser-Erweiterung', 'heise Security', CasterNews::TECH, 55);

    expect(array_count_values(newsMixTiers(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()))))
        ->toBe([NewsMix::TECH => 4, NewsMix::MONEY => 2]);
});

it('keeps a Blocktrainer article eligible for a week, other Bitcoin feeds three days, the general feeds twelve hours', function () {
    newsMixRow('Blocktrainer erklärt das neue Taproot-Upgrade', 'Blocktrainer', CasterNews::BITCOIN, 60 * 24 * 5, 'blocktrainer');
    newsMixRow('Blocktrainer-Artikel aus dem letzten Monat', 'Blocktrainer', CasterNews::BITCOIN, 60 * 24 * 8, 'blocktrainer');
    newsMixRow('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 60 * 24 * 2, 'btc-echo');
    newsMixRow('Lightning-Zahlungen wachsen bei Händlern in Europa', 'BTC-Echo', CasterNews::BITCOIN, 60 * 24 * 4, 'btc-echo');
    newsMixRow('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 60 * 20, 'heise-top');
    newsMixRow('Neue Grafikkarten verbrauchen deutlich weniger Strom', 'Golem', CasterNews::TECH, 60 * 30, 'golem');
    newsMixRow('EZB senkt den Leitzins erneut um einen Viertelpunkt', 'tagesschau', CasterNews::GERMANY, 60 * 11, 'tagesschau-wirtschaft');
    newsMixRow('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'Welt', CasterNews::GERMANY, 60 * 13, 'welt-deutschland');

    $titles = array_column(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()), 'title');

    expect($titles)->toEqualCanonicalizing([
        'Blocktrainer erklärt das neue Taproot-Upgrade',
        'Miner verlagern Rechenleistung nach Skandinavien',
        'Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa',
        'EZB senkt den Leitzins erneut um einen Viertelpunkt',
    ]);
});

it('stores and keeps a five-day-old Blocktrainer article while the general feeds are pruned to max_rows', function () {
    $published = CarbonImmutable::now()->subDays(5)->toRfc2822String();
    $old = CarbonImmutable::now()->subDays(3)->toRfc2822String();
    config(['tv.commentary.news.feeds' => [
        'blocktrainer' => [...config('tv.commentary.news.feeds.blocktrainer'), 'url' => 'https://www.blocktrainer.de/feed.xml'],
        'welt-deutschland' => config('tv.commentary.news.feeds.welt-deutschland'),
    ], 'tv.commentary.news.max_rows' => 2]);
    Http::fake([
        'www.blocktrainer.de/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><item><title>Blocktrainer erklärt das neue Taproot-Upgrade</title><pubDate>'.$published.'</pubDate></item></channel></rss>'),
        'www.welt.de/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel>'
            .'<item><title>Bundestag beschließt Reform der Grundsteuer für Eigentümer</title><pubDate>'.CarbonImmutable::now()->subMinutes(10)->toRfc2822String().'</pubDate></item>'
            .'<item><title>Koalition legt Entwurf für Pflegereform vor</title><pubDate>'.CarbonImmutable::now()->subMinutes(20)->toRfc2822String().'</pubDate></item>'
            .'<item><title>Länder streiten über Finanzierung neuer Schulgebäude</title><pubDate>'.CarbonImmutable::now()->subMinutes(30)->toRfc2822String().'</pubDate></item>'
            .'<item><title>Uralte Meldung von vorgestern</title><pubDate>'.$old.'</pubDate></item>'
            .'</channel></rss>'),
    ]);

    app(NewsFeeds::class)->fetch(CarbonImmutable::now());

    expect(CasterNews::query()->where('feed', 'blocktrainer')->pluck('title')->all())->toBe(['Blocktrainer erklärt das neue Taproot-Upgrade'])
        ->and(CasterNews::query()->where('feed', 'welt-deutschland')->count())->toBe(2)
        ->and(CasterNews::query()->where('title', 'Uralte Meldung von vorgestern')->exists())->toBeFalse();

    $this->travel(3)->days();
    app(NewsFeeds::class)->prune(CarbonImmutable::now());
    expect(CasterNews::query()->where('feed', 'blocktrainer')->count())->toBe(0);
});

it('never reads a story in two bulletins: a Blocktrainer article read at 14:00 stays out for good', function () {
    newsMixDesk();
    VoiceAgent::fake(fn (string $prompt): array => newsMixAnswer($prompt));

    $first = app(NewsBulletin::class)->write(BroadcastClock::fromConfig()->nextBulletin(CarbonImmutable::now()), CarbonImmutable::now());
    $blocktrainer = CasterNews::query()->where('source', 'Blocktrainer')->firstOrFail();

    expect($first)->not->toBeNull()->and($blocktrainer->bulletin_at)->not->toBeNull();

    foreach (['14:25', '19:55', '2026-10-13 01:55'] as $time) {
        $this->travelTo(CarbonImmutable::parse(str_contains($time, '-') ? "{$time}:00" : "2026-10-12 {$time}:00", 'Europe/Berlin'));
        expect(array_column(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()), 'source'))->not->toContain('Blocktrainer');
    }
});

it('leaves altcoin hype to the Altcoin-Hype-Detektor and keeps a crash that names Bitcoin', function () {
    newsMixRow('Solana-Memecoin explodiert um 300 Prozent', 'BTC-Echo', CasterNews::BITCOIN, 10);
    newsMixRow('XRP-Anleger hoffen auf den nächsten Pump', 'BTC-Echo', CasterNews::BITCOIN, 11);
    newsMixRow('Ethereum und Bitcoin im Kurssturz: Liquidationen über eine Milliarde', 'BTC-Echo', CasterNews::BITCOIN, 12);
    newsMixRow('Miner verlagern Rechenleistung nach Skandinavien', 'Blocktrainer', CasterNews::BITCOIN, 13);

    $titles = array_column(app(NewsBulletin::class)->select(BroadcastClock::NEWS, CarbonImmutable::now()), 'title');

    expect($titles)->toEqualCanonicalizing(['Ethereum und Bitcoin im Kurssturz: Liquidationen über eine Milliarde', 'Miner verlagern Rechenleistung nach Skandinavien'])
        ->and(NewsMix::altcoinHype('Dogecoin steigt nach Musk-Tweet'))->toBeTrue()
        ->and(NewsMix::altcoinHype('Bitcoin-ETF verzeichnet Rekordzuflüsse'))->toBeFalse();
});

it('gives the Presseschau the same balance: Bitcoin, tech and money before politics, one political headline at most', function () {
    newsMixDesk();

    $press = app(Rubrics::class)->material(Rubrics::PRESS, CarbonImmutable::now());

    expect($press['sources'])->toBe(['EINUNDZWANZIG News', 'heise online', 'tagesschau'])
        ->and($press['same_story'])->toBeFalse();

    // A political story two outlets tell is the comparison, with at most two of its outlets; the third is Bitcoin.
    newsMixRow('Grundsteuer: Bundestag beschließt Reform für Eigentümer', 'Spiegel', CasterNews::GERMANY, 9);
    newsMixRow('Bundestag beschließt Grundsteuer-Reform für Eigentümer', 'tagesschau', CasterNews::GERMANY, 8);
    $press = app(Rubrics::class)->material(Rubrics::PRESS, CarbonImmutable::now());

    expect($press['same_story'])->toBeTrue()
        ->and(array_slice($press['sources'], 2))->toBe(['EINUNDZWANZIG News']);
});

it('has no Presseschau from one Bitcoin headline and three political ones', function () {
    newsMixRow('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 12);
    newsMixRow('Bundestag beschließt Reform der Grundsteuer für Eigentümer', 'Welt', CasterNews::GERMANY, 10);
    newsMixRow('Koalition legt Entwurf für Pflegereform vor', 'FAZ', CasterNews::GERMANY, 11);
    newsMixRow('Länder streiten über Finanzierung neuer Schulgebäude', 'n-tv', CasterNews::GERMANY, 13);

    expect(app(Rubrics::class)->material(Rubrics::PRESS, CarbonImmutable::now()))->toBeNull();

    newsMixRow('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 14);
    expect(app(Rubrics::class)->material(Rubrics::PRESS, CarbonImmutable::now())['sources'])->toBe(['BTC-Echo', 'heise online', 'Welt']);
});

it('orders the news talk stories Bitcoin, tech, money first and asks for a mix', function () {
    $item = fn (string $section, string $source, string $title): array => ['key' => 'news:'.$source, 'section' => $section, 'source' => $source, 'title' => $title, 'news_id' => null, 'summary' => ''];
    $prompt = NewsTalk::prompt([
        $item(CasterNews::GERMANY, 'Welt', 'Bundestag beschließt Reform der Grundsteuer'),
        $item(CasterNews::GERMANY, 'tagesschau', 'EZB senkt den Leitzins erneut'),
        $item(CasterNews::TECH, 'heise online', 'Chatkontrolle: Messenger-Dienste drohen mit Rückzug'),
        $item(CasterNews::BITCOIN, 'Blocktrainer', 'Blocktrainer erklärt das neue Taproot-Upgrade'),
    ], [], []);

    preg_match_all('/^\d+\. ([^:]+):/mu', $prompt, $sources);

    expect($sources[1])->toBe(['Blocktrainer', 'heise online', 'tagesschau', 'Welt'])
        ->and($prompt)->toContain('mindestens eine Bitcoin-Geschichte und eine Technik-Geschichte, Politik höchstens eine');
});

it('reads the real EINUNDZWANZIG News feed for a week and puts its article first in the bulletin, the talk and the Presseschau', function () {
    // Two items captured from the live feed on 2026-10-12 (Nostr long-form articles with a teaser, relative links; no shownotes list).
    config(['tv.commentary.news.feeds' => ['einundzwanzig-news' => config('tv.commentary.news.feeds.einundzwanzig-news')]]);
    Http::fake(['media.einundzwanzig.space/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/caster-news/einundzwanzig-news-2026-10-12.xml')))]);
    // Four days after the articles: far beyond the general twelve hours.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 23:55:00', 'Europe/Berlin'));

    app(NewsFeeds::class)->fetch(CarbonImmutable::now());
    $ledger = CasterNews::query()->where('title', 'like', 'Ledger mit Spionage-Implantat%')->firstOrFail();

    expect($ledger->source)->toBe('EINUNDZWANZIG News')
        ->and($ledger->link)->toBe('https://media.einundzwanzig.space/s/einundzwanzig-news/ledger-implantat-cryptobilis-lieferkette')
        ->and($ledger->summary)->toStartWith('In einem Ledger Nano X steckte eine zweite Platine');

    newsMixRow('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 5);
    newsMixRow('Lightning-Zahlungen wachsen bei Händlern in Europa', 'Blocktrainer', CasterNews::BITCOIN, 6);
    newsMixRow('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 7);

    $items = app(NewsBulletin::class)->select(BroadcastClock::HEADLINES, CarbonImmutable::now());
    expect($items[0]['source'])->toBe('EINUNDZWANZIG News')
        ->and(NewsTalk::prompt(array_reverse($items), [], []))->toMatch('/^1\. EINUNDZWANZIG News: /mu')->toContain('wie bei EINUNDZWANZIG News berichtet')
        ->and(app(Rubrics::class)->material(Rubrics::PRESS, CarbonImmutable::now())['sources'][0])->toBe('EINUNDZWANZIG News');
});

it('follows a Bitcoin story in the Presseschau with tech, not a third Bitcoin outlet', function () {
    newsMixRow('CARF: Bundestag beschließt Krypto-Informationsaustausch über die EU hinaus', 'Blocktrainer', CasterNews::BITCOIN, 60 * 30, 'blocktrainer');
    newsMixRow('Bundestag beschließt Krypto-Informationsaustausch CARF', 'BTC-Echo', CasterNews::BITCOIN, 12);
    newsMixRow('Lightning-Zahlungen wachsen bei Händlern in Europa', 'EINUNDZWANZIG News', CasterNews::BITCOIN, 13);
    newsMixRow('Chatkontrolle: Messenger-Dienste drohen mit Rückzug aus Europa', 'heise online', CasterNews::TECH, 14);

    $press = app(Rubrics::class)->material(Rubrics::PRESS, CarbonImmutable::now());

    expect($press['same_story'])->toBeTrue()
        ->and($press['sources'])->toEqualCanonicalizing(['Blocktrainer', 'BTC-Echo', 'heise online']);
});

it('never reads an advertisement from a feed (Golem, prod 2026-10-13: "Anzeige: Legos Mondrakete …")', function () {
    expect(NewsFilter::reason('Anzeige: Legos Mondrakete von Tim und Struppi wieder fast auf Prime-Day-Niveau'))->toBe(NewsFilter::AD)
        ->and(NewsFilter::reason('heise-Angebot: Jetzt Ticket für die Konferenz sichern'))->toBe(NewsFilter::AD)
        ->and(NewsFilter::reason('Bundestag beschließt Anzeigepflicht für Krypto-Börsen'))->toBeNull()
        // Golem's paywall mark is not spoken either.
        ->and(NewsFeeds::normalize("(g+) Künstliche Intelligenz: Ein Blick in die 'Roboter-Fitnessstudios'"))->toBe("Künstliche Intelligenz: Ein Blick in die 'Roboter-Fitnessstudios'");
});
