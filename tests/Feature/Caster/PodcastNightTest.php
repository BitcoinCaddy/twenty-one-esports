<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\Imaging;
use App\Support\Caster\PodcastLibrary;
use App\Support\Caster\PodcastNight;
use App\Support\Caster\PodcastReplay;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the podcast replay's night loop
|--------------------------------------------------------------------------
|
| User 2026-10-12 23:30: "warum nicht heute Nacht ausnahmsweise mehrere
| Podcastfolgen im Loop … weil heute Nacht dazu Zeit ist". Inside the night
| window the newest episodes air back to back, newest first, each once, between
| the podcast opener and the back-announce; a bulletin still stops an episode
| at a pause and it resumes; no slide scenes meanwhile, the GPU renders stock.
|
*/

const PODCAST_NIGHT_RATE = 100;

const PODCAST_NIGHT_FEED = 'https://anchor.fm/s/d8d3c38/podcast/rss';

beforeEach(function () {
    Storage::fake('local');
    $this->imagingDir = storage_path('framework/testing/podcast-night-imaging-'.uniqid());
    config([
        'tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10, 'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00',
        'tv.commentary.podcast_replay.enabled' => true,
        'tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => true,
        'tv.commentary.podcast_replay.sources.resat.enabled' => false,
        'tv.commentary.podcast_replay.night' => [
            'enabled' => true, 'from' => '01:00', 'to' => '09:00', 'until' => null, 'episodes' => 3, 'sources' => ['einundzwanzig'],
            'skip_daytime_episode' => false, 'queue_ahead_seconds' => 900, 'overrun_minutes' => 30, 'prefetch_per_run' => 6,
        ],
        'tv.commentary.imaging.dir' => $this->imagingDir,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->imagingDir);
});

/** Fake Opus whose decode (podcastNightDecodes()) gives `$seconds` of the sample value `$fill`. */
function podcastNightOpus(float $seconds, int $fill): string
{
    return casterOpus().sprintf('LEN:%.3F;FILL:%d;', $seconds, $fill);
}

/** Decodes the fake Opus of a clip; anything without one (a jingle file) is one second of the value 9. */
function podcastNightDecodes(): void
{
    Process::fake(function (PendingProcess $process) {
        $found = preg_match('/LEN:([\d.]+);FILL:(\d+);/', (string) $process->input, $match) === 1;

        return Process::result(output: str_repeat(pack('v', $found ? (int) $match[2] : 9), (int) round(PODCAST_NIGHT_RATE * ($found ? (float) $match[1] : 1.0))));
    });
}

/**
 * Stores an episode as the library would (its own directory and meta.json; `$current` also as episode.json).
 *
 * @param  list<array{0: float, 1: int, 2: list<float>}>  $chunks  [seconds, fill, pauses]
 */
function podcastNightStored(string $guid, int $publishedAt, array $chunks, bool $current = false, string $source = 'einundzwanzig'): void
{
    $directory = 'caster/podcasts/'.$source.'/'.substr(sha1($guid), 0, 12);
    $stored = [];
    foreach ($chunks as $index => [$seconds, $fill, $pauses]) {
        $path = sprintf('%s/chunk-%03d.opus', $directory, $index);
        Storage::disk('local')->put($path, podcastNightOpus($seconds, $fill));
        $stored[] = ['path' => $path, 'seconds' => $seconds, 'bytes' => 4000, 'pauses' => [...$pauses, $seconds]];
    }
    $meta = json_encode(['guid' => $guid, 'title' => '#'.$guid, 'published_at' => $publishedAt, 'seconds' => array_sum(array_column($chunks, 0)), 'chunks' => $stored]);
    Storage::disk('local')->put($directory.'/meta.json', $meta);

    if ($current) {
        Storage::disk('local')->put('caster/podcasts/'.$source.'/episode.json', $meta);
    }
}

/** Three stored episodes: g-3 the newest (the current one), g-1 the oldest, plus an older g-0 beyond the loop's three. */
function podcastNightLibrary(float $seconds = 600.0): void
{
    podcastNightStored('g-3', 3000, [[$seconds / 2, 21, [100.0]], [$seconds / 2, 22, [100.0]]], current: true);
    podcastNightStored('g-2', 2000, [[$seconds, 31, [100.0]]]);
    podcastNightStored('g-1', 1000, [[$seconds, 41, [100.0]]]);
    podcastNightStored('g-0', 500, [[$seconds, 51, [100.0]]]);
}

/** @return list<string> the guids of the night airings in queue order */
function podcastNightQueued(): array
{
    return CasterScript::query()->where('kind', CasterScript::PODCAST)->where('topic', 'n-einundzwanzig')->orderBy('id')->pluck('text')
        ->map(fn (string $text): string => str_replace('EINUNDZWANZIG Podcast: #', '', $text))->all();
}

/** Marks every ready chunk of the night airings as played, as the director would at their end. */
function podcastNightPlayed(): void
{
    CasterClip::query()->where('kind', CasterScript::PODCAST)->get()->each(fn (CasterClip $clip) => app(ClipSpool::class)->played($clip));
}

it('queues the newest episodes one after the other inside the window, newest first, each once a night', function () {
    podcastNightLibrary();
    config(['tv.commentary.podcast_replay.night.queue_ahead_seconds' => 400]);
    $night = new PodcastNight(new PodcastLibrary);
    $start = CarbonImmutable::parse('2026-10-13 01:00', 'Europe/Berlin');

    expect($night->schedule($start->subMinute()))->toBe(['einundzwanzig' => PodcastNight::NOT_DUE])
        ->and($night->schedule($start))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3'])
        // 600 s still to play: more than queue_ahead_seconds (400), the next one waits.
        ->and($night->schedule($start->addMinutes(5)))->toBe(['einundzwanzig' => PodcastNight::WAITING]);

    // The first chunk played: 300 s left, the next episode goes into the spool behind it.
    app(ClipSpool::class)->played(CasterClip::query()->where('kind', CasterScript::PODCAST)->orderBy('id')->first());
    expect($night->schedule($start->addMinutes(10)))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-2']);

    podcastNightPlayed();
    expect($night->schedule($start->addMinutes(20)))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-1']);
    podcastNightPlayed();

    // Three is the loop's length: g-0 never airs, and nothing airs twice, even with the airings gone from the queue.
    expect($night->schedule($start->addMinutes(30)))->toBe(['einundzwanzig' => PodcastNight::DONE]);
    CasterScript::query()->delete();
    expect($night->schedule($start->addMinutes(35)))->toBe(['einundzwanzig' => PodcastNight::DONE])
        ->and(podcastNightQueued())->toBe([])
        // The window's end, and the next night starts over.
        ->and($night->schedule(CarbonImmutable::parse('2026-10-13 09:00', 'Europe/Berlin')))->toBe(['einundzwanzig' => PodcastNight::NOT_DUE])
        ->and($night->schedule($start->addDay()))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3']);
});

it('takes turns between its sources and starts the next episode only when the one before, of either source, nearly ended', function () {
    podcastNightLibrary();
    podcastNightStored('r-2', 2500, [[600.0, 61, [100.0]]], current: true, source: 'resat');
    podcastNightStored('r-1', 1500, [[600.0, 71, [100.0]]], source: 'resat');
    config([
        'tv.commentary.podcast_replay.sources.resat.enabled' => true,
        'tv.commentary.podcast_replay.night.sources' => ['einundzwanzig', 'resat'],
        'tv.commentary.podcast_replay.night.episodes' => 2,
        'tv.commentary.podcast_replay.night.queue_ahead_seconds' => 400,
    ]);
    $night = new PodcastNight(new PodcastLibrary);
    $start = CarbonImmutable::parse('2026-10-13 01:00', 'Europe/Berlin');

    expect($night->schedule($start))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3', 'resat' => PodcastNight::WAITING])
        // The EINUNDZWANZIG episode still has 600 s to play: Re:set waits for it, it does not air on top of it.
        ->and($night->schedule($start->addMinutes(1)))->toBe(['einundzwanzig' => PodcastNight::WAITING, 'resat' => PodcastNight::WAITING]);

    podcastNightPlayed();
    expect($night->schedule($start->addMinutes(11)))->toBe(['einundzwanzig' => PodcastNight::WAITING, 'resat' => PodcastNight::QUEUED.' #r-2']);
    podcastNightPlayed();
    expect($night->schedule($start->addMinutes(21)))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-2', 'resat' => PodcastNight::WAITING]);
    podcastNightPlayed();
    expect($night->schedule($start->addMinutes(31)))->toBe(['einundzwanzig' => PodcastNight::WAITING, 'resat' => PodcastNight::QUEUED.' #r-1']);
    podcastNightPlayed();
    expect($night->schedule($start->addMinutes(41)))->toBe(['einundzwanzig' => PodcastNight::DONE, 'resat' => PodcastNight::DONE]);
});

it('starts no night episode while a day slot\'s airing still has to play', function () {
    podcastNightLibrary();
    config([
        'tv.commentary.podcast_replay.sources.einundzwanzig.starts_at' => '14:03',
        'tv.commentary.podcast_replay.night.from' => '13:00', 'tv.commentary.podcast_replay.night.to' => '20:00',
        'tv.commentary.podcast_replay.night.queue_ahead_seconds' => 400,
    ]);
    $slot = CarbonImmutable::parse('2026-10-13 14:03', 'Europe/Berlin');
    expect((new PodcastReplay(new PodcastLibrary))->schedule($slot->subMinutes(10)))->toBe(['einundzwanzig' => PodcastReplay::SCHEDULED]);

    $night = new PodcastNight(new PodcastLibrary);
    expect($night->schedule($slot->subMinutes(5)))->toBe(['einundzwanzig' => PodcastNight::WAITING]);

    podcastNightPlayed();
    expect($night->schedule($slot->addMinutes(11)))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3']);
});

it('leaves a music break of `break_minutes` after every episode before the next one starts', function () {
    podcastNightLibrary();
    config(['tv.commentary.podcast_replay.night.break_minutes' => 60]);
    $night = new PodcastNight(new PodcastLibrary);
    $start = CarbonImmutable::parse('2026-10-13 01:00', 'Europe/Berlin');

    expect($night->schedule($start))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3']);
    podcastNightPlayed();
    // g-3 ran 600 s: until an hour after its end only music, then g-2.
    expect($night->schedule($start->addMinutes(11)))->toBe(['einundzwanzig' => PodcastNight::WAITING])
        ->and($night->schedule($start->addMinutes(69)))->toBe(['einundzwanzig' => PodcastNight::WAITING])
        ->and($night->schedule($start->addMinutes(71)))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-2']);
});

it('airs the night episodes from now as their own airings: the day\'s slot still airs, the chunks are the library\'s', function () {
    podcastNightLibrary();
    $now = CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin');
    (new PodcastNight(new PodcastLibrary))->schedule($now);

    $script = CasterScript::query()->where('kind', CasterScript::PODCAST)->sole();
    $chunks = CasterClip::query()->where('caster_script_id', $script->id)->orderBy('id')->get();

    expect($script->topic)->toBe('n-einundzwanzig')
        ->and($script->airs_at->getTimestamp())->toBe($now->getTimestamp())
        ->and($chunks->pluck('block')->pluck('podcast')->all())->toBe([['source' => 'einundzwanzig', 'index' => 1, 'count' => 2], ['source' => 'einundzwanzig', 'index' => 2, 'count' => 2]])
        ->and(PodcastReplay::airedToday('einundzwanzig', CarbonImmutable::parse('2026-10-13 14:03', 'Europe/Berlin')))->toBeFalse()
        ->and(app(ClipSpool::class)->bytes())->toBe(0);
});

it('skips the daytime episode only when told to, and starts none that would run far into the morning', function () {
    podcastNightLibrary();
    config(['tv.commentary.podcast_replay.night.skip_daytime_episode' => true]);
    $night = new PodcastNight(new PodcastLibrary);

    expect($night->schedule(CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin')))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-2']);

    CasterScript::query()->delete();
    CasterClip::query()->delete();
    config(['tv.commentary.podcast_replay.night.skip_daytime_episode' => false]);
    // 08:50 plus 600 s ends at the window's end; two hours from 08:00 would end 30 minutes past overrun, from 07:30 just within.
    expect($night->schedule(CarbonImmutable::parse('2026-10-14 08:50', 'Europe/Berlin')))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3']);

    Storage::disk('local')->deleteDirectory('caster/podcasts');
    CasterScript::query()->delete();
    CasterClip::query()->delete();
    podcastNightLibrary(7200.0);
    expect($night->schedule(CarbonImmutable::parse('2026-10-15 08:00', 'Europe/Berlin')))->toBe(['einundzwanzig' => PodcastNight::DONE])
        ->and($night->schedule(CarbonImmutable::parse('2026-10-15 07:30', 'Europe/Berlin')))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3']);
});

it('runs only until `until` and not at all while off, an unreadable `until` counting as passed', function () {
    podcastNightLibrary();
    $night = new PodcastNight(new PodcastLibrary);
    $at = CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin');

    config(['tv.commentary.podcast_replay.night.until' => '2026-10-13 03:00']);
    expect($night->schedule($at->subMinute()))->toBe(['einundzwanzig' => PodcastNight::QUEUED.' #g-3'])
        ->and($night->schedule($at))->toBe([])
        ->and(PodcastNight::episodeCount('einundzwanzig', $at))->toBe(0);

    config(['tv.commentary.podcast_replay.night.until' => 'kaputt']);
    expect(PodcastNight::episodeCount('einundzwanzig', $at->subHour()))->toBe(0);

    config(['tv.commentary.podcast_replay.night.until' => null, 'tv.commentary.podcast_replay.night.enabled' => false]);
    expect($night->schedule($at))->toBe([])
        ->and(PodcastNight::episodeCount('einundzwanzig', $at))->toBe(0);

    config(['tv.commentary.podcast_replay.night.enabled' => true, 'tv.commentary.podcast_replay.night.from' => '23:00', 'tv.commentary.podcast_replay.night.to' => '02:00']);
    expect(PodcastNight::window(CarbonImmutable::parse('2026-10-13 23:30', 'Europe/Berlin'))['to']->format('Y-m-d H:i'))->toBe('2026-10-14 02:00')
        ->and(PodcastNight::window(CarbonImmutable::parse('2026-10-14 01:30', 'Europe/Berlin'))['from']->format('Y-m-d H:i'))->toBe('2026-10-13 23:00')
        ->and(PodcastNight::window(CarbonImmutable::parse('2026-10-14 02:00', 'Europe/Berlin')))->toBeNull();
});

/*
| The library: the newest `episodes` stored, `episodes + 1` kept.
*/

/** An RSS feed whose items are guids, newest first, a day apart. */
function podcastNightFeed(array $guids): string
{
    $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Feed</title>';
    foreach ($guids as $guid) {
        $day = CarbonImmutable::parse('2026-10-01 20:00', 'UTC')->addDays((int) substr($guid, 2));
        $xml .= '<item><title>#'.$guid.'</title><guid>'.$guid.'</guid><pubDate>'.$day->toRfc2822String().'</pubDate><enclosure url="https://cdn.test/'.$guid.'.mp3" type="audio/mpeg" length="9"/></item>';
    }

    return $xml.'</channel></rss>';
}

/** Fakes ffmpeg for the library: loudness, the encode with one pause, and the segment pass writing chunks of `$bytes`. */
function podcastNightFfmpeg(int $bytes = 1000): void
{
    Process::fake(function (PendingProcess $process) use ($bytes) {
        $command = (array) $process->command;
        $line = implode(' ', $command);

        if (str_contains($line, 'ebur128')) {
            return Process::result(errorOutput: "Summary:\n  I:  -20.0 LUFS\n");
        }

        if (str_contains($line, 'silencedetect')) {
            file_put_contents($command[array_search('ogg', $command, true) + 1], casterOpus());

            return Process::result(errorOutput: "silence_start: 199.8\nsilence_end: 200.2 | silence_duration: 0.4\nsize=N/A time=00:06:40.00 bitrate=N/A");
        }

        if (str_contains($line, 'segment')) {
            $pattern = end($command);
            file_put_contents(sprintf($pattern, 0), casterOpus($bytes));
            file_put_contents($command[array_search('-segment_list', $command, true) + 1], basename(sprintf($pattern, 0)).",0,400\n");
        }

        return Process::result();
    });
}

it('stores the newest episodes for the night, keeps one more and evicts the oldest beyond that, within max_bytes', function () {
    Http::fake([PODCAST_NIGHT_FEED => Http::sequence()
        ->push(podcastNightFeed(['g-5', 'g-4', 'g-3', 'g-2', 'g-1']))
        ->push(podcastNightFeed(['g-6', 'g-5', 'g-4', 'g-3', 'g-2']))
        ->push(podcastNightFeed(['g-7', 'g-6', 'g-5', 'g-4', 'g-3'])), 'cdn.test/*' => fn () => Http::response('MP3')]);
    podcastNightFfmpeg();
    $library = new PodcastLibrary;
    $guids = fn (): array => array_column($library->episodes('einundzwanzig'), 'guid');
    $this->travelTo(CarbonImmutable::parse('2026-10-12 23:00', 'Europe/Berlin'));

    expect($library->refresh(CarbonImmutable::now(), force: true))->toBe(['einundzwanzig' => PodcastLibrary::STORED])
        ->and($library->episode('einundzwanzig')['guid'])->toBe('g-5')
        ->and($guids())->toBe(['g-5', 'g-4', 'g-3'])
        ->and($library->measured['einundzwanzig']['night_stored'])->toBe(2);

    $library->refresh(CarbonImmutable::now(), force: true);
    expect($guids())->toBe(['g-6', 'g-5', 'g-4', 'g-3']);

    // Four kept (episodes + 1): the oldest goes.
    $library->refresh(CarbonImmutable::now(), force: true);
    expect($guids())->toBe(['g-7', 'g-6', 'g-5', 'g-4'])
        ->and(Storage::disk('local')->directories('caster/podcasts/einundzwanzig'))->toHaveCount(4)
        // Only the missing ones were downloaded: 3 + 1 + 1.
        ->and(collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'cdn.test'))->count())->toBe(5);

    // The night is over (or off): the next trim keeps the newest only.
    config(['tv.commentary.podcast_replay.night.enabled' => false]);
    $library->bound();
    expect($guids())->toBe(['g-7']);
});

it('stores no night episode that would take the library over max_bytes', function () {
    Http::fake([PODCAST_NIGHT_FEED => Http::response(podcastNightFeed(['g-5', 'g-4', 'g-3'])), 'cdn.test/*' => fn () => Http::response('MP3')]);
    podcastNightFfmpeg(bytes: 4000);
    config(['tv.commentary.podcast_replay.max_bytes' => 11000]);
    $library = new PodcastLibrary;

    expect($library->refresh(CarbonImmutable::now(), force: true))->toBe(['einundzwanzig' => PodcastLibrary::STORED])
        ->and(array_column($library->episodes('einundzwanzig'), 'guid'))->toBe(['g-5', 'g-4'])
        ->and($library->bytes())->toBeLessThanOrEqual(11000);
});

/*
| On air.
*/

function podcastNightDirector(?Imaging $imaging = null): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(PODCAST_NIGHT_RATE, -60.0, 1.0, 2.0), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'long_max_hold_seconds' => ['scene' => 135, 'bulletin' => 430, 'rubric' => 140],
        'feed_lead_seconds' => 1.0, 'gap_seconds' => ['min' => 8, 'max' => 8], 'bulletin_late_seconds' => 120, 'rubric_late_seconds' => 240,
        'parts' => ['breath_seconds' => 0.0, 'wait_seconds' => 120],
        'blocks' => ['join_seconds' => ['min' => 0.4, 'max' => 0.4], 'reaction_wait_seconds' => 90, 'hold_seconds' => 30, 'resume_within_seconds' => 600],
        'reaction_max_hold_seconds' => 600,
        'podcast' => ['pause_window_seconds' => 20, 'resume_within_seconds' => 1800],
    ], null, null, $imaging);
}

/**
 * Runs the director like the stream does (a poll every second, the feed one second ahead), the night loop's
 * scheduler every `$scheduleEvery` seconds: the sample values on air, run-length encoded, and the music duck.
 *
 * @return array{runs: list<array{value: int, seconds: float}>, duck: list<int>}
 */
function podcastNightAir(SceneDirector $director, float $start, float $seconds, int $scheduleEvery = 60): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $night = new PodcastNight(new PodcastLibrary);
    $next = (int) round($start * PODCAST_NIGHT_RATE);
    $runs = [];
    $duck = [];

    for ($step = 0; $step <= $seconds * 4; $step++) {
        $t = $start + $step * 0.25;

        if ($step % 4 === 0) {
            test()->travelTo(CarbonImmutable::createFromTimestamp((int) $t));

            if (intdiv($step, 4) % $scheduleEvery === 0) {
                $night->schedule(CarbonImmutable::now());
            }

            $director->tick($planner, $planner->at($t, []), $t);
        }

        $target = (int) round(($t + 1.0) * PODCAST_NIGHT_RATE);
        foreach (array_values(unpack('v*', $director->timeline->samples($next, $target)) ?: []) as $value) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['value'] === $value) {
                $runs[$last]['seconds'] += 1 / PODCAST_NIGHT_RATE;
            } else {
                $runs[] = ['value' => $value, 'seconds' => 1 / PODCAST_NIGHT_RATE];
            }
        }
        array_push($duck, ...array_values(unpack('v*', $director->timeline->musicDuck($next, $target)) ?: []));
        $next = $target;
    }

    return ['runs' => array_map(fn (array $run): array => ['value' => $run['value'], 'seconds' => round($run['seconds'], 2)], $runs), 'duck' => $duck];
}

/** @return list<int> the sample values on air in order, silence left out */
function podcastNightOrder(array $air): array
{
    return array_values(array_map(fn (array $run): int => $run['value'], array_filter($air['runs'], fn (array $run): bool => $run['value'] !== 0)));
}

function podcastNightSecondsOf(array $air, int $value): float
{
    return round(array_sum(array_map(fn (array $run): float => $run['value'] === $value ? $run['seconds'] : 0.0, $air['runs'])), 1);
}

it('plays the episodes back to back, the bulletin stopping one at a pause and the episode resuming, nothing else between them', function () {
    podcastNightDecodes();
    $slot = CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin');
    $start = $slot->subSeconds(60);
    // g-3: one 300 s chunk whose pauses lie at the slot -40, -12, +7 and +40 s (it starts 1.05 s after the first poll).
    podcastNightStored('g-3', 3000, [[300.0, 21, [18.95, 46.95, 65.95, 98.95, 150.0, 200.0, 250.0]]], current: true);
    podcastNightStored('g-2', 2000, [[40.0, 31, [20.0]]]);
    $this->travelTo($start);
    $bulletin = casterScript(CasterScript::BULLETIN);
    $bulletin->update(['airs_at' => $slot->utc(), 'expires_at' => $slot->addSeconds(120)->utc()]);
    app(ClipSpool::class)->store($bulletin->fresh(), podcastNightOpus(30.0, 7), 30.0, range(5.0, 30.0, 5.0), CarbonImmutable::now());

    $air = podcastNightAir(podcastNightDirector(), (float) $start->getTimestamp(), 420);
    $podcast = array_keys(array_filter($air['duck'], fn (int $duck): bool => $duck > 0));

    expect(podcastNightOrder($air))->toBe([21, 7, 21, 31])
        ->and(collect($air['runs'])->firstWhere('value', 21)['seconds'])->toBe(65.95)
        // Not a second of either episode lost.
        ->and(podcastNightSecondsOf($air, 21))->toBe(300.0)
        ->and(podcastNightSecondsOf($air, 31))->toBe(40.0)
        ->and(podcastNightQueued())->toBe(['g-3', 'g-2'])
        // The music fully ducked under the episodes.
        ->and(max($air['duck']))->toBe((int) round((1.0 - 10 ** (-60 / 20)) * 32767))
        ->and($podcast)->not->toBe([]);
});

it('puts the podcast opener before and the back-announce after every night episode', function () {
    podcastNightDecodes();
    File::ensureDirectoryExists($this->imagingDir.'/files');
    $items = [];
    foreach (['station', 'sweeper', 'podcast-open-einundzwanzig', 'podcast-close-einundzwanzig', 'podcast-open', 'podcast-close'] as $category) {
        file_put_contents($this->imagingDir.'/files/'.$category.'.opus', 'stub');
        $items[] = ['id' => $category.'-a', 'line' => $category, 'category' => $category, 'file' => 'files/'.$category.'.opus', 'seconds' => 1.0];
    }
    file_put_contents($this->imagingDir.'/manifest.json', json_encode(['items' => $items]));
    config(['tv.commentary.imaging.enabled' => true]);
    $imaging = new Imaging((array) config('tv.commentary.imaging'), PODCAST_NIGHT_RATE, fn (): float => 0.0);
    podcastNightStored('g-3', 3000, [[40.0, 21, [20.0]], [40.0, 22, [20.0]]], current: true);
    podcastNightStored('g-2', 2000, [[40.0, 31, [20.0]]]);
    $start = CarbonImmutable::parse('2026-10-13 04:10', 'Europe/Berlin');
    $this->travelTo($start);

    $air = podcastNightAir(podcastNightDirector($imaging), (float) $start->getTimestamp(), 150, scheduleEvery: 30);
    $played = collect(json_decode((string) file_get_contents($this->imagingDir.'/rotation.json'), true)['played'] ?? [])->pluck('line')->all();

    expect(podcastNightOrder($air))->toBe([9, 21, 22, 9, 9, 31, 9])
        // The source's own line again within repeat_minutes: the general opener and back-announce stand in.
        ->and($played)->toBe(['podcast-open-einundzwanzig', 'podcast-close-einundzwanzig', 'podcast-open', 'podcast-close']);
});

it('writes no slide scene while the night loop airs, and lets the workstation render stock', function () {
    config(['tv.commentary.ai' => false, 'tv.commentary.patterns.enabled' => true, 'tv.commentary.scripts.ahead_seconds' => 30,
        'tv.commentary.fetch.token' => 'night-token-0123456789abcdef0123456789abcd']);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 02:00', 'Europe/Berlin'));
    $facts = new class(app(TournamentSlides::class)) extends SceneFacts
    {
        protected function league(): array
        {
            return ['players' => 21, 'clans' => 3];
        }
    };
    podcastNightLibrary();
    (new PodcastNight(new PodcastLibrary))->schedule(CarbonImmutable::now());

    (new ScriptQueue(app(CasterVoice::class), $facts))->fill(CarbonImmutable::now());
    $claim = $this->withToken('night-token-0123456789abcdef0123456789abcd')->postJson('/api/caster/claim');

    expect(PodcastReplay::active(CarbonImmutable::now()))->toBeTrue()
        ->and(CasterScript::query()->where('kind', CasterScript::SCENE)->count())->toBe(0)
        ->and($claim->json('podcast'))->toBe(['gpu' => 'stock']);
});
