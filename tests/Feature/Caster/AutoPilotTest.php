<?php

use App\Console\Commands\CasterFetchCommand;
use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\AtomicFile;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Blocks\BlockFetcher;
use App\Support\Caster\Blocks\DayPlanner;
use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Blocks\StockFeeder;
use App\Support\Caster\Blocks\StockLibrary;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ChatReactions;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\ImagingStore;
use App\Support\Caster\Pcm;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpoolFull;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\AutoPilotSimulator;
use Tests\Support\DayStockSimulator;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio P18: the server's stock library and the autopilot
|--------------------------------------------------------------------------
|
| User 2026-10-12: "mehrere Tage fest-statische Programme vorrendern, weil
| dann muss mein Laptop nicht die ganze Zeit an sein"; 2026-10-13: "so bauen,
| dass man den Laptop ohne Probleme runterfahren kann und dann der Auto-Modus
| von selbst loslegt; das Runterfahren macht nichts kaputt; wenn er wieder da
| ist und VoiceStudio wieder da ist, seamless übernehmen für Aktuelles".
| The workstation uploads every finished stock block into a bounded library
| on the server and renders until it holds 60 h; the server feeds its spool
| from it and runs the programme on its own while the workstation is gone.
| HTTP and process fakes, never the network, VoiceStudio or ffmpeg.
|
*/

const AUTOPILOT_TOKEN = 'autopilot-token-0123456789abcdef0123456789ab';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        // The daytime slots and hours these tests use: no replay window (PodcastLibrary::airsAt()).
        'tv.commentary.podcast_replay.window' => ['from' => '00:00', 'to' => '00:00'],
        'tv.commentary.fetch.token' => AUTOPILOT_TOKEN,
        'tv.commentary.fetch.online_seconds' => 120,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.blocks.enabled' => true,
        'tv.commentary.blocks.stock.enabled' => true,
        'tv.commentary.blocks.stock.library.enabled' => true,
        'tv.commentary.blocks.stock.library.dir' => autopilotDir(),
        'tv.commentary.blocks.stock.dir' => autopilotDir(),
        'tv.commentary.blocks.replay.enabled' => true,
        'tv.commentary.blocks.replay.dir' => autopilotDir(),
        'tv.commentary.blocks.jingles.dir' => autopilotDir(),
        'tv.commentary.blocks.autopilot.enabled' => true,
        'tv.commentary.blocks.autopilot.music_gap_seconds' => ['min' => 30, 'max' => 30],
        'tv.commentary.imaging.enabled' => true,
        'tv.commentary.imaging.dir' => autopilotDir(),
        'tv.commentary.spool.quiet_hours' => ['from' => '01:00', 'to' => '09:00', 'timezone' => 'Europe/Berlin', 'every_minutes' => 10, 'ahead_seconds' => 90],
        'tv.commentary.podcast_replay.enabled' => false,
    ]);
});

function autopilotDir(): string
{
    return storage_path('framework/testing/autopilot-'.bin2hex(random_bytes(4)));
}

/** An Opus stand-in that passes the format checks, distinct per `$tag`. */
function autopilotOpus(string $tag): string
{
    return casterOpus(3000).$tag;
}

/**
 * A block straight into the library; its hash.
 *
 * @param  array<string, mixed>  $meta
 */
function autopilotBlock(string $tag, array $meta = [], ?CarbonImmutable $now = null): string
{
    $opus = autopilotOpus($tag);
    StockLibrary::fromConfig()->store(sha1($opus), $opus, $meta + ['format' => 'talk', 'subjects' => ['stock:talk:'.$tag], 'seconds' => 300.0, 'ends' => [100.0, 300.0], 'boundaries' => [150.0], 'evergreen' => true], $now ?? CarbonImmutable::now());

    return sha1($opus);
}

/**
 * The imaging library on the server with one jingle per category.
 *
 * @param  list<string>  $categories
 */
function autopilotImaging(array $categories): void
{
    $store = ImagingStore::fromConfig();
    $items = [];

    foreach ($categories as $index => $category) {
        $opus = autopilotOpus('jingle-'.$category);
        $store->storeFile(sha1($opus), $opus);
        $items[] = ['id' => 'img-'.$index, 'line' => 'img-'.$index, 'category' => $category, 'seconds' => 4.0, 'hash' => sha1($opus), 'text' => null];
    }

    expect($store->storeManifest($items))->toBe([]);
}

/** @param  array<string, string>  $headers */
function autopilotPost(string $uri, string $body, array $headers = []): TestResponse
{
    $server = collect(['Authorization' => 'Bearer '.AUTOPILOT_TOKEN, ...$headers])->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all();

    return test()->call('POST', $uri, [], [], [], $server, $body);
}

/** ffmpeg faked: a decode gives one second of PCM, an encode an Opus stand-in. */
function autopilotFfmpeg(): void
{
    Process::fake(fn (PendingProcess $process) => Process::result(output: in_array('libopus', (array) $process->command, true) ? autopilotOpus('encoded') : str_repeat(pack('v', 1000), 24000)));
}

function autopilotWorkstationSeen(?CarbonImmutable $at = null): void
{
    Cache::put(ClipSpool::FETCHER_SEEN, ($at ?? CarbonImmutable::now())->getTimestamp(), 3600);
}

it('takes a block into the library under its sha1, idempotent, and refuses what is not that block', function () {
    $opus = autopilotOpus('a');
    $meta = base64_encode((string) json_encode(['format' => 'tutorial', 'topics' => ['stock:tutorial'], 'subjects' => ['stock:tutorial:abc'], 'evergreen' => true]));
    $headers = ['X-Caster-Duration' => '300.000', 'X-Caster-Sentence-Ends' => '10,300', 'X-Caster-Boundaries' => '150', 'X-Caster-Stock-Meta' => $meta];

    test()->call('POST', '/api/caster/stock/library/'.sha1($opus), [], [], [], [], $opus)->assertStatus(401);
    autopilotPost('/api/caster/stock/library/'.sha1('other'), $opus, $headers)->assertStatus(422)->assertJson(['reason' => 'hash']);
    autopilotPost('/api/caster/stock/library/'.sha1('RIFF'), 'RIFF', $headers)->assertStatus(422);
    autopilotPost('/api/caster/stock/library/'.sha1($opus), $opus, [...$headers, 'X-Caster-Stock-Meta' => base64_encode('{"evergreen":false}')])->assertStatus(422)->assertJson(['reason' => 'not_evergreen']);
    autopilotPost('/api/caster/stock/library/'.sha1($opus), $opus, [...$headers, 'X-Caster-Duration' => '0'])->assertStatus(422)->assertJson(['reason' => 'size']);

    autopilotPost('/api/caster/stock/library/'.sha1($opus), $opus, $headers)->assertCreated()->assertJson(['outcome' => StockLibrary::STORED, 'seconds' => 300.0, 'full' => false]);
    autopilotPost('/api/caster/stock/library/'.sha1($opus), $opus, $headers)->assertOk()->assertJson(['outcome' => StockLibrary::EXISTS]);

    $entry = StockLibrary::fromConfig()->entries()[sha1($opus)];
    expect($entry)->toMatchArray(['format' => 'tutorial', 'subjects' => ['stock:tutorial:abc'], 'seconds' => 300.0, 'ends' => [10.0, 300.0], 'boundaries' => [150.0], 'aired_count' => 0, 'evergreen' => true])
        ->and(autopilotPost('/api/caster/stock/library', '')->assertOk()->json())->toMatchArray(['blocks' => 1, 'seconds' => 300.0, 'unaired_seconds' => 300.0, 'target_seconds' => 216000.0]);

    config(['tv.commentary.blocks.stock.library.enabled' => false]);
    autopilotPost('/api/caster/stock/library', '')->assertStatus(409);
});

it('evicts the retired and then the oldest-aired blocks for a new one, never one that never aired', function () {
    $size = strlen(autopilotOpus('a'));
    config(['tv.commentary.blocks.stock.library.max_bytes' => 3 * $size, 'tv.commentary.blocks.stock.library.retire_after_airings' => 2]);
    $library = StockLibrary::fromConfig();
    [$a, $b, $c] = [autopilotBlock('a'), autopilotBlock('b'), autopilotBlock('c')];
    $library->markAired($b, CarbonImmutable::now()->subHours(30));
    $library->markAired($c, CarbonImmutable::now()->subHours(50));
    $library->markAired($c, CarbonImmutable::now()->subHours(40));

    // c aired twice (retired) goes first although b aired longer ago... no: b aired 30 h ago, c 40 h ago; c is retired anyway.
    autopilotBlock('d');
    expect(array_keys($library->entries()))->toEqualCanonicalizing([$a, $b, sha1(autopilotOpus('d'))])
        ->and(is_file($library->file($c)))->toBeFalse();

    // Then the oldest-aired (b); a never aired, d never aired: a full library of unaired blocks refuses.
    autopilotBlock('e');
    expect(array_keys($library->entries()))->toEqualCanonicalizing([$a, sha1(autopilotOpus('d')), sha1(autopilotOpus('e'))]);
    expect(fn () => autopilotBlock('f'))->toThrow(SpoolFull::class)
        ->and($library->entries())->toHaveCount(3);
});

it('picks never-aired blocks first, spaces subjects six hours, never repeats within 24 hours, then the least recently aired', function () {
    $now = CarbonImmutable::parse('2026-10-14 12:00', 'Europe/Berlin');
    $library = StockLibrary::fromConfig();
    $old = autopilotBlock('old', ['subjects' => ['stock:talk:fiat'], 'rendered_at' => $now->subDays(3)->getTimestamp()]);
    $same = autopilotBlock('same', ['subjects' => ['stock:talk:fiat'], 'rendered_at' => $now->subDays(2)->getTimestamp()]);
    $other = autopilotBlock('other', ['format' => 'geschichte', 'subjects' => ['stock:geschichte:x'], 'rendered_at' => $now->subDay()->getTimestamp()]);

    expect($library->pick($now, repeats: false)['hash'])->toBe($old);
    $library->markAired($old, $now);

    // The same subject waits six hours: the history block first, although rendered later.
    expect($library->pick($now->addMinutes(5), repeats: false)['hash'])->toBe($other);
    $library->markAired($other, $now->addMinutes(5));
    // Only the crowded one is left: it still airs rather than nothing.
    expect($library->pick($now->addMinutes(10), repeats: false)['hash'])->toBe($same);
    $library->markAired($same, $now->addMinutes(10));

    // Without repeats nothing is left; with repeats nothing within 24 h of its airing.
    expect($library->pick($now->addHours(2), repeats: false))->toBeNull()
        ->and($library->pick($now->addHours(23), repeats: true))->toBeNull();

    $pick = $library->pick($now->addHours(25), repeats: true);
    expect($pick['hash'])->toBe($old)->and($pick['repeat'])->toBeTrue();
});

it('lets the workstation upload every finished stock block at once and pause rendering at the library target', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin'));
    $stock = DayStock::fromConfig();
    $scripts = [['id' => 5, 'kind' => 'scene', 'scene' => 'stock', 'topic' => 'stock:tutorial', 'subject' => 'stock:tutorial:4f1e2d3c4b', 'sentences' => ['Eine Seed-Phrase gehört auf Papier oder Metall.']]];
    $stock->store(autopilotOpus('w'), $scripts, ['ends' => [5.0, 240.0], 'boundaries' => [120.0], 'steps' => ['jingle:stock-tutorial-01', 'script:5'], 'seconds' => 240.0], CarbonImmutable::now());
    $sent = null;
    Http::fake(function (Request $request) use (&$sent) {
        $sent = $request;

        return Http::response(['outcome' => 'stored'], 201);
    });

    // Upload first, day or night, whatever the spool holds; no rendering once the server's library is full.
    expect(DayPlanner::whenIdle(3000.0, true, false, false, false))->toBe([DayPlanner::UPLOAD_LIBRARY, DayPlanner::RENDER_STOCK, DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(3000.0, true, false, true, true))->toBe([DayPlanner::UPLOAD_LIBRARY, DayPlanner::RENDER_STOCK, DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(3000.0, false, false, false, false, null, libraryFull: true))->toBe([DayPlanner::WAIT]);

    expect(app(BlockFetcher::class)->uploadLibrary(new CasterFetcher(fn () => null)))->toBe(CasterFetcher::VOICED)
        ->and($stock->entries())->toBe([])
        ->and($sent->url())->toBe('https://tv.test/api/caster/stock/library/'.sha1(autopilotOpus('w')))
        ->and(json_decode(base64_decode($sent->header('X-Caster-Stock-Meta')[0]), true))->toMatchArray(['format' => 'tutorial', 'subjects' => ['stock:tutorial:4f1e2d3c4b'], 'evergreen' => true]);
});

it('keeps a block the server refuses, and falls back to the day-stock path for an hour on a server without the library', function () {
    $stock = DayStock::fromConfig();
    $scripts = [['id' => 5, 'kind' => 'scene', 'scene' => 'stock', 'topic' => 'stock:talk', 'sentences' => ['Fiatgeld verliert jedes Jahr an Wert.']]];
    $stock->store(autopilotOpus('k'), $scripts, ['ends' => [240.0], 'boundaries' => [], 'steps' => ['script:5'], 'seconds' => 240.0], CarbonImmutable::now());

    $status = 507;
    Http::fake(function () use (&$status) {
        return Http::response(['error' => 'refused'], $status);
    });
    expect(app(BlockFetcher::class)->uploadLibrary(new CasterFetcher(fn () => null)))->toBe(CasterFetcher::FULL)
        ->and($stock->entries())->toHaveCount(1)
        ->and(DayPlanner::libraryInUse())->toBeTrue();

    $status = 404;
    expect(app(BlockFetcher::class)->uploadLibrary(new CasterFetcher(fn () => null)))->toBeNull()
        ->and($stock->entries())->toHaveCount(1)
        ->and(DayPlanner::libraryInUse())->toBeFalse()
        ->and(DayPlanner::whenIdle(300.0, true, false, false, false)[0])->toBe(DayPlanner::UPLOAD_STOCK);
});

it('tells the workstation in every claim whether the library is full, and writes no stock script then', function () {
    config(['tv.commentary.blocks.stock.library.target_hours' => 300 / 3600]);
    autopilotBlock('full');

    $claim = autopilotPost('/api/caster/claim?only=stock', '', [DayStock::HEADER => '0.0;0', AutoPilot::HEADER => 'up'])->assertOk();

    expect($claim->json('stock_library'))->toBe(['seconds' => 300, 'target_seconds' => 300, 'full' => true])
        ->and($claim->json('script'))->toBeNull()
        ->and(DayPlanner::writeStockAhead(CarbonImmutable::parse('2026-10-14 03:00', 'Europe/Berlin'), ['seconds' => 0.0, 'full' => false, 'at' => 0]))->toBeFalse();
});

it('feeds the spool by day from unaired library blocks when it runs low, never in the quiet hours, never a second one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:00', 'Europe/Berlin'));
    autopilotWorkstationSeen();
    $hash = autopilotBlock('day');
    $feeder = app(StockFeeder::class);

    expect($feeder->tick(CarbonImmutable::now()))->toBe(['block '.substr($hash, 0, 8).' talk 300 s']);
    $clip = CasterClip::query()->sole();
    expect($clip->isStock())->toBeTrue()->and($clip->isReplay())->toBeFalse()->and($clip->block['library'])->toBe($hash)
        ->and(StockLibrary::fromConfig()->entries()[$hash]['aired_count'])->toBe(1)
        ->and($feeder->tick(CarbonImmutable::now()))->toBe([]);

    autopilotBlock('day2');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 03:00', 'Europe/Berlin'));
    autopilotWorkstationSeen();
    $clip->delete();
    expect($feeder->tick(CarbonImmutable::now()))->toBe([]);
});

it('airs a talk show of several library blocks only whole and in order: its blocks as the parts of one story, never a later block alone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:00', 'Europe/Berlin'));
    autopilotWorkstationSeen();
    // Stock by day with the workstation online: the mode without live only (MusicFirstTest: no stock block then).
    config(['tv.commentary.music_first.live_only' => false]);
    // The workstation's view: three blocks of one stock talk, parts 1–12, 13–22, 23–30 (StockLibrary::episodeOf()).
    $part = fn (int $index, bool $continues): array => ['part' => ['group' => '01JTALKEPISODE0000000000AA', 'index' => $index, 'continues' => $continues]];
    expect(StockLibrary::episodeOf([$part(1, true), $part(12, true)]))->toBe(['group' => '01JTALKEPISODE0000000000AA', 'first' => 1, 'last' => 12, 'continues' => true])
        ->and(StockLibrary::episodeOf([$part(23, true), $part(30, false)]))->toBe(['group' => '01JTALKEPISODE0000000000AA', 'first' => 23, 'last' => 30, 'continues' => false])
        ->and(StockLibrary::episodeOf([$part(1, true), $part(9, false)]))->toBeNull()
        ->and(StockLibrary::episodeOf([['id' => 1]]))->toBeNull();

    $episode = fn (int $first, int $last, bool $continues): array => ['episode' => ['group' => '01JTALKEPISODE0000000000AA', 'first' => $first, 'last' => $last, 'continues' => $continues],
        'format' => 'stammtisch', 'subjects' => ['stock:stammtisch:topic-a']];
    // The last block uploaded first, through the endpoint (its meta header): no block of an incomplete episode airs.
    $opus = autopilotOpus('talk-3');
    autopilotPost('/api/caster/stock/library/'.sha1($opus), $opus, ['X-Caster-Duration' => '480', 'X-Caster-Sentence-Ends' => '10,480', 'X-Caster-Boundaries' => '',
        'X-Caster-Stock-Meta' => base64_encode((string) json_encode(['format' => 'stammtisch', 'subjects' => ['stock:stammtisch:topic-a'], 'evergreen' => true] + $episode(23, 30, false)))])->assertCreated();
    $third = sha1($opus);
    $first = autopilotBlock('talk-1', $episode(1, 12, true), CarbonImmutable::now()->subMinutes(30));
    expect(StockLibrary::fromConfig()->entries()[$third]['episode'])->toBe(['group' => '01JTALKEPISODE0000000000AA', 'first' => 23, 'last' => 30, 'continues' => false])
        ->and(StockLibrary::fromConfig()->pick(CarbonImmutable::now(), repeats: true))->toBeNull();

    $second = autopilotBlock('talk-2', $episode(13, 22, true), CarbonImmutable::now()->subMinutes(20));
    $feeder = app(StockFeeder::class);

    expect($feeder->tick(CarbonImmutable::now()))->toBe(['block '.substr($first, 0, 8).' stammtisch 300 s + 2 more of the episode']);

    $clips = CasterClip::query()->with('script')->orderBy('id')->get();
    $group = $clips[0]->script->part_group;
    expect($clips->map(fn (CasterClip $clip): array => [$clip->block['library'], $clip->script->part_group, $clip->script->part_index])->all())
        ->toBe([[$first, $group, 1], [$second, $group, 2], [$third, $group, 3]])
        // The stream starts only the first; the others come as its next parts, back to back (SceneDirector::joinNextBlock()).
        ->and(app(ClipSpool::class)->nextBlock(CarbonImmutable::now())?->id)->toBe($clips[0]->id)
        ->and(app(ClipSpool::class)->part($group, 2, CarbonImmutable::now())?->id)->toBe($clips[1]->id)
        ->and(collect(StockLibrary::fromConfig()->entries())->only([$first, $second, $third])->pluck('aired_count')->all())->toBe([1, 1, 1]);
});

it('opens a repeated talk show episode with one replay jingle, not one per block', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:10', 'Europe/Berlin'));
    autopilotImaging(['replay', 'station-long']);
    autopilotFfmpeg();
    $episode = fn (int $first, int $last, bool $continues): array => ['episode' => ['group' => 'G1', 'first' => $first, 'last' => $last, 'continues' => $continues], 'format' => 'stammtisch'];
    $library = StockLibrary::fromConfig();
    $one = autopilotBlock('rep-1', $episode(1, 10, true));
    $two = autopilotBlock('rep-2', $episode(11, 20, false));
    $library->markAired($one, CarbonImmutable::now()->subHours(30));
    $library->markAired($two, CarbonImmutable::now()->subHours(30));
    $feeder = app(StockFeeder::class);
    $feeder->tick(CarbonImmutable::now());
    $this->travel(31)->seconds();

    expect($feeder->tick(CarbonImmutable::now()))->toBe(['repeat '.substr($one, 0, 8).' stammtisch 2 s + 1 more of the episode']);
    $clips = CasterClip::query()->where('kind', CasterClip::BLOCK)->orderBy('id')->get();
    expect($clips->map(fn (CasterClip $clip): bool => $clip->isReplay())->all())->toBe([true, false]);
    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('libopus', (array) $process->command, true), 1);
});

it('switches to the autopilot when the workstation is gone: claims back at once, a block after the music gap, a repeat behind the replay jingle', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:10', 'Europe/Berlin'));
    autopilotImaging(['replay', 'station-long']);
    autopilotFfmpeg();
    // Claimed by the workstation in its last seconds; it never came back with it.
    $held = casterScript(now: CarbonImmutable::now()->subMinutes(5));
    autopilotWorkstationSeen(CarbonImmutable::now()->subMinutes(5));
    $aired = autopilotBlock('aired');
    StockLibrary::fromConfig()->markAired($aired, CarbonImmutable::now()->subHours(30));
    $feeder = app(StockFeeder::class);

    expect(AutoPilot::active(CarbonImmutable::now()))->toBeTrue()
        ->and($feeder->tick(CarbonImmutable::now()))->toBe(['autopilot on, released 1'])
        ->and($held->fresh()->status)->toBe(CasterScript::QUEUED);

    // The music gap (30 s here) first, then the block: only a repeat is left, it opens with the replay jingle.
    $this->travel(31)->seconds();
    $done = $feeder->tick(CarbonImmutable::now());
    $clip = CasterClip::query()->where('kind', CasterClip::BLOCK)->sole();

    expect($done)->toBe(['repeat '.substr($aired, 0, 8).' talk 2 s'])
        ->and($clip->isReplay())->toBeTrue()->and($clip->isStock())->toBeTrue()
        ->and($clip->block['autopilot'])->toBeTrue()
        // The fake process ends its output with a newline.
        ->and(rtrim((string) Storage::disk('local')->get($clip->path)))->toBe(autopilotOpus('encoded'));
    Process::assertRan(fn (PendingProcess $process): bool => in_array('libopus', (array) $process->command, true));

    // On air: nothing more goes in until it ended.
    $clip->update(['status' => CasterClip::PLAYING]);
    expect($feeder->tick(CarbonImmutable::now()->addSeconds(40)))->toBe([]);
});

it('airs no repeat without the replay jingle: music instead', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:10', 'Europe/Berlin'));
    autopilotImaging(['station-long']);
    autopilotFfmpeg();
    $aired = autopilotBlock('aired');
    StockLibrary::fromConfig()->markAired($aired, CarbonImmutable::now()->subHours(30));
    $feeder = app(StockFeeder::class);
    $feeder->tick(CarbonImmutable::now());
    $this->travel(31)->seconds();

    expect($feeder->tick(CarbonImmutable::now()))->toBe(['repeat skipped: no replay jingle'])
        ->and(CasterClip::query()->count())->toBe(0);
});

it('puts the news-free station ID at the full hour and a podcast episode a minute past it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:58', 'Europe/Berlin'));
    autopilotImaging(['autopilot-hour', 'station-long']);
    $feeder = app(StockFeeder::class);
    $done = $feeder->tick(CarbonImmutable::now());
    $id = CasterClip::query()->where('kind', CasterScript::RUBRIC)->sole();

    expect(collect($done)->contains(fn (string $line): bool => str_starts_with($line, 'station ID img-0 at 2026-10-14T12:00:00')))->toBeTrue()
        ->and($id->script->topic)->toBe(StockFeeder::HOUR_TOPIC)
        ->and($id->isBlock())->toBeTrue()
        ->and(app(ClipSpool::class)->rubric(CarbonImmutable::parse('2026-10-14 14:00:05', 'Europe/Berlin'), 180)?->id)->toBe($id->id);

    // Once per hour.
    $feeder->tick(CarbonImmutable::now()->addSeconds(20));
    expect(CasterClip::query()->where('kind', CasterScript::RUBRIC)->count())->toBe(1);
});

it('airs a podcast episode in the autopilot every few hours, the least recently aired first', function () {
    $now = CarbonImmutable::parse('2026-10-14 13:55', 'Europe/Berlin');
    $episodes = [
        ['key' => 'einundzwanzig', 'guid' => 'new', 'published_at' => 300],
        ['key' => 'einundzwanzig', 'guid' => 'old', 'published_at' => 100],
    ];

    expect(AutoPilot::podcastDue(null, $now))->toBeTrue()
        ->and(AutoPilot::podcastDue($now->subHours(3)->getTimestamp(), $now))->toBeFalse()
        ->and(AutoPilot::podcastDue($now->subHours(4)->getTimestamp(), $now))->toBeTrue()
        ->and(AutoPilot::pickEpisode($episodes, [], $now)['guid'])->toBe('new')
        ->and(AutoPilot::pickEpisode($episodes, ['new' => $now->subHours(5)->getTimestamp()], $now)['guid'])->toBe('old')
        ->and(AutoPilot::pickEpisode($episodes, ['new' => $now->subHours(5)->getTimestamp(), 'old' => $now->subHours(9)->getTimestamp()], $now))->toBeNull()
        ->and(AutoPilot::pickEpisode($episodes, ['new' => $now->subHours(25)->getTimestamp(), 'old' => $now->subHours(30)->getTimestamp()], $now)['guid'])->toBe('old');
});

it('puts the next podcast episode of the library into the spool in the autopilot, and takes it out again unstarted when the workstation is back', function () {
    config(['tv.commentary.podcast_replay.enabled' => true, 'tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => true, 'tv.commentary.podcast_replay.sources.resat.enabled' => false]);
    foreach ([['old', 1000], ['new', 2000]] as [$guid, $published]) {
        $directory = 'caster/podcasts/einundzwanzig/'.substr(sha1($guid), 0, 12);
        Storage::disk('local')->put($directory.'/chunk-000.opus', autopilotOpus('podcast-'.$guid));
        Storage::disk('local')->put($directory.'/meta.json', (string) json_encode(['guid' => $guid, 'title' => '#'.$guid, 'published_at' => $published, 'seconds' => 1800.0,
            'chunks' => [['path' => $directory.'/chunk-000.opus', 'seconds' => 1800.0, 'bytes' => 3000, 'pauses' => [900.0, 1800.0]]]]));
    }
    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:52', 'Europe/Berlin'));
    $feeder = app(StockFeeder::class);

    expect($feeder->tick(CarbonImmutable::now()))->toContain('podcast einundzwanzig new at 2026-10-14T12:01:00+00:00');
    $airing = CasterScript::query()->where('kind', CasterScript::PODCAST)->sole();
    expect($airing->clips()->count())->toBe(1)
        ->and($feeder->tick(CarbonImmutable::now()->addSeconds(15)))->not->toContain('podcast einundzwanzig old at 2026-10-14T12:01:00+00:00');

    autopilotPost('/api/caster/claim', '', [AutoPilot::HEADER => 'up'])->assertOk();
    expect($feeder->tick(CarbonImmutable::now()->addSeconds(30)))->toContain('autopilot off, removed 1')
        ->and(CasterScript::query()->where('kind', CasterScript::PODCAST)->exists())->toBeFalse()
        // The episode's file is the podcast library's: it stays.
        ->and(Storage::disk('local')->exists('caster/podcasts/einundzwanzig/'.substr(sha1('new'), 0, 12).'/chunk-000.opus'))->toBeTrue();
});

it('runs the autopilot while VoiceStudio is reported down although the workstation is there, and ends it with the next claim', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:10', 'Europe/Berlin'));

    autopilotPost('/api/caster/heartbeat', '', [AutoPilot::HEADER => 'down'])->assertOk()->assertJson(['autopilot' => true]);
    expect(app(ClipSpool::class)->fetcherOnline(CarbonImmutable::now()))->toBeTrue()
        ->and(AutoPilot::active(CarbonImmutable::now()))->toBeTrue();

    autopilotPost('/api/caster/claim', '', [AutoPilot::HEADER => 'up'])->assertOk();
    expect(AutoPilot::active(CarbonImmutable::now()))->toBeFalse();

    // Gone: no claim for `online_seconds`.
    $this->travel(121)->seconds();
    expect(AutoPilot::active(CarbonImmutable::now()))->toBeTrue();

    // Off as a whole: never (music as before).
    config(['tv.commentary.blocks.autopilot.enabled' => false]);
    expect(AutoPilot::active(CarbonImmutable::now()))->toBeFalse();
});

it('yields when the workstation is back: repeats, station IDs and unstarted podcasts leave, unaired blocks stay, nothing on air is cut', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:58', 'Europe/Berlin'));
    autopilotImaging(['replay', 'autopilot-hour']);
    autopilotFfmpeg();
    $library = StockLibrary::fromConfig();
    $aired = autopilotBlock('aired');
    $library->markAired($aired, CarbonImmutable::now()->subHours(30));
    $feeder = app(StockFeeder::class);
    $feeder->tick(CarbonImmutable::now());
    $this->travel(31)->seconds();
    $feeder->tick(CarbonImmutable::now());
    $repeat = CasterClip::query()->where('kind', CasterClip::BLOCK)->sole();
    $unaired = autopilotBlock('new');
    $repeat->update(['status' => CasterClip::PLAYING]);
    $playing = $repeat->id;

    // A second repeat waiting would be removed; the one on air plays on.
    $fresh = app(ClipSpool::class)->storeBlock([casterScript()], autopilotOpus('waiting'), 200.0, [200.0], [], 0.0, CarbonImmutable::now(), replay: true, stock: true,
        extra: ['library' => $unaired, 'autopilot' => true, 'library_before' => $library->markAired($unaired, CarbonImmutable::now())]);
    $kept = app(ClipSpool::class)->storeBlock([casterScript()], autopilotOpus('unaired'), 200.0, [200.0], [], 0.0, CarbonImmutable::now(), stock: true, extra: ['autopilot' => true]);

    expect(CasterClip::query()->where('kind', CasterScript::RUBRIC)->count())->toBe(1);

    autopilotPost('/api/caster/claim', '', [AutoPilot::HEADER => 'up'])->assertOk();
    $done = $feeder->tick(CarbonImmutable::now());

    expect($done[0])->toBe('autopilot off, removed 2')
        ->and(CasterClip::query()->pluck('id')->sort()->values()->all())->toBe([$playing, $kept->id])
        ->and(CasterClip::query()->where('kind', CasterScript::RUBRIC)->exists())->toBeFalse()
        ->and(CasterClip::query()->find($fresh->id))->toBeNull()
        // The dropped repeat never aired: the library forgets it.
        ->and($library->entries()[$unaired]['aired_count'])->toBe(0)
        ->and($feeder->tick(CarbonImmutable::now()))->toBe([]);
});

it('writes no script and answers no chat while the autopilot runs, and lets a bulletin of a past slot expire instead of airing late', function () {
    config(['tv.commentary.ai' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:10', 'Europe/Berlin'));
    $queue = app(ScriptQueue::class);
    $bulletin = $queue->enqueue(CasterScript::BULLETIN, ScenePhase::BREAK, 'Es ist vierzehn Uhr, die Nachrichten.', 'b1', 75, CarbonImmutable::now(),
        airsAt: CarbonImmutable::parse('2026-10-14 14:30', 'Europe/Berlin'), expiresAt: CarbonImmutable::parse('2026-10-14 14:32', 'Europe/Berlin'));

    $this->artisan('caster:script')->expectsOutputToContain('Autopilot: no scripts written')->assertSuccessful();
    expect(app(ChatReactions::class)->poll(CarbonImmutable::now()))->toBe(0);

    // The workstation is back at 15:00: the 14:30 bulletin is gone, never handed out late.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00', 'Europe/Berlin'));
    $claim = autopilotPost('/api/caster/claim?only=urgent', '', [AutoPilot::HEADER => 'up'])->assertOk();

    expect($claim->json('script'))->toBeNull()
        ->and($bulletin->fresh()->status)->toBe(CasterScript::EXPIRED);
});

it('stops on a stop request: no claim, no VoiceStudio call, the block in the making released, no half file left', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:10', 'Europe/Berlin'));
    // One sentence per VoiceStudio call: the block needs two calls.
    config(['tv.commentary.voicestudio.chunk.max_sentences' => 1]);
    $fetcher = new CasterFetcher(fn () => null);
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls, $fetcher) {
        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => ['id' => 40 + $calls, 'kind' => 'scene', 'sentences' => ['Erster Satz für den Block.', 'Zweiter Satz für den Block.'], 'estimated_seconds' => 200], 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => (function () use (&$calls, $fetcher) {
                // The shutdown arrives while VoiceStudio voices the first chunk.
                $calls++;
                $fetcher->stop();

                return Http::response(Pcm::toWav(str_repeat(pack('v', 2000), 24000), 24000));
            })(),
            default => Http::response(['ok' => true]),
        };
    });
    Process::fake(['*' => Process::result(output: casterOpus())]);

    $outcome = $fetcher->runOnce();

    expect($outcome)->toBeIn([CasterFetcher::RELEASED, CasterFetcher::STOPPED])
        ->and($calls)->toBe(1)
        ->and($fetcher->runOnce())->toBe(CasterFetcher::STOPPED);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/scripts/40/release'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/blocks'));
    expect(collect(Http::recorded())->filter(fn ($pair): bool => str_ends_with($pair[0]->url(), '/api/caster/claim'))->count())->toBe(1);
});

it('exits 0 within seconds on SIGTERM, the real command in its own process (the systemd stop)', function () {
    // The fetcher as systemd runs it, pointed at a closed port: it waits between runs, the signal ends the wait.
    $process = Process::path(base_path())->env([
        'TV_COMMENTARY_SERVER' => 'http://127.0.0.1:9', 'TV_VOICESTUDIO_URL' => 'http://127.0.0.1:9', 'TV_COMMENTARY_TOKEN' => AUTOPILOT_TOKEN,
        // Not `testing`: Laravel traps no signal in unit tests (ArtisanServiceProvider), systemd runs it as `local`.
        'APP_ENV' => 'local', 'TV_PODCAST_TRANSCRIBE' => 'false', 'CACHE_STORE' => 'array', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'LOG_CHANNEL' => 'null',
    ])->timeout(30)->start([PHP_BINARY, 'artisan', 'caster:fetch']);
    $deadline = microtime(true) + 15;

    while (! str_contains($process->output(), 'offline') && microtime(true) < $deadline) {
        usleep(100_000);
    }

    $process->signal(SIGTERM);
    $signalled = microtime(true);
    $result = $process->wait();

    expect($process->output())->toContain('offline')
        ->and($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('stopped')
        ->and(microtime(true) - $signalled)->toBeLessThan(10.0);
})->skip(! function_exists('pcntl_signal'), 'needs pcntl');

it('stops the loop when the fetcher was told to stop (the seam of the signal handler)', function () {
    $fetcher = new class(fn () => null) extends CasterFetcher
    {
        public int $runs = 0;

        public function runOnce(): string
        {
            $this->runs++;
            // What the SIGTERM handler does (CasterFetchCommand::handle()): the fetcher is told to stop mid-run.
            $this->stop();

            return CasterFetcher::RELEASED;
        }
    };
    app()->instance(CasterFetcher::class, $fetcher);

    $this->artisan('caster:fetch')->expectsOutputToContain('stopped')->assertExitCode(0);

    expect($fetcher->runs)->toBe(1);
});

it('waits for VoiceStudio at boot with growing pauses, reports it down to the server and claims nothing until it answers', function () {
    $fetcher = new CasterFetcher(fn () => null);
    $profiles = false;
    Http::fake(function (Request $request) use (&$profiles) {
        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => ['id' => 9, 'kind' => 'chat', 'sentences' => ['Danke für die Frage im Chat.']], 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => throw new ConnectionException('Connection refused'),
            str_ends_with($request->url(), '/profiles') => $profiles ? Http::response([['id' => 'p']]) : throw new ConnectionException('Connection refused'),
            default => Http::response(['ok' => true]),
        };
    });

    // The first run finds VoiceStudio unreachable: the script goes back.
    expect($fetcher->runOnce())->toBe(CasterFetcher::RELEASED);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/scripts/9/release'));

    // From then on: a heartbeat with VoiceStudio down, no claim.
    $claims = fn (): int => collect(Http::recorded())->filter(fn ($pair): bool => str_contains($pair[0]->url(), '/api/caster/claim'))->count();
    $before = $claims();
    expect($fetcher->runOnce())->toBe(CasterFetcher::VOICE_DOWN)
        ->and($claims())->toBe($before);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/heartbeat') && $request->header(AutoPilot::HEADER)[0] === 'down');

    // VoiceStudio is up: the next run claims again, telling the server.
    $profiles = true;
    $fetcher->runOnce();
    expect($claims())->toBe($before + 1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/claim') && $request->header(AutoPilot::HEADER)[0] === 'up');

    expect(array_map(fn (int $runs): int => CasterFetchCommand::voiceDownWait($runs, 10), [1, 2, 3, 4, 5, 9]))->toBe([10, 20, 40, 80, 120, 120]);
});

it('gives the fetcher room for a ten-minute block: the CLI default of 128M rises to voicestudio.memory_limit, a higher limit or none stays', function () {
    $before = ini_get('memory_limit');

    try {
        ini_set('memory_limit', '128M');
        CasterFetchCommand::raiseMemoryLimit();
        expect(ini_get('memory_limit'))->toBe('512M');

        ini_set('memory_limit', '2G');
        CasterFetchCommand::raiseMemoryLimit();
        expect(ini_get('memory_limit'))->toBe('2G');

        ini_set('memory_limit', '-1');
        CasterFetchCommand::raiseMemoryLimit();
        expect(ini_get('memory_limit'))->toBe('-1');
    } finally {
        ini_set('memory_limit', (string) $before);
    }
});

it('writes the local stock atomically: a failed write keeps the old file and leaves no temporary file', function () {
    $dir = autopilotDir();
    File::ensureDirectoryExists($dir);
    expect(AtomicFile::put($dir.'/a.json', 'old'))->toBeTrue()
        ->and(AtomicFile::put($dir.'/missing/b.json', 'x'))->toBeFalse()
        ->and(file_get_contents($dir.'/a.json'))->toBe('old')
        ->and(glob($dir.'/*.part') ?: [])->toBe([]);

    $stock = DayStock::fromConfig();
    $stock->store(autopilotOpus('s'), [['id' => 1, 'kind' => 'scene', 'topic' => 'stock:talk', 'sentences' => ['Ein zeitloser Satz über Fiatgeld.']]], ['ends' => [1.0], 'boundaries' => [], 'steps' => ['script:1'], 'seconds' => 1.0], CarbonImmutable::now());
    expect($stock->entries())->toHaveCount(1)
        ->and(glob(config('tv.commentary.blocks.stock.dir').'/*.part') ?: [])->toBe([]);
    File::deleteDirectory($dir);
});

it('spreads the stock writer over the formats and subjects the library holds least', function () {
    $hash = fn (string $format, string $subject): string => StockScripts::subjectKey($format, $subject);
    $questions = (array) config('tv.commentary.blocks.stock.questions');
    config(['tv.commentary.blocks.stock.formats' => ['talk', 'liga']]);

    // Two liga blocks and one talk block with every question but the last: talk comes next, about the last question.
    autopilotBlock('l1', ['format' => 'liga', 'subjects' => [$hash('liga', 'liga')]]);
    autopilotBlock('l2', ['format' => 'liga', 'subjects' => [$hash('liga', 'liga')]]);
    foreach (array_slice($questions, 0, -1) as $index => $question) {
        autopilotBlock('t'.$index, ['format' => $index === 0 ? 'talk' : 'liga', 'subjects' => [$hash('talk', $question)]]);
    }
    // The liga count is now far higher: talk is the format with the fewest blocks.
    [$format, $material] = app(StockScripts::class)->material();

    expect($format)->toBe('talk')
        ->and($material)->toContain(end($questions));
});

it('simulates 72 hours with the workstation off: 60 h of unique stock and 7 podcast episodes keep the air going', function () {
    config(['tv.commentary.blocks.autopilot.music_gap_seconds' => ['min' => 20, 'max' => 75], 'tv.commentary.blocks.autopilot.night_gap_seconds' => ['min' => 240, 'max' => 420]]);
    $sim = (new AutoPilotSimulator(72, 60, 7))->run();
    $report = $sim->report();

    fwrite(STDERR, 'autopilot 72 h: '.json_encode(['days' => $report, 'max_repeats_of_one_block' => $sim->maxRepeatsOfOneBlock, 'subject_spacing_misses' => $sim->subjectSpacingMisses,
        'podcast_airings' => $sim->podcastAirings, 'unique_hours_aired' => round($sim->uniqueSecondsAired / 3600, 1)]).PHP_EOL);

    expect($report)->toHaveCount(3)
        ->and(array_sum(array_column($report, 'empty_seconds')))->toBe(0)
        ->and($sim->maxRepeatsOfOneBlock)->toBeLessThanOrEqual(1)
        ->and($sim->podcastAirings)->toBeGreaterThanOrEqual(12)
        ->and(min(array_column($report, 'voice_share')))->toBeGreaterThan(0.6);
});

it('measures how many stock hours a day the workstation adds at the measured pace', function () {
    // The day model of P6 (DayStockSimulator: its fresh demand is an assumption, named there) with the stock never full.
    config(['tv.commentary.blocks.stock.library.enabled' => false, 'tv.commentary.blocks.stock.target_hours' => 1000, 'tv.commentary.blocks.stock.max_bytes' => PHP_INT_MAX,
        'tv.commentary.blocks.replay.enabled' => false, 'tv.commentary.blocks.stock.window' => ['from' => '01:00', 'to' => '09:00'], 'tv.commentary.blocks.stock.day_share' => true,
        'tv.commentary.spool.quiet_hours' => ['from' => '01:00', 'to' => '09:00', 'timezone' => 'Europe/Berlin', 'every_minutes' => 10, 'ahead_seconds' => 90]]);
    $rates = [];

    foreach ([1.0, 1.2, 1.5] as $rtf) {
        $sim = (new DayStockSimulator($rtf))->run();
        $rates[(string) $rtf] = ['night_h' => round($sim->stockRenderedNight / 3600, 1), 'day_h' => round($sim->stockRenderedDay / 3600, 1),
            'per_day_h' => round(($sim->stockRenderedNight + $sim->stockRenderedDay) / 3600, 1), 'days_to_60h' => round(60 * 3600 / max(1.0, $sim->stockRenderedNight + $sim->stockRenderedDay), 1)];
    }

    fwrite(STDERR, 'stock render capacity (01:00-24:00): '.json_encode($rates).PHP_EOL);

    expect($rates['1.2']['night_h'])->toBeGreaterThan(5.0)
        ->and($rates['1.2']['per_day_h'])->toBeGreaterThan($rates['1.2']['night_h']);
});
