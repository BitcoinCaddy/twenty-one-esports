<?php

use App\Console\Commands\CasterJinglesCommand;
use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\AirLog;
use App\Support\Caster\Blocks\BlockComposer;
use App\Support\Caster\Blocks\BlockFetcher;
use App\Support\Caster\Blocks\DayPlanner;
use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Pcm;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\DayStockSimulator;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the day stock and the hourly airtime budget
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Wir brauchen für den Tag über im Buffer vorgerendertes
| Programm durchweg … mit allen 10 Minuten Reaktion auf Chat", no generic
| slide lines between the blocks, replays only as a reserve ("auf keinen
| Fall zu viele Wiederholungen"). At night the workstation renders evergreen
| programmes (formats: talk, tutorial, Geschichtsstunde, podcast, league)
| into a local stock; by day the stock tops the server's spool up behind
| every fresh item; a replay goes up only when the air would run dry and
| within the hourly and daily replay share. With the laravel/ai, HTTP and
| process fakes, never the network or VoiceStudio.
|
*/

const DAY_STOCK_TOKEN = 'stock-token-0123456789abcdef0123456789abcdef';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.fetch.token' => DAY_STOCK_TOKEN,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.blocks.enabled' => true,
        'tv.commentary.blocks.stock.enabled' => true,
        // The day-stock path without the server's library (P18 keeps it as the fallback; StockLibraryTest covers the library).
        'tv.commentary.blocks.stock.library.enabled' => false,
        'tv.commentary.blocks.stock.dir' => dayStockTempDir(),
        'tv.commentary.blocks.replay.enabled' => true,
        'tv.commentary.blocks.replay.dir' => dayStockTempDir(),
        'tv.commentary.blocks.jingles.dir' => dayStockTempDir(),
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours' => ['from' => '01:00', 'to' => '09:00', 'timezone' => 'Europe/Berlin', 'every_minutes' => 10, 'ahead_seconds' => 90],
        'tv.commentary.blocks.stock.window' => ['from' => '01:00', 'to' => '09:00'],
        'esports.voice.monthly_budget_usd' => 100,
    ]);
});

function dayStockTempDir(): string
{
    return sys_get_temp_dir().'/day-stock-'.bin2hex(random_bytes(4));
}

/** A model answer for a stock talk: eight turns of Markus and Sirius, one line naming the hour. */
function dayStockAnswer(bool $withHour = true): string
{
    $lines = [
        'Markus: Wer seine Seed-Phrase als Foto in der Cloud speichert, der schenkt seine Schlüssel praktisch dem nächsten Datenleck.',
        'Sirius: Ich habe meine Wörter früher auf einen Bierdeckel geschrieben und den Deckel dann beim Aufräumen weggeworfen.',
        'Markus: Genau deshalb gehören die Wörter auf Papier oder besser noch auf Metall und an einen Ort ohne Internet.',
        'Sirius: Metall klingt nach Baumarkt und Hammer, aber ich gebe zu, dass ein Wohnungsbrand dem Papier nicht gut bekommt.',
        'Markus: Und niemand fragt dich je nach diesen Wörtern, weder der Support noch eine Bank und auch kein freundlicher Fremder.',
        'Sirius: Der freundliche Fremde im Chat wollte meine Wörter neulich zur Sicherheit überprüfen und war dann plötzlich weg.',
        'Markus: Der war so freundlich wie eine Zentralbank, die dir verspricht, dass dein Geld nächstes Jahr noch genauso viel wert ist.',
        'Sirius: Dann schreibe ich die Wörter auf Metall, und damit war das das Bitcoin-Tutorial, zurück ins Programm.',
    ];

    // In the middle: the opener and the back-announce frame the show and never name the hour (P21, StockScripts::FRAME_LOST).
    if ($withHour) {
        array_splice($lines, 4, 0, ['Markus: Es ist jetzt kurz nach drei Uhr in der Nacht, und wir machen einfach weiter mit unserem Thema hier.']);
    }

    return implode("\n", $lines);
}

/** A whole programme's answer: the fixture's eight turns repeated to programme length (user 2026-10-16). */
function dayStockProgrammeAnswer(): string
{
    $lines = explode("\n", dayStockAnswer());
    $all = [];
    foreach ([0, 7, 0, 0, 0, 0, 0, 0] as $count) {
        $all = [...$all, ...array_slice($lines, 0, 8)];
    }

    return implode("\n", $all);
}

function dayStockClaim(string $query = ''): TestResponse
{
    return test()->postJson('/api/caster/claim'.$query, [], ['Authorization' => 'Bearer '.DAY_STOCK_TOKEN, DayStock::HEADER => '0.0;0']);
}

it('writes an evergreen talk without the line that names the hour, and turns down one left too short', function () {
    $stock = app(StockScripts::class);
    // A whole programme in one pass now (user 2026-10-16: 40-80 turns, one continuous conversation), so the
    // fixture answer repeats its eight turns five times - one long talk, same shape, same back-announce.
    $answer = implode("\n", [...array_slice(explode("\n", dayStockAnswer()), 0, 8), ...array_slice(explode("\n", dayStockAnswer()), 0, 7), ...array_slice(explode("\n", dayStockAnswer()), 0, 8), ...array_slice(explode("\n", dayStockAnswer()), 0, 8), ...array_slice(explode("\n", dayStockAnswer()), 0, 8), ...array_slice(explode("\n", dayStockAnswer()), 0, 8), ...array_slice(explode("\n", dayStockAnswer()), 0, 8), ...array_slice(explode("\n", dayStockAnswer()), 0, 8)]);

    [$talk, $violations] = $stock->evaluate($answer);

    expect($violations)->toBe([])
        ->and($talk['text'])->not->toContain('Uhr')
        ->and(count($talk['speakers']))->toBe(55)
        ->and(array_unique($talk['speakers']))->toBe(['markus', 'sirius']);

    // Five lines naming the time leave three turns: under turns.min, turned down.
    $short = implode("\n", array_map(fn (string $line): string => str_replace(['Wohnungsbrand', 'Internet', 'Bank und', 'Fremde im', 'Zentralbank'], 'heute', $line), explode("\n", dayStockAnswer(false))));
    expect($stock->evaluate($short)[1])->toBe([StockScripts::TIMESTAMPED]);
});

it('rotates the formats, a tutorial with its rules and no advice, a format without material skipped', function () {
    config(['tv.commentary.blocks.stock.formats' => ['tutorial', 'podcast']]);
    // The tutorial lives on the real discover feed (user 2026-10-16): one article, its page fetched.
    Http::fake([
        'discover.einundzwanzig.space/*' => Http::response('<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>E</title><item><title>Deine Seed-Phrase sicher aufbewahren</title><link>https://media.einundzwanzig.space/seed</link><pubDate>Mon, 12 Oct 2026 10:00:00 +0000</pubDate><dc:creator>pleb</dc:creator><description>Volltext folgt</description></item></channel></rss>'),
        'media.einundzwanzig.space/*' => Http::response('<html><body><article><p>Die Seed-Phrase gehort aufs Papier oder auf Metall, nie als Foto in die Cloud.</p></article></body></html>'),
    ]);
    $stock = app(StockScripts::class);

    [$format, $material] = $stock->material();

    // No podcast digest in the database: the tutorial is the only format with material.
    expect($format)->toBe('tutorial')
        ->and($material)->toStartWith('Der echte Artikel')
        ->and(StockScripts::prompt('tutorial', $material))->toContain('Keine Anlage-, Rechts- oder Steuerberatung')->toContain('ZEITLOS')
        ->and(StockScripts::jinglesFor('stock:tutorial'))->toBe(['stock-tutorial', 'talk', 'station'])
        ->and(StockScripts::jinglesFor('stock:geschichte'))->toBe(['stock-geschichte', 'rubric-geschichte', 'talk', 'station'])
        ->and(StockScripts::jinglesFor('league'))->toBeNull();

    // A stock block opens with its format's jingle line, never with a time-of-day jingle.
    $plan = BlockComposer::plan([['id' => 1, 'kind' => 'scene', 'topic' => 'stock:tutorial', 'speakers' => ['markus', 'sirius']]], CarbonImmutable::now(), 0.0, fn () => 0.5, evergreen: true);
    expect($plan[0]['categories'])->toBe(['stock-tutorial', 'talk', 'station']);

    foreach ([0.0, 0.1, 0.3, 0.45, 0.6, 0.7, 0.9] as $roll) {
        $talk = BlockComposer::plan([['id' => 1, 'kind' => 'scene', 'topic' => 'league', 'speakers' => ['markus', 'sirius']]], CarbonImmutable::now(), 0.0, fn () => $roll, evergreen: true);
        expect(collect($talk[0]['categories'])->contains(fn (string $category): bool => str_starts_with($category, 'time-')))->toBeFalse();
    }
});

it('hands out stock scripts only to a stock claim, written by the scheduler, and marks them done', function () {
    // The fixture ends on the tutorial's back-announce (StockScripts::BACK_ANNOUNCE); the programme length
    // (user 2026-10-16: one pass, 40-80 turns) is a config value - under test is the flow, so the turns match
    // the eight-turn fixture.
    config(['tv.commentary.blocks.stock.formats' => ['tutorial'], 'tv.commentary.blocks.stock.turns' => ['min' => 2, 'max' => 10], 'tv.commentary.blocks.stock.judge.enabled' => false]);
    Http::fake([
        'discover.einundzwanzig.space/*' => Http::response('<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>E</title><item><title>Deine Seed-Phrase sicher aufbewahren</title><link>https://media.einundzwanzig.space/seed</link><pubDate>Mon, 12 Oct 2026 10:00:00 +0000</pubDate><dc:creator>pleb</dc:creator><description>Volltext folgt</description></item></channel></rss>'),
        'media.einundzwanzig.space/*' => Http::response('<html><body><article><p>Die Seed-Phrase gehort aufs Papier oder auf Metall, nie als Foto in die Cloud.</p></article></body></html>'),
    ]);
    VoiceAgent::fake(fn (): array => ['text' => dayStockAnswer(), 'canon_id' => null, 'mood' => 'fakten']);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));

    // The scheduler writes the stock now (ScriptQueue::fill -> fillStock, CLI with no web timeout; user 2026-10-16):
    // with the workstation reporting room, one programme lands in the queue for the claim to hand out.
    DayStock::rememberReport('100.0;0', CarbonImmutable::now());
    expect(app(ScriptQueue::class)->fillStock(CarbonImmutable::now()))->toBe(1);

    $claim = dayStockClaim('?only=stock')->assertOk();
    $id = $claim->json('script.id');
    $script = CasterScript::query()->find($id);

    expect($script->scene)->toBe(StockScripts::SCENE)
        ->and($script->topic)->toStartWith(StockScripts::TOPIC_PREFIX)
        ->and($script->status)->toBe(CasterScript::CLAIMED)
        ->and(implode(' ', $script->sentences()))->not->toContain('Uhr');

    // A normal claim never takes a stock script, not even a queued later part.
    CasterScript::query()->where('scene', StockScripts::SCENE)->where('id', '!=', $id)->update(['status' => CasterScript::QUEUED]);
    expect(app(ScriptQueue::class)->claim(CarbonImmutable::now()))->toBeNull();

    $this->postJson('/api/caster/stock/stored', [], ['Authorization' => 'Bearer '.DAY_STOCK_TOKEN, 'X-Caster-Scripts' => (string) $id])->assertOk()->assertJson(['stored' => 1]);
    expect($script->fresh()->status)->toBe(CasterScript::VOICED);

    // By day without the day share: the scheduler writes no more, and the claim has nothing.
    config(['tv.commentary.blocks.stock.day_share' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin'));
    CasterScript::query()->where('scene', StockScripts::SCENE)->delete();
    DayStock::rememberReport('100.0;0', CarbonImmutable::now());
    expect(app(ScriptQueue::class)->fillStock(CarbonImmutable::now()))->toBe(0);
    expect(dayStockClaim('?only=stock')->json('script'))->toBeNull();
});

it('stops writing slide scenes by day while the stock is reported, keeps the live match, and writes them at night', function () {
    $facts = Mockery::mock(SceneFacts::class, [app(TournamentSlides::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $facts->shouldReceive('forTopic')->andReturnUsing(fn (string $topic): array => ['players' => 21, 'topic' => $topic]);
    $facts->shouldReceive('hasData')->andReturn(true);
    $queue = new ScriptQueue(app(CasterVoice::class), $facts);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin'));

    DayStock::rememberReport('3600.0;0', CarbonImmutable::now());

    expect($queue->scenesSuppressed(SceneTopic::LEAGUE, false))->toBeTrue()
        ->and($queue->scenesSuppressed(SceneTopic::LIVE_GAMES, false))->toBeFalse()
        ->and($queue->scenesSuppressed(SceneTopic::LEAGUE, true))->toBeFalse();

    config(['tv.commentary.blocks.stock.suppress_scenes' => false]);
    expect($queue->scenesSuppressed(SceneTopic::LEAGUE, false))->toBeFalse();

    config(['tv.commentary.blocks.stock.suppress_scenes' => true]);
    DayStock::rememberReport('120.0;0', CarbonImmutable::now());
    expect($queue->scenesSuppressed(SceneTopic::LEAGUE, false))->toBeFalse();
});

it('stores a stock block by day behind every fresh block, never makes the spool full for fresh scripts, and refuses it at night', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin'));
    config(['tv.commentary.spool.target_seconds' => 200]);
    $headers = ['Authorization' => 'Bearer '.DAY_STOCK_TOKEN, 'X-Caster-Duration' => '240.000', 'X-Caster-Sentence-Ends' => '10,20,240', 'X-Caster-Boundaries' => '120'];

    $stocked = $this->call('POST', '/api/caster/stock/blocks', [], [], [], collect($headers)->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all(), casterOpus())
        ->assertCreated()->json('clip');
    $fresh = app(ClipSpool::class)->storeBlock([casterScript()], casterOpus(), 60.0, [60.0], [], 0.0, CarbonImmutable::now());

    expect(CasterClip::query()->find($stocked)->isStock())->toBeTrue()
        ->and(app(ClipSpool::class)->nextBlock(CarbonImmutable::now())->id)->toBe($fresh->id)
        ->and(app(ClipSpool::class)->reserveSeconds())->toBe(240.0)
        // 300 s ready, 240 of them stock: below the target of 200 for fresh scripts.
        ->and(dayStockClaim()->json('full'))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-10-14 03:00', 'Europe/Berlin'));
    $this->call('POST', '/api/caster/stock/blocks', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.DAY_STOCK_TOKEN, 'HTTP_X_CASTER_DURATION' => '240'], casterOpus())->assertStatus(409);
});

it('renders a stock block at night when nothing is to voice, without a time jingle, and keeps it on the workstation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));
    config(['tv.commentary.blocks.stock.seconds' => ['min' => 1, 'max' => 60]]);
    $stockScript = ['id' => 71, 'kind' => 'scene', 'scene' => 'stock', 'topic' => 'stock:talk', 'sentences' => ['Wer seine Seed-Phrase fotografiert, der schenkt sie dem nächsten Datenleck.'], 'speakers' => ['markus'], 'estimated_seconds' => 5];
    $claims = [];
    Http::fake(function (Request $request) use ($stockScript, &$claims) {
        $claims[] = $request->url();

        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim?only=stock') => Http::response(['script' => count(array_filter($claims, fn ($url) => str_ends_with($url, 'only=stock'))) === 1 ? $stockScript : null, 'spool_seconds' => 0, 'pronunciation' => []]),
            str_contains($request->url(), '/api/caster/claim') => Http::response(['script' => null, 'full' => false, 'spool_seconds' => 300, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(Pcm::toWav(str_repeat(pack('v', 2000), 24000), 24000)),
            str_ends_with($request->url(), '/api/caster/stock/stored') => Http::response(['stored' => 1]),
            str_ends_with($request->url(), '/profiles') => Http::response([['id' => 'p']]),
            default => Http::response([], 404),
        };
    });
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::STOCKED);

    $entries = DayStock::fromConfig()->entries();
    $meta = array_values($entries)[0];

    expect($entries)->toHaveCount(1)
        ->and($meta['scripts'][0]['id'])->toBe(71)
        ->and(collect($meta['steps'])->contains(fn (string $step): bool => str_starts_with($step, 'jingle:time-')))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/stock/stored') && $request->header('X-Caster-Scripts')[0] === '71');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/caster/claim') && $request->header(DayStock::HEADER) !== []);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/stock/blocks'));
});

it('uploads a stock block by day below the upload mark, moves it to the archive once taken, and never in the quiet hours', function () {
    $stock = DayStock::fromConfig();
    $scripts = [['id' => 5, 'kind' => 'scene', 'scene' => 'stock', 'topic' => 'stock:talk', 'sentences' => ['Fiatgeld verliert jedes Jahr an Wert, und das ist so gewollt.']]];
    $stock->store(casterOpus(), $scripts, ['ends' => [5.0, 240.0], 'boundaries' => [120.0], 'steps' => ['jingle:talk-01', 'script:5'], 'seconds' => 240.0], CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/api/caster/claim') => Http::response(['script' => null, 'full' => false, 'spool_seconds' => 400, 'pronunciation' => []]),
        str_ends_with($request->url(), '/api/caster/stock/blocks') => Http::response(['clip' => 1], 201),
        str_ends_with($request->url(), '/profiles') => Http::response([['id' => 'p']]),
        default => Http::response([], 404),
    });

    $this->travelTo(CarbonImmutable::parse('2026-10-13 08:30', 'Europe/Berlin'));
    config(['tv.commentary.blocks.stock.window' => ['from' => '', 'to' => '']]);
    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::IDLE)
        // The upload itself refuses in the quiet hours too, whoever calls it.
        ->and(app(BlockFetcher::class)->uploadStock(new CasterFetcher(fn () => null)))->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/stock/blocks'));

    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin'));
    config(['tv.commentary.blocks.stock.window' => ['from' => '00:00', 'to' => '23:59']]);
    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED)
        ->and($stock->entries())->toBe([])
        ->and(array_values(ReplayArchive::fromConfig()->entries())[0]['evergreen'])->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/stock/blocks')
        && $request->header('X-Caster-Duration')[0] === '240.000' && $request->header('X-Caster-Boundaries')[0] === '120.000');
});

it('never stocks a block that names the time', function () {
    $name = DayStock::fromConfig()->store(casterOpus(), [['id' => 9, 'kind' => 'scene', 'sentences' => ['Guten Morgen, es ist sieben Uhr in der Früh.']]],
        ['ends' => [3.0], 'boundaries' => [], 'steps' => ['script:9'], 'seconds' => 3.0], CarbonImmutable::now());

    expect($name)->toBeNull()->and(DayStock::fromConfig()->entries())->toBe([]);
});

it('orders the idle steps: stock before a replay, a replay only when the air runs dry, stock rendering in its window', function () {
    config(['tv.commentary.blocks.stock.day_share' => false]);

    expect(DayPlanner::whenIdle(400, true, false, false, false))->toBe([DayPlanner::UPLOAD_STOCK, DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(60, true, false, false, false))->toBe([DayPlanner::UPLOAD_STOCK, DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(60, false, false, false, false))->toBe([DayPlanner::REPLAY, DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(300, false, false, false, false))->toBe([DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(60, false, false, true, true))->toBe([DayPlanner::REPLAY, DayPlanner::RENDER_STOCK, DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(300, false, true, true, true))->toBe([DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(1000, true, false, false, false))->toBe([DayPlanner::WAIT]);

    config(['tv.commentary.blocks.stock.day_share' => true]);
    expect(DayPlanner::whenIdle(1000, true, false, false, false))->toBe([DayPlanner::RENDER_STOCK, DayPlanner::WAIT]);

    config(['tv.commentary.blocks.replay.day_reserve' => false]);
    expect(DayPlanner::whenIdle(60, false, true, false, false))->toBe([DayPlanner::WAIT]);
});

it('caps the day replays at the hourly and daily share', function () {
    // 10 % of the hour: 360 s; 20 % of the day's voice.
    expect(DayPlanner::replayAllowed(0, 0, 3000, 300))->toBeTrue()
        ->and(DayPlanner::replayAllowed(100, 100, 3000, 300))->toBeFalse()
        ->and(DayPlanner::replayAllowed(0, 400, 3000, 300))->toBeFalse()
        ->and(DayPlanner::replayAllowed(0, 0, 0, 300))->toBeFalse();

    config(['tv.commentary.blocks.replay.hourly_share' => 0.0]);
    expect(DayPlanner::replayAllowed(0, 0, 100_000, 60))->toBeFalse();
});

it('takes a day replay only when the spool runs dry and within the budget from the air log', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:30', 'Europe/Berlin'));
    $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.DAY_STOCK_TOKEN, 'HTTP_X_CASTER_DURATION' => '240'];
    $at = (float) now()->getTimestamp();

    // Since 09:00: 4800 s of fresh voice, no replay yet.
    AirLog::record(['clip' => 1, 'kind' => 'block', 'block' => true, 'start' => $at - 5000, 'end' => $at - 200, 'source' => AirLog::FRESH]);
    $first = $this->call('POST', '/api/caster/replays', [], [], [], $headers, casterOpus())->assertCreated();
    expect(CasterClip::query()->find($first->json('clip'))->expires_at->getTimestamp())->toBe(now()->addMinutes(20)->getTimestamp());

    // The waiting replay counts: another 240 s would pass 360 s this hour.
    $this->call('POST', '/api/caster/replays', [], [], [], $headers, casterOpus())->assertStatus(409)->assertJson(['error' => 'not_dry']);
    CasterClip::query()->update(['status' => CasterClip::PLAYING]);
    $this->call('POST', '/api/caster/replays', [], [], [], $headers, casterOpus())->assertStatus(409)->assertJson(['error' => 'over_budget']);

    config(['tv.commentary.blocks.replay.day_reserve' => false]);
    $this->call('POST', '/api/caster/replays', [], [], [], $headers, casterOpus())->assertStatus(409)->assertJson(['error' => 'not_now']);
});

it('replays a block never within six hours of its first airing and once a day', function () {
    $archive = ReplayArchive::fromConfig();
    $scripts = [['id' => 3, 'kind' => 'scene', 'topic' => 'stock:talk', 'sentences' => ['Bitcoin hat keinen Chef und braucht auch keinen.']]];
    $archive->store(casterOpus(), $scripts, ['ends' => [3.0], 'boundaries' => [], 'steps' => ['script:3'], 'seconds' => 3.0], CarbonImmutable::parse('2026-10-13 10:00', 'Europe/Berlin'));

    expect($archive->pick(CarbonImmutable::parse('2026-10-13 15:59', 'Europe/Berlin'), 24, 6))->toBeNull()
        ->and($archive->pick(CarbonImmutable::parse('2026-10-13 16:01', 'Europe/Berlin'), 24, 6))->not->toBeNull();

    $archive->markReplayed(array_key_first($archive->entries()), CarbonImmutable::parse('2026-10-13 16:01', 'Europe/Berlin'));
    expect($archive->pick(CarbonImmutable::parse('2026-10-14 15:00', 'Europe/Berlin'), 24, 6))->toBeNull()
        ->and($archive->pick(CarbonImmutable::parse('2026-10-14 16:02', 'Europe/Berlin'), 24, 6))->not->toBeNull();
});

it('logs the airtime mix per hour: fresh, stock, replay and music', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 16:00', 'Europe/Berlin'));
    $hour = (float) CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin')->getTimestamp();
    Cache::forget(AirLog::KEY);
    AirLog::record(['clip' => 1, 'kind' => 'block', 'block' => true, 'start' => $hour, 'end' => $hour + 1800, 'source' => AirLog::FRESH]);
    AirLog::record(['clip' => 2, 'kind' => 'block', 'block' => true, 'start' => $hour + 1800, 'end' => $hour + 3000, 'source' => AirLog::STOCK]);
    AirLog::record(['clip' => 3, 'kind' => 'block', 'block' => true, 'start' => $hour + 3000, 'end' => $hour + 3900, 'source' => AirLog::REPLAY]);

    $mix = AirLog::hourly(AirLog::entries(), $hour, $hour + 7200);

    expect($mix)->toBe([
        ['hour' => '2026-10-13 14:00', 'fresh' => 1800.0, 'stock' => 1200.0, 'replay' => 600.0, 'music' => 0.0],
        ['hour' => '2026-10-13 15:00', 'fresh' => 0.0, 'stock' => 0.0, 'replay' => 300.0, 'music' => 3300.0],
    ]);

    Artisan::call('caster:gaps', ['--minutes' => 180, '--mix' => true, '--json' => true]);
    expect(Artisan::output())->toContain('"stock":1200');
});

it('writes stock talks ahead only in the stock window and while the workstation reports room', function () {
    config(['tv.commentary.blocks.stock.formats' => ['tutorial'], 'tv.commentary.blocks.stock.judge.enabled' => false, 'tv.commentary.blocks.stock.turns' => ['min' => 2, 'max' => 10]]);
    // The tutorial programme lives on the real discover feed now (user 2026-10-16): one real article, its page too.
    Http::fake([
        'discover.einundzwanzig.space/*' => Http::response('<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>E</title>'
            .'<item><title>Deine Seed-Phrase sicher aufbewahren</title><link>https://media.einundzwanzig.space/seed</link><pubDate>Mon, 12 Oct 2026 10:00:00 +0000</pubDate><dc:creator>pleb</dc:creator><description>Volltext folgt</description></item></channel></rss>'),
        'media.einundzwanzig.space/*' => Http::response('<html><body><article><p>Die Seed-Phrase gehort aufs Papier oder auf Metall, nie als Foto in die Cloud: Wer seine Worter fotografiert, schenkt sie dem nachsten Datenleck. Metall ubersteht sogar einen Wohnungsbrand, Papier nicht. Ein Bierdeckel ist ubrigens keine Aufbewahrung.</p></article></body></html>'),
    ]);
    // The answer retells the article's facts in its own words - taking a sentence from the material is what the
    // judge refuses - with the opener/back-announce frame the writer requires (dayStockAnswer's shape, P21).
    VoiceAgent::fake(fn (): array => ['text' => "Markus: Wer seine zwolf Worter abfotografiert und in irgendeine Cloud schiebt, hat sie praktisch schon verschenkt, nur ohne Geschenkpapier.\nSirius: Meine Worter lagen frueher auf einem Bierdeckel, und der Deckel ist beim Aufraumen verloren gegangen.\nMarkus: Genau deshalb gehoren die Worter auf Papier oder besser noch auf Metall und an einen Ort ohne Internet.\nSirius: Metall klingt nach Baumarkt und Hammer, aber ein Wohnungsbrand bekommt dem Papier gar nicht gut.\nMarkus: Und niemand fragt dich je nach diesen Wortern, weder der Support noch eine Bank und auch kein freundlicher Fremder.\nSirius: Der freundliche Fremde wollte sie neulich zur Sicherheit uberprufen und war dann plotzlich weg.\nMarkus: Der war so freundlich wie eine Zentralbank, die dir verspricht, dass dein Geld nachstes Jahr noch genauso viel wert ist.\nSirius: Dann schreibe ich die Worter auf Metall, und damit war das das Bitcoin-Tutorial, zuruck ins Programm.", 'canon_id' => null, 'mood' => 'fakten']);
    $queue = app(ScriptQueue::class);
    $stockTalks = fn (): int => CasterScript::query()->where('scene', StockScripts::SCENE)->where(fn ($query) => $query->whereNull('part_index')->orWhere('part_index', 1))->count();

    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(0);

    DayStock::rememberReport('100.0;1', CarbonImmutable::now());
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(0);

    DayStock::rememberReport('100.0;0', CarbonImmutable::now());
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillStock(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillStock(CarbonImmutable::now()))->toBe(0)
        ->and($stockTalks())->toBe(2)
        // Never counted as airtime ahead: the night scenes are written as before.
        ->and($queue->airtimeAhead())->toBe(0.0);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin'));
    CasterScript::query()->delete();
    DayStock::rememberReport('100.0;0', CarbonImmutable::now());
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(0);
});

it('writes no stock while a show window is open: the writer belongs to the show (live 2026-10-16)', function () {
    config(['tv.commentary.blocks.stock.formats' => ['tutorial'], 'tv.commentary.blocks.stock.judge.enabled' => false, 'tv.commentary.blocks.stock.turns' => ['min' => 2, 'max' => 10],
        'tv.commentary.programme.enabled' => true, 'tv.commentary.news.enabled' => true, 'tv.commentary.programme.shows.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true]);
    Http::fake([
        'discover.einundzwanzig.space/*' => Http::response('<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>E</title>'
            .'<item><title>Deine Seed-Phrase sicher aufbewahren</title><link>https://media.einundzwanzig.space/seed</link><pubDate>Mon, 12 Oct 2026 10:00:00 +0000</pubDate><dc:creator>pleb</dc:creator><description>Volltext folgt</description></item></channel></rss>'),
        'media.einundzwanzig.space/*' => Http::response('<html><body><article><p>Die Seed-Phrase gehort aufs Papier oder auf Metall, nie als Foto in die Cloud: Wer seine Worter fotografiert, schenkt sie dem nachsten Datenleck. Metall ubersteht sogar einen Wohnungsbrand, Papier nicht. Ein Bierdeckel ist ubrigens keine Aufbewahrung.</p></article></html>'),
    ]);
    VoiceAgent::fake(fn (): array => ['text' => dayStockAnswer(false), 'canon_id' => null, 'mood' => 'fakten']);
    $queue = app(ScriptQueue::class);
    $stockTalks = fn (): int => CasterScript::query()->where('scene', StockScripts::SCENE)->where(fn ($query) => $query->whereNull('part_index')->orWhere('part_index', 1))->count();

    // 08:40 Berlin, the stock window open and the workstation reports room: the jahrestag of 10:03 is inside its
    // 90-minute write window and not written, so the stock writer waits.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 08:40', 'Europe/Berlin'));
    DayStock::rememberReport('100.0;0', CarbonImmutable::now());
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(0)
        ->and($stockTalks())->toBe(0);

    // The show written (the slot's script exists): the stock writes again.
    CasterScript::create(['kind' => CasterScript::RUBRIC, 'phase' => 'break', 'status' => CasterScript::QUEUED, 'source' => 'ai', 'topic' => 'jahrestag',
        'text' => 'Markus: Die Bitcoin-Zeitreise steht.', 'facts_hash' => 'show-window-open-1', 'priority' => 70, 'estimated_seconds' => 30,
        'expires_at' => CarbonImmutable::parse('2026-10-13 10:10', 'Europe/Berlin')->utc(),
        'airs_at' => CarbonImmutable::parse('2026-10-13 10:03', 'Europe/Berlin')->utc(), 'speakers' => ['markus', 'sirius']]);
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(1)
        ->and($stockTalks())->toBe(1);
});

it('caps the stock writes per hour: one whole-programme write holds the writer for minutes', function () {
    config(['tv.commentary.blocks.stock.formats' => ['tutorial'], 'tv.commentary.blocks.stock.judge.enabled' => false, 'tv.commentary.blocks.stock.turns' => ['min' => 2, 'max' => 10],
        'tv.commentary.blocks.stock.writes_per_hour' => 1]);
    Http::fake([
        'discover.einundzwanzig.space/*' => Http::response('<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>E</title>'
            .'<item><title>Deine Seed-Phrase sicher aufbewahren</title><link>https://media.einundzwanzig.space/seed</link><pubDate>Mon, 12 Oct 2026 10:00:00 +0000</pubDate><dc:creator>pleb</dc:creator><description>Volltext folgt</description></item></channel></rss>'),
        'media.einundzwanzig.space/*' => Http::response('<html><body><article><p>Die Seed-Phrase gehort aufs Papier oder auf Metall, nie als Foto in die Cloud: Wer seine Worter fotografiert, schenkt sie dem nachsten Datenleck. Metall ubersteht sogar einen Wohnungsbrand, Papier nicht. Ein Bierdeckel ist ubrigens keine Aufbewahrung.</p></article></html>'),
    ]);
    VoiceAgent::fake(fn (): array => ['text' => dayStockAnswer(false), 'canon_id' => null, 'mood' => 'fakten']);
    $queue = app(ScriptQueue::class);

    // 03:00 Berlin, room reported: the first programme of the hour writes, the second waits (one queued, so the
    // two-queued guard alone would let it through).
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));
    DayStock::rememberReport('100.0;0', CarbonImmutable::now());
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillStock(CarbonImmutable::now()))->toBe(0);

    // An hour past the first write: the next programme goes out.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 04:01', 'Europe/Berlin'));
    DayStock::rememberReport('100.0;0', CarbonImmutable::now());
    expect($queue->fillStock(CarbonImmutable::now()))->toBe(1);
});

it('answers a chat reaction in the middle of a stock render: fresh items keep the GPU', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));
    config(['tv.commentary.blocks.stock.seconds' => ['min' => 1, 'max' => 60], 'tv.commentary.blocks.urgent_poll_seconds' => 0]);
    $stockScript = ['id' => 71, 'kind' => 'scene', 'scene' => 'stock', 'topic' => 'stock:talk', 'sentences' => ['Wer seine Seed-Phrase fotografiert, der schenkt sie dem nächsten Datenleck.', 'Und wer sie auf Metall schreibt, der schläft einfach ruhiger.'], 'speakers' => ['markus', 'sirius'], 'estimated_seconds' => 5];
    $chat = ['id' => 90, 'kind' => 'chat', 'sentences' => ['Danke für die Frage aus dem Chat, die beantworte ich gern sofort.']];
    [$stockClaims, $urgentClaims] = [0, 0];
    Http::fake(function (Request $request) use ($stockScript, $chat, &$stockClaims, &$urgentClaims) {
        return match (true) {
            str_ends_with($request->url(), '?only=stock') => Http::response(['script' => $stockClaims++ === 0 ? $stockScript : null, 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '?only=urgent') => Http::response(['script' => $urgentClaims++ === 0 ? $chat : null, 'spool_seconds' => 300, 'pronunciation' => []]),
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => null, 'full' => false, 'spool_seconds' => 300, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(Pcm::toWav(str_repeat(pack('v', 2000), 24000), 24000)),
            default => Http::response(['ok' => true], 201),
        };
    });
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::STOCKED);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/scripts/90/clip'));
    expect(DayStock::fromConfig()->entries())->toHaveCount(1);
});

it('gives the Anchor English lines only, ESPORTS spoken as "E. Sports.", and a slow length per line', function () {
    $lines = json_decode((string) file_get_contents(resource_path('data/voice/anchor-jingles.json')), true)['lines'];

    expect(count($lines))->toBeGreaterThanOrEqual(40);

    foreach ($lines as $line) {
        expect($line['language'])->toBe('en')
            ->and($line['text'])->not->toMatch('/\b(?:die|der|das|ihr|hört|und|nachrichten|gleich)\b/iu')
            ->and($line['spoken'])->not->toContain('ESPORTS');

        if (str_contains($line['text'], 'ESPORTS')) {
            expect($line['spoken'])->toContain('TWENTY ONE. E. Sports. Radio');
        }
    }

    expect(collect($lines)->pluck('category')->unique()->all())->toContain('stock-talk', 'stock-tutorial', 'stock-geschichte', 'replay')
        ->and(CasterJinglesCommand::duration('You are listening to TWENTY ONE. E. Sports. Radio.'))->toBe(7.4);
});

it('renders an Anchor line again only when its spoken text or length changed', function () {
    config(['tv.commentary.blocks.jingles.dir' => dayStockTempDir(), 'tv.commentary.blocks.anchor.profile_id' => 'anchor-1']);
    Http::fake(['voicestudio.test/generate' => Http::response(Pcm::toWav(str_repeat(pack('v', 900), 24000), 24000)), '*' => Http::response([])]);
    Process::fake();
    $generated = fn (): int => count(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/generate')));
    // The block jingles and the radio imaging (P11) are voiced by the same run.
    $count = count(json_decode((string) file_get_contents(resource_path('data/voice/anchor-jingles.json')), true)['lines'])
        + count(json_decode((string) file_get_contents(resource_path('data/voice/anchor-imaging.json')), true)['lines']);
    $field = fn (Request $request, string $name): ?string => collect($request->data())->firstWhere('name', $name)['contents'] ?? null;

    $this->artisan('caster:jingles', ['--pause' => 0, '--no-blips' => true, '--limit' => 400])->assertSuccessful();
    expect($generated())->toBe($count);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/generate')
        && $field($request, 'text') === "You're listening to TWENTY ONE. E. Sports. Radio."
        && $field($request, 'duration') === '6.8' && $field($request, 'language') === 'en');

    $this->artisan('caster:jingles', ['--pause' => 0, '--no-blips' => true, '--limit' => 400])->assertSuccessful();
    expect($generated())->toBe($count);

    config(['tv.commentary.blocks.anchor.seconds_per_word' => 0.7]);
    $this->artisan('caster:jingles', ['--pause' => 0, '--no-blips' => true, '--limit' => 3])->assertSuccessful();
    expect($generated())->toBe($count + 3);
});

it('simulates a day at the measured pace: the night stock carries the day, chat within ten minutes, bulletins on time, replays within their share', function () {
    config(['tv.commentary.chat.max_hold_seconds' => 600]);

    $sim = (new DayStockSimulator(1.2))->run();

    expect($sim->stockRenderedNight)->toBeGreaterThan(5 * 3600)
        ->and($sim->emptySecondsDay)->toBeLessThan(60)
        ->and(max($sim->chatLatency))->toBeLessThanOrEqual(600)
        ->and(max($sim->slotLateness))->toBeLessThanOrEqual(10)
        ->and($sim->replayDay)->toBe(0.0);

    // Without any stock the reserve steps in, but never past its share: music instead.
    config(['tv.commentary.blocks.stock.enabled' => false]);
    $bare = (new DayStockSimulator(1.2))->run();

    expect($bare->replayDay)->toBeGreaterThan(0.0)
        ->and($bare->replayDay / $bare->voiceDay)->toBeLessThanOrEqual(0.2)
        ->and(collect($bare->hours)->max('replay'))->toBeLessThanOrEqual(360)
        ->and(max($bare->chatLatency))->toBeLessThanOrEqual(600);
});
