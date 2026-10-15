<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ChatWishes;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpoolFull;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: long scripts in parts
|--------------------------------------------------------------------------
|
| Live 2026-10-11: a chatter wished for the Bauernkrieg, the reply played,
| then 2.5 minutes of silence before the 27-line episode, because the
| workstation voiced the whole script before uploading one clip ("er wollte
| was über Bauernkriege sagen und hat dann einfach aufgehört"). A long
| script is queued in parts of at most `parts.sentences` sentences, voiced
| in order, and played back to back with only a breath between them.
|
*/

beforeEach(function () {
    Storage::fake('local');
    // The part mechanics alone: the numbered test lines are near copies for the no-repeat memory (SpokenMemory).
    config(['tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10, 'tv.commentary.parts.sentences' => 5, 'tv.commentary.repeat_hours' => 0]);
});

/** A script of `$count` numbered sentences, one per line. */
function casterPartText(int $count, string $word = 'Satz'): string
{
    return implode("\n", array_map(fn (int $number): string => "{$word} Nummer {$number} ist hier.", range(1, $count)));
}

/**
 * The parts of a group in order.
 *
 * @return list<CasterScript>
 */
function casterPartsOf(CasterScript $first): array
{
    return CasterScript::query()->where('part_group', $first->part_group)->orderBy('part_index')->get()->all();
}

/** Spools a clip of `$seconds` for a queued script (claimed and stored as the fetcher would); decodes are faked to that length. */
function casterPartClip(CasterScript $script, float $seconds = 10.0): CasterClip
{
    $script->update(['status' => CasterScript::CLAIMED, 'claimed_at' => now()]);
    $clip = app(ClipSpool::class)->store($script->refresh(), casterOpus(), $seconds, range(5.0, $seconds, 5.0), CarbonImmutable::now());
    Process::fake(['*' => Process::result(output: str_repeat("\1\0", (int) (24000 * $seconds)))]);

    return $clip;
}

function casterPartDirector(float $wait = 20.0): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 5, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'gap_seconds' => ['min' => 25.0, 'max' => 25.0],
        'parts' => ['breath_seconds' => 0.0, 'wait_seconds' => $wait],
    ]);
}

/**
 * Runs the director for `$seconds` and returns when each script's clip started (seconds from the start), in order.
 *
 * @param  (Closure(float): void)|null  $at  called before every tick with the elapsed seconds
 * @return list<array{script: int, start: float}>
 */
function casterPartStarts(SceneDirector $director, float $seconds, ?Closure $at = null): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $starts = [];
    $playing = null;

    for ($t = $start; $t <= $start + $seconds; $t += 0.25) {
        if ($at !== null) {
            $at($t - $start);
        }

        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id !== $playing) {
            $playing = $director->clip()?->id;
            if ($director->clip() !== null) {
                $starts[] = ['script' => $director->clip()->caster_script_id, 'start' => $t - $start];
            }
        }
    }

    return $starts;
}

it('splits a long script between sentences into parts of at most parts.sentences, as even as possible', function (int $lines, int $perPart, array $sizes) {
    $parts = ScriptQueue::parts(casterPartText($lines), $perPart);

    expect(array_map(fn (string $part): int => count(explode("\n", $part)), $parts))->toBe($sizes)
        // Never mid-sentence: the parts put together are the script, line for line.
        ->and(implode("\n", $parts))->toBe(casterPartText($lines));
})->with([
    'the live episode' => [27, 5, [5, 5, 5, 4, 4, 4]],
    'just over' => [6, 5, [3, 3]],
    'at the limit' => [5, 5, [5]],
    'short' => [2, 5, [2]],
    'off' => [27, 0, [27]],
    'a long scene' => [14, 5, [5, 5, 4]],
]);

it('queues a long segment, scene or wished segment as parts with one group, one expiry and the same priority', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);

    $history = $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, "[laughter] Intro.\n".casterPartText(11), 'history:x', 0, $now, source: CasterScript::SOURCE_PATTERN, maxAgeMinutes: 120);
    $parts = casterPartsOf($history);

    expect($history->part_index)->toBe(1)
        ->and($parts)->toHaveCount(3)
        ->and(array_map(fn (CasterScript $part): int => (int) $part->part_index, $parts))->toBe([1, 2, 3])
        ->and(array_unique(array_map(fn (CasterScript $part): string => $part->kind.$part->phase.$part->facts_hash.$part->priority.$part->source.$part->expires_at->toIso8601String(), $parts)))->toHaveCount(1)
        ->and($parts[0]->sentences()[0])->toBe('[laughter] Intro.')
        // Nothing expires unused any more (user 2026-10-15): the group shares one far-future expiry instead of
        // the max age that was passed.
        ->and($parts[0]->expires_at->getTimestamp())->toBeGreaterThan($now->addYears(9)->getTimestamp())
        // Each part is estimated on its own text, so the airtime of the group is the airtime of the script.
        ->and($parts[0]->estimated_seconds)->toBe(ScriptQueue::estimateSeconds($parts[0]->text));

    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(8), 'h', 0, $now, topic: 'ladders');
    $wish = $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, casterPartText(7), 'wish:joke', 100, $now, scene: ChatWishes::SCENE);

    expect(casterPartsOf($scene))->toHaveCount(2)
        ->and(collect(casterPartsOf($scene))->pluck('topic')->unique()->all())->toBe(['ladders'])
        ->and(collect(casterPartsOf($wish))->pluck('scene')->unique()->all())->toBe([ChatWishes::SCENE])
        ->and(collect(casterPartsOf($wish))->pluck('priority')->unique()->all())->toBe([100]);
});

it('keeps a chat reply, a live moment, a filler and a short script in one piece', function (string $kind, int $lines) {
    $script = app(ScriptQueue::class)->enqueue($kind, ScenePhase::BREAK, casterPartText($lines), 'h', 50, CarbonImmutable::now());

    expect($script->part_group)->toBeNull()
        ->and($script->part_index)->toBeNull()
        ->and(CasterScript::query()->count())->toBe(1);
})->with([
    'chat reply' => [CasterScript::CHAT, 8],
    'live moment' => [CasterScript::LIVE, 8],
    'filler' => [CasterScript::FILLER, 8],
    'short history' => [CasterScript::HISTORY, 5],
]);

it('hands the parts to the fetcher in order, a later part only after the one before it left the queue', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $first = $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(12), 'history:x', 0, $now);
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(3), 'h', 0, $now);
    [, $second, $third] = casterPartsOf($first);

    expect($queue->claim($now)->id)->toBe($first->id);

    // VoiceStudio failed: part one goes back, and it is handed out again before part two.
    $queue->release($first->refresh());
    expect($queue->claim($now)->id)->toBe($first->id);

    casterPartClip($first->refresh());
    expect($queue->claim($now)->id)->toBe($second->id);
    casterPartClip($second->refresh());

    // The scene queued after the long segment waits behind all of its parts.
    expect($queue->claim($now)->id)->toBe($third->id)
        ->and($queue->claim($now)->id)->toBe($scene->id);
});

it('never hands out a later part while the part before it waits, also while the spool is full', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $first = $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(12), 'history:x', 0, $now);
    [, $second] = casterPartsOf($first);

    // Full: live kinds only. A scheduled segment's first part waits, and so do its later parts.
    expect($queue->claim($now, ClipSpool::LIVE_KINDS))->toBeNull();

    // Once part one is spooled, the later parts travel with the live kinds: the story on air must not stall.
    casterPartClip($queue->claim($now));
    expect($queue->claim($now, ClipSpool::LIVE_KINDS)?->id)->toBe($second->id);
});

it('never refuses a later part of a long scene for the airtime cap, which still holds back a first part', function () {
    config(['tv.commentary.spool.max_seconds' => 15, 'tv.commentary.spool.max_bytes' => 10_000_000, 'tv.commentary.spool.max_clip_bytes' => 1_000_000]);
    $now = CarbonImmutable::now();
    $first = app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(8), 'h', 0, $now);
    [, $second] = casterPartsOf($first);
    casterPartClip($first->refresh(), 10.0);
    $other = app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(2), 'h2', 0, $now);

    expect(casterPartClip($second->refresh(), 10.0)->status)->toBe(CasterClip::READY)
        ->and(fn () => casterPartClip($other->refresh(), 10.0))->toThrow(SpoolFull::class);
});

it('plays the parts back to back with only a breath, ignoring segment spacing, before any other scene — no gap while a clip waits (user 2026-10-15)', function () {
    $now = CarbonImmutable::now();
    $first = app(ScriptQueue::class)->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(12), 'history:x', 0, $now);
    $parts = casterPartsOf($first);
    $scene = app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(3), 'h', 0, $now);
    foreach ([...$parts, $scene] as $script) {
        casterPartClip($script->refresh());
    }

    $starts = casterPartStarts(casterPartDirector(), 90);
    $pauses = array_map(fn (array $next, array $previous): float => round($next['start'] - $previous['start'] - 10.0, 2), array_slice($starts, 1), array_slice($starts, 0, -1));

    // Part one opens the segment; parts two and three follow after the 0.5 s lead-in, although part one set
    // the segment spacing of five minutes; the scene follows right after the last part (user 2026-10-15: no
    // music gap while a clip waits — the gap itself stays proven with an empty spool in SceneDirectorTest).
    expect(array_column($starts, 'script'))->toBe([...array_map(fn (CasterScript $part): int => $part->id, $parts), $scene->id])
        ->and($pauses)->each->toBeLessThanOrEqual(1.0)
        ->and(Cache::get(ClipSpool::SEGMENT_PLAYED))->toBeInt()
        ->and(CasterClip::query()->count())->toBe(0);
});

it('plays a wish: the reply, then every part of the wished segment back to back, before the scenes', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(3), 'h', 0, $now);
    $reply = $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, 'Gerne, Ben, hier kommt der Bauernkrieg.', 'chat', 100, $now);
    $first = $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(14), 'history:x', 100, $now, scene: ChatWishes::SCENE);
    $parts = casterPartsOf($first);

    // The fetcher's order: the reply, then the parts; the scene behind them.
    $claimed = [];
    while (($script = $queue->claim($now)) !== null) {
        $claimed[] = $script->id;
        casterPartClip($script);
    }
    expect($claimed)->toBe([$reply->id, ...array_map(fn (CasterScript $part): int => $part->id, $parts), $scene->id]);

    $starts = casterPartStarts(casterPartDirector(), 100);
    $pauses = array_map(fn (array $next, array $previous): float => round($next['start'] - $previous['start'] - 10.0, 2), array_slice($starts, 1), array_slice($starts, 0, -1));

    // The scene follows the last part right away (user 2026-10-15: with a clip waiting there is no music gap).
    expect(array_column($starts, 'script'))->toBe($claimed)
        ->and($pauses)->each->toBeLessThanOrEqual(1.0);
});

it('lets a reaction cut in between two parts, and the next part follows it after a breath', function () {
    $now = CarbonImmutable::now();
    $first = app(ScriptQueue::class)->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(10), 'history:x', 0, $now);
    [, $second] = casterPartsOf($first);
    casterPartClip($first->refresh());
    $reaction = null;

    $starts = casterPartStarts(casterPartDirector(), 60, function (float $elapsed) use (&$reaction, $second): void {
        // While part one is on air the reaction is spooled; part two only later (the workstation still voicing it).
        if (abs($elapsed - 4.0) < 0.01) {
            $reaction = casterPartClip(casterScript(CasterScript::CHAT));
        }
        if (abs($elapsed - 14.0) < 0.01) {
            casterPartClip($second->refresh());
        }
    });

    expect(array_column($starts, 'script'))->toBe([$first->id, $reaction->caster_script_id, $second->id])
        ->and($starts[1]['start'] - $starts[0]['start'] - 10.0)->toBeLessThanOrEqual(1.0)
        ->and($starts[2]['start'] - $starts[1]['start'] - 10.0)->toBeLessThanOrEqual(1.0);
});

it('ends the group when a later part expired: the rest never plays, and the scene follows at once (user 2026-10-15)', function () {
    $now = CarbonImmutable::now();
    $first = app(ScriptQueue::class)->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(12), 'history:x', 0, $now);
    [, $second, $third] = casterPartsOf($first);
    $scene = app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(3), 'h', 0, $now);
    foreach ([$first, $third, $scene] as $script) {
        casterPartClip($script->refresh());
    }
    $second->update(['status' => CasterScript::EXPIRED]);

    $starts = casterPartStarts(casterPartDirector(), 60);

    expect(array_column($starts, 'script'))->toBe([$first->id, $scene->id])
        // The waiting scene follows the ended group after a breath, not after a music gap (user 2026-10-15).
        ->and($starts[1]['start'] - $starts[0]['start'] - 10.0)->toBeLessThanOrEqual(1.5)
        // Part three's clip left the spool with its group, it never waits for a story that is over.
        ->and(CasterClip::query()->where('caster_script_id', $third->id)->exists())->toBeFalse()
        ->and($third->refresh()->status)->toBe(CasterScript::EXPIRED);
});

it('waits wait_seconds for a part still being voiced, then ends the group and the scenes return', function () {
    $now = CarbonImmutable::now();
    $first = app(ScriptQueue::class)->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(12), 'history:x', 0, $now);
    [, $second] = casterPartsOf($first);
    $scene = app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(3), 'h', 0, $now);
    casterPartClip($first->refresh());
    casterPartClip($scene->refresh());

    $starts = casterPartStarts(casterPartDirector(wait: 8.0), 60);
    $after = $starts[1]['start'] - $starts[0]['start'] - 10.0;

    // Part two never came: the scene starts once the wait is over (no music gap on top), and the group is gone.
    expect(array_column($starts, 'script'))->toBe([$first->id, $scene->id])
        ->and($after)->toBeGreaterThanOrEqual(8.0)->toBeLessThan(9.0)
        ->and($second->refresh()->status)->toBe(CasterScript::EXPIRED);
});

it('expires the parts together: one part expired takes the unvoiced rest of its group along, a shared deadline the whole group', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $first = $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(12), 'history:x', 0, $now);
    $other = $queue->enqueue(CasterScript::NEWS, ScenePhase::BREAK, casterPartText(12), 'news:1', 0, $now->addMinute(), expiresAt: $now->addMinutes(30));
    [, $second, $third] = casterPartsOf($first);

    // Part two expired (superseded, or a claim that ran out): the unvoiced parts one and three go with it.
    $second->update(['status' => CasterScript::EXPIRED]);
    expect($queue->expire($now))->toBe(2)
        ->and($first->refresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($third->refresh()->status)->toBe(CasterScript::EXPIRED)
        ->and(collect(casterPartsOf($other))->pluck('status')->unique()->all())->toBe([CasterScript::QUEUED]);

    // No max age any more (user 2026-10-15), but the explicit deadline of the group still takes it as a whole:
    // none of it lingers past its slot.
    expect($queue->expire($now->addMinutes(32)))->toBe(3)
        ->and(collect(casterPartsOf($other))->pluck('status')->unique()->all())->toBe([CasterScript::EXPIRED]);
});

it('never starts a story mid-way: a later part whose part before it is not on air is never picked on its own', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $wish = $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, casterPartText(10), 'history:x', 100, $now, scene: ChatWishes::SCENE);
    $segment = $queue->enqueue(CasterScript::NEWS, ScenePhase::BREAK, casterPartText(10), 'news:1', 0, $now);
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, casterPartText(3), 'h', 0, $now);
    // Part one of each played before a restart of the stream (the director forgot the group); part two waits in the spool.
    casterPartClip(casterPartsOf($wish)[1]->refresh());
    casterPartClip(casterPartsOf($segment)[1]->refresh());
    casterPartClip($scene->refresh());
    $spool = app(ClipSpool::class);

    expect($spool->nextLive($now))->toBeNull()
        ->and($spool->nextSegment($now))->toBeNull()
        ->and($spool->next(ScenePhase::BREAK, $now)['clip']?->caster_script_id)->toBe($scene->id);
});
