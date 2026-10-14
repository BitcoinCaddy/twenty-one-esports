<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\VoiceFeed;
use App\Support\TwentyOne\Stream\FfmpegCommands;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: scene and commentary as one unit (P6)
|--------------------------------------------------------------------------
|
| User 2026-10-11: the slide stays until its commentary has been spoken
| completely. The director holds every slot until its clip ended plus the
| tail, never switches mid-sentence (a takeover waits for the end of the
| sentence on air), deletes a clip right after it played, and shows the
| script as a lower third only while the workstation is off. The voice goes
| into ffmpeg through a named pipe, gapless and ahead of the clock, and is
| mixed at TV levels.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10]);
});

/** A spooled clip of `$seconds` whose sentences end every five seconds; its decode is faked to that much PCM. */
function casterSpooledClip(float $seconds, string $phase = ScenePhase::BREAK, string $kind = CasterScript::SCENE): CasterClip
{
    $ends = range(5.0, $seconds, 5.0);
    $clip = app(ClipSpool::class)->store(casterScript($kind, $phase), casterOpus(), $seconds, $ends, CarbonImmutable::now());
    Process::fake(['*' => Process::result(output: str_repeat("\1\0", (int) (24000 * $seconds)))]);

    return $clip;
}

function casterDirector(): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
    ]);
}

function casterPlanner(): RotationPlanner
{
    return new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
}

it('never changes the scene before its clip has been spoken, plus the tail', function () {
    casterSpooledClip(30.0);
    $planner = casterPlanner();
    $director = casterDirector();
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $changes = [];
    $last = null;

    for ($t = $start; $t <= $start + 45; $t += 0.25) {
        $slot = $planner->at($t, []);
        $director->tick($planner, $slot, $t);

        if ($planner->slotNumber() !== $last) {
            $changes[] = $t - $start;
            $last = $planner->slotNumber();
        }
    }

    // The loop slot would have ended after 5 s; it stands until 0.5 + 30 + 1.5 s.
    expect($changes[0])->toBe(0.0)
        ->and($changes[1])->toBeGreaterThanOrEqual(32.0)
        ->and($changes[1])->toBeLessThan(32.5)
        ->and(CasterClip::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('caster/spool');
});

/** A director with a fixed gap of music between two clips. */
function casterGapDirector(float $gap): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'gap_seconds' => ['min' => $gap, 'max' => $gap],
    ]);
}

it('leaves a gap of music between two clips, the next scene change starting with the next clip', function () {
    casterSpooledClip(10.0);
    casterSpooledClip(10.0);
    $planner = casterPlanner();
    $director = casterGapDirector(25.0);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $starts = [];
    $playing = null;
    $changes = [];
    $last = null;

    for ($t = $start; $t <= $start + 60; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id !== $playing) {
            $playing = $director->clip()?->id;
            if ($playing !== null) {
                $starts[] = $t - $start;
            }
        }
        if ($planner->slotNumber() !== $last) {
            $changes[] = $t - $start;
            $last = $planner->slotNumber();
        }
    }

    // Clip one is spoken 0.5 to 10.5 s; 25 s of music follow, so clip two starts at 35.5 s, with a new scene.
    expect($starts)->toHaveCount(2)
        ->and($starts[0])->toBe(0.0)
        ->and($starts[1])->toBeGreaterThanOrEqual(35.4)->toBeLessThan(36.0)
        ->and($changes)->toContain($starts[1]);
});

it('lets a live moment cut the gap short, also in the middle of a quiet slot', function () {
    casterSpooledClip(10.0);
    $planner = casterPlanner();
    $director = casterGapDirector(25.0);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $liveAt = null;

    for ($t = $start; $t <= $start + 40 && $liveAt === null; $t += 0.25) {
        if (abs($t - $start - 24.0) < 0.01) {
            casterSpooledClip(5.0, ScenePhase::BREAK, CasterScript::LIVE);
        }

        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->kind === CasterScript::LIVE) {
            $liveAt = $t - $start;
        }
    }

    expect($liveAt)->toBeGreaterThanOrEqual(24.0)->toBeLessThan(24.5);
});

it('starts a clip after the silence the voice feed already wrote, so its first syllable is never cut', function () {
    casterSpooledClip(10.0);
    $planner = casterPlanner();
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100, 'feed_lead_seconds' => 1.0,
    ]);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    // The feed pumps before the director's tick: silence is written up to one second ahead.
    $written = (int) round(($start + 1.0) * 24000);
    $director->tick($planner, $planner->at($start, []), $start);
    $handed = $director->timeline->samples($written, (int) round(($start + 15.0) * 24000));

    // Every sample of the clip (the faked decode is "\1\0" throughout) reaches the feed, the head included.
    expect(substr_count($handed, "\1\0"))->toBe(240000);
});

it('holds a slot without a clip for the calmer minimum', function () {
    $planner = casterPlanner();
    $director = casterDirector();
    $start = (float) CarbonImmutable::now()->getTimestamp();

    $planner->at($start, []);
    $director->tick($planner, $planner->at($start, []), $start);

    expect($planner->at($start + 14, [])['until'])->toBe($start + 15.0)
        ->and($planner->slotNumber())->toBe(1);
});

it('lets a champion moment in at the end of the sentence on air, never mid-sentence', function () {
    casterSpooledClip(30.0);
    $planner = casterPlanner();
    $director = casterDirector();
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $champion = [['id' => 9, 'phase' => 'finished', 'fomo' => false, 'moment' => true]];
    $switch = null;

    for ($t = $start; $t <= $start + 20 && $switch === null; $t += 0.25) {
        $slot = $planner->at($t, [], [], 'off', $t >= $start + 7 ? $champion : []);
        $director->tick($planner, $slot, $t);

        if ($planner->slotNumber() > 1) {
            $switch = $t - $start;
        }
    }

    // Sentences end at 5, 10, … s into the clip, which starts 0.5 s in: the one on air at 7 s ends at 10.5 s.
    expect($switch)->toBeGreaterThanOrEqual(10.8)
        ->and($switch)->toBeLessThan(11.5)
        ->and($planner->at($start + 12, [], [], 'off', $champion)['scene'])->toBe(RotationPlanner::CHAMPION_SCENE);
});

it('shows no text lower third while the workstation is off', function () {
    $now = CarbonImmutable::now();
    CasterScript::query()->create([
        'kind' => CasterScript::SCENE, 'phase' => ScenePhase::forSlot(['kind' => RotationPlanner::LOOP, 'scene' => null], $now), 'priority' => 0,
        'text' => "Hey.\nHier ist die Liga.", 'facts_hash' => 'h', 'status' => CasterScript::QUEUED, 'estimated_seconds' => 3, 'expires_at' => $now->addMinutes(10),
    ]);
    $planner = casterPlanner();

    $caption = casterDirector()->tick($planner, $planner->at((float) $now->getTimestamp(), []), (float) $now->getTimestamp());

    expect($caption)->toBeNull();
});

it('appends the lower third to a rotation scene only when a caption is set', function () {
    $renderer = new SceneRenderer('rsvg-convert', resource_path('fonts/stream'), storage_path('framework/testing/scene'));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1280 720"><rect width="1280" height="720"/></svg>';
    view()->addNamespace('castertest', storage_path('framework/testing/caster-views'));
    File::ensureDirectoryExists(storage_path('framework/testing/caster-views'));
    file_put_contents(storage_path('framework/testing/caster-views/plain.blade.php'), $svg);

    $plain = $renderer->svg([], 'castertest::plain');
    $captioned = $renderer->svg(['voice_caption' => 'Hey. Hier ist die Liga.'], 'castertest::plain');

    expect($plain)->toBe($svg)
        ->and($captioned)->toContain('data-unit="voice-caption"')
        ->and($captioned)->toContain('Hey. Hier ist die Liga.')
        ->and($captioned)->not->toContain('KI')
        ->and(strrpos($captioned, '</svg>'))->toBe(strlen($captioned) - 6);
});

it('hands out the wall clock as gapless PCM, silence around the clip and after a cut', function () {
    $timeline = new CommentaryTimeline(10);
    $timeline->start(str_repeat("\1\0", 20), [1.0, 2.0], 100.0);

    $before = $timeline->samples(995, 1000);
    $across = $timeline->samples(998, 1003);

    expect($before)->toBe(str_repeat("\0\0", 5))
        ->and($across)->toBe("\0\0\0\0\1\0\1\0\1\0")
        ->and($timeline->endsAt())->toBe(102.0)
        ->and($timeline->nextSentenceEnd(100.4))->toBe(101.0);

    $timeline->cutAt(101.0);

    expect($timeline->samples(1009, 1012))->toBe("\1\0\0\0\0\0")
        ->and($timeline->isPlaying(101.0))->toBeFalse();
});

it('writes the voice into a named pipe ahead of the clock without blocking', function () {
    $path = storage_path('framework/testing/caster-'.getmypid().'.pcm');
    $feed = VoiceFeed::create($path, 100, 1.0);
    expect($feed)->not->toBeNull()->and(filetype($path))->toBe('fifo');

    $reader = fopen($path, 'r+');
    stream_set_blocking($reader, false);
    $timeline = new CommentaryTimeline(100);
    $timeline->start(str_repeat("\7\0", 50), [0.5], 10.0);

    $feed->pump($timeline, 10.0);
    $feed->pump($timeline, 10.5);
    $read = (string) fread($reader, 10_000);

    // 1.5 s of stereo frames (0..150), the voice left, no news duck right: the clip's 50 samples first, then
    // silence; nothing twice, nothing missing.
    expect(strlen($read))->toBe(150 * 4)
        ->and(substr($read, 0, 200))->toBe(str_repeat("\7\0\0\0", 50))
        ->and(substr($read, 200))->toBe(str_repeat("\0\0\0\0", 100));

    fclose($reader);
    $feed->close();
    expect(file_exists($path))->toBeFalse();
});

it('mixes the voice at TV levels and leaves the commands as they were without it', function () {
    config(['tv.commentary.mix.music_gain_db' => -14.0, 'tv.commentary.mix.voice_gain_db' => 3.0]);
    $ffmpeg = new FfmpegCommands('ffmpeg');

    $plain = $ffmpeg->scene('/hls/scene', 'run', '/music.txt', 35);
    $mixed = $ffmpeg->scene('/hls/scene', 'run', '/music.txt', 35, '/voice.pcm');
    $loop = $ffmpeg->hls('/promo.mp4', '/hls/loop', 'run', '/music.txt', '/voice.pcm');
    $filter = $mixed[array_search('-filter_complex', $mixed, true) + 1];

    expect($plain)->toContain('copy')->not->toContain('/voice.pcm')
        ->and($ffmpeg->hls('/promo.mp4', '/hls/loop', 'run', '/music.txt'))->toContain('-c')
        ->and($mixed)->toContain('/voice.pcm')->toContain('[aout]')->toContain('aac')
        ->and($filter)->toContain('volume=-14.0dB')
        // The voice lifted over the bed (radio feeling, 2026-10-14), before the sidechain key splits off.
        ->and($filter)->toContain('c1=0.70710678*c0,volume=3.0dB,')
        ->and($filter)->toContain('sidechaincompress')
        ->and($filter)->toContain('alimiter=limit=0.891')
        ->and($loop)->toContain('-c:v')->not->toContain('-c');
});

it('plays a reaction at the next clip boundary: at once in a silent slot, right after the clip on air otherwise', function () {
    $planner = casterPlanner();
    $director = casterGapDirector(25.0);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $liveAt = null;

    // A slot without a clip (it holds 15 s, no gap running): the reaction arrives at 5 s.
    for ($t = $start; $t <= $start + 14 && $liveAt === null; $t += 0.25) {
        if (abs($t - $start - 5.0) < 0.01) {
            casterSpooledClip(5.0, ScenePhase::BREAK, CasterScript::CHAT);
        }

        $director->tick($planner, $planner->at($t, []), $t);
        $liveAt = $director->clip()?->kind === CasterScript::CHAT ? $t - $start : null;
    }

    expect($liveAt)->toBeGreaterThanOrEqual(5.0)->toBeLessThan(5.5);
});

it('never cuts the clip on air for a reaction, and plays it as soon as that clip ended', function () {
    casterSpooledClip(10.0);
    $planner = casterPlanner();
    $director = casterGapDirector(25.0);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $liveAt = null;

    for ($t = $start; $t <= $start + 30 && $liveAt === null; $t += 0.25) {
        if (abs($t - $start - 3.0) < 0.01) {
            casterSpooledClip(5.0, ScenePhase::BREAK, CasterScript::CHAT);
        }

        $director->tick($planner, $planner->at($t, []), $t);
        $liveAt = $director->clip()?->kind === CasterScript::CHAT ? $t - $start : null;
    }

    // The scene clip is spoken 0.5 to 10.5 s; the reaction follows at its end, not after the 25 s gap.
    expect($liveAt)->toBeGreaterThanOrEqual(10.5)->toBeLessThan(11.0);
});

it('holds the slot through a long history segment, beyond max_hold_seconds up to long_max_hold_seconds of its kind', function () {
    casterSpooledClip(120.0, ScenePhase::BREAK, CasterScript::HISTORY);
    $planner = casterPlanner();
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100, 'long_max_hold_seconds' => ['history' => 160],
    ]);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $changes = [];
    $last = null;

    for ($t = $start; $t <= $start + 130; $t += 0.5) {
        $director->tick($planner, $planner->at($t, []), $t);

        if ($planner->slotNumber() !== $last) {
            $changes[] = $t - $start;
            $last = $planner->slotNumber();
        }
    }

    // Spoken 0.5 to 120.5 s, plus the 1.5 s tail: the slot changes at 122 s, not at the 100 s scene cap.
    expect($changes[1] ?? null)->toBeGreaterThanOrEqual(122.0)->toBeLessThan(123.0);
});

it('holds the slot through a long scene clip, beyond max_hold_seconds up to long_max_hold_seconds of a scene', function () {
    casterSpooledClip(110.0);
    $planner = casterPlanner();
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100, 'long_max_hold_seconds' => ['scene' => 135],
    ]);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $changes = [];
    $last = null;

    for ($t = $start; $t <= $start + 120; $t += 0.5) {
        $director->tick($planner, $planner->at($t, []), $t);

        if ($planner->slotNumber() !== $last) {
            $changes[] = $t - $start;
            $last = $planner->slotNumber();
        }
    }

    // Spoken 0.5 to 110.5 s plus the tail: the slot changes at 112 s, not at the 100 s cap of the other kinds.
    expect($changes[1] ?? null)->toBeGreaterThanOrEqual(112.0)->toBeLessThan(113.0);
});

/**
 * The start times (seconds after the first tick) of every clip the director plays within `$seconds`.
 *
 * @return list<float>
 */
function casterClipStarts(SceneDirector $director, float $seconds): array
{
    $planner = casterPlanner();
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $starts = [];
    $playing = null;

    for ($t = $start; $t <= $start + $seconds; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id !== $playing) {
            $playing = $director->clip()?->id;
            if ($playing !== null) {
                $starts[] = $t - $start;
            }
        }
    }

    return $starts;
}

function casterRunDirector(int $clips): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 5, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'gap_seconds' => ['min' => 25.0, 'max' => 25.0],
        'runs' => ['enabled' => true, 'every_minutes' => ['min' => 1.0, 'max' => 1.0], 'clips' => ['min' => $clips, 'max' => $clips], 'breath_seconds' => ['min' => 1.0, 'max' => 1.0]],
    ]);
}

it('plays a run of clips back to back with only a breath between them, then the music gap returns', function () {
    foreach (range(1, 6) as $ignored) {
        casterSpooledClip(10.0);
    }

    $starts = casterClipStarts(casterRunDirector(3), 160);
    $pauses = array_map(fn (float $next, float $previous): float => round($next - $previous - 10.0, 2), array_slice($starts, 1), array_slice($starts, 0, -1));

    // Clip one draws the first run a minute ahead; clip two follows the 25 s gap; clip three (about 71 s, past
    // the minute) opens a run of three: three, four and five only a breath apart; then the 25 s gap again.
    expect($starts)->toHaveCount(6)
        ->and(array_slice($pauses, 0, 2))->each->toBeGreaterThanOrEqual(25.0)
        ->and(array_slice($pauses, 2, 2))->each->toBeLessThan(4.0)
        ->and($pauses[4])->toBeGreaterThanOrEqual(25.0);
});

it('counts no reaction as a run clip, and ends a run at a slot without a clip', function () {
    casterSpooledClip(10.0);
    $director = casterRunDirector(2);
    $planner = casterPlanner();
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $runs = [];

    for ($t = $start; $t <= $start + 230; $t += 0.25) {
        // A reaction lands at 70 s, past the minute; a scene clip at 140 s (counted, the reaction would have opened a run, and the clip a second one).
        if (abs($t - $start - 70.0) < 0.01) {
            casterSpooledClip(5.0, ScenePhase::BREAK, CasterScript::CHAT);
        }
        if (abs($t - $start - 140.0) < 0.01) {
            casterSpooledClip(10.0);
        }

        $director->tick($planner, $planner->at($t, []), $t);
        $runs[] = $director->run();
    }

    // The reaction opened no run; the scene clip did (run 1, one clip to come), and the empty slot after it ended the run.
    expect(array_values(array_unique(array_column($runs, 'id'))))->toBe([0, 1])
        ->and(array_column($runs, 'left'))->toContain(1)
        ->and(end($runs))->toBe(['id' => 1, 'position' => 0, 'left' => 0]);
});
