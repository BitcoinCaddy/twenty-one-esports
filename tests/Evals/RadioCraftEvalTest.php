<?php

use App\Support\Caster\CasterVoice;
use App\Support\Caster\RecentlySaid;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\WorldMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV radio craft against the real model (plan "TWENTY ONE Radio", P3)
|--------------------------------------------------------------------------
|
| Costs money: runs only with `--evals` and a key (ANTHROPIC_API_KEY). One
| long scene and one chat reply go through CasterVoice with the radio craft
| block in the cached prefix and every check. Prints both texts and the
| rejection reasons (STDERR) as the listening sample of the phase.
|
*/

beforeEach(function () {
    if (($_SERVER['PEST_EVALS'] ?? getenv('PEST_EVALS')) !== '1') {
        $this->markTestSkipped('Eval skipped. Run with [--evals] to evaluate against a real model.');
    }

    if (blank(config('ai.providers.anthropic.key'))) {
        $this->markTestSkipped('No ANTHROPIC_API_KEY.');
    }

    config(['tv.commentary.enabled' => true, 'tv.commentary.scripts.materials' => ['league' => 1], 'tv.commentary.scripts.daily_cap' => 100,
        'esports.voice.monthly_budget_usd' => 5, 'esports.voice.fact_check.enabled' => true]);
});

it('writes a scene and a chat reply with the radio craft in the prompt', function () {
    $voice = app(CasterVoice::class);
    $facts = ['sender' => 'TWENTY ONE TV', 'website_spoken' => 'esports punkt einundzwanzig punkt space', 'slide' => 'Liga-Überblick', 'players' => 34, 'clans' => 8,
        'live_games' => [], 'tournaments_open' => [['name' => 'Herbst Cup']], 'next_slide' => 'Turniere der Woche'];

    // What ran just before, so the scene has something to refer back to.
    RecentlySaid::remember(RecentlySaid::FACT_LINES, ['Der Herbst Cup hat noch freie Plätze', 'Im Pong fiel vorhin ein neuer Ballwechsel-Rekord'], 30, CarbonImmutable::now());

    $scene = $voice->write('scene', ScenePhase::PRE_SHOW, $facts, 4, 8, long: true);
    fwrite(STDERR, "[scene]\n".($scene['text'] ?? 'NULL (rejected)')."\n");

    $reply = $voice->chatReply('Hey Markus, was läuft heute Abend bei euch so?', 'El Presidento Ben');
    fwrite(STDERR, "[chat]\n".($reply ?? 'NULL (rejected)')."\n");

    // A callback refers back by design: it shows whether the craft turns it into a back reference with a step further.
    $callback = $voice->callback([['key' => 'result:1', 'at' => CarbonImmutable::now()->subHours(3)->getTimestamp(), 'kind' => WorldMemory::RESULT, 'line' => 'Ergebnis: Im Herbst Cup hat ein Neuling sein erstes Match gewonnen']]);
    fwrite(STDERR, "[callback]\n".($callback ?? 'NULL (rejected)')."\n");

    fwrite(STDERR, '[rejections] '.json_encode(DB::table('voice_rejections')->get(['channel', 'reason', 'count'])->all(), JSON_UNESCAPED_UNICODE)."\n");

    expect($scene)->not->toBeNull()->and($reply)->not->toBeNull();
});
