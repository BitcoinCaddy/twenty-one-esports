<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\InvalidClip;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\SpoolFull;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the server's audio spool (plan "KI-Stimme der Liga", P6)
|--------------------------------------------------------------------------
|
| Strictly bounded (user 2026-10-11: no storage problem on the server):
| uploads over a cap are refused, a clip is deleted right after it played,
| expired and orphan clips are pruned. next() is the stream's fallback
| chain: live clip, clip of the phase, filler below a minute, text lower
| third while the workstation is off.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true]);
});

function casterSpool(): ClipSpool
{
    return app(ClipSpool::class);
}

it('stores an Opus clip on disk and marks its script voiced', function () {
    $script = casterScript();

    $clip = casterSpool()->store($script, casterOpus(), 12.5, [3.2, 7.8, 12.5], CarbonImmutable::now());

    Storage::disk('local')->assertExists($clip->path);
    expect($clip->bytes)->toBe(4000)
        ->and($clip->sentence_ends)->toBe([3.2, 7.8, 12.5])
        ->and($script->fresh()->status)->toBe(CasterScript::VOICED)
        ->and(casterSpool()->readySeconds())->toBe(12.5);
});

it('refuses an upload that is not an Opus clip or over the per-clip limits', function (string $body, float $seconds) {
    expect(fn () => casterSpool()->store(casterScript(), $body, $seconds, [], CarbonImmutable::now()))->toThrow(InvalidClip::class);
})->with([
    'not ogg' => ['RIFF....WAVEfmt ', 5.0],
    'too big' => [casterOpus(3 * 1024 * 1024), 5.0],
    'too long' => [casterOpus(), 120.0],
    'empty' => [casterOpus(), 0.0],
]);

it('refuses a clip once the spool holds its byte cap', function () {
    config(['tv.commentary.spool.max_bytes' => 10_000]);
    casterSpool()->store(casterScript(), casterOpus(6000), 5, [], CarbonImmutable::now());

    expect(fn () => casterSpool()->store(casterScript(), casterOpus(6000), 5, [], CarbonImmutable::now()))->toThrow(SpoolFull::class)
        ->and(CasterClip::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('refuses a clip once the spool holds its seconds cap', function () {
    config(['tv.commentary.spool.max_seconds' => 100]);
    casterSpool()->store(casterScript(), casterOpus(), 80, [], CarbonImmutable::now());

    expect(fn () => casterSpool()->store(casterScript(), casterOpus(), 30, [], CarbonImmutable::now()))->toThrow(SpoolFull::class);
});

it('deletes a clip and its file right after it played, but keeps a filler for its next turn', function () {
    $clip = casterSpool()->store(casterScript(), casterOpus(), 5, [], CarbonImmutable::now());
    $filler = casterSpool()->store(casterScript(CasterScript::FILLER), casterOpus(), 2, [], CarbonImmutable::now());

    casterSpool()->played($clip);
    casterSpool()->played($filler);

    Storage::disk('local')->assertMissing($clip->path);
    Storage::disk('local')->assertExists($filler->path);
    expect(CasterClip::query()->pluck('id')->all())->toBe([$filler->id]);
});

it('prunes expired clips, clips of expired scripts, stuck clips and orphans', function () {
    $now = CarbonImmutable::now();
    $expired = casterSpool()->store(casterScript(), casterOpus(), 5, [], $now);
    DB::table('caster_clips')->where('id', $expired->id)->update(['expires_at' => $now->subMinute()]);
    $ofExpiredScript = casterSpool()->store($script = casterScript(), casterOpus(), 5, [], $now);
    $script->update(['status' => CasterScript::EXPIRED]);
    $stuck = casterSpool()->store(casterScript(), casterOpus(), 5, [], $now);
    DB::table('caster_clips')->where('id', $stuck->id)->update(['status' => CasterClip::PLAYING, 'updated_at' => $now->subMinutes(11)]);
    $noFile = casterSpool()->store(casterScript(), casterOpus(), 5, [], $now);
    Storage::disk('local')->delete($noFile->path);
    Storage::disk('local')->put('caster/spool/orphan.opus', casterOpus());
    $kept = casterSpool()->store(casterScript(), casterOpus(), 5, [], $now);

    $counts = casterSpool()->prune($now);

    expect($counts)->toBe(['expired' => 2, 'stale' => 1, 'orphan_files' => 1, 'orphan_rows' => 1])
        ->and(CasterClip::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(Storage::disk('local')->allFiles())->toBe([$kept->path]);
});

it('plays a live clip first, then a clip of the slot\'s phase', function () {
    config(['tv.commentary.spool.filler_below_seconds' => 10]);
    $now = CarbonImmutable::now();
    $scene = casterSpool()->store(casterScript(CasterScript::SCENE, ScenePhase::LIVE_MATCH), casterOpus(), 30, [], $now);
    $live = casterSpool()->store(casterScript(CasterScript::LIVE, ScenePhase::AFTER_RESULT), casterOpus(), 5, [], $now);

    expect(casterSpool()->next(ScenePhase::LIVE_MATCH, $now)['clip']?->id)->toBe($live->id);

    casterSpool()->played($live);

    expect(casterSpool()->next(ScenePhase::LIVE_MATCH, $now)['clip']?->id)->toBe($scene->id)
        // A match clip never plays over a break: wrong context is worse than music.
        ->and(casterSpool()->next(ScenePhase::BREAK, $now)['clip'])->toBeNull();
});

it('plays the clip about the slide on air first, and any clip of the phase without one', function () {
    config(['tv.commentary.spool.filler_below_seconds' => 10]);
    $now = CarbonImmutable::now();
    $league = casterScript();
    $league->update(['topic' => SceneTopic::LEAGUE]);
    $ladder = casterScript();
    $ladder->update(['topic' => SceneTopic::LADDERS]);
    $first = casterSpool()->store($league, casterOpus(), 30, [], $now);
    $ladderClip = casterSpool()->store($ladder, casterOpus(), 30, [], $now);

    expect(casterSpool()->next(ScenePhase::BREAK, $now, SceneTopic::LADDERS)['clip']?->id)->toBe($ladderClip->id)
        ->and(casterSpool()->next(ScenePhase::BREAK, $now, SceneTopic::TMNF)['clip']?->id)->toBe($first->id)
        ->and(casterSpool()->next(ScenePhase::BREAK, $now)['clip']?->id)->toBe($first->id)
        // The topic never pulls a clip out of its phase.
        ->and(casterSpool()->next(ScenePhase::LIVE_MATCH, $now, SceneTopic::LADDERS)['clip'])->toBeNull();
});

it('lets the calm phases stand in for each other', function () {
    config(['tv.commentary.spool.filler_below_seconds' => 10]);
    $now = CarbonImmutable::now();
    $break = casterSpool()->store(casterScript(CasterScript::SCENE, ScenePhase::BREAK), casterOpus(), 30, [], $now);

    expect(casterSpool()->next(ScenePhase::PRE_SHOW, $now)['clip']?->id)->toBe($break->id);
});

it('plays fillers in turn while less than a minute of commentary is ready', function () {
    $now = CarbonImmutable::now();
    casterSpool()->store(casterScript(CasterScript::SCENE, ScenePhase::BREAK), casterOpus(), 30, [], $now);
    $first = casterSpool()->store(casterScript(CasterScript::FILLER), casterOpus(), 2, [], $now);
    $second = casterSpool()->store(casterScript(CasterScript::FILLER), casterOpus(), 2, [], $now);
    DB::table('caster_clips')->where('id', $second->id)->update(['updated_at' => $now->addSecond()]);

    $decision = casterSpool()->next(ScenePhase::BREAK, $now);
    expect($decision['source'])->toBe('filler')->and($decision['clip']?->id)->toBe($first->id);

    $this->travel(5)->seconds();
    casterSpool()->played($decision['clip']);

    expect(casterSpool()->next(ScenePhase::BREAK, $now)['clip']?->id)->toBe($second->id);
});

it('shows the script as a lower third while the workstation is off, and nothing while it is on', function () {
    $now = CarbonImmutable::now();
    CasterScript::query()->create([
        'kind' => CasterScript::SCENE, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => "Hey.\nHier ist die Liga.\nDritter Satz.",
        'facts_hash' => 'h', 'status' => CasterScript::QUEUED, 'estimated_seconds' => 3, 'expires_at' => $now->addMinutes(10),
    ]);

    expect(casterSpool()->next(ScenePhase::BREAK, $now))->toBe(['clip' => null, 'caption' => 'Hey. Hier ist die Liga.', 'source' => 'caption']);

    Cache::put(ClipSpool::FETCHER_SEEN, $now->getTimestamp(), 600);

    expect(casterSpool()->next(ScenePhase::BREAK, $now))->toBe(['clip' => null, 'caption' => null, 'source' => 'none']);
});
