<?php

use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\VoiceBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the server writes only what the workstation can voice
|--------------------------------------------------------------------------
|
| Prod 2026-10-13, three hours at 64 OmniVoice steps (about real time): 27
| scenes, 15 news, 15 rubric parts and a bulletin expired unvoiced, and no
| stock was rendered at all. The voicing budget (VoiceBudget) measures the
| throughput from the uploads, writes within throughput × 3600 × (1 −
| stock_share) an hour in strict priority, writes nothing that would expire
| before it is voiced, never holds back a bulletin, and gives the GPU to the
| stock once the hour's fresh talk is voiced.
|
*/

const VOICE_BUDGET_TOKEN = 'voice-budget-token-0123456789abcdef01234567';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.fetch.token' => VOICE_BUDGET_TOKEN,
        'tv.commentary.fetch.claim_seconds' => 600,
        'tv.commentary.programme.bulletin.ready_seconds' => 60,
        'tv.commentary.blocks.enabled' => false,
        'tv.commentary.podcast_replay.enabled' => false,
        'tv.commentary.programme.enabled' => false,
        'tv.commentary.chat.reactions' => false,
        'tv.commentary.budget' => [
            'enabled' => true, 'stock_share' => 0.3, 'window_minutes' => 60, 'measure_minutes' => 180, 'min_busy_seconds' => 900,
            'keep_hours' => 24, 'default_rtf' => 0.8, 'reserve_minutes' => 30, 'margin_seconds' => 60,
            'expected_seconds' => ['bulletin' => 120, 'news' => 150, 'rubric' => 150, 'chat' => 60, 'segment' => 60, 'scene' => 40],
            'chat_per_hour' => 6, 'scene_cap' => ['per_hour' => 6, 'tight_share' => 0.6],
        ],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 14:10', 'Europe/Berlin'));
});

/** A word of letters only for `$n` (SpokenMemory compares letters, so digits make no sentence new). */
function voiceBudgetWord(int $n): string
{
    return strtr(base_convert((string) ($n + 20000), 10, 26), '0123456789abcdefghijklmnop', 'abcdefghijklmnopqrstuvwxyz');
}

/** A sentence of `$words` words nobody said before (SpokenMemory also counts shared words). */
function voiceBudgetSentence(int $n, int $words = 7): string
{
    return ucfirst(implode(' ', array_map(fn (int $k): string => voiceBudgetWord($n * 20 + $k), range(0, $words - 1)))).'.';
}

/** Two sentences nobody said before; `$long`: four of twenty words, about 35 estimated seconds like a scene on prod. */
function voiceBudgetText(int $n, bool $long = false): string
{
    return $long
        ? implode("\n", array_map(fn (int $k): string => voiceBudgetSentence(4 * $n + $k, 20), range(0, 3)))
        : voiceBudgetSentence(2 * $n)."\n".voiceBudgetSentence(2 * $n + 1);
}

/**
 * Voiced history: `$count` uploads, the last `$endsAgo` seconds ago and the others before it, each claimed `$wall` seconds before its
 * upload with `$seconds` of estimated airtime.
 */
function voiceBudgetHistory(int $count, float $seconds, int $wall, string $kind = CasterScript::SCENE, ?string $factsHash = null, int $endsAgo = 60): void
{
    $now = CarbonImmutable::now();

    for ($i = 0; $i < $count; $i++) {
        $upload = $now->subSeconds($endsAgo + $i * ($wall + 30));
        $script = CasterScript::query()->create([
            'kind' => $kind, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => 'Gesprochen.', 'facts_hash' => $factsHash ?? 'history-'.$i,
            'status' => CasterScript::VOICED, 'source' => CasterScript::SOURCE_AI, 'estimated_seconds' => $seconds,
            'claimed_at' => $upload->subSeconds($wall), 'expires_at' => $now->addHour(),
        ]);
        DB::table('caster_scripts')->where('id', $script->id)->update(['created_at' => $upload->subSeconds($wall)->utc(), 'updated_at' => $upload->utc()]);
    }
}

/** @param  array<string, string>  $headers */
function voiceBudgetClaim(string $uri = '/api/caster/claim'): TestResponse
{
    $server = collect(['Authorization' => 'Bearer '.VOICE_BUDGET_TOKEN, AutoPilot::HEADER => 'up'])
        ->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all();

    return test()->call('POST', $uri, [], [], [], $server);
}

function voiceBudgetQueued(string $kind, int $priority, ?string $topic = null, array $extra = []): CasterScript
{
    return CasterScript::query()->create([
        'kind' => $kind, 'phase' => ScenePhase::BREAK, 'priority' => $priority, 'topic' => $topic, 'text' => 'Ein Satz.', 'facts_hash' => $kind.':'.($topic ?? '-'),
        'status' => CasterScript::QUEUED, 'source' => CasterScript::SOURCE_AI, 'estimated_seconds' => 30, 'expires_at' => CarbonImmutable::now()->addHour(), ...$extra,
    ]);
}

it('counts overlapping voicings once: busy time is the union of claim to upload', function () {
    $measured = VoiceBudget::measure([
        ['seconds' => 200, 'from' => 0, 'to' => 300],
        // A chat reply voiced inside the block's render.
        ['seconds' => 30, 'from' => 100, 'to' => 140],
        ['seconds' => 60, 'from' => 400, 'to' => 460],
        // A stand-in that "ended" before it started counts nothing.
        ['seconds' => 500, 'from' => 600, 'to' => 600],
    ]);

    expect($measured)->toBe(['voiced' => 290.0, 'busy' => 360.0]);
});

it('measures the throughput from the uploads, leaves stand-ins out and keeps the value for a quiet window', function () {
    $budget = app(VoiceBudget::class);

    expect($budget->throughput(CarbonImmutable::now()))->toMatchArray(['rtf' => 0.8, 'source' => 'default']);

    // Six uploads, 150 s of airtime each in 300 s: half real time. A stock block stand-in (uploaded in the same second
    // it was "claimed") and a replay stand-in would make it look a hundred times faster.
    voiceBudgetHistory(6, 150, 300);
    voiceBudgetHistory(1, 3000, 1, CasterScript::SCENE, 'stock');
    voiceBudgetHistory(1, 3000, 1, CasterScript::REPLAY, 'replay');

    $measured = app(VoiceBudget::class)->throughput(CarbonImmutable::now());
    expect($measured['source'])->toBe('measured')
        ->and($measured['rtf'])->toBe(0.5)
        ->and($measured['busy_seconds'])->toBe(1800.0);

    // Hours later nothing was voiced: the last measured value stands, not the default.
    $this->travel(4)->hours();
    expect(app(VoiceBudget::class)->throughput(CarbonImmutable::now()))->toMatchArray(['rtf' => 0.5, 'source' => 'kept']);
});

it('reads a kept throughput the way Redis hands it back', function (mixed $stored, ?float $read) {
    expect(VoiceBudget::kept($stored))->toBe($read);
})->with([
    'string with timestamp' => ['0.5000|1791886109', 0.5],
    'numeric string' => ['1.25', 1.25],
    'float' => [0.75, 0.75],
    'garbage' => ['fast', null],
    'zero' => ['0|123', null],
    'missing' => [null, null],
]);

it('budgets throughput × 3600 × (1 − stock share) seconds of fresh talk an hour', function () {
    voiceBudgetHistory(6, 300, 300);
    $budget = app(VoiceBudget::class);

    expect($budget->hourBudget(CarbonImmutable::now()))->toBe(2520.0);

    config(['tv.commentary.budget.stock_share' => 0.5]);
    expect(app(VoiceBudget::class)->hourBudget(CarbonImmutable::now()))->toBe(1800.0);
});

it('ranks every kind the same in SQL as in PHP', function () {
    $rows = [
        voiceBudgetQueued(CasterScript::SCENE, 0),
        voiceBudgetQueued(CasterScript::FILLER, 0),
        voiceBudgetQueued(CasterScript::SCENE, 0, 'world_callback'),
        voiceBudgetQueued(CasterScript::HISTORY, 0),
        voiceBudgetQueued(CasterScript::FOLLOWED, 0),
        voiceBudgetQueued(CasterScript::TEASER, 72, 'rubric'),
        voiceBudgetQueued(CasterScript::RUBRIC, 70, 'liga'),
        voiceBudgetQueued(CasterScript::RUBRIC, 68, 'programmvorschau'),
        voiceBudgetQueued(CasterScript::NEWS, 0, 'bitcoin'),
        voiceBudgetQueued(CasterScript::RUBRIC, 70, NewsTalk::TOPIC),
        voiceBudgetQueued(CasterScript::RUBRIC, 70, SportWeather::TOPIC),
        voiceBudgetQueued(CasterScript::CHAT, 50),
        voiceBudgetQueued(CasterScript::LIVE, 100),
        voiceBudgetQueued(CasterScript::SCENE, 0, null, ['scene' => 'chat_wish']),
        voiceBudgetQueued(CasterScript::RUBRIC, 70, Shows::TRIBUNAL),
        voiceBudgetQueued(CasterScript::RUBRIC, 70, Shows::STAMMTISCH),
        voiceBudgetQueued(CasterScript::PODCAST_INTRO, 80),
        voiceBudgetQueued(CasterScript::BULLETIN, 75, 'news'),
    ];
    [$sql, $bindings] = VoiceBudget::rankSql();
    $ranks = CasterScript::query()->selectRaw("id, ({$sql}) as tier_rank", $bindings)->pluck('tier_rank', 'id');

    foreach ($rows as $row) {
        expect((int) $ranks[$row->id])->toBe(VoiceBudget::rank(VoiceBudget::tierOf($row->kind, $row->topic, $row->scene)), "{$row->kind}/{$row->topic}");
    }
});

it('hands scripts to the workstation in strict priority: bulletin, show, replies, news talk, rubric, segment, scene', function () {
    // Inserted lowest tier first, with the priorities the writers give them.
    $scene = voiceBudgetQueued(CasterScript::SCENE, 0);
    $history = voiceBudgetQueued(CasterScript::HISTORY, 0);
    $rubric = voiceBudgetQueued(CasterScript::RUBRIC, 70, 'liga');
    $newsTalk = voiceBudgetQueued(CasterScript::RUBRIC, 70, NewsTalk::TOPIC);
    $chat = voiceBudgetQueued(CasterScript::CHAT, 50);
    $live = voiceBudgetQueued(CasterScript::LIVE, 100);
    $show = voiceBudgetQueued(CasterScript::RUBRIC, 70, Shows::TRIBUNAL);
    $bulletin = voiceBudgetQueued(CasterScript::BULLETIN, 75, 'news');
    $queue = app(ScriptQueue::class);
    $order = [];

    while (($script = $queue->claim(CarbonImmutable::now())) !== null) {
        $order[] = $script->id;
    }

    expect($order)->toBe([$bulletin->id, $show->id, $live->id, $chat->id, $newsTalk->id, $rubric->id, $history->id, $scene->id]);
});

it('writes no scene the workstation would voice only after it expired, but always the bulletin', function () {
    // A slow workstation: a quarter of real time, measured before this hour (630 s of budget, none of it used).
    voiceBudgetHistory(6, 75, 300, endsAgo: 3700);
    $queue = app(ScriptQueue::class);
    $now = CarbonImmutable::now();

    // Half an hour of rubrics waits ahead of any scene; a scene lives ten minutes.
    voiceBudgetQueued(CasterScript::RUBRIC, 70, 'liga', ['estimated_seconds' => 450]);
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, voiceBudgetText(1), 'scene', 0, $now, maxAgeMinutes: 10);

    $slot = $now->addMinutes(20);
    $bulletin = $queue->enqueue(CasterScript::BULLETIN, ScenePhase::BREAK, 'Es ist halb drei, die Nachrichten. Der Kurs steigt.', 'bulletin:'.$slot->getTimestamp(), 75, $now,
        topic: 'news', airsAt: $slot, expiresAt: $slot->addSeconds(120));

    expect($scene)->toBeNull()
        ->and($bulletin)->not->toBeNull()
        ->and(CasterScript::query()->where('kind', CasterScript::SCENE)->where('status', CasterScript::QUEUED)->count())->toBe(0);

    // With the rubrics gone, the scene fits in time again.
    CasterScript::query()->where('kind', CasterScript::RUBRIC)->delete();
    expect($queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, voiceBudgetText(2), 'scene', 0, $now, maxAgeMinutes: 10))->not->toBeNull();
});

it('lets everything else wait while a bulletin might miss its slot', function () {
    voiceBudgetHistory(6, 150, 300);
    $queue = app(ScriptQueue::class);
    $now = CarbonImmutable::now();
    $budget = app(VoiceBudget::class);

    expect($budget->opens(VoiceBudget::CHAT, $now))->toBeTrue();

    // 120 s of bulletin at half real time take four minutes; its slot is three minutes away (ready a minute before).
    $slot = $now->addMinutes(3);
    $queue->enqueue(CasterScript::BULLETIN, ScenePhase::BREAK, 'Es ist halb drei, die Nachrichten. Der Kurs steigt heute deutlich.', 'bulletin:'.$slot->getTimestamp(), 75, $now,
        topic: 'news', airsAt: $slot, expiresAt: $slot->addSeconds(120));
    CasterScript::query()->where('kind', CasterScript::BULLETIN)->update(['estimated_seconds' => 120]);

    $budget = app(VoiceBudget::class);
    expect($budget->bulletinAtRisk($now))->toBeTrue()
        ->and($budget->opens(VoiceBudget::CHAT, $now))->toBeFalse()
        ->and($budget->opens(VoiceBudget::SCENE, $now))->toBeFalse()
        ->and($budget->opens(VoiceBudget::BULLETIN, $now))->toBeTrue();
});

it('keeps the hour within the budget and the higher tiers their reserve', function () {
    // Real time: 2520 s of fresh talk an hour, voiced before this hour.
    voiceBudgetHistory(6, 300, 300, endsAgo: 3700);
    $now = CarbonImmutable::now();
    $budget = app(VoiceBudget::class);

    // Written this hour, ten minutes ago: 2400 s.
    $written = voiceBudgetQueued(CasterScript::SCENE, 0, null, ['estimated_seconds' => 2400, 'status' => CasterScript::VOICED]);
    DB::table('caster_scripts')->where('id', $written->id)->update(['created_at' => $now->subMinutes(10)->utc()]);

    expect($budget->opens(VoiceBudget::SCENE, $now, 100))->toBeTrue()
        ->and($budget->opens(VoiceBudget::SCENE, $now, 130))->toBeFalse();

    // The chat cadence (6 × 60 s an hour, 180 s in the next half hour) is kept free for the tier above the scenes.
    config(['tv.commentary.chat.reactions' => true]);
    $budget = app(VoiceBudget::class);
    expect($budget->reserve(VoiceBudget::SCENE, $now))->toBe(180.0)
        ->and($budget->reserve(VoiceBudget::CHAT, $now))->toBe(0.0)
        ->and($budget->opens(VoiceBudget::SCENE, $now, 100))->toBeFalse()
        ->and($budget->opens(VoiceBudget::CHAT, $now, 100))->toBeTrue();
});

it('caps the slide scenes at six an hour while the budget is tight', function () {
    // A tenth of real time: 252 s an hour, and the chat cadence alone asks 360 s.
    voiceBudgetHistory(6, 30, 300);
    config(['tv.commentary.chat.reactions' => true, 'tv.commentary.budget.reserve_minutes' => 0, 'tv.commentary.budget.stock_share' => 0.0,
        'tv.commentary.budget.default_rtf' => 0.1]);
    $now = CarbonImmutable::now();

    for ($i = 0; $i < 6; $i++) {
        voiceBudgetQueued(CasterScript::SCENE, 0, null, ['estimated_seconds' => 1, 'status' => CasterScript::VOICED]);
    }

    $budget = app(VoiceBudget::class);
    expect($budget->tight($now))->toBeTrue()
        ->and($budget->opens(VoiceBudget::SCENE, $now, 1))->toBeFalse()
        ->and($budget->opens(VoiceBudget::SEGMENT, $now, 1))->toBeTrue();

    config(['tv.commentary.chat.reactions' => false]);
    expect(app(VoiceBudget::class)->tight($now))->toBeFalse()
        ->and(app(VoiceBudget::class)->opens(VoiceBudget::SCENE, $now, 1))->toBeTrue();
});

it('gives the GPU to the stock once the hour\'s fresh talk is voiced, but still hands out the bulletin', function () {
    // Real time: 2520 s an hour; 2700 s of fresh talk voiced in the last hour.
    voiceBudgetHistory(9, 300, 300);
    $scene = voiceBudgetQueued(CasterScript::SCENE, 0);

    $claim = voiceBudgetClaim()->assertOk();
    expect($claim->json('script'))->toBeNull()
        ->and($claim->json('budget.stock_turn'))->toBeTrue()
        ->and($claim->json('budget.rtf'))->toBe(1)
        ->and($claim->json('budget.hour_budget'))->toBe(2520);

    $bulletin = voiceBudgetQueued(CasterScript::BULLETIN, 75, 'news', ['airs_at' => CarbonImmutable::now()->addMinutes(10)]);
    expect(voiceBudgetClaim()->json('script.id'))->toBe($bulletin->id)
        ->and($scene->fresh()->status)->toBe(CasterScript::QUEUED);

    // Off, the scene comes as before.
    config(['tv.commentary.budget.enabled' => false]);
    expect(voiceBudgetClaim()->json('script.id'))->toBe($scene->id);
});

/**
 * `$minutes` (two hours by default) of a slow workstation (`$rtf` of real time) against a writer that wants a scene every half minute, a chat
 * reply every ten minutes and a bulletin at :00 and :30: the fetcher claims over the API, voices each script in its
 * estimated seconds / `$rtf`, uploads it the moment it is done and claims the next. Returns what happened and the
 * GPU seconds spent on fresh talk.
 *
 * @return array{bulletins: array<string, int>, scenes: array<string, int>, busy: float, seconds: int}
 */
function voiceBudgetSimulate(float $rtf, int $minutes = 120): array
{
    $queue = app(ScriptQueue::class);
    $start = CarbonImmutable::now();
    $end = $start->getTimestamp() + 60 * $minutes;
    [$current, $finishAt, $busy, $n, $tick, $step] = [null, 0, 0.0, 0, $start->getTimestamp(), 0];

    while (($now = CarbonImmutable::now())->getTimestamp() < $end) {
        if ($now->getTimestamp() >= $tick) {
            // The writer: a bulletin seven minutes before :00 and :30, a chat reply every ten minutes, a scene every tick.
            $minute = (int) $now->setTimezone('Europe/Berlin')->format('i');

            if (in_array($minute, [23, 53], true) && (int) $now->format('s') < 30) {
                $slot = $now->addMinutes(7)->startOfMinute();
                $queue->enqueue(CasterScript::BULLETIN, ScenePhase::BREAK, voiceBudgetText(1000 + $n++, long: true), 'bulletin:'.$slot->getTimestamp(), 75, $now,
                    topic: 'news', airsAt: $slot, expiresAt: $slot->addSeconds(120));
            }

            if ($step++ % 20 === 0) {
                $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, voiceBudgetSentence(5000 + $n++), 'chat', 50, $now);
            }

            if (app(VoiceBudget::class)->opens(VoiceBudget::SCENE, $now, deadline: $now->addMinutes(10))) {
                $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, voiceBudgetText($n++, long: true), 'scene', 0, $now, maxAgeMinutes: 10);
            }

            $tick += 30;
        }

        // The fetcher: uploads what it finished, then claims the next script.
        if ($current !== null && $now->getTimestamp() >= $finishAt) {
            if ($current->fresh()->status === CasterScript::CLAIMED) {
                $current->update(['status' => CasterScript::VOICED]);
            }

            $current = null;
        }

        if ($current === null) {
            $id = voiceBudgetClaim()->json('script.id');
            $current = $id === null ? null : CasterScript::query()->find($id);
            $finishAt = $current === null ? 0 : $now->getTimestamp() + (int) ceil($current->estimated_seconds / $rtf);
            $busy += $current === null ? 0 : min($end, $finishAt) - $now->getTimestamp();
        }

        $queue->expire($now);
        test()->travelTo(CarbonImmutable::createFromTimestamp(max($now->getTimestamp() + 1, min($tick, $current === null ? $tick : $finishAt))));
    }

    $count = fn (string $kind): array => CasterScript::query()->where('kind', $kind)->where('created_at', '>=', $start->utc())
        ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn (mixed $n): int => (int) $n)->all();

    return ['bulletins' => $count(CasterScript::BULLETIN), 'scenes' => $count(CasterScript::SCENE), 'busy' => (float) $busy, 'seconds' => $minutes * 60];
}

it('loses no bulletin and lets no scene expire on a slow workstation, and leaves the stock its share of the GPU', function () {
    // Measured before: half real time. The clock's bulletins count in the reserve (written here by the simulation).
    voiceBudgetHistory(6, 150, 300);
    config(['tv.commentary.chat.reactions' => true, 'tv.commentary.programme.enabled' => true, 'tv.commentary.news.enabled' => true, 'tv.commentary.ai' => true,
        'tv.commentary.budget.expected_seconds.bulletin' => 40]);

    // Without the budget the same run let 148 of 180 scenes expire and kept the GPU busy all the time (5400 of 5400 s).
    $run = voiceBudgetSimulate(0.5, 90);

    expect($run['bulletins'][CasterScript::EXPIRED] ?? 0)->toBe(0)
        ->and($run['bulletins'][CasterScript::VOICED] ?? 0)->toBe(3)
        ->and($run['scenes'][CasterScript::EXPIRED] ?? 0)->toBe(0)
        ->and($run['scenes'][CasterScript::VOICED] ?? 0)->toBeGreaterThanOrEqual(20)
        // The GPU voiced fresh talk at most 70 % of the time (plus one script's tail): the rest was the stock's.
        ->and($run['busy'] / $run['seconds'])->toBeLessThanOrEqual(0.75);
});
