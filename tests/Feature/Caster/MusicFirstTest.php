<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\LiveOnly;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE ESPORTS RADIO: music first, live only
|--------------------------------------------------------------------------
|
| User 2026-10-13: "Seit 15:30 wurde ununterbrochen gelabert mit Audios. Was
| ist mit den Pausen, damit Musikstücke durchlaufen können! … Maximal ein Gag
| und dann nicht ständig welche hintereinander!!!! … Lieber mehr Musik und
| Live-Reactions". Between two voice items one complete music track; a chat
| reaction waits for that track's end and goes first; while the workstation
| is online by day nothing generic is written and no stock block airs.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10]);
    // 12:04 in Berlin, by day.
    $this->travelTo(CarbonImmutable::parse('2026-10-13T10:04:00Z'));
});

/** Every decode answers with the PCM its fake Opus names (`LEN:<s>;FILL:<v>;`). */
function musicFirstDecodes(): void
{
    Process::fake(function ($process) {
        preg_match('/LEN:([\d.]+);FILL:(\d+);/', (string) $process->input, $match);

        return Process::result(output: str_repeat(pack('v', (int) ($match[2] ?? 9)), (int) round(24000 * (float) ($match[1] ?? 2))));
    });
}

/** A spooled single clip of `$kind`, `$seconds` long, every sample `$fill`. */
function musicFirstClip(string $kind, float $seconds, int $fill): CasterClip
{
    $script = casterScript($kind);
    // A reaction keeps chat.max_hold_seconds plus its voicing (ScriptQueue::enqueue()), never ten minutes only.
    $script->update(['expires_at' => CarbonImmutable::now()->addMinutes(30)]);

    return app(ClipSpool::class)->store($script, casterOpus().sprintf('LEN:%.3F;FILL:%d;', $seconds, $fill), $seconds, range(2.0, $seconds, 2.0), CarbonImmutable::now());
}

/**
 * Tracks from `$from` on, of lengths cycling through 150 to 260 seconds.
 *
 * @return list<array{file: string, start: float, end: float, vocal: bool, priority: bool}>
 */
function musicFirstTracks(float $from, int $count): array
{
    $lengths = [150.0, 210.0, 260.0, 180.0, 240.0];
    $tracks = [];

    for ($index = 0; $index < $count; $index++) {
        $end = $from + $lengths[$index % count($lengths)];
        $tracks[] = ['file' => "/music/t{$index}__v1.m4a", 'start' => $from, 'end' => $end, 'vocal' => false, 'priority' => false];
        $from = $end;
    }

    return $tracks;
}

/** @param  list<array{file: string, start: float, end: float, vocal: bool, priority: bool}>  $tracks */
function musicFirstDirector(array $tracks, bool $enabled = true): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'feed_lead_seconds' => 1.0, 'gap_seconds' => ['min' => 8, 'max' => 8], 'bulletin_late_seconds' => 120,
        'runs' => ['enabled' => true, 'every_minutes' => ['min' => 1, 'max' => 1], 'clips' => ['min' => 3, 'max' => 3], 'breath_seconds' => ['min' => 1, 'max' => 1]],
        'blocks' => ['join_seconds' => ['min' => 0.4, 'max' => 0.4], 'reaction_wait_seconds' => 90, 'hold_seconds' => 30, 'resume_within_seconds' => 600],
        'music_breaks' => ['enabled' => false],
        'music_first' => ['enabled' => $enabled, 'max_wait_seconds' => 540], 'live_anytime' => (bool) config('tv.commentary.chat.live_anytime', true),
    ], null, new BroadcastClock((array) config('tv.commentary.programme.slots'), 'Europe/Berlin', false), null,
        fn (float $from, float $to): array => array_values(array_filter($tracks, fn (array $track): bool => $track['end'] > $from && $track['start'] < $to)));
}

/**
 * Runs the director like the stream does (a poll a second, the voice feed written a second ahead every 0.25 s) and
 * returns the voice on air as pieces: start and end (seconds from the start) and the sample value (the clip's kind).
 *
 * @param  Closure(float): void  $each  called before every poll with the seconds since the start
 * @return array{pieces: list<array{start: float, end: float, fill: int}>, voiced: float}
 */
function musicFirstAir(SceneDirector $director, float $start, float $seconds, Closure $each): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $next = (int) round(($start + 1.0) * 24000);
    $pieces = [];
    $voiced = 0.0;

    for ($step = 0; $step <= $seconds * 4; $step++) {
        $t = $start + $step * 0.25;

        if ($step % 4 === 0) {
            $each($t - $start);
            $director->tick($planner, $planner->at($t, []), $t);
        }

        $target = (int) round(($t + 1.0) * 24000);
        $chunk = $director->timeline->samples($next, $target);
        $voice = ltrim($chunk, "\0");

        if ($voice !== '') {
            $at = round($next / 24000 - $start, 2);
            $fill = unpack('v', substr($voice, 0, 2) === "\0" ? substr($voice, 1, 2) : substr($voice, 0, 2))[1];
            $last = $pieces === [] ? null : $pieces[array_key_last($pieces)];
            $voiced += 0.25;

            if ($last !== null && $at - $last['end'] <= 1.5) {
                $pieces[array_key_last($pieces)]['end'] = $at + 0.25;
            } else {
                $pieces[] = ['start' => $at, 'end' => $at + 0.25, 'fill' => $fill];
            }
        }

        $next = $target;
    }

    return ['pieces' => $pieces, 'voiced' => $voiced];
}

/**
 * One simulated hour: a scene always ready (a full spool of generic talk), chat reactions at fixed seconds.
 *
 * @param  list<float>  $chats
 * @return array{air: array{pieces: list<array{start: float, end: float, fill: int}>, voiced: float}, tracks: list<array{file: string, start: float, end: float, vocal: bool, priority: bool}>, start: float}
 */
function musicFirstHour(bool $enabled, array $chats): array
{
    musicFirstDecodes();
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $tracks = musicFirstTracks($start - 100, 30);
    $director = musicFirstDirector($tracks, $enabled);
    $pending = $chats;

    $air = musicFirstAir($director, $start, 3600, function (float $at) use ($start, &$pending): void {
        test()->travelTo(CarbonImmutable::createFromTimestamp((int) ($start + $at)));

        while ($pending !== [] && $pending[0] <= $at) {
            array_shift($pending);
            musicFirstClip(CasterScript::CHAT, 20.0, 2);
        }

        if (CasterClip::query()->where('status', CasterClip::READY)->where('kind', CasterScript::SCENE)->where('expires_at', '>', CarbonImmutable::now()->utc())->doesntExist()) {
            musicFirstClip(CasterScript::SCENE, 30.0, 1);
        }
    });

    return ['air' => $air, 'tracks' => array_map(fn (array $track): array => [...$track, 'start' => $track['start'] - $start, 'end' => $track['end'] - $start], $tracks), 'start' => $start];
}

it('puts one complete music track between two voice items over a simulated hour, voice at most 40 %, a live reaction at once mid-track', function () {
    $chats = [300.0, 310.0, 1500.0, 2400.0];
    ['air' => $air, 'tracks' => $tracks] = musicFirstHour(true, $chats);
    $pieces = $air['pieces'];

    // Between every two voice pieces a whole track starts after the first ended and ends before the second starts. The one
    // exception is the older ceiling (user 2026-10-12: "Maximal 10 Minuten, dann dürfen wieder Reactions reinkommen"): a
    // reaction waiting chat.max_hold_seconds (600) cuts in at the next boundary.
    $arrivals = $chats;
    $chatIndex = 0;
    $without = [];
    $overdue = 0;
    for ($index = 0; $index < count($pieces); $index++) {
        $isChat = $pieces[$index]['fill'] === 2;
        $waited = $isChat ? $pieces[$index]['start'] - $arrivals[$chatIndex++] : 0.0;

        if ($index === 0) {
            continue;
        }

        $before = $pieces[$index - 1]['end'];
        $after = $pieces[$index]['start'];
        $whole = array_filter($tracks, fn (array $track): bool => $track['start'] >= $before - 1.0 && $track['end'] <= $after + 0.01);

        // A live reaction does not wait for the track's end (user 2026-10-14: "Für Live Reactions gilt die Ausnahme, dass die
        // immer kommen dürfen"): no whole track before it.
        if ($isChat) {
            continue;
        }
        if ($whole === [] && $isChat && $waited >= 600.0) {
            $overdue++;
        } elseif ($whole === []) {
            $without[] = [$before, $after];
        }
    }

    // Each reaction aired as soon as the clip on air (if any) ended, mid-track.
    $chatStarts = array_values(array_map(fn (array $piece): float => $piece['start'], array_filter($pieces, fn (array $piece): bool => $piece['fill'] === 2)));
    $latencies = [];
    foreach ([300.0, 1500.0, 2400.0] as $arrival) {
        $first = collect($chatStarts)->first(fn (float $start): bool => $start >= $arrival);
        $latencies[] = $first === null ? null : $first - $arrival;
    }

    $types = ['scene' => count(array_filter($pieces, fn (array $piece): bool => $piece['fill'] === 1)), 'chat' => count($chatStarts)];
    $share = $air['voiced'] / 3600;
    fwrite(STDERR, 'music first hour: '.json_encode(['voice_share' => round($share, 3), 'items' => $types, 'chat_latency_s' => $latencies, 'overdue_cut_ins' => $overdue])."\n");

    expect($without)->toBe([])
        ->and($overdue)->toBeLessThanOrEqual(1)
        ->and($share)->toBeLessThanOrEqual(0.40)
        ->and($share)->toBeGreaterThan(0.0)
        ->and($types['chat'])->toBe(4)
        ->and(max($latencies))->toBeLessThanOrEqual(60.0);
});

it('lets two voice items follow each other within seconds without music first (the control of the hour above)', function () {
    ['air' => $air, 'tracks' => $tracks] = musicFirstHour(false, [300.0, 310.0]);
    $pieces = $air['pieces'];
    $close = 0;

    for ($index = 1; $index < count($pieces); $index++) {
        $close += $pieces[$index]['start'] - $pieces[$index - 1]['end'] < 60 ? 1 : 0;
    }

    expect($close)->toBeGreaterThan(0);
});

it('writes no generic script while the workstation is online by day, and the same queue writes them while it is offline', function () {
    // The Bauernkrieg segment needs no model: the control that the same fill() writes a generic script while offline.
    config(['tv.commentary.ai' => false, 'tv.commentary.patterns.enabled' => true, 'tv.commentary.scripts.ahead_seconds' => 120, 'tv.commentary.history.enabled' => true,
        'tv.commentary.budget.enabled' => false, 'tv.commentary.music_first.enabled' => true, 'tv.commentary.music_first.live_only' => true]);
    $now = CarbonImmutable::now();

    expect(LiveOnly::active($now))->toBeFalse();
    app(ScriptQueue::class)->fill($now);
    $offline = CasterScript::query()->where('kind', CasterScript::HISTORY)->count();
    CasterScript::query()->delete();

    Cache::put(ClipSpool::FETCHER_SEEN, $now->getTimestamp(), 600);
    expect(LiveOnly::active($now))->toBeTrue();
    app(ScriptQueue::class)->fill($now);

    expect($offline)->toBeGreaterThan(0)
        ->and(CasterScript::query()->whereIn('kind', [CasterScript::SCENE, CasterScript::HISTORY, CasterScript::FOLLOWED, CasterScript::NEWS, CasterScript::TEASER])->count())->toBe(0);
});

it('keeps live only off in the quiet hours, in the autopilot and with its switch off', function () {
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $night = CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin');
    Cache::put(ClipSpool::FETCHER_SEEN, $night->getTimestamp(), 600);
    config(['tv.commentary.music_first.enabled' => true, 'tv.commentary.music_first.live_only' => true]);

    expect(LiveOnly::active($night))->toBeFalse();

    $day = CarbonImmutable::parse('2026-10-13 12:00', 'Europe/Berlin');
    Cache::put(ClipSpool::FETCHER_SEEN, $day->getTimestamp(), 600);
    expect(LiveOnly::active($day))->toBeTrue();

    // VoiceStudio down: the autopilot's stock and podcasts air, live only steps aside.
    AutoPilot::rememberVoice('down', $day);
    expect(LiveOnly::active($day))->toBeFalse();
    AutoPilot::rememberVoice('up', $day);

    config(['tv.commentary.music_first.live_only' => false]);
    expect(LiveOnly::active($day))->toBeFalse();
});

it('airs no stock block while live only holds, and the stock block again while the workstation is offline', function () {
    config(['tv.commentary.music_first.enabled' => true, 'tv.commentary.music_first.live_only' => true]);
    $now = CarbonImmutable::now();
    $stock = app(ClipSpool::class)->storeBlock([casterScript()], casterOpus(), 200.0, [10.0], [], 0.0, $now, stock: true);

    expect($stock->isStock())->toBeTrue()
        ->and(app(ClipSpool::class)->nextBlock($now)?->id)->toBe($stock->id);

    Cache::put(ClipSpool::FETCHER_SEEN, $now->getTimestamp(), 600);
    expect(app(ClipSpool::class)->nextBlock($now))->toBeNull();
});

it('flags a closing jab at the state or the banks only when the material is about none of them', function () {
    $jab = "Drüben fragt einer, ob Schach am Sonntag erlaubt ist.\nNatürlich, solange das Finanzamt nicht mitspielt.";

    expect(ScriptGuard::reflexJab($jab, 'Ist Schach am Sonntag erlaubt?'))->toBeTrue()
        ->and(ScriptGuard::reflexJab($jab, 'Was sagt das Finanzamt zu Turnierpreisen?'))->toBeFalse()
        ->and(ScriptGuard::reflexJab("Die EZB senkt den Zins.\nDas ist ein ganzer Satz ohne Spitze am Ende.", 'egal'))->toBeFalse()
        ->and(ScriptGuard::reflexJab("Erster Satz.\nUnd am Ende wieder die Zentralbank, wie immer.", 'Wer gewinnt das Turnier?'))->toBeTrue();
});

it('holds a live reaction for the end of the track again with live_anytime off', function () {
    config(['tv.commentary.chat.live_anytime' => false]);
    ['air' => $air] = musicFirstHour(true, [300.0]);
    $chat = collect($air['pieces'])->first(fn (array $piece): bool => $piece['fill'] === 2);

    expect($chat)->not->toBeNull()->and($chat['start'] - 300.0)->toBeGreaterThan(5.0);
});
