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

it('writes a news segment: intro, the headline word for word with its source, then the comment', function () {
    $news = casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    VoiceAgent::fake([casterNewsDraft("Noch eine Steuer, wie originell.\nDer Staat findet immer was zum Abkassieren.")]);
    NewsFactCheck::fake([['claims' => []]]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->where('kind', CasterScript::NEWS)->sole();
    expect($script->sentences())->toBe([
        NewsScripts::data()['intros']['germany'][$news->id % 4],
        'Laut tagesschau: Bundesregierung plant neue Zuckersteuer.',
        'Noch eine Steuer, wie originell.',
        'Der Staat findet immer was zum Abkassieren.',
    ])
        ->and($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->topic)->toBe(CasterNews::GERMANY)
        ->and($script->priority)->toBe(0)
        ->and($script->expires_at->equalTo(CarbonImmutable::now()->addMinutes(30)))->toBeTrue()
        ->and($news->fresh()->used_at)->not->toBeNull();
    VoiceAgent::assertPromptedTimes(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'tagesschau: Bundesregierung plant neue Zuckersteuer') && str_contains($prompt->prompt, 'SKIP'));
    NewsFactCheck::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "Headline:\nBundesregierung plant neue Zuckersteuer") && str_contains($prompt->prompt, "Comment:\nNoch eine Steuer, wie originell."));
});

it('writes at most one news segment per every_minutes, the sections in turn, no headline twice', function () {
    config(['tv.commentary.ai' => false]);
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
        ->and($spoken->map(fn (CasterScript $script): string => $script->sentences()[1])->all())->toBe([
            'Laut BTC-Echo: Bitcoin-Hashrate erreicht neues Allzeithoch.',
            'Laut tagesschau: Bundesregierung plant neue Zuckersteuer.',
            'Laut tagesschau: Ausschuss prüft Rentenpaket.',
        ])
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

it('rejects a comment with invented facts and speaks a pattern reaction instead, after one model call', function (string $draft, array $factCheck, string $reason) {
    casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    VoiceAgent::fake([casterNewsDraft($draft), casterNewsDraft("Ganz harmlos.\nWirklich.")]);
    NewsFactCheck::fake($factCheck);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->sole();
    expect($script->source)->toBe(CasterScript::SOURCE_PATTERN)
        ->and(implode("\n", array_slice($script->sentences(), 2)))->toBeIn(NewsScripts::data()['reactions']['germany'])
        ->and($script->text)->not->toContain(explode("\n", $draft)[0])
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::NEWS_CHANNEL)->where('reason', $reason)->value('count'))->toBe(1);
    VoiceAgent::assertPromptedTimes(1);
})->with([
    'invented cause (fact check)' => ["Die kommt, weil die Kassen leer sind.\nTypisch.", [['claims' => [['claim' => 'Die kommt, weil die Kassen leer sind.', 'evidence' => '', 'supported' => false]]]], VoiceGuard::UNSUPPORTED_CLAIM],
    'invented number (guard)' => ["Zwanzig Prozent mehr, sagt ein Experte.\nTypisch.", [['claims' => []]], VoiceGuard::ATTRIBUTION],
    'invented digits (guard)' => ["Das kostet uns 3 Milliarden.\nTypisch.", [['claims' => []]], VoiceGuard::NUMBER],
]);

it('lets the comment name a person the headline names, and no other', function (string $comment, ?string $source) {
    config(['esports.voice.persons' => ['Saylor', 'Jilch']]);
    casterNewsHeadline('Saylor kündigt neue Bitcoin-Käufe an', CasterNews::BITCOIN, 'BTC-Echo');
    VoiceAgent::fake([casterNewsDraft($comment)]);
    NewsFactCheck::fake([['claims' => []]]);

    app(ScriptQueue::class)->fillSegments(CarbonImmutable::now());

    expect(CasterScript::query()->sole()->source)->toBe($source);
})->with([
    'named in the headline' => ["Saylor kauft halt einfach weiter.\nIch auch.", CasterScript::SOURCE_AI],
    'not in the headline' => ["Jilch hätte das kommen sehen.\nIch auch.", CasterScript::SOURCE_PATTERN],
]);

it('lets opinion through the news fact check and fails closed when the check fails', function () {
    casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    VoiceAgent::fake([casterNewsDraft("Noch eine Steuer.\nDer Staat kann halt nicht anders.")]);
    NewsFactCheck::fake(fn () => throw new RuntimeException('timeout'));

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->sole()->source)->toBe(CasterScript::SOURCE_PATTERN);
});

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
        ->and(array_keys($data['attribution']))->toBe(['de']);

    foreach ([...$data['reactions']['bitcoin'], ...$data['reactions']['germany'], ...$data['intros']['bitcoin'], ...$data['intros']['germany']] as $line) {
        expect(preg_match('/\d/', $line))->toBe(0)
            ->and((new ScriptGuard)->check($line, 1, 3, 16))->toBe([]);
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

it('writes a long news segment: the same one model call, asking for four to eight sentences of comment, at most ten in all', function () {
    config(['tv.commentary.length.shares.news' => 1.0]);
    casterNewsHeadline('Bundesregierung plant neue Zuckersteuer');
    $comment = implode("\n", [
        'Noch eine Steuer, wie originell.',
        'Ich hab schon fast drauf gewartet.',
        'Irgendwann zahlen wir auch fürs Atmen.',
        'Der Staat findet immer einen Grund.',
        'Und am Ende heißt es wieder Gesundheit.',
        'Ich bleib bei meinem Kaffee schwarz.',
    ]);
    VoiceAgent::fake([casterNewsDraft($comment)]);
    NewsFactCheck::fake([['claims' => []]]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->whole()->where('kind', CasterScript::NEWS)->sole();
    expect(casterWholeSentences($script))->toHaveCount(8)
        ->and(array_slice(casterWholeSentences($script), 2))->toBe(ScriptGuard::lines($comment))
        ->and($script->source)->toBe(CasterScript::SOURCE_AI);
    VoiceAgent::assertPromptedTimes(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '4 bis 8 kurze Sätze'));
});

it('holds a long comment to four to eight sentences: fewer is turned down, more is trimmed to eight', function (int $sentences, bool $accepted) {
    $comment = implode("\n", array_map(fn (int $i): string => "Das ist mein Gedanke Nummer {$i} dazu.", range(1, $sentences)));
    $comment = str_replace(['1', '2', '3', '4', '5', '6', '7', '8', '9'], ['eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun'], $comment);
    VoiceAgent::fake([casterNewsDraft($comment)]);
    NewsFactCheck::fake([['claims' => []]]);

    $answer = app(CasterVoice::class)->news('Bundesregierung plant neue Zuckersteuer', 'tagesschau', CasterNews::GERMANY, long: true);

    expect($answer['text'] !== null)->toBe($accepted)
        ->and(count(ScriptGuard::lines((string) $answer['text'])))->toBe($accepted ? min(8, $sentences) : 0);
})->with([
    'three: too short' => [3, false],
    'four' => [4, true],
    'nine: trimmed to eight' => [9, true],
]);

it('stands in for a long comment with three different pattern reactions', function () {
    config(['tv.commentary.ai' => false]);
    $segment = app(NewsScripts::class)->write(casterNewsHeadline('Ausschuss prüft Vorlage'), CarbonImmutable::now(), false, long: true);
    $reactions = array_slice(explode("\n", (string) $segment['text']), 2);
    $known = array_merge(...array_map(ScriptGuard::lines(...), NewsScripts::data()['reactions']['germany']));

    expect($segment['long'])->toBeTrue()
        ->and(count(explode("\n", (string) $segment['text'])))->toBeLessThanOrEqual(10)
        ->and(count($reactions))->toBeGreaterThanOrEqual(4)
        ->and(array_diff($reactions, $known))->toBe([])
        ->and(array_unique($reactions))->toBe($reactions);
});
