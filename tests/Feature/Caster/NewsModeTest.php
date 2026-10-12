<?php

use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\Pcm;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\VoiceFeed;
use App\Support\TwentyOne\Stream\FfmpegCommands;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: news mode for the stream music
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Das News-Bett war gut. Du kannst doch die BG-Music ducken
| … dann halt so viel ducken im News-Modus, dass man die BG-Music nicht mehr
| hört!" While a bulletin plays (stinger and bed included) the music fades to
| `news_music_db` and comes back after it; outside news the sidechain duck
| stays as it was. The duck travels in the voice feed's right channel, so it
| is written on the voice's own timeline; a silent duck channel is the normal
| mix. The render tests run the real ffmpeg over the production filter graph.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true]);
});

function newsClockAwake(): BroadcastClock
{
    config(['tv.commentary.spool.quiet_hours.from' => '00:00', 'tv.commentary.spool.quiet_hours.to' => '00:00']);

    return BroadcastClock::fromConfig();
}

/** The duck of samples [$from, $to) as a list of s16 values. */
function newsDuckValues(CommentaryTimeline $timeline, int $from, int $to): array
{
    return array_values((array) unpack('v*', $timeline->musicDuck($from, $to)));
}

it('fades the music down from the news clip\'s first sample and back up after its end, on the feed\'s sample clock', function () {
    // 100 samples per second: the clip runs from 10 s to 13 s, fade down 1 s, fade up 2 s, down to -60 dB.
    $timeline = new CommentaryTimeline(100, -60.0, 1.0, 2.0);
    $timeline->start(str_repeat("\1\0", 300), [3.0], 10.0, news: true);
    $full = (int) round((1 - 10 ** (-60 / 20)) * 32767);

    $duck = newsDuckValues($timeline, 990, 1600);

    expect(array_slice($duck, 0, 10))->each->toBe(0)                 // before the clip: no news duck
        ->and($duck[10])->toBe(0)                                     // its first sample: the fade starts there
        ->and($duck[60])->toBeGreaterThan(30000)->toBeLessThan($full) // half way down: -30 dB
        ->and(array_slice($duck, 110, 190))->each->toBe($full)        // 11 s to 13 s: all the way down
        ->and($duck[410])->toBeGreaterThan(0)->toBeLessThan($full)    // 14 s: on the way back up
        ->and(array_slice($duck, 510))->each->toBe(0);                // 15 s on: the music is back

    // The director moved on (clear) while the feed still writes the fade back up: the window keeps its end.
    $timeline->clear();
    expect(newsDuckValues($timeline, 1300, 1600))->toBe(array_slice($duck, 310, 300));
});

it('writes no news duck for any other clip, with news mode off, or a cut moves the fade up with the end', function () {
    $scene = new CommentaryTimeline(100, -60.0, 1.0, 2.0);
    $scene->start(str_repeat("\1\0", 300), [1.0, 3.0], 10.0);
    $off = new CommentaryTimeline(100, 0.0, 1.0, 2.0);
    $off->start(str_repeat("\1\0", 300), [3.0], 10.0, news: true);
    $cut = new CommentaryTimeline(100, -60.0, 1.0, 2.0);
    $cut->start(str_repeat("\1\0", 300), [1.5, 3.0], 10.0, news: true);
    $cut->cutAt(11.5);

    expect($scene->musicDuck(990, 1600))->toBe(str_repeat("\0\0", 610))
        ->and($off->musicDuck(990, 1600))->toBe(str_repeat("\0\0", 610))
        ->and(newsDuckValues($cut, 1150, 1151)[0])->toBeGreaterThan(32000)
        ->and($cut->musicDuck(1350, 1400))->toBe(str_repeat("\0\0", 50));
});

it('starts the bulletin on the timeline in news mode, a scene clip without it', function () {
    $slot = CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin');
    $this->travelTo($slot);
    // Decoding is faked to as much PCM as the Opus file has kilobytes (one second each).
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(output: str_repeat("\1\0", (int) (24000 * strlen((string) $process->input) / 1000)))]);
    $script = casterScript(CasterScript::BULLETIN);
    $script->update(['airs_at' => $slot->utc(), 'expires_at' => $slot->utc()->addMinutes(10)]);
    $bulletin = app(ClipSpool::class)->store($script->fresh(), casterOpus(20_000), 20.0, range(5.0, 20.0, 5.0), CarbonImmutable::now());
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $rate = 24000;
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline($rate, -60.0, 1.0, 2.0), [
        'min_scene_seconds' => 20, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'long_max_hold_seconds' => ['bulletin' => 250], 'gap_seconds' => ['min' => 25, 'max' => 25], 'bulletin_late_seconds' => 120,
    ], clock: newsClockAwake());
    $t = (float) $slot->getTimestamp();

    $director->tick($planner, $planner->at($t, []), $t);
    $start = (int) round((float) $director->timeline->startsAt() * $rate);
    $end = (int) round((float) $director->timeline->endsAt() * $rate);

    expect($director->clip()?->id)->toBe($bulletin->id)
        ->and($director->bulletinOnAir())->toBe('news')
        ->and(newsDuckValues($director->timeline, $start - 10, $start + 1))->each->toBe(0)
        ->and(min(newsDuckValues($director->timeline, $start + $rate, $end)))->toBeGreaterThan(32000);

    // The bulletin ends and the director clears the timeline: past the fade back up the music is at normal ducking.
    for ($t += 0.25; $director->clip()?->id === $bulletin->id; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        $director->tick($planner, $planner->at($t, []), $t);
    }

    expect(array_unique(newsDuckValues($director->timeline, $end + 2 * $rate, $end + 3 * $rate)))->toBe([0]);
});

it('feeds voice left and news duck right as whole frames into the pipe', function () {
    $path = storage_path('framework/testing/news-'.getmypid().'.pcm');
    $feed = VoiceFeed::create($path, 100, 1.0);
    $reader = fopen($path, 'r+');
    stream_set_blocking($reader, false);
    $timeline = new CommentaryTimeline(100, -60.0, 0.5, 0.25);
    $timeline->start(str_repeat("\7\0", 50), [0.5], 10.0, news: true);

    $feed->pump($timeline, 10.0);
    $frames = str_split((string) fread($reader, 10_000), 4);

    // 1 s ahead: 100 frames; the clip's 50 voice samples left, the duck right (all the way down after 0.5 s).
    expect($frames)->toHaveCount(100)
        ->and(substr($frames[0], 0, 2))->toBe("\7\0")
        ->and(unpack('v', substr($frames[0], 2))[1])->toBe(0)
        ->and(unpack('v', substr($frames[49], 2))[1])->toBeGreaterThan(32000)
        ->and(substr($frames[50], 0, 2))->toBe("\0\0")
        ->and(unpack('v', substr($frames[99], 2))[1])->toBe(0);

    fclose($reader);
    $feed->close();
});

it('puts the news duck into the ffmpeg mix, the duck channel never into the output', function () {
    $ffmpeg = new FfmpegCommands('ffmpeg');
    $scene = $ffmpeg->scene('/hls/scene', 'run', '/music.txt', 35, '/voice.pcm');
    $loop = $ffmpeg->hls('/promo.mp4', '/hls/loop', 'run', '/music.txt', '/voice.pcm');

    foreach ([$scene, $loop] as $command) {
        $filter = $command[array_search('-filter_complex', $command, true) + 1];
        $voice = array_search('/voice.pcm', $command, true);

        expect(array_slice($command, $voice - 9, 8))->toBe(['-thread_queue_size', '512', '-f', 's16le', '-ar', '24000', '-ac', '2'])
            ->and($filter)->toContain('pan=mono|c0=c1')
            ->and($filter)->toContain('[ducked][newsduck]amerge=inputs=2,aeval=')
            ->and($filter)->toContain('[music][voice]amix')
            ->and(substr_count($filter, '[newsduck]'))->toBe(2);
    }
});

/**
 * Renders `$seconds` of the production audio mix: a 220 Hz tone at -1 dBFS as the music, the voice feed as written by the
 * daemon (left: a 3 kHz tone while the clip plays, right: the news duck); returns the output as mono s16 PCM.
 */
function newsRender(CommentaryTimeline $timeline, float $seconds, string $dir): string
{
    $rate = 24000;
    $total = (int) ($seconds * $rate);
    File::put($dir.'/voice.pcm', Pcm::interleave($timeline->samples(0, $total), $timeline->musicDuck(0, $total)));
    $ffmpeg = new FfmpegCommands('ffmpeg');
    $scene = $ffmpeg->scene('/unused', 'run', '/unused.txt', 35, $dir.'/voice.pcm');
    $filter = $scene[array_search('-filter_complex', $scene, true) + 1];
    $voice = array_search($dir.'/voice.pcm', $scene, true);

    $result = Process::timeout(60)->run([
        'nice', '-n', '19', 'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
        '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo:d='.$seconds,
        '-f', 'lavfi', '-i', 'aevalsrc=0.9*sin(2*PI*220*t)|0.9*sin(2*PI*220*t):s=44100:d='.$seconds,
        ...array_slice($scene, $voice - 7, 8),
        '-filter_complex', $filter, '-map', '[aout]', '-ac', '1', '-f', 's16le', 'pipe:1',
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return $result->output();
}

/** dBFS of the `$frequency` component of mono 44.1 kHz s16 PCM between `$from` and `$to` seconds (Goertzel). */
function newsToneDb(string $pcm, float $frequency, float $from, float $to): float
{
    $samples = array_values((array) unpack('v*', substr($pcm, (int) ($from * 44100) * 2, (int) (($to - $from) * 44100) * 2)));
    $coefficient = 2 * cos(2 * M_PI * $frequency / 44100);
    [$previous, $before] = [0.0, 0.0];

    foreach ($samples as $sample) {
        $value = ($sample >= 32768 ? $sample - 65536 : $sample) / 32768 + $coefficient * $previous - $before;
        [$before, $previous] = [$previous, $value];
    }

    $power = $previous ** 2 + $before ** 2 - $coefficient * $previous * $before;

    return 20 * log10(max(1e-9, 2 * sqrt($power) / count($samples)));
}

it('renders the music inaudible during news and at its normal ducked level outside, in the real ffmpeg', function () {
    config(['tv.commentary.mix.music_gain_db' => -14.0]);
    $dir = storage_path('framework/testing/news-render-'.getmypid());
    File::ensureDirectoryExists($dir);
    // The clip speaks a 3 kHz tone from 3 s to 6 s.
    $voice = '';
    for ($n = 0; $n < 3 * 24000; $n++) {
        $voice .= pack('v', (int) round(0.3 * 32767 * sin(2 * M_PI * 3000 * $n / 24000)) & 0xFFFF);
    }

    $news = new CommentaryTimeline(24000, -60.0, 1.0, 2.0);
    $news->start($voice, [3.0], 3.0, news: true);
    $scene = new CommentaryTimeline(24000, -60.0, 1.0, 2.0);
    $scene->start($voice, [3.0], 3.0);

    $withNews = newsRender($news, 10.0, $dir);
    $normal = newsRender($scene, 10.0, $dir);
    File::deleteDirectory($dir);

    $levels = [
        'before' => newsToneDb($withNews, 220, 1.0, 2.5),
        'news' => newsToneDb($withNews, 220, 4.2, 5.8),
        'scene' => newsToneDb($normal, 220, 4.2, 5.8),
        'after' => newsToneDb($withNews, 220, 9.0, 10.0),
        'normal_after' => newsToneDb($normal, 220, 9.0, 10.0),
        'voice_news' => newsToneDb($withNews, 3000, 4.2, 5.8),
        'voice_scene' => newsToneDb($normal, 3000, 4.2, 5.8),
    ];
    fwrite(STDERR, 'news mode levels (dBFS): '.json_encode(array_map(fn (float $db): float => round($db, 1), $levels)).PHP_EOL);

    // Music: -0.9 dBFS turned down 14 dB: about -15 dBFS; under the voice the sidechain alone takes it to about -33.
    expect($levels['before'])->toBeGreaterThan(-17.0)->toBeLessThan(-13.0)
        ->and($levels['news'])->toBeLessThanOrEqual(-50.0)
        ->and($levels['scene'])->toBeGreaterThan(-45.0)
        ->and(abs($levels['after'] - $levels['normal_after']))->toBeLessThan(0.5)
        ->and(abs($levels['after'] - $levels['before']))->toBeLessThan(0.5)
        // The voice is untouched by news mode, and at the level the mono feed had: 0.3 (-10.5 dBFS), -3 dB per side.
        ->and(abs($levels['voice_news'] - $levels['voice_scene']))->toBeLessThan(0.5)
        ->and($levels['voice_news'])->toBeGreaterThan(-14.0)->toBeLessThan(-13.0);
});
