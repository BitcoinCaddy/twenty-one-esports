<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\SceneDirector;
use App\Support\TwentyOne\Stream\MusicTimeline;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE ESPORTS RADIO: music breaks
|--------------------------------------------------------------------------
|
| User 2026-10-13: "Ab und an soll der Radiosender auch mal ein komplettes
| Musikstück spielen lassen, sonst wird nur ständig geredet." Twice an hour
| the voice pauses for one complete vocal track of the stream music, from
| its first second to its last; the next clip or block waits for the
| track's end. Never across the news, a rubric's slot or a show window,
| never in the quiet hours.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10]);
    // 12:04 in Berlin: a talk slot (12:03 to 12:15), by day.
    $this->travelTo(CarbonImmutable::parse('2026-10-13T10:04:00Z'));
});

/** Every decode answers with the PCM its fake Opus names (`LEN:<s>;FILL:<v>;`). */
function musicBreakDecodes(): void
{
    Process::fake(function ($process) {
        preg_match('/LEN:([\d.]+);FILL:(\d+);/', (string) $process->input, $match);

        return Process::result(output: str_repeat(pack('v', (int) ($match[2] ?? 9)), (int) round(24000 * (float) ($match[1] ?? 2))));
    });
}

/** A spooled single clip of `$seconds` with the sample value `$fill`. */
function musicBreakClip(float $seconds, int $fill, string $kind = CasterScript::SCENE): CasterClip
{
    return app(ClipSpool::class)->store(casterScript($kind), casterOpus().sprintf('LEN:%.3F;FILL:%d;', $seconds, $fill), $seconds, range(2.0, $seconds, 2.0), CarbonImmutable::now());
}

/** A spooled programme block of `$seconds` with the sample value `$fill`. */
function musicBreakBlock(float $seconds, int $fill): CasterClip
{
    return app(ClipSpool::class)->storeBlock([casterScript(), casterScript()], casterOpus().sprintf('LEN:%.3F;FILL:%d;', $seconds, $fill), $seconds, range(2.0, $seconds, 2.0), [], 0.0, CarbonImmutable::now());
}

/**
 * Tracks of `$seconds` each from `$from` on, instrumental and vocal by turns (instrumental first).
 *
 * @return list<array{file: string, start: float, end: float, vocal: bool}>
 */
function musicBreakTracks(float $from, int $count, float $seconds = 180.0): array
{
    return array_map(fn (int $index): array => ['file' => $index % 2 === 1 ? "/music/v{$index}__v1.m4a" : "/music/instrumental/i{$index}__v1.m4a",
        'start' => $from + $index * $seconds, 'end' => $from + ($index + 1) * $seconds, 'vocal' => $index % 2 === 1], range(0, $count - 1));
}

/** @param  list<array{file: string, start: float, end: float, vocal: bool}>  $tracks */
function musicBreakDirector(array $tracks, float $perHour = 2, float $wait = 300, array $windows = [], bool $enabled = true): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'feed_lead_seconds' => 1.0, 'gap_seconds' => ['min' => 1, 'max' => 1], 'bulletin_late_seconds' => 120,
        'blocks' => ['join_seconds' => ['min' => 0.4, 'max' => 0.4], 'reaction_wait_seconds' => 90, 'hold_seconds' => 30, 'resume_within_seconds' => 600],
        'music_breaks' => ['enabled' => $enabled, 'per_hour' => $perHour, 'wait_seconds' => $wait],
    ], null, new BroadcastClock((array) config('tv.commentary.programme.slots'), 'Europe/Berlin', true, $windows), null,
        fn (float $from, float $to): array => array_values(array_filter($tracks, fn (array $track): bool => $track['end'] > $from && $track['start'] < $to)));
}

/**
 * Runs the director like the stream does (a poll every second, the voice feed written one second ahead every 0.25 s)
 * and returns the seconds (from the start) in which voice was on air, plus every music break it planned.
 *
 * @param  Closure(float): void|null  $each  called before every poll with the seconds since the start (feeds the spool)
 * @return array{voice: list<float>, breaks: list<array{start: float, end: float}>}
 */
function musicBreakAir(SceneDirector $director, float $start, float $seconds, ?Closure $each = null): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $next = (int) round(($start + 1.0) * 24000);
    $voice = [];
    $breaks = [];

    for ($step = 0; $step <= $seconds * 4; $step++) {
        $t = $start + $step * 0.25;

        if ($step % 4 === 0) {
            if ($each !== null) {
                $each($t - $start);
            }

            $director->tick($planner, $planner->at($t, []), $t);
            $break = $director->musicBreak();

            if ($break !== null && ! in_array(['start' => $break['start'] - $start, 'end' => $break['end'] - $start], $breaks, true)) {
                $breaks[] = ['start' => $break['start'] - $start, 'end' => $break['end'] - $start];
            }
        }

        // Each quarter second of feed is one entry when any of it carries voice.
        $target = (int) round(($t + 1.0) * 24000);
        $chunk = $director->timeline->samples($next, $target);

        if (trim($chunk, "\0") !== '') {
            $voice[] = round($next / 24000 - $start, 2);
        }

        $next = $target;
    }

    return ['voice' => $voice, 'breaks' => $breaks];
}

it('pauses the voice for one complete vocal track twice an hour, and the next clip waits for its end', function () {
    musicBreakDecodes();
    $start = (float) CarbonImmutable::now()->getTimestamp();
    // Vocal tracks start 80 s after the start and every six minutes after that, each 180 s long.
    $director = musicBreakDirector(musicBreakTracks($start - 100, 24));
    $feed = function (float $at) use ($start): void {
        // The wall clock runs with the stream, so a clip spooled now does not expire on the director's clock.
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) ($start + $at)));

        if (CasterClip::query()->where('status', CasterClip::READY)->doesntExist()) {
            musicBreakClip(10.0, 1);
        }
    };

    $air = musicBreakAir($director, $start, 3600, $feed);

    // Due 900 s in, the first vocal track after that boundary runs 1160..1340; the next is due 1800 s after its end
    // (3140), passes over the track at 3320 (12:59:20 in Berlin, it would run into the news at 13:00) and takes the one
    // at 3680..3860 (13:05:20, talk).
    expect($air['breaks'])->toBe([['start' => 1160.0, 'end' => 1340.0], ['start' => 3680.0, 'end' => 3860.0]]);

    foreach (array_slice($air['breaks'], 0, 1) as $break) {
        $inside = array_filter($air['voice'], fn (float $at): bool => $at >= $break['start'] - 0.25 && $at < $break['end']);
        $after = array_values(array_filter($air['voice'], fn (float $at): bool => $at >= $break['end']));

        expect($inside)->toBe([])
            // The voice comes back as the track ends, not a poll later than needed.
            ->and($after[0])->toBeLessThanOrEqual($break['end'] + 2.0)
            // Before the track a clip still played that ended in time: the voice stood aside at most a clip's length early.
            ->and(max(array_filter($air['voice'], fn (float $at): bool => $at < $break['start'])))->toBeGreaterThan($break['start'] - 25.0);
    }

    // Outside the break the voice ran on (a 10 s clip, a breath of music): the only long silence is the break.
    $silences = [];
    foreach (array_slice($air['voice'], 1) as $index => $at) {
        if ($at - $air['voice'][$index] > 30.0) {
            $silences[] = [$air['voice'][$index], $at];
        }
    }
    expect($silences)->toHaveCount(1)
        ->and($silences[0][0])->toBeGreaterThan(1160.0 - 25.0)
        ->and($silences[0][1])->toBeGreaterThanOrEqual(1340.0)->toBeLessThanOrEqual(1342.0);
});

it('keeps the next programme block off a planned break: it starts as the track ends instead of joining', function () {
    musicBreakDecodes();
    $start = CarbonImmutable::now()->getTimestamp() + 0.37;
    musicBreakBlock(60.0, 1);
    musicBreakBlock(30.0, 3);
    // Once a minute (due 30 s in), the vocal track 65..245 s after the start.
    $director = musicBreakDirector(musicBreakTracks($start - 115, 6), perHour: 60);

    $air = musicBreakAir($director, $start, 300);
    $second = array_values(array_filter($air['voice'], fn (float $at): bool => $at > 62.0));

    expect($air['breaks'][0])->toBe(['start' => 65.0, 'end' => 245.0])
        ->and($second[0])->toBeGreaterThanOrEqual(245.0)->toBeLessThanOrEqual(247.0)
        // The whole second block aired after the track.
        ->and(count($second) / 4)->toBeGreaterThanOrEqual(30.0);
});

it('never plans a break across the news, a rubric slot or a show window, nor in the quiet hours', function () {
    // 12:27:00 in Berlin; a vocal track from 12:28:30 would run into the headlines at 12:30.
    $this->travelTo(CarbonImmutable::parse('2026-10-13T10:27:00Z'));
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $tracks = musicBreakTracks($start - 90, 8);

    $air = musicBreakAir(musicBreakDirector($tracks, perHour: 60, wait: 600), $start, 60);
    // The track after it, 12:34:30 to 12:37:30, lies in talk (12:33 to 12:45).
    expect($air['breaks'])->toBe([['start' => 450.0, 'end' => 630.0]]);

    // A show window from 12:33 holds that track too: nothing within the wait.
    $air = musicBreakAir(musicBreakDirector($tracks, perHour: 60, wait: 600, windows: ['12:33' => 30]), $start, 60);
    expect($air['breaks'])->toBe([]);

    // 03:27 in Berlin, in the quiet hours: no break at all.
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-13T01:27:00Z'));
    $night = (float) CarbonImmutable::now()->getTimestamp();
    $air = musicBreakAir(musicBreakDirector(musicBreakTracks($night - 90, 8), perHour: 60, wait: 600), $night, 60);
    expect($air['breaks'])->toBe([]);
});

it('prefers a PlebRap track within the wait over an earlier other vocal track', function () {
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $tracks = musicBreakTracks($start - 100, 8);
    // The vocal track at 80 s is an ordinary one, the one at 440 s PlebRap, both within the wait of 600 s.
    $tracks = array_map(fn (array $track): array => [...$track, 'priority' => $track['start'] === $start + 440.0], $tracks);

    expect(musicBreakAir(musicBreakDirector($tracks, perHour: 60, wait: 600), $start, 40)['breaks'])->toBe([['start' => 440.0, 'end' => 620.0]])
        // No PlebRap within the wait: the first other vocal track.
        ->and(musicBreakAir(musicBreakDirector($tracks, perHour: 60, wait: 300), $start, 40)['breaks'])->toBe([['start' => 80.0, 'end' => 260.0]]);
});

it('plans nothing when music breaks are off or the music timeline is unreadable', function () {
    $start = (float) CarbonImmutable::now()->getTimestamp();

    expect(musicBreakAir(musicBreakDirector(musicBreakTracks($start - 100, 8), perHour: 60, enabled: false), $start, 60)['breaks'])->toBe([])
        ->and(musicBreakAir(musicBreakDirector([], perHour: 60), $start, 60)['breaks'])->toBe([])
        ->and(MusicTimeline::tracks(storage_path('framework/testing/no-such-timeline.json'), $start, $start + 600))->toBe([]);
});

it('reads the tracks of the timeline file the stream writes, as they sound on the wall clock', function () {
    $path = storage_path('framework/testing/music-timeline-'.getmypid().'.json');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, json_encode(['fingerprint' => 'x', 'anchor' => 1000.0, 'entries' => [
        ['/music/a__v1.m4a', 200.0, 1.5, 201.5], ['/music/instrumental/b__v1.m4a', 150.0, 0.0, null], ["/music/plebrap/01 - Pleb - It's (Grüße)__v1.m4a", 180.0, 0.0, null],
    ], 'durations' => []]));

    expect(MusicTimeline::tracks($path, 1150.0, 1400.0))->toBe([
        ['file' => '/music/a__v1.m4a', 'start' => 1000.0, 'end' => 1200.0],
        ['file' => '/music/instrumental/b__v1.m4a', 'start' => 1200.0, 'end' => 1350.0],
        ['file' => "/music/plebrap/01 - Pleb - It's (Grüße)__v1.m4a", 'start' => 1350.0, 'end' => 1530.0],
    ]);

    config(['twentyone.stream.music.timeline_file' => $path, 'twentyone.stream.music.dir' => '/music', 'twentyone.stream.music.plebrap_dir' => '/music/plebrap']);
    $tracks = (SceneDirector::musicTracksFromConfig())(1150.0, 1400.0);
    expect(array_column($tracks, 'vocal'))->toBe([true, false, true])
        ->and(array_column($tracks, 'priority'))->toBe([false, false, true]);

    unlink($path);
});
