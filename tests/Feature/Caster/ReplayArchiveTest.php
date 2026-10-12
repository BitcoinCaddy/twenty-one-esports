<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\AirLog;
use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\Pcm;
use App\Support\Caster\SceneDirector;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio, P6: the replay archive (best of the day at night)
|--------------------------------------------------------------------------
|
| User 2026-10-12: keep every rendered block of the day (bounded, with its
| metadata) and in the quiet hours play the evergreen ones again behind the
| Anchor's "Ihr hört die Wiederholung", without new rendering; never a
| bulletin, a line that names the time, a chat reply or a wish.
|
*/

const REPLAY_ARCHIVE_TOKEN = 'replay-token-0123456789abcdef0123456789abcdef';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.fetch.token' => REPLAY_ARCHIVE_TOKEN,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.blocks.enabled' => true,
        'tv.commentary.blocks.replay.enabled' => true,
        'tv.commentary.blocks.replay.dir' => replayArchiveTempDir(),
        'tv.commentary.spool.quiet_hours' => ['from' => '01:00', 'to' => '09:00', 'timezone' => 'Europe/Berlin', 'every_minutes' => 10, 'ahead_seconds' => 90],
    ]);
});

function replayArchiveTempDir(): string
{
    return sys_get_temp_dir().'/replay-archive-'.bin2hex(random_bytes(4));
}

/** A talk block's payloads: a duet and a history part, no time in them. */
function replayArchiveTalk(int $id = 1): array
{
    return [
        ['id' => $id, 'kind' => 'scene', 'topic' => 'league', 'sentences' => ['Die Liga wächst, und das freut uns sehr.'], 'speakers' => ['markus']],
        ['id' => $id + 1, 'kind' => 'history', 'sentences' => ['Im Bauernkrieg ging es um Freiheit und Brot.']],
    ];
}

function replayArchiveBlock(): array
{
    return ['ends' => [3.0, 6.0], 'boundaries' => [3.2], 'steps' => ['jingle:station-01-a', 'script:1', 'script:2'], 'seconds' => 6.0];
}

it('replays only evergreen blocks: never a bulletin, teaser, chat reply, wish, news talk or a line with the time', function (array $script, array $steps, bool $evergreen) {
    expect(ReplayArchive::evergreen([replayArchiveTalk()[0], $script], $steps))->toBe($evergreen);
})->with([
    'talk and history' => [['kind' => 'history', 'sentences' => ['Freiheit und Brot.']], ['jingle:station-01-a'], true],
    'podcast rubric' => [['kind' => 'rubric', 'topic' => 'podcast', 'airs_at' => '2026-10-12T14:45:00+02:00', 'sentences' => ['Die Folge handelte von Seed-Phrasen.']], [], true],
    'bulletin' => [['kind' => 'bulletin', 'sentences' => ['Meldung.']], [], false],
    'teaser' => [['kind' => 'teaser', 'sentences' => ['Gleich kommt die Rubrik.']], [], false],
    'chat reply' => [['kind' => 'chat', 'sentences' => ['Danke für die Frage.']], [], false],
    'live moment' => [['kind' => 'live', 'sentences' => ['Tor!']], [], false],
    'wish' => [['kind' => 'scene', 'scene' => 'chat_wish', 'sentences' => ['Dein Wunsch.']], [], false],
    'news talk' => [['kind' => 'rubric', 'topic' => 'nachrichten_talk', 'sentences' => ['Zur Lage.']], [], false],
    'press review' => [['kind' => 'rubric', 'topic' => 'presseschau', 'sentences' => ['Drei Blätter.']], [], false],
    'running games' => [['kind' => 'scene', 'topic' => 'live_games', 'sentences' => ['Da läuft ein Match.']], [], false],
    'the time named' => [['kind' => 'scene', 'sentences' => ['Es ist vierzehn Uhr, schön dass ihr da seid.']], [], false],
    'today named' => [['kind' => 'scene', 'sentences' => ['Heute Abend wird es spannend.']], [], false],
    'time-of-day jingle' => [['kind' => 'scene', 'sentences' => ['Weiter geht es.']], ['jingle:time-morning-01-a'], false],
    'coming-up jingle' => [['kind' => 'scene', 'sentences' => ['Weiter geht es.']], ['jingle:coming-up-02-b'], false],
]);

it('keeps the day bounded: older than keep_hours goes, then the oldest until under max_bytes', function () {
    $t0 = CarbonImmutable::parse('2026-10-10 12:00');

    // Plenty of bytes: only the age counts. At 49 h the first block is past keep_hours.
    $byAge = new ReplayArchive(replayArchiveTempDir(), keepHours: 48, maxBytes: 1_000_000);
    $byAge->store(str_repeat('a', 1000), replayArchiveTalk(1), replayArchiveBlock(), $t0);
    $byAge->store(str_repeat('b', 1000), replayArchiveTalk(3), replayArchiveBlock(), $t0->addHours(30));
    $byAge->store(str_repeat('c', 1000), replayArchiveTalk(5), replayArchiveBlock(), $t0->addHours(49));

    expect(array_keys($byAge->entries()))->toBe(['20261011-180000-3', '20261012-130000-5']);

    // Plenty of time: only the bytes count, the oldest goes until two of 1000 bytes stay under 2500.
    $dir = replayArchiveTempDir();
    $bySize = new ReplayArchive($dir, keepHours: 1000, maxBytes: 2500);
    foreach ([1, 3, 5] as $hour => $id) {
        $bySize->store(str_repeat('d', 1000), replayArchiveTalk($id), replayArchiveBlock(), $t0->addHours($hour));
    }

    expect(array_keys($bySize->entries()))->toBe(['20261010-130000-3', '20261010-140000-5'])
        ->and(glob($dir.'/*.opus'))->toHaveCount(2);
});

it('picks the block replayed longest ago, never one replayed within repeat_hours, never a day-bound one', function () {
    $archive = new ReplayArchive(replayArchiveTempDir());
    $t0 = CarbonImmutable::parse('2026-10-12 12:00');
    $archive->store('OggS-1', replayArchiveTalk(1), replayArchiveBlock(), $t0);
    $archive->store('OggS-2', [['id' => 9, 'kind' => 'bulletin', 'sentences' => ['Meldung.']]], replayArchiveBlock(), $t0->addMinute());
    $archive->store('OggS-3', replayArchiveTalk(3), replayArchiveBlock(), $t0->addMinutes(2));
    $night = CarbonImmutable::parse('2026-10-13 02:00');

    $first = $archive->pick($night, 12);
    $archive->markReplayed($first['name'], $night);
    $second = $archive->pick($night->addMinutes(10), 12);
    $archive->markReplayed($second['name'], $night->addMinutes(10));

    expect($first['name'])->toBe('20261012-120000-1')
        ->and($second['name'])->toBe('20261012-120200-3')
        ->and($archive->pick($night->addHour(), 12))->toBeNull()
        ->and($archive->entries()['20261012-120100-9']['evergreen'])->toBeFalse();
});

/** A replay upload to the server, at `$at`. */
function replayArchiveUpload($test, CarbonImmutable $at)
{
    $test->travelTo($at);

    return $test->call('POST', '/api/caster/replays', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.REPLAY_ARCHIVE_TOKEN, 'CONTENT_TYPE' => 'audio/ogg',
        'HTTP_X_CASTER_DURATION' => '120.0', 'HTTP_X_CASTER_SENTENCE_ENDS' => '60.0,120.0', 'HTTP_X_CASTER_BOUNDARIES' => '60.0',
    ], content: casterOpus());
}

it('takes a replay only in the quiet hours and with the switch on, and lets it expire when the quiet hours end', function () {
    replayArchiveUpload($this, CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin'))->assertStatus(409);

    config(['tv.commentary.blocks.replay.enabled' => false]);
    replayArchiveUpload($this, CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin'))->assertStatus(409);

    config(['tv.commentary.blocks.replay.enabled' => true]);
    replayArchiveUpload($this, CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin'))->assertStatus(201);
    $clip = CasterClip::query()->sole();

    expect($clip->isReplay())->toBeTrue()
        ->and($clip->kind)->toBe(CasterClip::BLOCK)
        ->and($clip->boundaries())->toBe([60.0])
        ->and($clip->expires_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-13 09:00', 'Europe/Berlin')->getTimestamp())
        ->and(CasterScript::query()->sole()->only(['kind', 'status']))->toBe(['kind' => CasterScript::REPLAY, 'status' => CasterScript::VOICED]);
});

/** Two replay blocks of 30 s, played by a director from `$at` on; the gap between them (air log). */
function replayArchiveGap(CarbonImmutable $at, bool $continuous): float
{
    Process::fake(fn ($process) => Process::result(output: str_repeat(pack('v', 1), 24000 * 30)));
    $scripts = fn () => [CasterScript::query()->create(['kind' => CasterScript::REPLAY, 'phase' => 'break', 'priority' => 0, 'text' => '(replay)', 'facts_hash' => 'r', 'status' => CasterScript::CLAIMED, 'estimated_seconds' => 30, 'claimed_at' => $at, 'expires_at' => $at->addHours(3)])];
    app(ClipSpool::class)->storeBlock($scripts(), casterOpus(), 30.0, [15.0, 30.0], [15.0], 0.0, $at, replay: true);
    app(ClipSpool::class)->storeBlock($scripts(), casterOpus(), 30.0, [15.0, 30.0], [15.0], 0.0, $at, replay: true);

    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'feed_lead_seconds' => 1.0, 'gap_seconds' => ['min' => 8, 'max' => 8],
        'blocks' => ['join_seconds' => ['min' => 0.4, 'max' => 0.4], 'reaction_wait_seconds' => 90, 'hold_seconds' => 30, 'replay' => ['continuous' => $continuous]],
    ]);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $start = (float) $at->getTimestamp();

    for ($t = $start; $t <= $start + 700; $t += 1.0) {
        $director->tick($planner, $planner->at($t, []), $t);
    }

    $gaps = AirLog::gaps(AirLog::entries(), true);

    return $gaps[0]['gap'] ?? -1.0;
}

it('keeps the quiet hours\' rhythm between two replays, and plays them back to back only when continuous', function () {
    $night = CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin');
    $this->travelTo($night);

    // One clip every ten minutes, counted from the start of the one before: 600 - 30 s of music.
    expect(replayArchiveGap($night, false))->toBeGreaterThan(500.0);

    CasterClip::query()->delete();
    Cache::forget(AirLog::KEY);

    expect(replayArchiveGap($night, true))->toBeLessThanOrEqual(1.0);
});

/** A jingle dir with a replay jingle (or none), and an archive with one evergreen block on disk. */
function replayArchiveWorkstation(bool $withJingle): ReplayArchive
{
    $jingles = replayArchiveTempDir();
    mkdir($jingles.'/anchor', 0755, true);
    file_put_contents($jingles.'/anchor/replay-01-a.opus', 'OggS-jingle');
    file_put_contents($jingles.'/manifest.json', json_encode(['items' => $withJingle ? [['id' => 'replay-01-a', 'line' => 'replay-01', 'category' => 'replay', 'file' => 'anchor/replay-01-a.opus', 'seconds' => 3.0]] : []]));
    config(['tv.commentary.blocks.jingles.dir' => $jingles]);

    $archive = ReplayArchive::fromConfig();
    $archive->store('OggS-archived', replayArchiveTalk(1), replayArchiveBlock(), CarbonImmutable::parse('2026-10-12 15:00', 'Europe/Berlin'));

    return $archive;
}

function replayArchiveServer(int $status = 201): void
{
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => null, 'full' => false, 'spool_seconds' => 0, 'pronunciation' => []]),
        str_ends_with($request->url(), '/api/caster/replays') => Http::response(['clip' => 1], $status),
        default => Http::response([], 404),
    });
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 3), 24000 * 3)));
}

it('uploads an evergreen block of the day behind the replay jingle at night when nothing new waits, once within repeat_hours', function () {
    $archive = replayArchiveWorkstation(true);
    replayArchiveServer();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin'));
    $fetcher = new CasterFetcher(fn () => null);

    expect($fetcher->runOnce())->toBe(CasterFetcher::VOICED);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/replays'
        && (float) $request->header('X-Caster-Boundaries')[0] > 3.2 && (float) $request->header('X-Caster-Duration')[0] > 3.0);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://voicestudio.test'));

    expect($archive->entries()['20261012-150000-1']['replayed_at'])->toBe(now()->getTimestamp())
        ->and($fetcher->runOnce())->toBe(CasterFetcher::IDLE);
});

it('never replays without the replay jingle, by day, or with the switch off', function () {
    replayArchiveWorkstation(false);
    replayArchiveServer();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin'));
    $fetcher = new CasterFetcher(fn () => null);

    expect($fetcher->runOnce())->toBe(CasterFetcher::IDLE);

    replayArchiveWorkstation(true);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:00', 'Europe/Berlin'));
    expect($fetcher->runOnce())->toBe(CasterFetcher::IDLE);

    config(['tv.commentary.blocks.replay.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 02:00', 'Europe/Berlin'));
    expect($fetcher->runOnce())->toBe(CasterFetcher::IDLE);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/replays'));
});

it('archives every uploaded block with its metadata, the evergreen flag decided at once', function () {
    config(['tv.commentary.blocks.seconds' => ['min' => 1, 'max' => 60], 'tv.commentary.blocks.jingles.dir' => replayArchiveTempDir()]);
    $claims = [['id' => 41, 'kind' => 'bulletin', 'airs_at' => now()->addMinutes(3)->toIso8601String(), 'sentences' => ['Es ist vierzehn Uhr, die Nachrichten.'], 'estimated_seconds' => 5]];
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => array_shift($claims), 'spool_seconds' => 0, 'pronunciation' => []]),
        str_ends_with($request->url(), '/generate') => Http::response(Pcm::toWav(str_repeat(pack('v', 2000), 24000), 24000)),
        str_ends_with($request->url(), '/blocks') => Http::response(['clip' => 1], 201),
        default => Http::response([]),
    });
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED);

    $entries = ReplayArchive::fromConfig()->entries();
    $meta = array_values($entries)[0];

    expect($entries)->toHaveCount(1)
        ->and($meta['kinds'])->toBe(['bulletin'])
        ->and($meta['scripts'])->toBe([41])
        ->and($meta['evergreen'])->toBeFalse();
});
