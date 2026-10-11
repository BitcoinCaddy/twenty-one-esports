<?php

use App\Models\CasterScript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the commentary script queue (plan "KI-Stimme der Liga", P6)
|--------------------------------------------------------------------------
|
| With the laravel/ai fake, never the network. Scripts are written ahead to
| the airtime target, live moments jump the queue, stale scripts expire
| (age or superseded facts), and every script passes the spoken-text guard
| (sentences, words, markers, no stage directions) on top of the league
| voice's own checks.
|
*/

beforeEach(function () {
    config(['tv.commentary.enabled' => true]);
});

/**
 * Facts the queue's tests write for: no live games, no results, no tournaments.
 */
function casterQuietFacts(): SceneFacts
{
    return new class(app(TournamentSlides::class)) extends SceneFacts
    {
        protected function league(): array
        {
            return ['players' => 21, 'clans' => 3];
        }

        protected function liveGames(): array
        {
            return [];
        }

        protected function results(): array
        {
            return [];
        }

        protected function openTournaments(): array
        {
            return [];
        }
    };
}

function casterQueue(): ScriptQueue
{
    return new ScriptQueue(app(CasterVoice::class), casterQuietFacts());
}

/** A script the guards accept: short spoken German, one sentence per line. */
const CASTER_GOOD_SCRIPT = "Hey, hier ist TWENTY ONE TV.\nDie Liga hat 21 Spieler.\nKlar, da geht noch mehr.\nSchaut einfach mal rein.";

it('writes scene scripts ahead until the airtime target, at most per run', function () {
    config(['tv.commentary.scripts.ahead_seconds' => 15, 'tv.commentary.scripts.per_run' => 4]);
    VoiceAgent::fake([
        ['text' => CASTER_GOOD_SCRIPT, 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => "Läuft bei der Liga.\nEinundzwanzig Leute sind dabei.\nUnd ihr?\nRein da.", 'canon_id' => null, 'mood' => 'call'],
    ]);

    $written = casterQueue()->fill(CarbonImmutable::now());

    expect($written)->toBe(2)
        ->and(CasterScript::query()->where('kind', CasterScript::SCENE)->count())->toBe(2)
        ->and(casterQueue()->airtimeAhead())->toBeGreaterThanOrEqual(15.0);
    VoiceAgent::assertPromptedTimes(2);
});

it('writes nothing and asks nobody while the commentary is off', function () {
    config(['tv.commentary.enabled' => false]);
    VoiceAgent::fake();

    expect(casterQueue()->fill(CarbonImmutable::now()))->toBe(0)
        ->and(CasterScript::query()->count())->toBe(0);
    VoiceAgent::assertNeverPrompted();
});

it('hands out live moments before scene scripts, then the oldest first', function () {
    $now = CarbonImmutable::now();
    $queue = casterQueue();
    $first = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Eins.', 'h', 0, $now);
    $second = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Zwei.', 'h', 0, $now);
    $live = $queue->enqueue(CasterScript::LIVE, ScenePhase::AFTER_RESULT, 'Sieg!', 'h', 100, $now);
    $chat = $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, 'Hey!', 'h', 50, $now);

    expect($queue->claim($now)?->id)->toBe($live->id)
        ->and($queue->claim($now)?->id)->toBe($chat->id)
        ->and($queue->claim($now)?->id)->toBe($first->id)
        ->and($queue->claim($now)?->id)->toBe($second->id)
        ->and($queue->claim($now))->toBeNull();
});

it('expires a script after ten minutes unused', function () {
    $now = CarbonImmutable::now();
    $queue = casterQueue();
    $script = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Alt.', 'h', 0, $now);

    expect($queue->expire($now->addMinutes(9)))->toBe(0)
        ->and($queue->expire($now->addMinutes(10)))->toBe(1)
        ->and($script->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($queue->claim($now->addMinutes(10)))->toBeNull();
});

it('expires scene scripts written for facts that changed', function () {
    $now = CarbonImmutable::now();
    $queue = casterQueue();
    $old = $queue->enqueue(CasterScript::SCENE, ScenePhase::LIVE_MATCH, 'Alt.', 'old', 0, $now);
    $other = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Pause.', 'old', 0, $now);

    expect($queue->supersede(ScenePhase::LIVE_MATCH, 'new'))->toBe(1)
        ->and($old->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($other->fresh()->status)->toBe(CasterScript::QUEUED);
});

it('frees a claim that never came back', function () {
    $now = CarbonImmutable::now();
    $queue = casterQueue();
    $script = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Eins.', 'h', 0, $now);

    expect($queue->claim($now)?->id)->toBe($script->id)
        ->and($queue->claim($now->addSeconds(60)))->toBeNull()
        ->and($queue->claim($now->addSeconds(181))?->id)->toBe($script->id);
});

it('deletes rows that left the queue a day ago', function () {
    $now = CarbonImmutable::now();
    $queue = casterQueue();
    $script = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Eins.', 'h', 0, $now);
    DB::table('caster_scripts')->where('id', $script->id)->update(['status' => CasterScript::EXPIRED, 'updated_at' => $now->subHours(25)]);

    $queue->expire($now);

    expect(CasterScript::query()->count())->toBe(0);
});

it('puts a live moment at the front of the queue', function () {
    $now = CarbonImmutable::now();
    VoiceAgent::fake([['text' => "Wahnsinn!\nDie Liga hat 21 Spieler.", 'canon_id' => null, 'mood' => 'hype']]);
    $queue = casterQueue();
    $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Vorher.', 'h', 0, $now);

    $live = $queue->pushLive(CasterScript::LIVE, ScenePhase::AFTER_RESULT, ['players' => 21], 100, $now);

    expect($live)->not->toBeNull()
        ->and($queue->claim($now)?->id)->toBe($live?->id);
});

it('seeds every filler once and never expires it', function () {
    $now = CarbonImmutable::now();
    $queue = casterQueue();

    $seeded = $queue->seedFillers($now);

    expect($seeded)->toBeGreaterThan(3)
        ->and($queue->seedFillers($now))->toBe(0)
        ->and($queue->expire($now->addDays(30)))->toBe(0);
});

it('rejects scripts that break the spoken-text rules', function (string $text, string $reason) {
    config(['tv.commentary.scripts.ahead_seconds' => 5, 'tv.commentary.scripts.per_run' => 1]);
    VoiceAgent::fake([['text' => $text, 'canon_id' => null, 'mood' => 'fakten']]);

    expect(casterQueue()->fill(CarbonImmutable::now()))->toBe(0)
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->pluck('reason')->all())->toContain($reason);
})->with([
    'unknown marker' => ["Hey, hier ist TWENTY ONE TV.\n[applause] Die Liga hat 21 Spieler.\nSchaut rein.", ScriptGuard::MARKER_UNKNOWN],
    'stage direction' => ["Hey, hier ist TWENTY ONE TV.\nDie Liga hat 21 Spieler.\nKlar, da geht noch mehr (lacht).", ScriptGuard::STAGE_DIRECTION],
    'too few sentences' => ['Hey, hier ist TWENTY ONE TV.', ScriptGuard::SENTENCE_COUNT],
    'long sentence' => ["Hey, hier ist TWENTY ONE TV.\nDie Liga hat 21 Spieler und alle spielen jeden Tag sehr gerne mit ihren Freunden zusammen.\nSchaut rein.", ScriptGuard::SENTENCE_LONG],
    'invented number' => ["Hey, hier ist TWENTY ONE TV.\nDie Liga hat 500 Spieler.\nSchaut rein.", 'number'],
    'stock phrase' => ["Hey, hier ist TWENTY ONE TV.\nDarüber hinaus hat die Liga 21 Spieler.\nSchaut rein.", ScriptGuard::STOCK_PHRASE],
]);

it('lets the whitelisted markers through and counts no marker as a word', function () {
    $guard = new ScriptGuard;

    expect($guard->check("Hey!\n[laughter] Na also.\nLäuft.", 3, 8, 12))->toBe([])
        ->and(ScriptGuard::withoutMarkers('[surprise-oh] Boah. [sigh]'))->toBe('Boah.')
        ->and($guard->check("Eins.\nZwei.\nDrei.", 3, 8, 12, [ScriptGuard::opening('Eins zwei drei.')]))->toContain(ScriptGuard::OPENING_REPEAT);
});

it('estimates airtime from the words and the pauses', function () {
    config(['tv.commentary.scripts.words_per_second' => 2.0]);

    expect(ScriptQueue::estimateSeconds("Eins zwei drei vier.\n[laughter] Fünf sechs."))->toBe(3.4);
});
