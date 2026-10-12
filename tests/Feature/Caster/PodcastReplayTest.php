<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\DayPlanner;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\Imaging;
use App\Support\Caster\PodcastLibrary;
use App\Support\Caster\PodcastReplay;
use App\Support\Caster\Programme\PodcastPromos;
use App\Support\Caster\Programme\PodcastScripts;
use App\Support\Caster\Programme\ShowScripts;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio, P12: the podcast replay
|--------------------------------------------------------------------------
|
| User 2026-10-12: "lass jeweils die letzten Podcast-Folgen von EINUNDZWANZIG
| und Blocktrainer Re:Sat nutzen und pro Tag abspielen. Dann hast du Zeit für
| das Vor-Rendern von Programm und eventuell sogar auch mal Renderpause. Dabei
| soll die BG-Musik natürlich voll geduckt sein." The server stores the newest
| episode per source (levelled, cut into chunks at pauses, bounded), airs it
| once a day from its slot with the music fully ducked, stops it at a pause for
| the bulletins and resumes it afterwards, lets replies wait for the break (or
| cut in at a pause after max_hold_seconds), puts only the podcast opener and
| back-announce around it, and meanwhile writes no slide scenes.
|
*/

const PODCAST_REPLAY_RATE = 100;

const PODCAST_REPLAY_FEED = 'https://anchor.fm/s/d8d3c38/podcast/rss';

beforeEach(function () {
    Storage::fake('local');
    $this->imagingDir = storage_path('framework/testing/podcast-imaging-'.uniqid());
    config([
        'tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10, 'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00',
        'tv.commentary.podcast_replay.enabled' => true,
        'tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => true,
        'tv.commentary.podcast_replay.sources.resat.enabled' => false,
        'tv.commentary.imaging.dir' => $this->imagingDir,
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->imagingDir);
});

/** Fake Opus whose decode (podcastReplayDecodes()) gives `$seconds` of the sample value `$fill`. */
function podcastReplayOpus(float $seconds, int $fill): string
{
    return casterOpus().sprintf('LEN:%.3F;FILL:%d;', $seconds, $fill);
}

/** Decodes the fake Opus of a clip; anything without one (a jingle file) is one second of the value 9. */
function podcastReplayDecodes(): void
{
    Process::fake(function (PendingProcess $process) {
        $found = preg_match('/LEN:([\d.]+);FILL:(\d+);/', (string) $process->input, $match) === 1;

        return Process::result(output: str_repeat(pack('v', $found ? (int) $match[2] : 9), (int) round(PODCAST_REPLAY_RATE * ($found ? (float) $match[1] : 1.0))));
    });
}

/**
 * An airing in the spool from `$airsAt`: one chunk per [seconds, fill, pauses] as the library would have stored it.
 *
 * @param  list<array{0: float, 1: int, 2: list<float>}>  $chunks
 */
function podcastReplayAiring(array $chunks, CarbonImmutable $airsAt, string $source = 'einundzwanzig'): CasterScript
{
    $stored = [];

    foreach ($chunks as $index => [$seconds, $fill, $pauses]) {
        $path = sprintf('caster/podcasts/%s/abc/chunk-%03d.opus', $source, $index);
        Storage::disk('local')->put($path, podcastReplayOpus($seconds, $fill));
        $stored[] = ['path' => $path, 'seconds' => $seconds, 'bytes' => 4000, 'pauses' => [...$pauses, $seconds]];
    }

    $script = CasterScript::query()->create([
        'kind' => CasterScript::PODCAST, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => 'EINUNDZWANZIG Podcast: #335', 'facts_hash' => sha1('guid'),
        'status' => CasterScript::VOICED, 'topic' => $source, 'estimated_seconds' => array_sum(array_column($chunks, 0)),
        'airs_at' => $airsAt->utc(), 'expires_at' => $airsAt->addHours(5)->utc(),
    ]);
    app(ClipSpool::class)->storePodcast($script, $source, $stored);

    return $script;
}

/** A spooled clip of `$kind` of `$seconds`, its sentences ending every five seconds. */
function podcastReplayClip(string $kind, float $seconds, int $fill, ?CarbonImmutable $airsAt = null, ?CarbonImmutable $expires = null): CasterClip
{
    $script = casterScript($kind);
    $script->update(['airs_at' => $airsAt?->utc(), 'expires_at' => ($expires ?? CarbonImmutable::now()->addHour())->utc()]);

    return app(ClipSpool::class)->store($script->fresh(), podcastReplayOpus($seconds, $fill), $seconds, range(5.0, $seconds, 5.0), CarbonImmutable::now());
}

function podcastReplayDirector(float $maxHold = 600, ?Imaging $imaging = null): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(PODCAST_REPLAY_RATE, -60.0, 1.0, 2.0), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'long_max_hold_seconds' => ['scene' => 135, 'bulletin' => 430, 'rubric' => 140],
        'feed_lead_seconds' => 1.0, 'gap_seconds' => ['min' => 8, 'max' => 8], 'bulletin_late_seconds' => 120, 'rubric_late_seconds' => 240,
        'parts' => ['breath_seconds' => 0.0, 'wait_seconds' => 120],
        'blocks' => ['join_seconds' => ['min' => 0.4, 'max' => 0.4], 'reaction_wait_seconds' => 90, 'hold_seconds' => 30, 'resume_within_seconds' => 600],
        'reaction_max_hold_seconds' => $maxHold,
        'podcast' => ['pause_window_seconds' => 20, 'resume_within_seconds' => 1800],
    ], null, null, $imaging);
}

/**
 * Runs the director like the stream does (a poll every second, the feed written one second ahead every quarter
 * second): what went on air, run-length encoded by sample value, and the music duck of every sample.
 *
 * @param  array<int, Closure(): void>  $events  seconds from the start => what happens then
 * @return array{runs: list<array{value: int, seconds: float, from: float}>, voice: list<int>, duck: list<int>}
 */
function podcastReplayAir(SceneDirector $director, float $start, float $seconds, array $events = []): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $next = (int) round($start * PODCAST_REPLAY_RATE);
    $voice = [];
    $duck = [];

    for ($step = 0; $step <= $seconds * 4; $step++) {
        $t = $start + $step * 0.25;

        if ($step % 4 === 0) {
            test()->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
            ($events[intdiv($step, 4)] ?? fn () => null)();
            $director->tick($planner, $planner->at($t, []), $t);
        }

        $target = (int) round(($t + 1.0) * PODCAST_REPLAY_RATE);
        array_push($voice, ...array_values(unpack('v*', $director->timeline->samples($next, $target)) ?: []));
        array_push($duck, ...array_values(unpack('v*', $director->timeline->musicDuck($next, $target)) ?: []));
        $next = $target;
    }

    $runs = [];
    foreach ($voice as $position => $value) {
        $last = array_key_last($runs);

        if ($last !== null && $runs[$last]['value'] === $value) {
            $runs[$last]['seconds'] += 1 / PODCAST_REPLAY_RATE;
        } else {
            $runs[] = ['value' => $value, 'seconds' => 1 / PODCAST_REPLAY_RATE, 'from' => $position / PODCAST_REPLAY_RATE];
        }
    }

    return ['runs' => array_map(fn (array $run): array => ['value' => $run['value'], 'seconds' => round($run['seconds'], 2), 'from' => round($run['from'], 2)], $runs), 'voice' => $voice, 'duck' => $duck];
}

/** @return list<int> the sample values on air in order, silence left out */
function podcastReplayOrder(array $air): array
{
    return array_values(array_map(fn (array $run): int => $run['value'], array_filter($air['runs'], fn (array $run): bool => $run['value'] !== 0)));
}

function podcastReplaySecondsOf(array $air, int $value): float
{
    return round(array_sum(array_map(fn (array $run): float => $run['value'] === $value ? $run['seconds'] : 0.0, $air['runs'])), 1);
}

/** The music duck at full depth (-60 dB): 1 - 10^(-3) of 32767. */
function podcastReplayFullDuck(): int
{
    return (int) round((1.0 - 10 ** (-60 / 20)) * 32767);
}

it('plays the episode from its slot in one piece, chunk after chunk without a breath, the music fully ducked throughout', function () {
    podcastReplayDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(10));
    podcastReplayAiring([[60.0, 21, [20.0, 40.0]], [60.0, 22, [30.0]], [40.0, 23, [20.0]]], $slot);

    $air = podcastReplayAir(podcastReplayDirector(), (float) $slot->subSeconds(10)->getTimestamp(), 190, [
        // A reply that is not overdue waits for the end of the episode, also at the chunk boundaries.
        30 => fn () => podcastReplayClip(CasterScript::CHAT, 5.0, 5),
    ]);
    $first = collect($air['runs'])->firstWhere('value', 21);
    $podcast = array_keys(array_filter($air['voice'], fn (int $value): bool => in_array($value, [21, 22, 23], true)));
    $ducked = array_filter($podcast, fn (int $position): bool => $position >= $podcast[0] + PODCAST_REPLAY_RATE);

    expect(podcastReplayOrder($air))->toBe([21, 22, 23, 5])
        // From its slot (plus the lead-in), never before.
        ->and($first['from'])->toBeGreaterThanOrEqual(10.0)->toBeLessThanOrEqual(12.5)
        // Seamless: the three chunks follow each other without a sample of silence.
        ->and(array_slice(array_column($air['runs'], 'value'), array_search(21, array_column($air['runs'], 'value'), true), 3))->toBe([21, 22, 23])
        ->and([podcastReplaySecondsOf($air, 21), podcastReplaySecondsOf($air, 22), podcastReplaySecondsOf($air, 23)])->toBe([60.0, 60.0, 40.0])
        // The music fully ducked from the end of the one-second fade down to the last sample, the joins included.
        ->and(array_values(array_unique(array_map(fn (int $position): int => $air['duck'][$position], $ducked))))->toBe([podcastReplayFullDuck()])
        ->and($air['duck'][$podcast[0] - 1])->toBe(0);
});

it('stops for the bulletin at the first pause after its slot within the window and resumes after bulletin, sport and the waiting reply', function () {
    podcastReplayDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:30', 'Europe/Berlin');
    $start = $slot->subSeconds(60);
    $this->travelTo($start);
    // The chunk starts 1.05 s after the first poll: pauses at the slot -40, -12, +7 and +40 s.
    podcastReplayAiring([[300.0, 21, [18.95, 46.95, 65.95, 98.95, 150.0, 200.0, 250.0]]], $start);
    podcastReplayClip(CasterScript::BULLETIN, 30.0, 7, $slot, $slot->addSeconds(120));
    podcastReplayClip(CasterScript::RUBRIC, 20.0, 11, $slot->addSeconds(30));

    $air = podcastReplayAir(podcastReplayDirector(), (float) $start->getTimestamp(), 420, [
        5 => fn () => podcastReplayClip(CasterScript::CHAT, 5.0, 5),
    ]);
    $runs = collect($air['runs']);

    expect(podcastReplayOrder($air))->toBe([21, 7, 11, 5, 21])
        // Cut at the pause seven seconds after the slot, not at the one twelve seconds before it.
        ->and($runs->firstWhere('value', 21)['seconds'])->toBe(65.95)
        ->and($runs->firstWhere('value', 7)['from'])->toBeGreaterThanOrEqual(67.0)->toBeLessThanOrEqual(69.5)
        // Not a second of the episode lost.
        ->and(podcastReplaySecondsOf($air, 21))->toBe(300.0);
});

it('stops at the last pause before the slot when none follows within the window, and nothing else plays until the bulletin', function () {
    podcastReplayDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:30', 'Europe/Berlin');
    $start = $slot->subSeconds(60);
    $this->travelTo($start);
    // Pauses at the slot -40, -12 and +40 s: none within 20 s after it.
    podcastReplayAiring([[200.0, 21, [18.95, 46.95, 98.95, 150.0]]], $start);
    podcastReplayClip(CasterScript::BULLETIN, 30.0, 7, $slot, $slot->addSeconds(120));

    $air = podcastReplayAir(podcastReplayDirector(), (float) $start->getTimestamp(), 300, [
        5 => fn () => podcastReplayClip(CasterScript::CHAT, 5.0, 5),
    ]);
    $runs = collect($air['runs']);

    expect(podcastReplayOrder($air))->toBe([21, 7, 5, 21])
        ->and($runs->firstWhere('value', 21)['seconds'])->toBe(46.95)
        // The twelve seconds to the slot stay free of the waiting reply: the bulletin comes first, on time.
        ->and($runs->firstWhere('value', 7)['from'])->toBeGreaterThanOrEqual(60.0)->toBeLessThanOrEqual(62.5)
        ->and(podcastReplaySecondsOf($air, 21))->toBe(200.0);
});

it('stops the podcast at the slot when no pause lies within the window, rather than let the bulletin expire', function () {
    podcastReplayDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:30', 'Europe/Berlin');
    $start = $slot->subSeconds(60);
    $this->travelTo($start);
    // A music outro: pauses 50 s before and 200 s after the slot only.
    podcastReplayAiring([[400.0, 21, [8.95, 258.95]]], $start);
    podcastReplayClip(CasterScript::BULLETIN, 30.0, 7, $slot, $slot->addSeconds(120));

    $air = podcastReplayAir(podcastReplayDirector(), (float) $start->getTimestamp(), 460);
    $runs = collect($air['runs']);

    expect(podcastReplayOrder($air))->toBe([21, 7, 21])
        ->and($runs->firstWhere('value', 7)['from'])->toBeGreaterThanOrEqual(60.0)->toBeLessThanOrEqual(63.5)
        ->and(podcastReplaySecondsOf($air, 21))->toBe(400.0);
});

it('lets an overdue reply cut in at the next pause after max_hold_seconds, and the episode resumes from there', function () {
    podcastReplayDecodes();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:40', 'Europe/Berlin'));
    $start = CarbonImmutable::now();
    podcastReplayAiring([[240.0, 21, range(25.0, 225.0, 25.0)]], $start);

    $air = podcastReplayAir(podcastReplayDirector(maxHold: 30), (float) $start->getTimestamp(), 300, [
        10 => fn () => podcastReplayClip(CasterScript::CHAT, 5.0, 5),
    ]);
    $runs = collect($air['runs']);
    $cut = $runs->firstWhere('value', 21)['seconds'];

    // Overdue at 40 s: the next pause is 50 s into the chunk (51.05 on the clock), never mid-sentence.
    expect(podcastReplayOrder($air))->toBe([21, 5, 21])
        ->and($cut)->toBe(50.0)
        ->and(podcastReplaySecondsOf($air, 21))->toBe(240.0);
});

it('opens with the podcast opener of its source and closes with its back-announce, with nothing in between', function () {
    podcastReplayDecodes();
    File::ensureDirectoryExists($this->imagingDir.'/files');
    $items = [];
    foreach (['station', 'sweeper', 'podcast-open-einundzwanzig', 'podcast-close-einundzwanzig', 'podcast-open', 'podcast-close'] as $category) {
        file_put_contents($this->imagingDir.'/files/'.$category.'.opus', 'stub');
        $items[] = ['id' => $category.'-a', 'line' => $category, 'category' => $category, 'file' => 'files/'.$category.'.opus', 'seconds' => 1.0];
    }
    file_put_contents($this->imagingDir.'/manifest.json', json_encode(['items' => $items]));
    config(['tv.commentary.imaging.enabled' => true]);
    $imaging = new Imaging((array) config('tv.commentary.imaging'), PODCAST_REPLAY_RATE, fn (): float => 0.0);
    $start = CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin');
    $this->travelTo($start);
    podcastReplayAiring([[40.0, 21, [20.0]], [40.0, 22, [20.0]], [40.0, 23, [20.0]]], $start);

    $director = podcastReplayDirector(imaging: $imaging);
    $air = podcastReplayAir($director, (float) $start->getTimestamp(), 140);
    $played = collect(json_decode((string) file_get_contents($this->imagingDir.'/rotation.json'), true)['played'] ?? [])->pluck('line')->all();

    expect(podcastReplayOrder($air))->toBe([9, 21, 22, 23, 9])
        ->and($played)->toBe(['podcast-open-einundzwanzig', 'podcast-close-einundzwanzig'])
        ->and(podcastReplaySecondsOf($air, 9))->toBe(2.0);
});

it('falls back to the general podcast opener when its source has none', function () {
    File::ensureDirectoryExists($this->imagingDir.'/files');
    file_put_contents($this->imagingDir.'/files/open.opus', 'stub');
    file_put_contents($this->imagingDir.'/manifest.json', json_encode(['items' => [['id' => 'podcast-open-01-a', 'line' => 'podcast-open-01', 'category' => 'podcast-open', 'file' => 'files/open.opus', 'seconds' => 3.0]]]));
    config(['tv.commentary.imaging.enabled' => true]);
    $script = podcastReplayAiring([[40.0, 21, []], [40.0, 22, []]], CarbonImmutable::now(), 'resat');
    $chunks = CasterClip::query()->where('caster_script_id', $script->id)->orderBy('id')->get();
    $imaging = new Imaging((array) config('tv.commentary.imaging'), 24000, fn (): float => 0.0);

    expect($imaging->choose($chunks[0], false, CarbonImmutable::now())['before']['category'] ?? null)->toBe('podcast-open')
        ->and($imaging->choose($chunks[1], false, CarbonImmutable::now()))->toBe(['before' => null, 'after' => null]);
});

/*
| The library: feed, download, transcode, bounds.
*/

/** An RSS feed whose items are [guid, title, enclosure URL], newest first. */
function podcastReplayFeed(array $items): string
{
    $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><title>Feed</title>';
    foreach ($items as [$guid, $title, $url]) {
        $xml .= '<item><title>'.htmlspecialchars($title).'</title><guid>'.$guid.'</guid><pubDate>Fri, 02 Oct 2026 21:19:42 GMT</pubDate><enclosure url="'.$url.'" type="audio/mpeg" length="9"/></item>';
    }

    return $xml.'</channel></rss>';
}

/**
 * Fakes ffmpeg for the library: the loudness pass reports `$lufs`, the encode pass writes the Opus and logs pauses
 * at `$pauses` (0.4 s each) and an episode of `$seconds`, the segment pass writes the chunks and their list.
 *
 * @param  list<float>  $pauses
 * @param  list<list<string>>  $commands  every ffmpeg command line, for the assertions
 */
function podcastReplayFfmpeg(float $seconds, array $pauses, float $lufs = -20.0, int $chunkBytes = 1000, array &$commands = []): void
{
    Process::fake(function (PendingProcess $process) use ($seconds, $pauses, $lufs, $chunkBytes, &$commands) {
        $command = (array) $process->command;
        $commands[] = $command;
        $line = implode(' ', $command);

        if (str_contains($line, 'ebur128')) {
            return Process::result(errorOutput: "[Parsed_ebur128_0] Summary:\n\n  Integrated loudness:\n    I:         {$lufs} LUFS\n");
        }

        if (str_contains($line, 'silencedetect')) {
            file_put_contents($command[array_search('ogg', $command, true) + 1], casterOpus());
            $log = '';
            foreach ($pauses as $pause) {
                $log .= sprintf("[silencedetect] silence_start: %.3F\n[silencedetect] silence_end: %.3F | silence_duration: 0.4\n", $pause - 0.2, $pause + 0.2);
            }

            return Process::result(errorOutput: $log.sprintf('size=N/A time=%s bitrate=N/A', gmdate('H:i:s', (int) $seconds).'.00'));
        }

        if (str_contains($line, 'segment')) {
            $times = ($index = array_search('-segment_times', $command, true)) === false ? [] : array_map('floatval', explode(',', $command[$index + 1]));
            $bounds = [0.0, ...$times, $seconds];
            $pattern = end($command);
            $csv = '';
            for ($n = 0; $n < count($bounds) - 1; $n++) {
                $file = sprintf($pattern, $n);
                file_put_contents($file, casterOpus($chunkBytes));
                $csv .= basename($file).','.$bounds[$n].','.$bounds[$n + 1]."\n";
            }
            file_put_contents($command[array_search('-segment_list', $command, true) + 1], $csv);
        }

        return Process::result();
    });
}

it('stores the newest episode: downloaded, measured, levelled under nice, cut into chunks at pauses, the download gone', function () {
    Http::fake([
        PODCAST_REPLAY_FEED => Http::response(podcastReplayFeed([['g-335', '#335 - 51% Attacke', 'https://cdn.test/335.mp3'], ['g-334', '#334', 'https://cdn.test/334.mp3']])),
        'cdn.test/335.mp3' => fn () => Http::response('MP3'),
    ]);
    $commands = [];
    podcastReplayFfmpeg(720.0, [100.0, 290.0, 310.0, 500.0, 610.0], -20.0, commands: $commands);
    $temp = count(glob(sys_get_temp_dir().'/twentyone-podcast-replay-*') ?: []);

    $library = new PodcastLibrary;
    $outcome = $library->refresh(CarbonImmutable::now());
    $episode = $library->episode('einundzwanzig');

    expect($outcome)->toBe(['einundzwanzig' => PodcastLibrary::STORED])
        ->and($episode['guid'])->toBe('g-335')
        ->and($episode['seconds'])->toEqual(720.0)
        // 720 s cut at the pauses nearest 300 s after the cut before (the earlier one on a tie): 0-290, 290-610, 610-720.
        ->and(array_column($episode['chunks'], 'seconds'))->toEqual([290.0, 320.0, 110.0])
        ->and(array_column($episode['chunks'], 'pauses'))->toEqual([[100.0, 290.0], [20.0, 210.0, 320.0], [110.0]])
        // Only the newest item was downloaded, and every ffmpeg ran under nice, the gain +4 dB to -16 LUFS.
        ->and(collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url())->all())->toBe([PODCAST_REPLAY_FEED, 'https://cdn.test/335.mp3'])
        ->and(collect($commands)->every(fn (array $command): bool => $command[0] === 'nice'))->toBeTrue()
        ->and(implode(' ', $commands[1]))->toContain('volume=4dB')->toContain('silencedetect=noise=-32dB:d=0.3')
        ->and(count(glob(sys_get_temp_dir().'/twentyone-podcast-replay-*') ?: []))->toBe($temp);
});

it('never reads the feed of a disabled source, and nothing at all while the replay is off', function () {
    Http::fake();
    podcastReplayFfmpeg(600.0, []);

    expect((new PodcastLibrary)->refresh(CarbonImmutable::now()))->toBe(['einundzwanzig' => PodcastLibrary::NO_EPISODE]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'podhome.fm'));

    config(['tv.commentary.podcast_replay.enabled' => false]);
    expect((new PodcastLibrary)->refresh(CarbonImmutable::now(), force: true))->toBe([])
        ->and(PodcastLibrary::sources())->toBe([]);
    Http::assertSentCount(1);
});

it('takes only the Re:set episodes of the Blocktrainer feed once the user switches the source on', function () {
    config(['tv.commentary.podcast_replay.sources.resat.enabled' => true, 'tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => false]);
    Http::fake([
        'serve.podhome.fm/*' => Http::response(podcastReplayFeed([['w-149', 'Wochenrückblick #149 - Korrektur', 'https://cdn.test/w149.mp3'], ['r-19', 'Re:set #19 - Blocktrainer Event Special', 'https://cdn.test/r19.mp3']])),
        'cdn.test/r19.mp3' => fn () => Http::response('MP3'),
    ]);
    podcastReplayFfmpeg(400.0, [200.0]);

    expect((new PodcastLibrary)->refresh(CarbonImmutable::now()))->toBe(['resat' => PodcastLibrary::STORED])
        ->and((new PodcastLibrary)->episode('resat')['guid'])->toBe('r-19');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'w149'));
});

it('keeps the stored episode while the feed names it again, and reads a feed at most every check_minutes', function () {
    Http::fake([PODCAST_REPLAY_FEED => Http::response(podcastReplayFeed([['g-335', '#335', 'https://cdn.test/335.mp3']])), 'cdn.test/*' => fn () => Http::response('MP3')]);
    podcastReplayFfmpeg(400.0, [200.0]);
    $library = new PodcastLibrary;

    expect($library->refresh(CarbonImmutable::now()))->toBe(['einundzwanzig' => PodcastLibrary::STORED])
        ->and($library->refresh(CarbonImmutable::now()->addMinutes(30)))->toBe(['einundzwanzig' => PodcastLibrary::NOT_DUE])
        ->and($library->refresh(CarbonImmutable::now()->addMinutes(61)))->toBe(['einundzwanzig' => PodcastLibrary::SAME]);
    Http::assertSentCount(3);
});

it('replaces the old episode with a newer one, but keeps the old files while an airing still plays from them', function () {
    Http::fake([PODCAST_REPLAY_FEED => Http::sequence()
        ->push(podcastReplayFeed([['g-334', '#334', 'https://cdn.test/334.mp3']]))
        ->push(podcastReplayFeed([['g-335', '#335', 'https://cdn.test/335.mp3']]))
        ->push(podcastReplayFeed([['g-336', '#336', 'https://cdn.test/336.mp3']])), 'cdn.test/*' => fn () => Http::response('MP3')]);
    podcastReplayFfmpeg(400.0, [200.0]);
    $library = new PodcastLibrary;
    $disk = Storage::disk('local');

    $library->refresh(CarbonImmutable::now(), force: true);
    $old = dirname($library->episode('einundzwanzig')['chunks'][0]['path']);
    // An airing of #334 is in the spool.
    $script = casterScript(CasterScript::PODCAST);
    app(ClipSpool::class)->storePodcast($script, 'einundzwanzig', $library->episode('einundzwanzig')['chunks']);

    $library->refresh(CarbonImmutable::now(), force: true);
    $middle = dirname($library->episode('einundzwanzig')['chunks'][0]['path']);
    expect($library->episode('einundzwanzig')['guid'])->toBe('g-335')
        ->and($disk->directories('caster/podcasts/einundzwanzig'))->toEqualCanonicalizing([$old, $middle]);

    // The airing is over: the next refresh keeps the newest only.
    CasterClip::query()->delete();
    $library->refresh(CarbonImmutable::now(), force: true);
    expect($disk->directories('caster/podcasts/einundzwanzig'))->toBe([dirname($library->episode('einundzwanzig')['chunks'][0]['path'])])
        ->and($library->episode('einundzwanzig')['guid'])->toBe('g-336');
});

it('drops a download over max_download_bytes and an episode that would take the library over max_bytes', function () {
    Http::fake([PODCAST_REPLAY_FEED => Http::response(podcastReplayFeed([['g-335', '#335', 'https://cdn.test/335.mp3']])), 'cdn.test/*' => fn () => Http::response(str_repeat('M', 2000))]);
    podcastReplayFfmpeg(400.0, [200.0], chunkBytes: 5000);
    $library = new PodcastLibrary;

    config(['tv.commentary.podcast_replay.max_download_bytes' => 1000]);
    expect($library->refresh(CarbonImmutable::now(), force: true))->toBe(['einundzwanzig' => PodcastLibrary::TOO_BIG])
        ->and(Storage::disk('local')->allFiles('caster/podcasts'))->toBe([]);

    config(['tv.commentary.podcast_replay.max_download_bytes' => 10_000, 'tv.commentary.podcast_replay.max_bytes' => 4_000]);
    expect($library->refresh(CarbonImmutable::now(), force: true))->toBe(['einundzwanzig' => PodcastLibrary::TOO_BIG])
        ->and(Storage::disk('local')->allFiles('caster/podcasts'))->toBe([]);

    config(['tv.commentary.podcast_replay.max_bytes' => 20_000]);
    expect($library->refresh(CarbonImmutable::now(), force: true))->toBe(['einundzwanzig' => PodcastLibrary::STORED])
        ->and($library->bytes())->toBeLessThanOrEqual(20_000);
});

it('leaves the stored episode as it was when ffmpeg fails', function () {
    Http::fake([PODCAST_REPLAY_FEED => Http::response(podcastReplayFeed([['g-335', '#335', 'https://cdn.test/335.mp3']])), 'cdn.test/*' => fn () => Http::response('MP3')]);
    Process::fake(fn () => Process::result(exitCode: 1));

    expect((new PodcastLibrary)->refresh(CarbonImmutable::now()))->toBe(['einundzwanzig' => PodcastLibrary::TRANSCODE_FAILED])
        ->and(Storage::disk('local')->allFiles('caster/podcasts'))->toBe([]);
});

it('plans chunks at the pause nearest each target, a short rest joining the chunk before it', function () {
    $pauses = fn (array $at): array => array_map(fn (float $time): array => ['start' => $time - 0.2, 'end' => $time + 0.2], $at);

    expect(PodcastLibrary::plan(1000.0, $pauses([250.0, 280.0, 330.0, 590.0, 640.0, 905.0]), 300.0))
        ->toBe([['from' => 0.0, 'to' => 280.0], ['from' => 280.0, 'to' => 590.0], ['from' => 590.0, 'to' => 1000.0]])
        // No pause in reach: straight at the target.
        ->and(PodcastLibrary::plan(700.0, [], 300.0))->toBe([['from' => 0.0, 'to' => 300.0], ['from' => 300.0, 'to' => 700.0]])
        ->and(PodcastLibrary::loudness("I: -30.0 LUFS\nSummary:\n  I:  -17.9 LUFS\n"))->toBe(-17.9)
        ->and(PodcastLibrary::loudness('no summary'))->toBeNull()
        ->and(PodcastLibrary::duration("Duration: 01:05:24.10\nsize=1 time=01:05:24.92 bitrate"))->toBe(3924.92);
});

/*
| Airings: the slot, once a day, the spool.
*/

/** Stores an episode of `$chunks` [seconds, pauses] for a source as the library would. */
function podcastReplayStored(string $source, string $guid, array $chunks): void
{
    $stored = [];
    foreach ($chunks as $index => [$seconds, $pauses]) {
        $path = sprintf('caster/podcasts/%s/%s/chunk-%03d.opus', $source, substr(sha1($guid), 0, 12), $index);
        Storage::disk('local')->put($path, casterOpus());
        $stored[] = ['path' => $path, 'seconds' => $seconds, 'bytes' => 4000, 'pauses' => [...$pauses, $seconds]];
    }
    Storage::disk('local')->put("caster/podcasts/{$source}/episode.json", json_encode(['guid' => $guid, 'title' => '#335 - 51% Attacke', 'published_at' => 1, 'seconds' => array_sum(array_column($chunks, 0)), 'chunks' => $stored]));
}

it('puts the episode into the spool once a day from ahead_minutes before its slot, the files staying the library\'s', function () {
    podcastReplayStored('einundzwanzig', 'g-335', [[300.0, [100.0, 200.0]], [250.0, [120.0]]]);
    $replay = new PodcastReplay(new PodcastLibrary);
    $slot = CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin');

    expect($replay->schedule($slot->subMinutes(16)))->toBe(['einundzwanzig' => PodcastReplay::NOT_DUE])
        ->and($replay->schedule($slot->subMinutes(15)))->toBe(['einundzwanzig' => PodcastReplay::SCHEDULED])
        ->and($replay->schedule($slot->subMinutes(5)))->toBe(['einundzwanzig' => PodcastReplay::DONE_TODAY]);

    $script = CasterScript::query()->where('kind', CasterScript::PODCAST)->sole();
    $chunks = CasterClip::query()->where('caster_script_id', $script->id)->orderBy('id')->get();
    expect($script->airs_at->getTimestamp())->toBe($slot->getTimestamp())
        ->and($script->status)->toBe(CasterScript::VOICED)
        ->and($chunks->pluck('sentence_ends')->all())->toEqual([[100.0, 200.0, 300.0], [120.0, 250.0]])
        ->and($chunks->pluck('kind')->unique()->all())->toBe([CasterScript::PODCAST])
        // Never counted against the spool's bytes, never deleted with their clip.
        ->and(app(ClipSpool::class)->bytes())->toBe(0)
        ->and(app(ClipSpool::class)->readySeconds())->toBe(0.0);

    app(ClipSpool::class)->played($chunks[0]);
    expect(Storage::disk('local')->exists($chunks[0]->path))->toBeTrue()
        ->and(CasterClip::query()->count())->toBe(1);

    // The same day never again, even with the airing gone from the queue; the next day again; too late not at all.
    CasterClip::query()->delete();
    $script->delete();
    expect($replay->schedule($slot->addMinutes(10)))->toBe(['einundzwanzig' => PodcastReplay::DONE_TODAY])
        ->and($replay->schedule($slot->addDay()->addMinutes(61)))->toBe(['einundzwanzig' => PodcastReplay::NOT_DUE])
        ->and($replay->schedule($slot->addDay()->subMinutes(1)))->toBe(['einundzwanzig' => PodcastReplay::SCHEDULED]);
});

it('schedules no source that is switched off, and none without a stored episode', function () {
    podcastReplayStored('resat', 'r-19', [[300.0, []]]);
    $replay = new PodcastReplay(new PodcastLibrary);
    $at = CarbonImmutable::parse('2026-10-12 16:00', 'Europe/Berlin');

    expect($replay->schedule($at))->toBe(['einundzwanzig' => PodcastReplay::NOT_DUE])
        ->and($replay->schedule(CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin')))->toBe(['einundzwanzig' => PodcastReplay::NO_EPISODE])
        ->and(CasterScript::query()->count())->toBe(0);
});

/*
| Meanwhile: no slide scenes, the workstation renders stock or idles.
*/

it('writes no slide scene, teaser or talk while an airing is on or about to start, only the bulletins', function () {
    config(['tv.commentary.ai' => false, 'tv.commentary.patterns.enabled' => true, 'tv.commentary.scripts.ahead_seconds' => 30, 'tv.commentary.podcast_replay.ahead_minutes' => 15]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:05', 'Europe/Berlin'));
    // The league's size is the one slide with data.
    $facts = new class(app(TournamentSlides::class)) extends SceneFacts
    {
        protected function league(): array
        {
            return ['players' => 21, 'clans' => 3];
        }
    };
    $queue = new ScriptQueue(app(CasterVoice::class), $facts);

    $queue->fill(CarbonImmutable::now());
    $before = CasterScript::query()->where('kind', CasterScript::SCENE)->count();
    CasterScript::query()->delete();

    podcastReplayAiring([[300.0, 21, []]], CarbonImmutable::now()->addMinutes(10));
    expect(PodcastReplay::active(CarbonImmutable::now()))->toBeTrue();
    $queue->fill(CarbonImmutable::now());

    expect($before)->toBeGreaterThan(0)
        // The fixed fillers are seeded as ever (they play only on an empty spool); nothing else is written.
        ->and(CasterScript::query()->whereNotIn('kind', [CasterScript::PODCAST, CasterScript::BULLETIN, CasterScript::FILLER])->count())->toBe(0)
        ->and(PodcastReplay::active(CarbonImmutable::now()->subMinutes(10)))->toBeFalse();
});

it('hands the workstation only replies and bulletins while an airing is on, and tells its GPU to render stock or idle', function () {
    config(['tv.commentary.fetch.token' => 'podcast-token-0123456789abcdef0123456789']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:10', 'Europe/Berlin'));
    $queue = app(ScriptQueue::class);
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, "Gerade läuft eine ruhige Runde im Turnier.\nDie Liga wächst weiter.", 'h1', 10, CarbonImmutable::now());
    $claim = fn () => $this->withToken('podcast-token-0123456789abcdef0123456789')->postJson('/api/caster/claim');

    expect($claim()->json('script.id'))->toBe($scene->id)
        ->and($claim()->json('podcast'))->toBeNull();

    $queue->release($scene->fresh());
    podcastReplayAiring([[300.0, 21, []]], CarbonImmutable::now()->subMinutes(5));
    $chat = $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, 'Danke für die Frage im Chat, Alice.', 'h2', 50, CarbonImmutable::now());

    $first = $claim();
    expect($first->json('script.id'))->toBe($chat->id)
        ->and($first->json('podcast'))->toBe(['gpu' => 'stock'])
        ->and($claim()->json('script'))->toBeNull();

    config(['tv.commentary.podcast_replay.gpu' => 'idle']);
    expect($claim()->json('podcast'))->toBe(['gpu' => 'idle'])
        ->and(DayPlanner::whenIdle(0.0, true, false, false, false, 'idle'))->toBe([DayPlanner::WAIT]);

    config(['tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.stock.enabled' => true]);
    expect(DayPlanner::whenIdle(0.0, true, false, false, false, 'stock'))->toBe([DayPlanner::RENDER_STOCK, DayPlanner::WAIT])
        ->and(DayPlanner::whenIdle(0.0, true, true, false, false, 'stock'))->toBe([DayPlanner::WAIT]);
});

/*
| Promos, announcement and back-announce (user addendum 2026-10-12: "Die Podcasts, die wir abspielen, müssen auch
| regelmäßig angekündigt werden von KI-Markus und Sirius und bevor sie gespielt werden auch explizit.").
*/

/** What the model answers for a promo, an announcement or a back-announce, by its prompt. */
function podcastReplayAnswer(string $prompt): array
{
    $lines = match (true) {
        str_contains($prompt, 'Programmhinweis') => [
            'Markus: In der neuen Folge geht es um die einundfünfzig Prozent Attacke auf Deutschland, und das klingt ziemlich dramatisch.',
            'Sirius: Wer wissen will, ob Deutschland jetzt endgültig verloren ist, sollte sich diese Folge auf keinen Fall entgehen lassen.',
            'Markus: Ich verspreche euch jedenfalls mehr Substanz als in jeder Pressekonferenz der Bundesregierung in diesem ganzen Jahr.',
            'Sirius: Also bleibt einfach dran, holt euch etwas zu trinken und lasst euch von den beiden ordentlich durchrütteln.',
        ],
        str_contains($prompt, 'direkt nach dieser Ansage') => [
            'Markus: Jetzt kommt die neueste Folge vom EINUNDZWANZIG Podcast, Nummer dreihundertfünfunddreißig mit dem Titel einundfünfzig Prozent Attacke auf Deutschland.',
            'Sirius: Laut Shownotes reden die beiden über die Frage, wer in Deutschland eigentlich gerade die Mehrheit im Netzwerk hätte.',
            'Markus: Es geht um Politik, um Bitcoin und um die Frage, warum Behörden so gerne Dinge regulieren, die sie nicht verstehen.',
            'Sirius: Ich sage nur, wer eine einundfünfzig Prozent Attacke befürchtet, hat wahrscheinlich auch Angst vor dem eigenen Toaster.',
            'Markus: Lehnt euch zurück, macht es euch bequem und hört genau zu, denn diese Folge lohnt sich von Anfang bis Ende.',
            'Sirius: Und wer danach noch Fragen hat, schreibt sie einfach in den Chat, wir lesen wirklich alles mit großer Freude.',
        ],
        default => [
            'Markus: Das war die neueste Folge vom EINUNDZWANZIG Podcast über die einundfünfzig Prozent Attacke auf Deutschland, in voller Länge.',
            'Markus: Ich hoffe, euer Toaster hat die ganze Sendung ohne einen einzigen Angriff auf eure Ersparnisse überstanden.',
        ],
    };

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

function podcastReplayVoice(): void
{
    config(['tv.commentary.ai' => true]);
    VoiceAgent::fake(fn (string $prompt): array => podcastReplayAnswer($prompt));
}

it('promotes today\'s airing from nine on every two hours with the slot\'s true time, and stops before the slot', function () {
    podcastReplayVoice();
    podcastReplayStored('einundzwanzig', 'g-335', [[300.0, [100.0]]]);
    $slot = CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin');
    $queue = app(ScriptQueue::class);

    for ($at = $slot->setTime(8, 0); $at->lessThan($slot); $at = $at->addMinutes(10)) {
        $this->travelTo($at);
        $queue->fillPodcast($at);
    }

    $promos = CasterScript::query()->where('kind', CasterScript::TEASER)->where('scene', PodcastPromos::SCENE)->orderBy('id')->get();

    expect($promos->map(fn (CasterScript $script): string => $script->airs_at->setTimezone('Europe/Berlin')->format('H:i'))->all())->toBe(['09:00', '11:00', '13:00'])
        ->and($promos->every(fn (CasterScript $script): bool => str_starts_with($script->text, 'Heute um vierzehn Uhr drei läuft bei uns die neueste Folge vom EINUNDZWANZIG Podcast')))->toBeTrue()
        ->and($promos->every(fn (CasterScript $script): bool => in_array('sirius', $script->speakers, true) && $script->expires_at->lessThan($slot)))->toBeTrue();
});

it('promotes once more in the hour before the slot when the last promo is older than ninety minutes', function () {
    podcastReplayVoice();
    config(['tv.commentary.podcast_replay.promos.every_minutes' => 180]);
    podcastReplayStored('einundzwanzig', 'g-335', [[300.0, [100.0]]]);
    $slot = CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin');

    for ($at = $slot->setTime(8, 0); $at->lessThan($slot); $at = $at->addMinutes(10)) {
        $this->travelTo($at);
        app(ScriptQueue::class)->fillPodcast($at);
    }

    expect(CasterScript::query()->where('scene', PodcastPromos::SCENE)->orderBy('id')->get()
        ->map(fn (CasterScript $script): string => $script->airs_at->setTimezone('Europe/Berlin')->format('H:i'))->all())->toBe(['09:00', '12:00', '13:10']);
});

it('promotes nothing without a real airing ahead: source off, no episode, aired today, and withdraws a promo that is no longer true', function () {
    podcastReplayVoice();
    $at = CarbonImmutable::parse('2026-10-12 11:00', 'Europe/Berlin');
    $this->travelTo($at);
    $queue = app(ScriptQueue::class);

    // No episode stored.
    expect($queue->fillPodcast($at))->toBe(0);

    podcastReplayStored('einundzwanzig', 'g-335', [[300.0, [100.0]]]);
    expect($queue->fillPodcast($at))->toBe(1);
    $promo = CasterScript::query()->where('scene', PodcastPromos::SCENE)->sole();
    $clip = app(ClipSpool::class)->store($promo->fresh(), casterOpus(), 20.0, [20.0], $at);

    // The source switched off: the voiced promo leaves the spool, and no new one comes.
    config(['tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => false]);
    expect($queue->fillPodcast($at->addHours(2)))->toBe(0)
        ->and($promo->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and(CasterClip::query()->find($clip->id))->toBeNull();

    // Aired today already (the airing is in the spool): no promo either; its frame instead.
    config(['tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => true]);
    podcastReplayAiring([[300.0, 21, []]], CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin'));
    $queue->fillPodcast($at->addHours(2));
    expect(CasterScript::query()->where('scene', PodcastPromos::SCENE)->where('status', '!=', CasterScript::EXPIRED)->count())->toBe(0);
});

it('writes the announcement duet and Markus\' back-announce once the airing is in the spool, once each', function () {
    podcastReplayVoice();
    podcastReplayStored('einundzwanzig', 'g-335', [[300.0, [100.0]]]);
    $slot = CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin');
    $this->travelTo($slot->subMinutes(14));
    (new PodcastReplay(new PodcastLibrary))->schedule(CarbonImmutable::now());

    expect(app(ScriptQueue::class)->fillPodcast(CarbonImmutable::now()))->toBe(2)
        ->and(app(ScriptQueue::class)->fillPodcast(CarbonImmutable::now()))->toBe(0);

    $intro = CasterScript::query()->where('kind', CasterScript::PODCAST_INTRO)->sole();
    $outro = CasterScript::query()->where('kind', CasterScript::PODCAST_OUTRO)->sole();
    expect($intro->airs_at->getTimestamp())->toBe($slot->getTimestamp())
        ->and(array_values(array_unique($intro->speakers)))->toBe(['markus', 'sirius'])
        ->and(array_values(array_unique($outro->speakers)))->toBe(['markus'])
        ->and($intro->text)->toContain('dreihundertfünfunddreißig');
});

it('plays announcement, podcast opener, the episode, back-announce jingle and Markus\' back-announce in this order, nothing in between', function () {
    podcastReplayDecodes();
    File::ensureDirectoryExists($this->imagingDir.'/files');
    $items = [];
    foreach (['station', 'sweeper', 'podcast-open-einundzwanzig', 'podcast-close-einundzwanzig'] as $category) {
        file_put_contents($this->imagingDir.'/files/'.$category.'.opus', 'stub');
        $items[] = ['id' => $category.'-a', 'line' => $category, 'category' => $category, 'file' => 'files/'.$category.'.opus', 'seconds' => 1.0];
    }
    file_put_contents($this->imagingDir.'/manifest.json', json_encode(['items' => $items]));
    config(['tv.commentary.imaging.enabled' => true]);
    $imaging = new Imaging((array) config('tv.commentary.imaging'), PODCAST_REPLAY_RATE, fn (): float => 0.0);
    $slot = CarbonImmutable::parse('2026-10-12 14:03', 'Europe/Berlin');
    $this->travelTo($slot);
    podcastReplayAiring([[40.0, 21, [20.0]], [40.0, 22, [20.0]]], $slot);
    foreach ([CasterScript::PODCAST_INTRO => 31, CasterScript::PODCAST_OUTRO => 32] as $kind => $fill) {
        $script = casterScript($kind);
        $script->update(['topic' => 'einundzwanzig', 'airs_at' => $slot->utc(), 'expires_at' => $slot->addHours(3)->utc()]);
        app(ClipSpool::class)->store($script->fresh(), podcastReplayOpus(30.0, $fill), 30.0, [15.0, 30.0], CarbonImmutable::now());
    }

    $air = podcastReplayAir(podcastReplayDirector(imaging: $imaging), (float) $slot->getTimestamp(), 160, [
        // A reply ready during the announcement waits for the end of the episode.
        5 => fn () => podcastReplayClip(CasterScript::CHAT, 5.0, 5),
    ]);

    expect(podcastReplayOrder($air))->toBe([31, 9, 21, 22, 9, 32, 5])
        ->and(CasterClip::query()->whereIn('kind', [CasterScript::PODCAST_INTRO, CasterScript::PODCAST_OUTRO])->count())->toBe(0);
});

it('turns down a promo with a number the feed does not have or a time of day of its own', function () {
    $scripts = app(PodcastScripts::class);
    $material = PodcastScripts::material('EINUNDZWANZIG Podcast', ['title' => '#335 - 51% Attacke auf Deutschland', 'episode_number' => 335, 'notes' => '']);
    $good = podcastReplayAnswer('Programmhinweis')['text'];

    expect($scripts->evaluate(PodcastScripts::PROMO, $material, $good)[1])->toBe([])
        ->and($scripts->evaluate(PodcastScripts::PROMO, $material, str_replace('einundfünfzig Prozent', 'zweiundvierzig Prozent', $good))[1])->toContain(ShowScripts::NUMBER)
        ->and($scripts->evaluate(PodcastScripts::PROMO, $material, str_replace('in diesem ganzen Jahr', 'heute um sechzehn Uhr', $good))[1])->toContain(PodcastScripts::CLOCK);
});
