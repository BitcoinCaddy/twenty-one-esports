<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ChatReactions;
use App\Support\Caster\ChatWishes;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: a reaction waits at most max_hold_seconds
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Die Reactions werden zu lange unterdrückt. Maximal 10
| Minuten, dann dürfen wieder Reactions reinkommen." A chat reaction that
| has been ready for `tv.commentary.chat.max_hold_seconds` cuts in at the
| next sentence end of whatever is on air (a talk, a rubric, a block, the
| parts of a story), never inside a bulletin's news, and the programme it
| interrupted resumes from that sentence afterwards.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10, 'tv.commentary.repeat_hours' => 0]);
});

/** The sample rate of these simulations: low, so a quarter of an hour of air stays small. */
const REACTION_HOLD_RATE = 100;

/** Fake Opus whose decode (reactionHoldDecodes()) gives `$seconds` of the sample value `$fill`. */
function reactionHoldOpus(float $seconds, int $fill): string
{
    return casterOpus().sprintf('LEN:%.3F;FILL:%d;', $seconds, $fill);
}

function reactionHoldDecodes(): void
{
    Process::fake(function ($process) {
        preg_match('/LEN:([\d.]+);FILL:(\d+);/', (string) $process->input, $match);

        return Process::result(output: str_repeat(pack('v', (int) ($match[2] ?? 9)), (int) round(REACTION_HOLD_RATE * (float) ($match[1] ?? 1))));
    });
}

/** A claimed script of `$kind`, living an hour unless `$expires` says otherwise. */
function reactionHoldScript(string $kind, ?CarbonImmutable $airsAt = null, ?string $group = null, ?int $index = null, ?CarbonImmutable $expires = null): CasterScript
{
    $script = casterScript($kind);
    $script->update([
        'airs_at' => $airsAt?->utc(), 'part_group' => $group, 'part_index' => $index,
        'expires_at' => ($expires ?? CarbonImmutable::now()->addHour())->utc(),
    ]);

    return $script->fresh();
}

/** A spooled clip of `$seconds` for `$script`, its sentences ending every five seconds. */
function reactionHoldClip(CasterScript $script, float $seconds, int $fill): CasterClip
{
    return app(ClipSpool::class)->store($script, reactionHoldOpus($seconds, $fill), $seconds, range(5.0, $seconds, 5.0), CarbonImmutable::now());
}

/** A story of `count($fills)` parts of `$seconds` each; the first part airs at `$airsAt`. */
function reactionHoldStory(string $kind, array $fills, float $seconds, ?CarbonImmutable $airsAt = null): void
{
    $group = (string) Str::ulid();

    foreach (array_values($fills) as $position => $fill) {
        reactionHoldClip(reactionHoldScript($kind, $position === 0 ? $airsAt : null, $group, $position + 1), $seconds, $fill);
    }
}

function reactionHoldDirector(float $maxHold = 600): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(REACTION_HOLD_RATE), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'long_max_hold_seconds' => ['scene' => 135, 'news' => 160, 'history' => 290, 'bulletin' => 430, 'rubric' => 140],
        'feed_lead_seconds' => 1.0, 'gap_seconds' => ['min' => 8, 'max' => 8], 'bulletin_late_seconds' => 120, 'rubric_late_seconds' => 240,
        'parts' => ['breath_seconds' => 0.0, 'wait_seconds' => 120],
        'blocks' => ['join_seconds' => ['min' => 0.4, 'max' => 0.4], 'reaction_wait_seconds' => 90, 'hold_seconds' => 30, 'resume_within_seconds' => 600],
        'reaction_max_hold_seconds' => $maxHold,
    ]);
}

/**
 * Runs the director like the stream does (a poll every second with the clock moved along, the feed written one
 * second ahead every quarter second) and returns what went on air, run-length encoded by sample value.
 *
 * @param  array<int, Closure(): void>  $events  seconds from the start => what happens then
 * @return list<array{value: int, seconds: float, from: float}>
 */
function reactionHoldAir(SceneDirector $director, float $start, float $seconds, array $events = []): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $next = (int) round($start * REACTION_HOLD_RATE);
    $runs = [];
    $position = 0;

    for ($step = 0; $step <= $seconds * 4; $step++) {
        $t = $start + $step * 0.25;

        if ($step % 4 === 0) {
            test()->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
            ($events[intdiv($step, 4)] ?? fn () => null)();
            $director->tick($planner, $planner->at($t, []), $t);
        }

        $target = (int) round(($t + 1.0) * REACTION_HOLD_RATE);

        foreach (unpack('v*', $director->timeline->samples($next, $target)) ?: [] as $value) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['value'] === $value) {
                $runs[$last]['seconds'] += 1 / REACTION_HOLD_RATE;
            } else {
                $runs[] = ['value' => $value, 'seconds' => 1 / REACTION_HOLD_RATE, 'from' => $position / REACTION_HOLD_RATE];
            }

            $position++;
        }

        $next = $target;
    }

    return array_map(fn (array $run): array => ['value' => $run['value'], 'seconds' => round($run['seconds'], 2), 'from' => round($run['from'], 2)], $runs);
}

function reactionHoldSecondsOf(array $runs, int $value): float
{
    return round(array_sum(array_map(fn (array $run): float => $run['value'] === $value ? $run['seconds'] : 0.0, $runs)), 1);
}

/** @return list<int> the sample values on air in order, silence left out */
function reactionHoldOrder(array $runs): array
{
    return array_values(array_map(fn (array $run): int => $run['value'], array_filter($runs, fn (array $run): bool => $run['value'] !== 0)));
}

it('lets a reaction in after max_hold_seconds in the worst case: bulletin, a six-part news talk and a rubric in a row', function () {
    reactionHoldDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(2));
    reactionHoldClip(reactionHoldScript(CasterScript::BULLETIN, $slot, expires: $slot->addSeconds(120)), 180.0, 7);
    reactionHoldStory(CasterScript::RUBRIC, [11, 12, 13, 14, 15, 16], 90.0, $slot->addSeconds(170));
    reactionHoldStory(CasterScript::RUBRIC, [21, 22], 70.0, $slot->addSeconds(700));

    $runs = reactionHoldAir(reactionHoldDirector(), (float) $slot->subSeconds(2)->getTimestamp(), 1100, [
        7 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 20.0, 5),
    ]);
    $latency = collect($runs)->firstWhere('value', 5)['from'] - 7.0;
    $cutPart = collect([11, 12, 13, 14, 15, 16])->first(fn (int $fill): bool => collect($runs)->where('value', $fill)->count() === 2);

    // Before the cap the reaction waited for all of it: 874 s in this simulation.
    expect($latency)->toBeGreaterThanOrEqual(600.0)->toBeLessThanOrEqual(610.0)
        ->and($cutPart)->not->toBeNull()
        // Cut at a sentence end (every 5 s), and the part resumes from there: not a second of the programme is lost.
        ->and(fmod(collect($runs)->firstWhere('value', $cutPart)['seconds'], 5.0))->toBe(0.0)
        ->and(collect([7, 11, 12, 13, 14, 15, 16])->map(fn (int $fill): float => reactionHoldSecondsOf($runs, $fill))->all())->toBe([180.0, 90.0, 90.0, 90.0, 90.0, 90.0, 90.0])
        ->and(reactionHoldSecondsOf($runs, 21) + reactionHoldSecondsOf($runs, 22))->toBe(140.0)
        ->and(array_slice(reactionHoldOrder($runs), 0, 9))->toBe([7, 11, 12, 13, 14, $cutPart, 5, $cutPart, 16]);
});

it('never cuts into a bulletin\'s news for an overdue reaction: it follows the bulletin', function () {
    reactionHoldDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(2));
    reactionHoldClip(reactionHoldScript(CasterScript::BULLETIN, $slot, expires: $slot->addSeconds(120)), 180.0, 7);

    $runs = reactionHoldAir(reactionHoldDirector(maxHold: 30), (float) $slot->subSeconds(2)->getTimestamp(), 220, [
        5 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 10.0, 5),
    ]);

    expect(reactionHoldOrder($runs))->toBe([7, 5])
        ->and(collect($runs)->firstWhere('value', 7)['seconds'])->toBe(180.0);
});

it('cuts a news block for an overdue reaction only after its protected news, and the talk resumes', function () {
    reactionHoldDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(2));
    $lead = reactionHoldScript(CasterScript::BULLETIN, $slot, expires: $slot->addSeconds(120));
    app(ClipSpool::class)->storeBlock([$lead, reactionHoldScript(CasterScript::RUBRIC)], reactionHoldOpus(240.0, 7), 240.0, range(5.0, 240.0, 5.0), [], 120.0, CarbonImmutable::now());

    $runs = reactionHoldAir(reactionHoldDirector(maxHold: 20), (float) $slot->subSeconds(2)->getTimestamp(), 280, [
        5 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 10.0, 5),
    ]);

    expect(reactionHoldOrder($runs))->toBe([7, 5, 7])
        ->and(collect($runs)->firstWhere('value', 7)['seconds'])->toBeGreaterThanOrEqual(120.0)->toBeLessThanOrEqual(130.0)
        ->and(reactionHoldSecondsOf($runs, 7))->toBe(240.0);
});

it('cuts a talk block with no boundary in sight at the next sentence end once the reaction is overdue', function () {
    reactionHoldDecodes();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:10', 'Europe/Berlin'));
    app(ClipSpool::class)->storeBlock([reactionHoldScript(CasterScript::SCENE), reactionHoldScript(CasterScript::SCENE)], reactionHoldOpus(200.0, 1), 200.0, range(5.0, 200.0, 5.0), [], 0.0, CarbonImmutable::now());

    $runs = reactionHoldAir(reactionHoldDirector(maxHold: 20), (float) CarbonImmutable::now()->getTimestamp(), 240, [
        2 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 10.0, 5),
    ]);
    $chat = collect($runs)->firstWhere('value', 5);

    // Without the cap the block waited reaction_wait_seconds (90) for a boundary.
    expect($chat['from'] - 2.0)->toBeGreaterThanOrEqual(20.0)->toBeLessThanOrEqual(27.0)
        ->and(reactionHoldOrder($runs))->toBe([1, 5, 1])
        ->and(reactionHoldSecondsOf($runs, 1))->toBe(200.0);
});

it('resumes what an overdue reaction cut before the block a bulletin cut earlier: the last cut first', function () {
    reactionHoldDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(20));
    app(ClipSpool::class)->storeBlock([reactionHoldScript(CasterScript::SCENE), reactionHoldScript(CasterScript::SCENE)], reactionHoldOpus(120.0, 1), 120.0, range(5.0, 120.0, 5.0), [], 0.0, CarbonImmutable::now());
    reactionHoldClip(reactionHoldScript(CasterScript::BULLETIN, $slot, expires: $slot->addSeconds(120)), 30.0, 7);
    reactionHoldClip(reactionHoldScript(CasterScript::RUBRIC, $slot->addSeconds(30)), 60.0, 11);

    $runs = reactionHoldAir(reactionHoldDirector(maxHold: 10), (float) $slot->subSeconds(20)->getTimestamp(), 260, [
        56 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 5.0, 5),
    ]);

    expect(reactionHoldOrder($runs))->toBe([1, 7, 11, 5, 11, 1])
        ->and(reactionHoldSecondsOf($runs, 1))->toBe(120.0)
        ->and(reactionHoldSecondsOf($runs, 11))->toBe(60.0);
});

it('plays an overdue reaction before the news talk that is due when the bulletin ends', function () {
    reactionHoldDecodes();
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(2));
    reactionHoldClip(reactionHoldScript(CasterScript::BULLETIN, $slot, expires: $slot->addSeconds(120)), 30.0, 7);
    reactionHoldClip(reactionHoldScript(CasterScript::RUBRIC, $slot->addSeconds(25)), 60.0, 11);

    $runs = reactionHoldAir(reactionHoldDirector(maxHold: 10), (float) $slot->subSeconds(2)->getTimestamp(), 120, [
        5 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 5.0, 5),
    ]);

    expect(reactionHoldOrder($runs))->toBe([7, 5, 11]);
});

it('plays an overdue reaction before the next part of a story it may not cut (a wished one)', function () {
    reactionHoldDecodes();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:10', 'Europe/Berlin'));
    $group = (string) Str::ulid();
    foreach ([31, 32] as $position => $fill) {
        $script = reactionHoldScript(CasterScript::HISTORY, group: $group, index: $position + 1);
        $script->update(['scene' => ChatWishes::SCENE]);
        reactionHoldClip($script->fresh(), 30.0, $fill);
    }

    $runs = reactionHoldAir(reactionHoldDirector(maxHold: 10), (float) CarbonImmutable::now()->getTimestamp(), 90, [
        3 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 5.0, 5),
    ]);

    expect(reactionHoldOrder($runs))->toBe([31, 5, 32]);
});

it('keeps a reaction that is not overdue out of a single clip, as before', function () {
    reactionHoldDecodes();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:10', 'Europe/Berlin'));
    reactionHoldClip(reactionHoldScript(CasterScript::SCENE), 120.0, 11);

    $runs = reactionHoldAir(reactionHoldDirector(maxHold: 600), (float) CarbonImmutable::now()->getTimestamp(), 150, [
        3 => fn () => reactionHoldClip(reactionHoldScript(CasterScript::CHAT), 5.0, 5),
    ]);

    expect(reactionHoldOrder($runs))->toBe([11, 5]);
});

it('never lets a chat reply, a wish or a zap thank-you expire before max_hold_seconds plus their voicing', function () {
    config(['tv.commentary.scripts.max_age_minutes' => 5, 'tv.commentary.chat.wishes_max_age_minutes' => 5, 'tv.commentary.patterns.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:10', 'Europe/Berlin'));
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);

    $chat = $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, 'Danke für die Frage im Chat, Alice.', 'h1', 50, $now);
    $wish = $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, 'Der Bauernkrieg begann im Jahr fünfzehnhundertvierundzwanzig.', 'h2', 50, $now, scene: ChatWishes::SCENE, maxAgeMinutes: 5);
    $zap = $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, 'Danke für den Zap, Bob, das wärmt.', 'h3', 100, $now, topic: ChatReactions::ZAP_TOPIC);
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Gerade läuft eine ruhige Runde im Turnier.', 'h4', 0, $now);

    expect($chat->expires_at->getTimestamp() - $now->getTimestamp())->toBe(1800)
        ->and($wish->expires_at->getTimestamp() - $now->getTimestamp())->toBe(1800)
        ->and($zap->expires_at->getTimestamp() - $now->getTimestamp())->toBe(1800)
        ->and($scene->expires_at->getTimestamp() - $now->getTimestamp())->toBe(300);
});
