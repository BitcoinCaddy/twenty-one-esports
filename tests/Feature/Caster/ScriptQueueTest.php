<?php

use App\Models\CasterScript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\LengthMix;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;

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
 * Facts the queue's tests write for: the league's size and, per topic, the
 * slide data given (none by default: only the league topic has data).
 *
 * @param  array<string, mixed>  $data  reader name => what it returns (liveGames, results, openTournaments, ladders, …)
 * @param  array<string, int>  $league
 */
function casterFactsWith(array $data = [], array $league = ['players' => 21, 'clans' => 3]): SceneFacts
{
    return new class(app(TournamentSlides::class), $data, $league) extends SceneFacts
    {
        /**
         * @param  array<string, mixed>  $data
         * @param  array<string, int>  $league
         */
        public function __construct(TournamentSlides $tournaments, private array $data, private array $league)
        {
            parent::__construct($tournaments);
        }

        protected function league(): array
        {
            return $this->league;
        }

        protected function leagueTotals(): array
        {
            return $this->data['leagueTotals'] ?? [];
        }

        protected function liveCount(): array
        {
            return $this->data['liveCount'] ?? [];
        }

        protected function todayCount(): array
        {
            return $this->data['todayCount'] ?? [];
        }

        protected function liveGames(): array
        {
            return $this->data['liveGames'] ?? [];
        }

        protected function results(): array
        {
            return $this->data['results'] ?? [];
        }

        protected function openTournaments(bool $night = false): array
        {
            return $this->data['openTournaments'] ?? [];
        }

        protected function tournamentsUnderWay(): array
        {
            return $this->data['tournamentsUnderWay'] ?? [];
        }

        protected function ladders(): array
        {
            return $this->data['ladders'] ?? [];
        }

        protected function clanSpotlight(): array
        {
            return $this->data['clanSpotlight'] ?? [];
        }

        protected function tmnfWeek(): array
        {
            return $this->data['tmnfWeek'] ?? [];
        }

        protected function blockfillWeek(): array
        {
            return $this->data['blockfillWeek'] ?? [];
        }
    };
}

/** Facts with nothing on but the league's size. */
function casterQuietFacts(): SceneFacts
{
    return casterFactsWith();
}

function casterQueue(?SceneFacts $facts = null): ScriptQueue
{
    return new ScriptQueue(app(CasterVoice::class), $facts ?? casterQuietFacts());
}

/** A script the guards accept: short spoken German, one sentence per line. */
const CASTER_GOOD_SCRIPT = "Hey, hier ist TWENTY ONE TV.\nDie Liga hat 21 Spieler.\nKlar, da geht noch mehr.\nSchaut einfach mal rein.";

it('asks the league voice once per fill and fills the rest of the airtime with pattern scripts', function () {
    config(['tv.commentary.scripts.ahead_seconds' => 20, 'tv.commentary.scripts.per_run_ai' => 1]);
    VoiceAgent::fake([
        ['text' => CASTER_GOOD_SCRIPT, 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => "Läuft bei der Liga.\nEinundzwanzig Leute sind dabei.\nUnd ihr?\nRein da.", 'canon_id' => null, 'mood' => 'call'],
    ]);

    $written = casterQueue()->fill(CarbonImmutable::now());

    expect($written)->toBeGreaterThan(1)
        ->and(CasterScript::query()->whole()->where('kind', CasterScript::SCENE)->where('source', CasterScript::SOURCE_AI)->count())->toBe(1)
        ->and(CasterScript::query()->whole()->where('kind', CasterScript::SCENE)->where('source', CasterScript::SOURCE_PATTERN)->count())->toBe($written - 1)
        ->and(CasterScript::query()->where('kind', CasterScript::SCENE)->whereNull('topic')->count())->toBe(0)
        ->and(casterQueue()->airtimeAhead())->toBeGreaterThanOrEqual(20.0);
    VoiceAgent::assertPromptedTimes(1);
});

it('never asks the league voice while its switch is off, and the patterns still fill the airtime', function () {
    config(['tv.commentary.ai' => false, 'tv.commentary.scripts.ahead_seconds' => 10]);
    VoiceAgent::fake();

    casterQueue()->fill(CarbonImmutable::now());

    expect(CasterScript::query()->where('kind', CasterScript::SCENE)->where('source', CasterScript::SOURCE_PATTERN)->count())->toBeGreaterThan(0)
        ->and(casterQueue()->airtimeAhead())->toBeGreaterThanOrEqual(10.0);
    VoiceAgent::assertNeverPrompted();
});

it('asks the league voice about no topic whose slides have no data', function () {
    config(['tv.commentary.scripts.ahead_seconds' => 60]);
    VoiceAgent::fake();

    expect(casterQueue(casterFactsWith(league: []))->fill(CarbonImmutable::now()))->toBe(0)
        ->and(CasterScript::query()->where('kind', CasterScript::SCENE)->count())->toBe(0);
    VoiceAgent::assertNeverPrompted();
});

it('gives the one league-voice script to a topic with data and its slide facts', function () {
    config(['tv.commentary.scripts.ahead_seconds' => 5]);
    VoiceAgent::fake([['text' => "Live auf dem Brett: eine Partie Blitz.\nBleibt dran.", 'canon_id' => null, 'mood' => 'fakten']]);

    casterQueue(casterFactsWith(['liveGames' => [['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'mode' => 'Blitz', 'move' => 14]]]))->fill(CarbonImmutable::now());

    expect(CasterScript::query()->where('kind', CasterScript::SCENE)->where('source', CasterScript::SOURCE_AI)->value('topic'))->toBe(SceneTopic::LIVE_GAMES);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"white": "Ada"') && str_contains($prompt->prompt, 'Live-Partien der Liga'));
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
    $old = $queue->enqueue(CasterScript::SCENE, ScenePhase::LIVE_MATCH, 'Alt.', 'old', 0, $now, topic: SceneTopic::LIVE_GAMES);
    $other = $queue->enqueue(CasterScript::SCENE, ScenePhase::LIVE_MATCH, 'Turnier.', 'old', 0, $now, topic: SceneTopic::LIVE_TOURNAMENT);

    expect($queue->supersedeTopic(SceneTopic::LIVE_GAMES, 'new'))->toBe(1)
        ->and($old->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($other->fresh()->status)->toBe(CasterScript::QUEUED);
});

it('frees a claim that never came back', function () {
    $now = CarbonImmutable::now();
    $queue = casterQueue();
    $script = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Eins.', 'h', 0, $now);

    expect($queue->claim($now)?->id)->toBe($script->id)
        ->and($queue->claim($now->addSeconds(60)))->toBeNull()
        ->and($queue->claim($now->addSeconds(299)))->toBeNull()
        ->and($queue->claim($now->addSeconds(301))?->id)->toBe($script->id);
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
    config(['tv.commentary.scripts.ahead_seconds' => 5, 'tv.commentary.patterns.per_run' => 0]);
    VoiceAgent::fake([['text' => $text, 'canon_id' => null, 'mood' => 'fakten']]);

    expect(casterQueue()->fill(CarbonImmutable::now()))->toBe(0)
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->pluck('reason')->all())->toContain($reason);
})->with([
    'unknown marker' => ["Hey, hier ist TWENTY ONE TV.\n[applause] Die Liga hat 21 Spieler.\nSchaut rein.", ScriptGuard::MARKER_UNKNOWN],
    'stage direction' => ["Hey, hier ist TWENTY ONE TV.\nDie Liga hat 21 Spieler.\nKlar, da geht noch mehr (lacht).", ScriptGuard::STAGE_DIRECTION],
    'too few sentences' => ['Hey, hier ist TWENTY ONE TV.', ScriptGuard::SENTENCE_COUNT],
    'long sentence' => ["Hey, hier ist TWENTY ONE TV.\nDie Liga hat 21 Spieler und alle spielen jeden Tag sehr gerne mit ihren Freunden und Nachbarn zusammen im Park.\nSchaut rein.", ScriptGuard::SENTENCE_LONG],
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

it('spreads the long variants evenly at their share, owes at most one that could not be built, and writes none at 0', function () {
    config(['tv.commentary.length.shares.scene' => 0.25, 'tv.commentary.length.shares.news' => 0.0]);
    $due = [];

    foreach (range(1, 8) as $ignored) {
        $due[] = LengthMix::isDue(LengthMix::SCENE);
        LengthMix::written(LengthMix::SCENE, end($due));
    }

    expect($due)->toBe([false, false, false, true, false, false, false, true])
        ->and(LengthMix::isDue(LengthMix::NEWS))->toBeFalse();

    // A long one due but written short (eight times): owed once, then the next is long, never two in a row.
    foreach (range(1, 8) as $ignored) {
        LengthMix::written(LengthMix::SCENE, false);
    }
    expect(LengthMix::isDue(LengthMix::SCENE))->toBeTrue();
    LengthMix::written(LengthMix::SCENE, true);
    expect(LengthMix::isDue(LengthMix::SCENE))->toBeFalse();
});

it('writes about every fourth pattern scene long, 8 to 14 sentences, the rest short as before', function () {
    config(['tv.commentary.ai' => false, 'tv.commentary.scripts.ahead_seconds' => 400]);
    VoiceAgent::fake();

    casterQueue(casterFactsWith())->fill(CarbonImmutable::now());

    $counts = CasterScript::query()->whole()->where('kind', CasterScript::SCENE)->orderBy('id')->get()->map(fn (CasterScript $script): int => count(casterWholeSentences($script)))->all();
    $long = array_filter($counts, fn (int $count): bool => $count >= 8);

    expect(count($counts))->toBeGreaterThanOrEqual(8)
        ->and(count($long))->toBeGreaterThanOrEqual(intdiv(count($counts), 4) - 1)->toBeLessThanOrEqual(intdiv(count($counts), 4) + 1)
        ->and(max($counts))->toBeLessThanOrEqual(14)
        ->and(min(array_diff_key($counts, $long)))->toBeGreaterThanOrEqual(3);
    VoiceAgent::assertNeverPrompted();
});
