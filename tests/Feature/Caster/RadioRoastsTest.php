<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Caster\Blocks\BlockComposer;
use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\RoastFacts;
use App\Support\Caster\Programme\RoastMaterial;
use App\Support\Caster\Programme\Roasts;
use App\Support\Caster\Programme\RoastScripts;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the eight roast formats (plan "TWENTY ONE Radio", P10)
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Alles aus echten Daten, alles roasten." Each format
| reads its real source server-side (shaped like the real answers, faked
| here), carries the facts as words into the prompt, turns down a draft
| that invents a number, a quotation or a name, and is skipped when its
| source is down. They take rubric slots: fresh ones 09:00 to midnight,
| never two back to back; only the Schwarzbuch-Stunde and the
| Inflationsbeichte are evergreen (day stock, replay).
|
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        'tv.commentary.programme.roasts.enabled' => true,
        'tv.commentary.programme.teasers.enabled' => false,
        'tv.commentary.programme.roasts.sources.nostr.relays' => ['wss://nostr.example.test'],
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '01:00',
        'tv.commentary.spool.quiet_hours.to' => '09:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:05:00', 'Europe/Berlin'));
    WorldMemory::forget();
});

/** An RSS 2.0 document of `$items` ([title, link, pubDate, description]). */
function roastRss(array $items): string
{
    $rows = implode('', array_map(fn (array $item): string => '<item><title>'.htmlspecialchars($item[0]).'</title><link>'.$item[1].'</link><pubDate>'.$item[2].'</pubDate>'
        .(isset($item[3]) ? '<description><![CDATA['.$item[3].']]></description>' : '').'</item>', $items));

    return '<?xml version="1.0" encoding="utf-8"?><rss version="2.0"><channel><title>Feed</title>'.$rows.'</channel></rss>';
}

/**
 * The fake sources, shaped like the real answers of 2026-10-12; `$down` names the ones that answer 500.
 *
 * @param  list<string>  $down
 * @param  array<string, mixed>  $treasury  overrides of the CoinGecko holdings (name => btc)
 */
function roastSources(array $down = [], array $treasury = []): void
{
    $GLOBALS['roastTreasury'] = $treasury;
    Http::fake(function (Request $request) use ($down) {
        $treasury = $GLOBALS['roastTreasury'];
        $url = $request->url();
        $is = fn (string $part): bool => str_contains($url, $part);
        $fail = fn (string $source): bool => in_array($source, $down, true);
        // Down: a 500, even with a body that would read as data.
        $broken = fn (string $body) => Http::response($body, 500);

        return match (true) {
            $is('ecb.europa.eu/rss') => $fail('ecb') ? $broken(roastRss([[' Monetary policy decisions', 'https://www.ecb.europa.eu//press/pr/date/2026/html/ecb.mp261001.en.html', 'Thu, 01 Oct 2026 14:15:00 +0200']])) : Http::response(roastRss([
                [' Philip R. Lane: Interview with Ansa', 'https://www.ecb.europa.eu//press/inter/date/2026/html/ecb.in261009.en.html', 'Fri, 09 Oct 2026 08:00:00 +0200'],
                [' Results of the September 2026 survey on credit terms (SESFOD)', 'https://www.ecb.europa.eu//press/pr/date/2026/html/ecb.pr261007.en.html', 'Wed, 07 Oct 2026 10:00:00 +0200'],
                [' Monetary policy decisions', 'https://www.ecb.europa.eu//press/pr/date/2026/html/ecb.mp261001.en.html', 'Thu, 01 Oct 2026 14:15:00 +0200'],
            ])),
            $is('ecb.mp261001') => Http::response('<html><nav><p>We use cookies to give you the best experience on our website, please accept them all now.</p></nav><main>'
                .'<p>The Governing Council today decided to keep the three key ECB interest rates unchanged at 2.15%. Incoming information is broadly in line with the previous assessment of the inflation outlook.</p>'
                .'<p>The Governing Council is determined to ensure that inflation stabilises at its 2% target in the medium term.</p></main></html>'),
            $is('federalreserve.gov/feeds') => $fail('fed') ? $broken(roastRss([['Federal Reserve issues FOMC statement', 'https://www.federalreserve.gov/newsevents/pressreleases/monetary20260916a.htm', 'Wed, 16 Sep 2026 18:00:00 GMT']])) : Http::response(roastRss([
                ['Federal Reserve issues FOMC statement', 'https://www.federalreserve.gov/newsevents/pressreleases/monetary20260916a.htm', 'Wed, 16 Sep 2026 18:00:00 GMT', 'Federal Reserve issues FOMC statement'],
            ])),
            $is('monetary20260916a') => Http::response('<div id="article"><p>For release at 2:00 p.m. EDT Share --> The Federal Open Market Committee approved the following statement:</p>'
                .'<p>The Committee decided to raise the target range for the federal funds rate by 1/4 percentage point to 3-3/4 to 4 percent. Inflation remains elevated.</p></div>'),
            $is('cointelegraph.com') => $fail('cointelegraph') ? Http::response('down', 500) : Http::response(roastRss([
                ['Solana memecoin surges 400% after celebrity endorsement', 'https://cointelegraph.com/news/solana-memecoin', 'Mon, 12 Oct 2026 09:00:00 +0000', 'A new memecoin on Solana rallied.'],
                ['Bitcoin price holds above key level as miners hodl', 'https://cointelegraph.com/news/btc-level', 'Mon, 12 Oct 2026 08:00:00 +0000'],
            ])),
            $is('decrypt.co') => $fail('decrypt') ? $broken(roastRss([['Dogecoin token frenzy', 'https://decrypt.co/2', 'Mon, 12 Oct 2026 07:30:00 +0000']])) : Http::response(roastRss([
                ['Ethereum layer-2 token airdrop draws record wallets', 'https://decrypt.co/1/eth-airdrop', 'Mon, 12 Oct 2026 07:30:00 +0000'],
            ])),
            $is('coindesk.com') => $fail('coindesk') ? $broken(roastRss([['Solana token frenzy', 'https://www.coindesk.com/3', 'Mon, 12 Oct 2026 06:00:00 +0000']])) : Http::response(roastRss([
                ['XRP jumps as traders bet on new ETF filing', 'https://www.coindesk.com/markets/xrp', 'Mon, 12 Oct 2026 06:00:00 +0000'],
            ])),
            $is('coingecko.com') => $fail('coingecko') ? $broken('{"total_holdings": 5, "companies": [{"name": "Broken", "total_holdings": 5}]}') : Http::response([
                'total_holdings' => 1301259.67, 'total_value_usd' => 107799577036.95, 'market_cap_dominance' => 6.2,
                'companies' => [
                    ['name' => 'Strategy', 'symbol' => 'MSTR.US', 'country' => 'US', 'total_holdings' => $treasury['Strategy'] ?? 848250.0, 'total_entry_value_usd' => 64536394091.0, 'total_current_value_usd' => 70271132948.57, 'percentage_of_total_supply' => 4.039],
                    ['name' => 'Metaplanet', 'symbol' => '3350.T', 'country' => 'JP', 'total_holdings' => $treasury['Metaplanet'] ?? 44000.0, 'total_entry_value_usd' => 3896249023.48, 'total_current_value_usd' => 3645069083.10, 'percentage_of_total_supply' => 0.21],
                    ['name' => 'MARA Holdings', 'symbol' => 'MARA.US', 'country' => 'US', 'total_holdings' => 35303.0, 'total_entry_value_usd' => 0.0, 'total_current_value_usd' => 2924588041.83, 'percentage_of_total_supply' => 0.168],
                ],
            ]),
            $is('destatis.de') => $fail('destatis') ? $broken('Inflationsrate im September 2026 bei +9,9 %') : Http::response('<html><body><div class="keyfigures">+3,3 % Inflations&shy;rate (vorläufig) + 10,5 % Verbraucherpreise Energie + 0,1 % Verbraucherpreise Nahrungsmittel</div>'
                .'<ul><li>30. September 2026 Inflationsrate im September 2026 voraussichtlich +3,3 % Mehr erfahren</li><li>10. September 2026 Inflationsrate im August 2026 bei +2,9 %</li></ul></body></html>'),
            default => Http::response('', 404),
        };
    });
}

/** A relay reader that answers from memory: kind 1 posts and kind 0 profiles. */
function roastRelay(array $posts, array $profiles): void
{
    app()->instance(RelayReader::class, new class($posts, $profiles) extends RelayReader
    {
        public array $asked = [];

        public function __construct(private array $posts, private array $profiles) {}

        public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
        {
            $this->asked[] = $relays;

            return ['events' => in_array(0, $filters[0]['kinds'], true) ? $this->profiles : $this->posts, 'answered' => 1];
        }
    });
}

function roastNote(string $id, string $pubkey, string $content, array $tags = [], int $kind = 1, int $minutesAgo = 90): SignedEvent
{
    return SignedEvent::fromInput(['id' => str_pad($id, 64, '0'), 'pubkey' => str_pad($pubkey, 64, 'a'), 'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        'kind' => $kind, 'tags' => $tags, 'content' => $content, 'sig' => str_repeat('0', 128)]);
}

/**
 * A draft of eighteen long sentences, Markus and Sirius in turn: `$facts` first, then filler about nothing checkable.
 *
 * @param  list<string>  $facts
 */
function roastDraft(array $facts): string
{
    $topics = ['Bürokratie', 'Steuerzahler', 'Stammtisch', 'Behördenpost', 'Formularwesen', 'Kaffeeautomaten', 'Gremiensitzung', 'Pressestelle', 'Haushaltsloch',
        'Förderantrag', 'Sitzungsprotokoll', 'Dienstwagen', 'Aktenordner', 'Planstelle', 'Ausschuss', 'Gutachten', 'Arbeitskreis', 'Leitbild'];
    $lines = array_values($facts);

    foreach ($topics as $index => $topic) {
        if (count($lines) >= 18) {
            break;
        }
        $lines[] = 'Beim Thema '.$topic.' muss ich ehrlich sagen, dass mich das alles mehr nach einem schlechten Witz klingt als nach ernsthafter Arbeit für irgendwen.';
    }

    return implode("\n", array_map(fn (int $index, string $line): string => ($index % 2 === 0 ? 'Markus: ' : 'Sirius: ').$line, array_keys($lines), $lines));
}

function roastSlot(string $at = '2026-10-12 14:15:00'): array
{
    return BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse($at, 'Europe/Berlin'));
}

/** The facts each format's good draft names, by format. @return array<string, list<string>> */
function roastFacts(): array
{
    return [
        Roasts::ZENTRALBANK => ['Die EZB schreibt wörtlich „keep the three key ECB interest rates unchanged at zwei Komma eins fünf Prozent“ und tut so, als wäre das eine Leistung.',
            'Die Fed will laut eigener Mitteilung die Zinsen anheben, und das nennt sie dann allen Ernstes Preisstabilität für die Bürger.'],
        Roasts::SCHWARZBUCH => ['Das Schwarzbuch erzählt von fünf Bäumen für hunderttausend Euro in Neu-Anspach, und das klingt nach einer sehr teuren Gartenparty.'],
        Roasts::ALTCOIN => ['Bei Cointelegraph steigt ein Memecoin auf Solana nach der Werbung eines Promis, und der Detektor piept schon wieder ganz laut.',
            'Decrypt meldet einen Airdrop für einen Ethereum-Token, und CoinDesk legt mit einem XRP-Sprung nach, weil irgendwer auf einen Fonds wettet.'],
        Roasts::STACKER => ['Laut CoinGecko liegt Strategy mit achthundertachtundvierzigtausendzweihundertfünfzig Bitcoin vorne, und die Aktionäre schwitzen trotzdem nicht.',
            'Metaplanet sitzt laut den Zahlen gerade unter Wasser, weil sie teurer gekauft haben, als der Haufen heute wert ist.'],
        Roasts::INFLATION => ['Das Statistische Bundesamt meldet für September eine Inflationsrate von plus drei Komma drei Prozent, und keiner beim Bundesamt schämt sich dafür.'],
    ];
}

it('builds every fresh source into words: no digit in any material, the real facts in it', function () {
    roastSources();
    $material = app(RoastMaterial::class);
    $now = CarbonImmutable::now();

    $bank = $material->centralBanks($now);
    expect($bank['data'])->toContain('„The Governing Council today decided to keep the three key ECB interest rates unchanged at zwei Komma eins fünf Prozent.“')
        ->toContain('raise the target range for the federal funds rate by null Komma zwei fünf percentage point to drei Komma sieben fünf to vier percent')
        // The decision, not the newest interview; the cookie banner and the release line stay out.
        ->not->toContain('Interview')->not->toContain('survey')->not->toContain('cookies')->not->toContain('For release')
        ->and($bank['sources'])->toBe(['EZB', 'Fed']);

    $alt = $material->altcoinHype($now);
    expect($alt['data'])->toContain('Cointelegraph: „Solana memecoin surges vierhundert Prozent after celebrity endorsement“')
        ->toContain('Decrypt')->toContain('CoinDesk')
        // Bitcoin-only news is not altcoin hype.
        ->not->toContain('miners hodl');

    $stack = $material->corporateStackers($now);
    expect($stack['data'])->toContain('Platz eins: Strategy mit achthundertachtundvierzigtausendzweihundertfünfzig Bitcoin, aktuell über dem Einstandswert')
        ->toContain('Metaplanet mit vierundvierzigtausend Bitcoin, aktuell unter dem Einstandswert')
        ->and($stack['names'])->toContain('MARA Holdings');

    $inflation = $material->inflation($now);
    expect($inflation['data'])->toContain('Inflationsrate im September zweitausendsechsundzwanzig plus drei Komma drei Prozent, vorläufig')
        ->toContain('Energie: plus zehn Komma fünf Prozent')->toContain('Preisvergleich:')->toContain(', Quelle: ')->not->toContain('(');

    foreach ([$bank, $alt, $stack, $inflation, $material->wasteCase($now)] as $one) {
        expect(preg_match('/\d/u', $one['data']))->toBe(0);
    }
});

it('reads who bought and who sold from the snapshot a day earlier', function () {
    roastSources();
    app(RoastMaterial::class)->corporateStackers(CarbonImmutable::now());

    // A day later the source says something new (its cache of half an hour long gone).
    $this->travel(21)->hours();
    $GLOBALS['roastTreasury'] = ['Strategy' => 850250.0, 'Metaplanet' => 43000.0];
    $data = app(RoastMaterial::class)->corporateStackers(CarbonImmutable::now())['data'];

    expect($data)->toContain('Seit gestern: Strategy hat zweitausend Bitcoin dazugekauft')->toContain('Seit gestern: Metaplanet hat eintausend Bitcoin verkauft');
});

it('skips a format whose source is down and never fills it in', function (string $id, array $down) {
    roastSources($down);
    roastRelay([], []);

    expect(app(Roasts::class)->material($id, CarbonImmutable::now()))->toBeNull();
})->with([
    'both central banks down' => [Roasts::ZENTRALBANK, ['ecb', 'fed']],
    'crypto press down to one outlet' => [Roasts::ALTCOIN, ['decrypt', 'coindesk']],
    'coingecko down' => [Roasts::STACKER, ['coingecko']],
    'relays have nothing' => [Roasts::NOSTR, []],
    'no league games' => [Roasts::LIGA_PRANGER, []],
    'no quoted headlines' => [Roasts::PHRASEN, []],
]);

it('keeps one bank when the other is down, and the Inflationsbeichte on its sourced prices when Destatis is down', function () {
    roastSources(['fed', 'destatis']);
    $now = CarbonImmutable::now();

    $bank = app(RoastMaterial::class)->centralBanks($now);
    $inflation = app(RoastMaterial::class)->inflation($now);

    expect($bank['sources'])->toBe(['EZB'])->and($bank['data'])->not->toContain('Committee')
        ->and($inflation['data'])->not->toContain('Inflationsrate')->toContain('Preisvergleich:')->and($inflation['sources'])->toBe([]);
});

it('writes each format from its facts and turns down an invented number, quotation or name', function (string $id, string $invented, string $code) {
    roastSources();
    $material = app(Roasts::class)->material($id, CarbonImmutable::now());
    $writer = app(RoastScripts::class);

    [$good, $why] = $writer->evaluate($id, $material, roastDraft(roastFacts()[$id]));
    expect($why)->toBe([])->and($good['speakers'])->toContain(DuetDirector::MARKUS)->toContain(DuetDirector::SIRIUS);

    [$bad, $why] = $writer->evaluate($id, $material, roastDraft([...roastFacts()[$id], $invented]));
    expect($bad)->toBeNull()->and($why)->toContain($code);
})->with([
    'bank: a quote the release never said' => [Roasts::ZENTRALBANK, 'Die EZB schreibt wörtlich „we will print money until everybody is happy again“ und das glaubt ihr doch selbst nicht.', RoastFacts::QUOTE],
    'bank: an invented rate' => [Roasts::ZENTRALBANK, 'Die EZB hat die Zinsen auf sieben Prozent gesetzt, und die Sparer jubeln vor lauter Freude in ihren Kellern.', RoastFacts::AMOUNT],
    'schwarzbuch: an invented sum' => [Roasts::SCHWARZBUCH, 'Am Ende waren es dann sogar dreihundert Millionen Euro, und keiner im Rathaus hat sich darüber auch nur gewundert.', RoastFacts::AMOUNT],
    'altcoins: a digit' => [Roasts::ALTCOIN, 'Der Memecoin ist um 900 Prozent gestiegen, und am Montag ist er dann bestimmt wieder bei null angekommen.', RoastFacts::NUMBER],
    'stackers: an invented company' => [Roasts::STACKER, 'Und dann kauft auch noch MicroHard eine Schubkarre Bitcoin, damit die Bilanz endlich mal nach etwas aussieht.', RoastFacts::NAME],
    'inflation: an invented rate' => [Roasts::INFLATION, 'Im Oktober sind es dann sicher neun Prozent, und der Finanzminister nennt das wieder eine vorübergehende Erscheinung.', RoastFacts::AMOUNT],
]);

it('turns down a roast that only one of the two speakers talks', function () {
    roastSources();
    $material = app(Roasts::class)->material(Roasts::STACKER, CarbonImmutable::now());
    $solo = (string) preg_replace('/^Sirius: /mu', 'Markus: ', roastDraft(roastFacts()[Roasts::STACKER]));

    expect(app(RoastScripts::class)->evaluate(Roasts::STACKER, $material, $solo)[1])->toContain(RoastScripts::SPEAKERS);
});

it('names its source or is turned down', function () {
    roastSources();
    $material = app(Roasts::class)->material(Roasts::STACKER, CarbonImmutable::now());

    [$draft, $why] = app(RoastScripts::class)->evaluate(Roasts::STACKER, $material, roastDraft(['Metaplanet sitzt gerade unter Wasser, weil sie teurer gekauft haben, als der Haufen heute wert ist, und das tut weh.']));

    expect($draft)->toBeNull()->and($why)->toContain(RoastScripts::SOURCES);
});

it('puts the league week on the pillory by display name, and turns down a player the week does not name', function () {
    $ada = User::factory()->create(['name' => 'Ada Gambit']);
    $bob = User::factory()->create(['name' => 'Bob Blunder']);
    User::factory()->create(['name' => 'Carla Castling']);
    $game = fn (User $white, User $black, string $result, ChessEndReason $reason, int $ply, int $hoursAgo) => ChessGame::factory()->create([
        'white_id' => $white->id, 'black_id' => $black->id, 'status' => ChessGameStatus::Finished, 'result' => $result, 'end_reason' => $reason, 'ply' => $ply,
        'ended_at' => now()->subHours($hoursAgo),
    ]);
    $game($ada, $bob, '1-0', ChessEndReason::Checkmate, 7, 50);
    $game($bob, $ada, '0-1', ChessEndReason::Resignation, 30, 40);
    $game($ada, $bob, '1-0', ChessEndReason::Abandoned, 22, 30);
    $game($bob, $ada, '0-1', ChessEndReason::Resignation, 41, 20);
    // Older than a week: not this week's shame.
    $game($ada, $bob, '0-1', ChessEndReason::Checkmate, 3, 200);

    $material = app(Roasts::class)->material(Roasts::LIGA_PRANGER, CarbonImmutable::now());

    expect($material['data'])->toContain('Kürzeste Niederlage: Bob Blunder verliert gegen Ada Gambit nach vier Zügen durch Matt')
        ->toContain('Bob Blunder hat zwei Partien aufgegeben')
        ->toContain('Bob Blunder hat eine Partie durch Verlassen des Bretts verloren')
        ->toContain('Längste Niederlagenserie: Bob Blunder mit vier Niederlagen am Stück')
        ->not->toContain('zwei Zügen')
        ->and($material['names'])->toEqualCanonicalizing(['Ada Gambit', 'Bob Blunder']);

    $facts = ['Bob Blunder verliert gegen Ada Gambit nach vier Zügen durch Matt, und das ist selbst für einen Mittwochabend eine sportliche Frechheit.'];
    $writer = app(RoastScripts::class);
    expect($writer->evaluate(Roasts::LIGA_PRANGER, $material, roastDraft($facts))[1])->toBe([])
        ->and($writer->evaluate(Roasts::LIGA_PRANGER, $material, roastDraft([...$facts, 'Und Carla Castling hat sich dafür gleich die ganze Woche lang im Keller versteckt, weil sie Angst vor dem Brett hat.']))[1])
        ->toContain(RoastFacts::NAME);
});

it('drescht the quoted phrases of tagesschau and Spiegel only, word for word', function () {
    $row = fn (string $title, string $source, int $hoursAgo) => CasterNews::query()->create(['hash' => NewsFeeds::hash($title), 'section' => CasterNews::GERMANY, 'feed' => 'test',
        'source' => $source, 'language' => 'de', 'title' => $title, 'link' => null, 'published_at' => now()->subHours($hoursAgo)]);
    $row('Kanzler: „Wir müssen jetzt gemeinsam Verantwortung übernehmen“', 'tagesschau', 30);
    $row('Ministerin: „Das ist ein klares Signal für die Zukunft“', 'Spiegel', 50);
    $row('Bahn kündigt neue Baustellen auf wichtigen Strecken an', 'tagesschau', 5);
    $row('Minister: „Wir werden alles tun für den Zusammenhalt“', 'Welt', 6);

    $material = app(Roasts::class)->material(Roasts::PHRASEN, CarbonImmutable::now());
    expect($material['data'])->toContain('„Wir müssen jetzt gemeinsam Verantwortung übernehmen“')->toContain('„Das ist ein klares Signal für die Zukunft“')
        ->not->toContain('Baustellen')->not->toContain('Zusammenhalt')
        ->and($material['sources'])->toEqualCanonicalizing(['tagesschau', 'Spiegel']);

    $facts = ['Bei tagesschau sagt der Kanzler „Wir müssen jetzt gemeinsam Verantwortung übernehmen“, und das sagt er wahrscheinlich auch beim Bäcker.',
        'Im Spiegel kommt dann „Das ist ein klares Signal für die Zukunft“, und welches Signal das sein soll, weiß niemand im ganzen Land.'];
    $writer = app(RoastScripts::class);
    expect($writer->evaluate(Roasts::PHRASEN, $material, roastDraft($facts))[1])->toBe([])
        ->and($writer->evaluate(Roasts::PHRASEN, $material, roastDraft([...$facts, 'Der Kanzler sagte außerdem „Wir senken morgen alle Steuern auf null“, und das hat ihm wirklich niemand geglaubt.']))[1])
        ->toContain(RoastFacts::QUOTE);
});

it('finds Nostr posts by their kind 0 name, leaves out replies, sensitive and family posts, and never airs one twice', function () {
    $good = roastNote('a1', 'b1', 'Habe heute versucht meinen Kühlschrank mit Lightning zu bezahlen und er hat nur gebrummt wie ein Altcoin.');
    $other = roastNote('a2', 'b2', 'Wer braucht schon Schlaf wenn man die ganze Nacht Blöcke zählen kann, ich bin jetzt bei sehr vielen angekommen.');
    $reply = roastNote('a3', 'b3', 'Da hast du völlig recht, das sehe ich ganz genauso wie du und alle anderen hier im Faden.', [['e', str_repeat('f', 64)]]);
    $family = roastNote('a4', 'b4', 'Mama hat heute gefragt warum ich ständig über Blöcke rede und ich habe es mit Lego erklärt, ging leider schief.');
    $profiles = [
        roastNote('c1', 'b1', json_encode(['name' => 'kuehlschrank_maxi', 'display_name' => 'Kühlschrank Maxi']), [], 0),
        roastNote('c2', 'b2', json_encode(['name' => 'Blockzaehler']), [], 0),
        roastNote('c3', 'b3', json_encode(['name' => 'Fadenmeister']), [], 0),
        roastNote('c4', 'b4', json_encode(['name' => 'Lego Stacker']), [], 0),
    ];
    roastRelay([$good, $other, $reply, $family], $profiles);

    $roasts = app(Roasts::class);
    $material = $roasts->material(Roasts::NOSTR, CarbonImmutable::now());

    expect($material['data'])->toContain('Kühlschrank Maxi schrieb')->toContain('„Habe heute versucht meinen Kühlschrank mit Lightning zu bezahlen')
        ->toContain('Blockzaehler schrieb')->not->toContain('völlig recht')->not->toContain('Lego')
        ->and($material['names'])->toEqualCanonicalizing(['Kühlschrank Maxi', 'Blockzaehler']);

    $roasts->aired(Roasts::NOSTR, $material, CarbonImmutable::now());
    $this->travel(400)->days();
    roastRelay([$good, $other, $reply, $family], $profiles);

    expect(app(Roasts::class)->material(Roasts::NOSTR, CarbonImmutable::now()))->toBeNull();
});

it('takes Schwarzbuch cases in turn, each with its source, from the curated real list', function () {
    $file = json_decode((string) file_get_contents(resource_path('data/voice/schwarzbuch.json')), true);
    expect(count($file['cases']))->toBeGreaterThanOrEqual(30);
    foreach ($file['cases'] as $case) {
        expect($case['url'])->toStartWith('https://www.schwarzbuch.de/aufgedeckt/')->and($case['facts'])->not->toBe('');
    }

    $roasts = app(Roasts::class);
    $first = $roasts->material(Roasts::SCHWARZBUCH, CarbonImmutable::now());
    $roasts->aired(Roasts::SCHWARZBUCH, $first, CarbonImmutable::now());
    $second = $roasts->material(Roasts::SCHWARZBUCH, CarbonImmutable::now());

    expect($first['sources'])->toBe(['Schwarzbuch'])->and($first['data'])->toContain('Schwarzbuch des Bundes der Steuerzahler')
        ->and($second['hash'])->not->toBe($first['hash']);
});

it('plans roast formats into the rubric rotation: never two back to back, fresh ones only from nine to midnight', function () {
    roastSources();
    config(['tv.commentary.programme.rubrics.rotation' => [Roasts::ZENTRALBANK, Roasts::ALTCOIN, Rubrics::HISTORY]]);
    $rubrics = app(Rubrics::class);
    $first = roastSlot('2026-10-12 14:15:00');
    $second = roastSlot('2026-10-12 14:45:00');
    $start = fn (array $slot): string => $rubrics->rotationFor($slot)[0];

    $plannedFirst = $rubrics->plannedFor($first, CarbonImmutable::now());
    expect(Roasts::owns($plannedFirst))->toBeTrue()
        ->and(Roasts::owns($start($second)) || Roasts::owns($rubrics->rotationFor($second)[1]))->toBeTrue()
        ->and($rubrics->plannedFor($second, CarbonImmutable::now()))->toBe(Rubrics::HISTORY);

    // After midnight only the evergreen formats.
    config(['tv.commentary.programme.rubrics.rotation' => [Roasts::ALTCOIN, Roasts::STACKER, Roasts::SCHWARZBUCH]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 00:05:00', 'Europe/Berlin'));
    expect(app(Rubrics::class)->plannedFor(roastSlot('2026-10-13 00:15:00'), CarbonImmutable::now()))->toBe(Roasts::SCHWARZBUCH)
        ->and(Roasts::allowedAt(Roasts::ALTCOIN, CarbonImmutable::parse('2026-10-13 09:15:00', 'Europe/Berlin')))->toBeTrue()
        ->and(Roasts::allowedAt(Roasts::INFLATION, CarbonImmutable::parse('2026-10-13 00:15:00', 'Europe/Berlin')))->toBeTrue();

    // Only fresh formats in the rotation: nothing airs after midnight.
    config(['tv.commentary.programme.rubrics.rotation' => [Roasts::ALTCOIN, Roasts::STACKER]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 00:05:00', 'Europe/Berlin'));
    expect(app(Rubrics::class)->plannedFor(roastSlot('2026-10-14 00:15:00'), CarbonImmutable::now()))->toBeNull();
    // The same rotation at nine in the morning has its show.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:05:00', 'Europe/Berlin'));
    expect(Roasts::owns((string) app(Rubrics::class)->plannedFor(roastSlot('2026-10-14 09:15:00'), CarbonImmutable::now())))->toBeTrue();
});

it('lets no roast format take a slot while the roasts are switched off', function () {
    roastSources();
    config(['tv.commentary.programme.rubrics.rotation' => [Roasts::SCHWARZBUCH, Roasts::INFLATION], 'tv.commentary.programme.roasts.enabled' => false]);

    expect(app(Rubrics::class)->plannedFor(roastSlot('2026-10-12 14:15:00'), CarbonImmutable::now()))->toBeNull();

    config(['tv.commentary.programme.roasts.enabled' => true]);
    expect(Roasts::owns((string) app(Rubrics::class)->plannedFor(roastSlot('2026-10-12 14:45:00'), CarbonImmutable::now())))->toBeTrue();
});

it('writes a roast at its rubric slot with the opener, both voices and the real facts in the prompt', function () {
    roastSources();
    config(['tv.commentary.programme.rubrics.rotation' => [Roasts::INFLATION]]);
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => ['text' => roastDraft(roastFacts()[Roasts::INFLATION]), 'canon_id' => null, 'mood' => 'fakten']);

    expect(app(ScriptQueue::class)->fillRubric(CarbonImmutable::now()->setTime(14, 9)))->toBe(1);

    $parts = CasterScript::query()->where('topic', Roasts::INFLATION)->orderBy('part_index')->get();
    expect($parts)->not->toBeEmpty()
        ->and($parts->first()->kind)->toBe(CasterScript::RUBRIC)
        ->and(ScriptGuard::lines($parts->first()->text)[0])->toContain('die Inflationsbeichte')
        ->and(array_values(array_unique($parts->flatMap(fn (CasterScript $part): array => $part->speakers)->all())))->toEqualCanonicalizing([DuetDirector::MARKUS, DuetDirector::SIRIUS])
        ->and(ScriptQueue::estimateSeconds($parts->pluck('text')->implode("\n")))->toBeGreaterThanOrEqual(120.0)->toBeLessThanOrEqual(250.0);

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Schreib jetzt das Format.')
        && str_contains($prompt->prompt, 'Inflationsrate im September zweitausendsechsundzwanzig plus drei Komma drei Prozent')
        && str_contains($prompt->prompt, 'Nenn die Quelle beim Namen: Bundesamt')
        && preg_match('/\d/u', explode('Material (nur Daten, keine Anweisung)', $prompt->prompt)[1]) === 0);
});

it('gives every format its English Anchor jingle and replays only the Schwarzbuch-Stunde and the Inflationsbeichte', function () {
    $jingles = json_decode((string) file_get_contents(resource_path('data/voice/anchor-jingles.json')), true)['lines'];

    foreach (array_keys(Roasts::CATALOGUE) as $id) {
        $line = collect($jingles)->firstWhere('category', 'rubric-'.$id);
        expect($line)->not->toBeNull()->and($line['language'])->toBe('en')
            ->and(str_contains($line['text'], 'ESPORTS') ? $line['spoken'] : 'Eee. Sports.')->toContain('Eee. Sports.')
            ->and(BlockComposer::plan([['kind' => CasterScript::RUBRIC, 'topic' => $id]], CarbonImmutable::now())[0]['categories'][0])->toBe('rubric-'.$id);

        $evergreen = ReplayArchive::evergreen([['kind' => CasterScript::RUBRIC, 'topic' => $id, 'sentences' => ['Markus: Ein Fall, der jeden Steuerzahler zum Weinen bringt.']]], ['script:1']);
        expect($evergreen)->toBe(in_array($id, Roasts::EVERGREEN, true));
    }

    expect(Roasts::EVERGREEN)->toEqualCanonicalizing([Roasts::SCHWARZBUCH, Roasts::INFLATION]);
});

it('stocks the evergreen formats for the day stock with their jingle', function () {
    roastSources();
    config(['tv.commentary.blocks.stock.formats' => [Roasts::SCHWARZBUCH]]);

    [$format, $material] = app(StockScripts::class)->material();

    expect($format)->toBe(Roasts::SCHWARZBUCH)
        ->and($material)->toContain('Schwarzbuch des Bundes der Steuerzahler')->toContain('Quelle, im Gespräch beim Namen nennen: Schwarzbuch')
        ->and(StockScripts::jinglesFor(StockScripts::TOPIC_PREFIX.Roasts::SCHWARZBUCH)[0])->toBe('rubric-schwarzbuch')
        ->and(StockScripts::prompt(Roasts::INFLATION, 'x'))->toContain('Inflationsbeichte');
});

it('reads the relays with the real reader when the container builds the material', function () {
    // Real run 2026-10-12: a nullable reader defaulted to null in the container and every Nostr-Fundbüro was skipped.
    $reader = (fn () => $this->reader)->call(app(RoastMaterial::class));

    expect($reader)->toBeInstanceOf(RelayReader::class);
});
