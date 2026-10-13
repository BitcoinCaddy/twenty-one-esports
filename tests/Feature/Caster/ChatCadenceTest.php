<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\AirLog;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ChatCadence;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\ReactionLines;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\YouTubeChatSummary;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE ESPORTS RADIO: a chat item every ten minutes
|--------------------------------------------------------------------------
|
| User 2026-10-13: "Die Reactions auf die Chats, auch die von YouTube …
| müssen alle 10 Minuten kommen, außer es läuft gerade eine fixe
| Show/Programm." When no chat item aired for ten minutes less the voicing
| lead and none is on its way, `caster:chat` queues one: a YouTube summary
| of what is new there, else a call-out to the quiet chat. Never on the
| news, a rubric's slot or in a show window, never in the autopilot.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.chat.reactions' => true,
        'tv.commentary.chat.cadence' => ['enabled' => true, 'every_minutes' => 10, 'lead_minutes' => 3],
        'tv.commentary.blocks.autopilot.enabled' => false,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.shows.enabled' => false,
    ]);
    // 12:05 in Berlin: talk (12:03 to 12:15).
    $this->travelTo(CarbonImmutable::parse('2026-10-13T10:05:00Z'));
    $this->summaries = [];
    $this->youtubeAnswers = 0;
    $test = $this;
    app()->instance(YouTubeChatSummary::class, new class(app(CasterVoice::class), app(ScriptQueue::class), app(VoiceUsage::class), $test) extends YouTubeChatSummary
    {
        public function __construct(CasterVoice $voice, ScriptQueue $queue, VoiceUsage $usage, private object $test)
        {
            parent::__construct($voice, $queue, $usage);
        }

        public function summarise(CarbonImmutable $now, bool $force = false): int
        {
            $this->test->summaries[] = $force;

            if ($this->test->youtubeAnswers > 0) {
                $this->test->youtubeAnswers--;
                CasterScript::query()->create(['kind' => CasterScript::CHAT, 'phase' => 'break', 'priority' => 50, 'text' => 'Drüben auf YouTube.', 'facts_hash' => 'y',
                    'status' => CasterScript::QUEUED, 'estimated_seconds' => 3, 'topic' => YouTubeChatSummary::TOPIC, 'expires_at' => $now->utc()->addHour()]);

                return 1;
            }

            return 0;
        }
    });
});

/** A chat item that left the air `$minutesAgo` minutes ago (AirLog). */
function chatCadenceAired(float $minutesAgo): void
{
    $end = CarbonImmutable::now()->getTimestamp() - 60 * $minutesAgo;
    AirLog::record(['clip' => 1, 'kind' => CasterScript::CHAT, 'block' => false, 'start' => $end - 20, 'end' => $end]);
}

it('queues a call-out to the quiet chat once no chat item aired for seven minutes and nothing new came in on YouTube', function () {
    chatCadenceAired(6.9);
    expect(app(ChatCadence::class)->tick(CarbonImmutable::now()))->toBeNull();

    // Other voice on air does not count: only a chat item does.
    AirLog::record(['clip' => 2, 'kind' => CasterScript::SCENE, 'block' => false, 'start' => CarbonImmutable::now()->getTimestamp() - 30, 'end' => CarbonImmutable::now()->getTimestamp() - 5]);
    $this->travelTo(CarbonImmutable::now()->addSeconds(6));

    expect(app(ChatCadence::class)->tick(CarbonImmutable::now()))->toBe('callout')
        ->and($this->summaries)->toBe([true]);

    $script = CasterScript::query()->sole();
    $lines = explode("\n", $script->text);
    $pools = app(ReactionLines::class);

    expect($script->kind)->toBe(CasterScript::CHAT)
        ->and($script->topic)->toBe(ChatCadence::CALLOUT_TOPIC)
        ->and($script->source)->toBe(CasterScript::SOURCE_PATTERN)
        ->and($script->priority)->toBe((int) config('tv.commentary.scripts.chat_priority', 50))
        ->and($lines)->toHaveCount(3)
        ->and($pools->pool('callout_open'))->toContain($lines[0])
        ->and($pools->pool('callout_roast'))->toContain($lines[1])
        ->and($pools->pool('callout_invite'))->toContain($lines[2]);
});

it('prefers a summary of whatever is new on YouTube, forced past its own cadence', function () {
    $this->youtubeAnswers = 1;

    expect(app(ChatCadence::class)->tick(CarbonImmutable::now()))->toBe('youtube')
        ->and($this->summaries)->toBe([true])
        ->and(CasterScript::query()->where('topic', ChatCadence::CALLOUT_TOPIC)->exists())->toBeFalse();
});

it('waits while a chat item is queued, being voiced, ready or on air', function () {
    $cadence = app(ChatCadence::class);
    $script = casterScript(CasterScript::CHAT);
    $script->update(['status' => CasterScript::QUEUED]);
    expect($cadence->tick(CarbonImmutable::now()))->toBeNull();

    $script->update(['status' => CasterScript::CLAIMED]);
    expect($cadence->tick(CarbonImmutable::now()))->toBeNull();

    $clip = app(ClipSpool::class)->store($script->fresh(), casterOpus(), 5.0, [5.0], CarbonImmutable::now());
    expect($cadence->tick(CarbonImmutable::now()))->toBeNull();

    $clip->update(['status' => CasterClip::PLAYING]);
    expect($cadence->tick(CarbonImmutable::now()))->toBeNull()
        ->and($this->summaries)->toBe([]);

    // Gone from the spool (it aired and the log lost it): the cadence goes on.
    $clip->delete();
    expect($cadence->tick(CarbonImmutable::now()))->toBe('callout');
});

it('queues nothing on the news, in a show window, with the news ahead, or in the autopilot', function (string $utc, array $config) {
    config($config);
    $this->travelTo(CarbonImmutable::parse($utc));

    expect(app(ChatCadence::class)->tick(CarbonImmutable::now()))->toBeNull()
        ->and(CasterScript::query()->count())->toBe(0)
        ->and($this->summaries)->toBe([]);
})->with([
    'news at 12:01' => ['2026-10-13T10:01:00Z', []],
    'headlines at 12:31' => ['2026-10-13T10:31:00Z', []],
    'airing would fall on the headlines at 12:30' => ['2026-10-13T10:28:00Z', []],
    'show window 12:03 to 12:33' => ['2026-10-13T10:05:00Z', ['tv.commentary.programme.shows.enabled' => true, 'tv.commentary.programme.shows.schedule' => ['12:03' => 'stammtisch']]],
    'autopilot' => ['2026-10-13T10:05:00Z', ['tv.commentary.blocks.autopilot.enabled' => true, 'tv.commentary.blocks.stock.enabled' => true, 'tv.commentary.blocks.stock.library.enabled' => true, 'tv.commentary.blocks.enabled' => true]],
    'cadence off' => ['2026-10-13T10:05:00Z', ['tv.commentary.chat.cadence.enabled' => false]],
]);

it('keeps a chat item on air every ten minutes over a quiet hour, the rubrics included, the news not', function () {
    // 12:03 in Berlin: talk, rubric 12:15-12:20, headlines 12:30-12:33, rubric 12:45-12:50, news at 13:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-13T10:03:00Z'));
    $start = CarbonImmutable::now();
    $cadence = app(ChatCadence::class);
    $aired = [];

    for ($minute = 0; $minute <= 60; $minute++) {
        $now = $start->addMinutes($minute);
        $this->travelTo($now);

        // A queued item is voiced and aired three minutes after it was queued (the lead).
        foreach (CasterScript::query()->where('status', CasterScript::QUEUED)->get() as $script) {
            if ($now->getTimestamp() - $script->created_at->getTimestamp() >= 180) {
                $script->update(['status' => CasterScript::VOICED]);
                AirLog::record(['clip' => $script->id, 'kind' => CasterScript::CHAT, 'block' => false, 'start' => $now->getTimestamp() - 20, 'end' => $now->getTimestamp()]);
                $aired[] = $minute;
            }
        }

        $cadence->tick($now);
    }

    // The first one three minutes in (the lead), then every ten minutes: 12:16 in the rubric, 12:36 right after the
    // headlines, which it never airs into (queued at 12:33, as they end).
    expect($aired)->toBe([3, 13, 23, 33, 43, 53]);
});

it('calls the quiet chat out at most once per seven minutes when the air log is lost, and repeats no line soon', function () {
    $cadence = app(ChatCadence::class);

    expect($cadence->tick(CarbonImmutable::now()))->toBe('callout');
    // The call-out expired unvoiced and the air log is gone: still no second one within seven minutes.
    CasterScript::query()->update(['status' => CasterScript::EXPIRED]);
    $this->travelTo(CarbonImmutable::now()->addMinutes(6));
    expect($cadence->tick(CarbonImmutable::now()))->toBeNull();
    $this->travelTo(CarbonImmutable::now()->addMinutes(2));
    expect($cadence->tick(CarbonImmutable::now()))->toBe('callout');

    // Twelve call-outs in a row: no opener twice.
    $openers = [];
    for ($i = 0; $i < 12; $i++) {
        $openers[] = explode("\n", app(ReactionLines::class)->callOut())[0];
    }
    expect(array_unique($openers))->toHaveCount(12);
});

it('keeps every call-out line speakable: one short sentence, no names, no fees, no address, no face', function () {
    $lines = app(ReactionLines::class);

    foreach (['callout_open', 'callout_roast', 'callout_invite'] as $pool) {
        expect(count($lines->pool($pool)))->toBeGreaterThanOrEqual(12);

        foreach ($lines->pool($pool) as $line) {
            expect((new ScriptGuard)->check($line, 1, 1, 32))->toBe([], $line)
                ->and(mb_strtolower($line))->not->toMatch('/gebühr|\bfees?\b|gesicht|@|#|lnurl|\{/u');
        }
    }

    expect((new ScriptGuard)->check($lines->callOut(), 3, 3, 32))->toBe([]);
});

it('keeps the cadence from the minute schedule of caster:chat', function () {
    $this->artisan('caster:chat')->expectsOutputToContain('Chat cadence: queued a callout chat item.')->assertSuccessful();

    expect(CasterScript::query()->where('topic', ChatCadence::CALLOUT_TOPIC)->count())->toBe(1);
});
