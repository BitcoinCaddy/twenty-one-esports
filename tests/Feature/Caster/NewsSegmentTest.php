<?php

use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\NewsFilter;
use App\Support\Caster\NewsScripts;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\NewsFactCheck;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the news segment
|--------------------------------------------------------------------------
|
| User 2026-10-11: "Breaking Bitcoin News kommentieren und auch Schlagzeilen
| aus Deutschland … gerne viel Sarkasmus und Häme". With Http and laravel/ai
| fakes, never the network: feeds are parsed from fixtures, headlines about
| suffering never reach the air, the headline is read word for word, the
| comment is opinion (an invented fact fails the check and a pattern
| reaction stands in), one model call per headline, one segment per
| `every_minutes`, no headline twice.
|
*/

beforeEach(function () {
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.history.enabled' => false,
        'tv.commentary.news.feeds' => [
            'tagesschau-inland' => ['url' => 'https://feeds.test/tagesschau', 'source' => 'tagesschau', 'section' => 'germany', 'language' => 'de'],
            'btc' => ['url' => 'https://feeds.test/btc', 'source' => 'BTC-Echo', 'section' => 'bitcoin', 'language' => 'de'],
        ],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 18:30:00', 'Europe/Berlin'));
});

function casterNewsFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/caster-news/'.$name));
}

function casterNewsHeadline(string $title, string $section = CasterNews::GERMANY, string $source = 'tagesschau', int $minutesAgo = 30): CasterNews
{
    return CasterNews::query()->create([
        'hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => 'test', 'source' => $source, 'language' => 'de',
        'title' => $title, 'link' => null, 'published_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
    ]);
}

/**
 * @return array{text: string, canon_id: null, mood: string}
 */
function casterNewsDraft(string $text): array
{
    return ['text' => $text, 'canon_id' => null, 'mood' => 'deadpan'];
}

it('parses RSS 2.0 headlines word for word, without entities expanded, script links or the outlet suffix', function () {
    $items = NewsFeeds::parse(casterNewsFixture('rss.xml'));

    expect(array_column($items, 'title'))->toBe([
        'Bundestag beschließt neue "Bürokratie-Entlastung" mit 400 Seiten',
        'Unglück auf der A7: Drei Tote nach Unfall',
        'Stromsteuer & Netzentgelte: Koalition streitet weiter',
    ])
        ->and($items[0]['link'])->toBe('https://www.tagesschau.de/inland/buerokratie-100.html')
        ->and($items[0]['published_at']?->toIso8601String())->toBe('2026-10-11T16:05:00+00:00')
        ->and($items[2]['published_at']?->toIso8601String())->toBe('2026-10-11T12:00:00+00:00');
});

it('parses Atom entries with the alternate link and the published date', function () {
    $items = NewsFeeds::parse(casterNewsFixture('atom.xml'));

    expect($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['title' => 'Bitcoin-Hashrate erreicht neues Allzeithoch', 'link' => 'https://example.org/hashrate'])
        ->and($items[1]['published_at']?->toIso8601String())->toBe('2026-10-11T11:00:00+00:00');
});

it('reads nothing from a document that is no feed', function (string $body) {
    expect(NewsFeeds::parse($body))->toBe([]);
})->with(['html' => '<html><body>Not a feed</body>', 'garbage' => "\x00\x01 nope", 'empty' => '']);

it('turns down headlines about suffering and live tickers, and lets politics and markets through', function (string $headline, ?string $reason, string $language = 'de') {
    expect(NewsFilter::reason($headline, $language))->toBe($reason);
})->with([
    'deaths' => ['Unglück auf der A7: Drei Tote nach Unfall', NewsFilter::TRAGEDY],
    'war compound' => ['Ukrainekrieg: Neue Verhandlungen in Genf', NewsFilter::TRAGEDY],
    'attack compound' => ['Drohnenangriff auf Hafen', NewsFilter::TRAGEDY],
    'terror' => ['Terrorverdacht am Bahnhof', NewsFilter::TRAGEDY],
    'suicide' => ['Studie zu Suizid unter Landwirten', NewsFilter::TRAGEDY],
    'abuse' => ['Missbrauchsskandal im Verein', NewsFilter::TRAGEDY],
    'english feed' => ['Bitcoin ETF Rekord', NewsFilter::FOREIGN, 'en'],
    'english headline on a german feed' => ['Saylor teases Strategy Bitcoin buying after ending September with STRC buybacks', NewsFilter::FOREIGN],
    'english question' => ['Everyone wants SCOTUS to take up prediction markets: State of Crypto', NewsFilter::FOREIGN],
    'german with an' => ['Neun Minister schreiben Protestbrief an Merz', null],
    'english the language check missed' => ['Three killed in bitcoin mine fire', NewsFilter::TRAGEDY],
    'german dies is this' => ['Dies kostet die Steuerzahler', null],
    'german nouns only' => ['Bitcoin-ETF: Rekordzuflüsse', null],
    'german with english words' => ['Coldcard warnt vor neuer Bitcoin-Falle', null],
    'ceasefire de' => ['Trump meldet Energie-Waffenruhe', NewsFilter::TRAGEDY],
    'hatred' => ['Kampf gegen Antisemitismus wird schwieriger', NewsFilter::TRAGEDY],
    'health' => ['Papst muss operiert werden', NewsFilter::TRAGEDY],
    'racism' => ['Rassistische Parolen im Landtag', NewsFilter::TRAGEDY],
    'extremism' => ['Provokante Landkarte: Großdeutsche Phantasien in der AfD', NewsFilter::TRAGEDY],
    'assistant is no racism' => ['Tesla nennt Fahrassistent nicht mehr selbstfahrend', null],
    'liveblog' => ['Liveblog: Haushaltsdebatte im Bundestag', NewsFilter::LIVE],
    'ticker' => ['+++ Koalition einigt sich +++', NewsFilter::LIVE],
    'tax' => ['Bundesregierung plant neue Zuckersteuer', null],
    'brandmauer' => ['Union streitet über die Brandmauer', null],
    'market' => ['Bitcoin fällt unter 80.000 Dollar', null],
    'german article die' => ['Die Stromsteuer muss für alle gesenkt werden', null],
    'german war is was' => ['Nie war die Schuldenbremse so locker', null],
]);

it('stores the headlines of every feed once, marks filtered ones used, and goes on when a feed fails', function () {
    Http::fake([
        'feeds.test/tagesschau' => Http::response(casterNewsFixture('rss.xml'), 200, ['ETag' => '"abc"']),
        'feeds.test/btc' => Http::response('down', 503),
    ]);

    $counts = app(NewsFeeds::class)->fetch(CarbonImmutable::now());

    expect($counts)->toMatchArray(['feeds' => 2, 'failed' => 1, 'stored' => 2, 'filtered' => 1])
        ->and(CasterNews::query()->whereNull('skipped')->pluck('title')->sort()->values()->all())->toBe([
            'Bundestag beschließt neue "Bürokratie-Entlastung" mit 400 Seiten',
            'Stromsteuer & Netzentgelte: Koalition streitet weiter',
        ])
        ->and(CasterNews::query()->where('skipped', NewsFilter::TRAGEDY)->whereNotNull('used_at')->count())->toBe(1);

    // The same headlines again (a second feed or the next run): nothing new, and the ETag goes along.
    Http::fake(['feeds.test/tagesschau' => Http::response(casterNewsFixture('rss.xml')), 'feeds.test/btc' => Http::response('', 304)]);
    expect(app(NewsFeeds::class)->fetch(CarbonImmutable::now())['stored'])->toBe(0)
        ->and(CasterNews::query()->count())->toBe(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://feeds.test/tagesschau' && $request->header('If-None-Match') === ['"abc"']);
});

it('asks the feeds with a connect and a total timeout and refuses an oversized body', function () {
    config(['tv.commentary.news.max_feed_bytes' => 100]);
    Http::fake(['*' => Http::response(casterNewsFixture('rss.xml'))]);

    expect(app(NewsFeeds::class)->fetch(CarbonImmutable::now()))->toMatchArray(['failed' => 2, 'stored' => 0]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->header('User-Agent')[0] ?? '', 'TwentyOneTV'));
});

it('prunes headlines past keep_hours and keeps at most max_rows', function () {
    config(['tv.commentary.news.keep_hours' => 48, 'tv.commentary.news.max_rows' => 3]);
    casterNewsHeadline('Alt', minutesAgo: 49 * 60);
    foreach (range(1, 4) as $i) {
        casterNewsHeadline("Schlagzeile {$i}", minutesAgo: 60 * $i);
    }

    expect(app(NewsFeeds::class)->prune(CarbonImmutable::now()))->toBe(2)
        ->and(CasterNews::query()->orderBy('published_at')->pluck('title')->all())->toBe(['Schlagzeile 3', 'Schlagzeile 2', 'Schlagzeile 1']);
});

it('writes a news segment in the model\'s own words: its lead-in, the headline word for word with its source, its comment, no fact check', function () {
    $news = casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    $segment = "Aus Berlin kommt mal wieder eine Meldung, bei der man sich nur an den Kopf fassen kann.\nDie tagesschau meldet nämlich wörtlich: Bundesregierung plant neue Zuckersteuer.\nNoch eine Steuer, wie originell, denn dem Staat fällt einfach nichts anderes mehr ein.\nDer Staat findet immer irgendetwas zum Abkassieren, und diesmal ist es halt euer Kuchen.";
    VoiceAgent::fake([casterNewsDraft($segment)]);
    NewsFactCheck::fake();

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->where('kind', CasterScript::NEWS)->sole();
    expect($script->sentences())->toBe(ScriptGuard::lines($segment))
        ->and($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->topic)->toBe(CasterNews::GERMANY)
        ->and($script->priority)->toBe(0)
        // Nothing expires unused any more (user 2026-10-15): the segment keeps a far-future expiry.
        ->and($script->expires_at->getTimestamp())->toBeGreaterThan(CarbonImmutable::now()->addYears(9)->getTimestamp())
        ->and($news->fresh()->used_at)->not->toBeNull();
    VoiceAgent::assertPromptedTimes(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "Quelle: tagesschau\nSchlagzeile: Bundesregierung plant neue Zuckersteuer") && str_contains($prompt->prompt, 'SKIP')
        // The source as context (user 2026-10-12): feed and publication time.
        && str_contains($prompt->prompt, 'Feed: ') && str_contains($prompt->prompt, 'Veröffentlicht: '));
    NewsFactCheck::assertNeverPrompted();
});

it('puts no headline title in front of the model segment: the model retells it in its own sentences', function () {
    $comment = "Die Bundesregierung will also schon wieder an euren Zucker ran, und dem Staat fällt einfach nichts anderes mehr ein.\nDer Staat findet immer irgendetwas zum Abkassieren, und diesmal ist es halt euer Kuchen.";
    VoiceAgent::fake([casterNewsDraft($comment)]);

    $answer = app(CasterVoice::class)->news('Bundesregierung plant neue Zuckersteuer', 'tagesschau', CasterNews::GERMANY);

    expect(ScriptGuard::lines((string) $answer['text']))->toBe(ScriptGuard::lines($comment));
    VoiceAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'nie die Schlagzeile als Titel vorlesen'));
});

it('never reads a bare headline: every carrier names the source and keeps the headline word for word inside one sentence', function () {
    $carriers = NewsScripts::data()['headline_carriers'];

    expect(count($carriers))->toBeGreaterThanOrEqual(10);

    foreach ($carriers as $turn => $carrier) {
        $line = NewsScripts::headlineLine('Ausschuss prüft das neue Rentenpaket', 'FAZ', $turn, $carriers);

        expect($carrier)->toContain('{source}')->toContain('{headline}')
            ->and($line)->toContain('FAZ')->toEndWith('Ausschuss prüft das neue Rentenpaket.')
            ->and(ScriptGuard::runs($line))->toHaveCount(1)
            ->and((new ScriptGuard)->check($line, 1, 1, 32))->toBe([], $line);
    }

    // A headline that would push the sentence over the limit gets the shortest carrier.
    $long = implode(' ', array_fill(0, 22, 'Wort'));
    expect(count(preg_split('/\s+/u', NewsScripts::headlineLine($long, 'FAZ', 0, $carriers))))->toBeLessThanOrEqual(32);
});

it('writes at most one news segment per every_minutes, the sections in turn, no headline twice', function () {
    // The order alone: the no-repeat memory may drop a stand-in intro said before.
    config(['tv.commentary.ai' => false, 'tv.commentary.repeat_hours' => 0]);
    casterNewsHeadline('Bundesregierung plant neue Zuckersteuer', minutesAgo: 10);
    casterNewsHeadline('Ausschuss prüft Rentenpaket', minutesAgo: 20);
    casterNewsHeadline('Bitcoin-Hashrate erreicht neues Allzeithoch', CasterNews::BITCOIN, 'BTC-Echo', 40);
    $queue = app(ScriptQueue::class);

    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(1);
    $this->travel(9)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(0);
    $this->travel(2)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(1);
    $this->travel(11)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(1);
    $this->travel(11)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(0);

    $spoken = CasterScript::query()->whole()->where('kind', CasterScript::NEWS)->orderBy('id')->get();
    expect($spoken->pluck('topic')->all())->toBe([CasterNews::BITCOIN, CasterNews::GERMANY, CasterNews::GERMANY])
        ->and($spoken->map(fn (CasterScript $script): string => preg_replace('/^.*: /u', '', $script->sentences()[1]))->all())->toBe([
            'Bitcoin-Hashrate erreicht neues Allzeithoch.',
            'Bundesregierung plant neue Zuckersteuer.',
            'Ausschuss prüft Rentenpaket.',
        ])
        ->and($spoken->map(fn (CasterScript $script): bool => str_contains($script->sentences()[1], 'BTC-Echo') || str_contains($script->sentences()[1], 'tagesschau'))->unique()->all())->toBe([true])
        ->and($spoken->pluck('source')->unique()->all())->toBe([CasterScript::SOURCE_PATTERN]);
});

it('drops the headline when the model calls it unfit, without a fact check or a fallback', function () {
    $news = casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    VoiceAgent::fake([casterNewsDraft('SKIP')]);
    NewsFactCheck::fake();

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0)
        ->and($news->fresh()->skipped)->toBe('model')
        ->and(CasterScript::query()->count())->toBe(0);
    NewsFactCheck::assertNeverPrompted();
});

it('never speaks a stored headline the widened filter now turns down', function () {
    $news = casterNewsHeadline('Großbrand in Lagerhalle bei Kempten');
    VoiceAgent::fake();

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0)
        ->and($news->fresh()->skipped)->toBe(NewsFilter::TRAGEDY);
    VoiceAgent::assertNeverPrompted();
});

it('speaks a pattern reaction instead when the segment breaks a house rule, after one model call', function (string $draft, string $reason) {
    casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    VoiceAgent::fake([casterNewsDraft($draft)]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->sole();
    expect($script->source)->toBe(CasterScript::SOURCE_PATTERN)
        ->and(implode("\n", array_slice($script->sentences(), 2)))->toBeIn(NewsScripts::data()['reactions']['germany'])
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::NEWS_CHANNEL)->where('reason', $reason)->value('count'))->toBe(1);
    VoiceAgent::assertPromptedTimes(1);
})->with([
    'a link' => ["Mehr dazu findet ihr unter https://example.com in aller Ausführlichkeit.\nDer Staat findet immer irgendetwas zum Abkassieren, und diesmal ist es euer Kuchen.", VoiceGuard::URL],
    'fragments' => ["Typisch.\nNa klar.\nWieder mal.", ScriptGuard::SENTENCE_SHORT],
]);

it('lets the comment name anyone and claim what it likes: free mode, no fact check', function (string $comment) {
    config(['esports.voice.persons' => ['Saylor', 'Jilch']]);
    casterNewsHeadline('Saylor kündigt neue Bitcoin-Käufe an', CasterNews::BITCOIN, 'BTC-Echo');
    VoiceAgent::fake([casterNewsDraft($comment)]);
    NewsFactCheck::fake();

    app(ScriptQueue::class)->fillSegments(CarbonImmutable::now());

    expect(CasterScript::query()->sole()->source)->toBe(CasterScript::SOURCE_AI);
    NewsFactCheck::assertNeverPrompted();
})->with([
    'named in the headline' => ["Saylor kauft halt einfach immer weiter, und ich mache es ganz genauso.\nIch stack jedenfalls auch heute wieder ein paar Sats in aller Ruhe."],
    'not in the headline' => ["Jilch hätte das bestimmt schon vor Jahren kommen sehen, da bin ich mir sicher.\nIch stack jedenfalls auch heute wieder ein paar Sats in aller Ruhe."],
    'a cause the headline does not give' => ["Die kaufen nur, weil die Kassen voll sind, und zwar randvoll mit Fiatgeld.\nIch stack jedenfalls auch heute wieder ein paar Sats in aller Ruhe."],
]);

it('writes no news segment when the switch is off or no fresh headline waits', function () {
    casterNewsHeadline('Uralte Schlagzeile', minutesAgo: 13 * 60);
    VoiceAgent::fake();

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0);

    casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    config(['tv.commentary.news.enabled' => false]);
    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0);
    VoiceAgent::assertNeverPrompted();
});

it('keeps the pattern reactions free of facts, names and numbers, and within the spoken limits', function () {
    $data = NewsScripts::data();

    expect(count($data['reactions']['bitcoin']))->toBeGreaterThanOrEqual(15)
        ->and(count($data['reactions']['germany']))->toBeGreaterThanOrEqual(15)
        ->and(count($data['headline_carriers']))->toBeGreaterThanOrEqual(10);

    foreach ([...$data['reactions']['bitcoin'], ...$data['reactions']['germany'], ...$data['intros']['bitcoin'], ...$data['intros']['germany']] as $line) {
        expect(preg_match('/\d/', $line))->toBe(0)
            ->and((new ScriptGuard)->check($line, 1, 3, 32))->toBe([]);
    }
});

it('rotates the pattern reactions: twelve segments in a row, twelve different reactions', function () {
    config(['tv.commentary.ai' => false]);
    $scripts = app(NewsScripts::class);
    $reactions = [];

    foreach (range(1, 12) as $i) {
        $segment = $scripts->write(casterNewsHeadline("Ausschuss prüft Vorlage {$i}"), CarbonImmutable::now(), false);
        $reactions[] = implode("\n", array_slice(explode("\n", (string) $segment['text']), 2));
    }

    expect(array_unique($reactions))->toHaveCount(12)
        ->and(array_diff($reactions, NewsScripts::data()['reactions']['germany']))->toBe([]);
});

it('never stores an English headline as speakable, even from a feed configured as German', function () {
    config(['tv.commentary.news.feeds' => ['btc' => ['url' => 'https://feeds.test/btc', 'source' => 'BTC-Echo', 'section' => 'bitcoin', 'language' => 'de']]]);
    Http::fake(['feeds.test/btc' => Http::response('<rss version="2.0"><channel><item><title>Saylor teases Strategy Bitcoin buying after ending September with STRC buybacks</title></item><item><title>Coldcard warnt vor neuer Bitcoin-Falle</title></item></channel></rss>')]);

    expect(app(NewsFeeds::class)->fetch(CarbonImmutable::now()))->toMatchArray(['stored' => 1, 'filtered' => 1])
        ->and(CasterNews::query()->where('skipped', NewsFilter::FOREIGN)->value('title'))->toStartWith('Saylor teases');
});

it('writes a long news segment: the same one model call, asking for three to six sentences of comment', function () {
    config(['tv.commentary.length.shares.news' => 1.0]);
    casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    $segment = implode("\n", [
        'Aus Berlin kommt mal wieder eine Meldung, bei der man sich nur an den Kopf fassen kann.',
        'Die tagesschau meldet nämlich wörtlich: Bundesregierung plant neue Zuckersteuer.',
        'Noch eine Steuer, wie originell, und ich hab ehrlich gesagt schon fast darauf gewartet.',
        'Irgendwann zahlen wir in diesem Land auch noch Steuern aufs Atmen, wenn das so weitergeht.',
        'Der Staat findet immer einen Grund, uns in die Tasche zu greifen, und diesmal trifft es den Kuchen.',
        'Und am Ende heißt es dann wieder, dass das alles nur zu unserer Gesundheit passiert.',
        'Ich bleib jedenfalls bei meinem Kaffee schwarz und stacke das gesparte Geld in Sats.',
    ]);
    VoiceAgent::fake([casterNewsDraft($segment)]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->whole()->where('kind', CasterScript::NEWS)->sole();
    expect(casterWholeSentences($script))->toBe(ScriptGuard::lines($segment))
        ->and($script->source)->toBe(CasterScript::SOURCE_AI);
    VoiceAgent::assertPromptedTimes(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'dann 3 bis 6 Sätze dein Kommentar'));
});

it('trims a long segment to its comment maximum plus lead-in and retold headline, nothing put in front', function (int $sentences, int $lines) {
    $comment = implode("\n", array_map(fn (int $i): string => "Das ist mein ganz eigener Gedanke Nummer {$i} zu dieser Meldung.", range(1, $sentences)));
    $comment = str_replace(['1', '2', '3', '4', '5', '6', '7', '8', '9'], ['eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun'], $comment);
    VoiceAgent::fake([casterNewsDraft($comment)]);

    $answer = app(CasterVoice::class)->news('Bundesregierung plant neue Zuckersteuer', 'tagesschau', CasterNews::GERMANY, long: true);

    expect(count(ScriptGuard::lines((string) $answer['text'])))->toBe($lines);
})->with([
    'two: free mode keeps them' => [2, 2],
    'nine: trimmed to the comment maximum' => [9, 8],
]);

it('stands in for a long comment with three different pattern reactions', function () {
    config(['tv.commentary.ai' => false]);
    $segment = app(NewsScripts::class)->write(casterNewsHeadline('Ausschuss prüft Vorlage'), CarbonImmutable::now(), false, long: true);
    $reactions = array_slice(explode("\n", (string) $segment['text']), 2);
    $known = array_merge(...array_map(ScriptGuard::lines(...), NewsScripts::data()['reactions']['germany']));

    expect($segment['long'])->toBeTrue()
        ->and(count(explode("\n", (string) $segment['text'])))->toBeLessThanOrEqual(10)
        ->and(count($reactions))->toBe(3)
        ->and(array_diff($reactions, $known))->toBe([])
        ->and(array_unique($reactions))->toBe($reactions);
});
