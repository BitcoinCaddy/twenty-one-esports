<?php

use App\Support\Caster\Blocks\StockScripts;
use App\Support\Voice\VoiceUsage;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio day stock against the real model (plan "TWENTY ONE Radio", P21)
|--------------------------------------------------------------------------
|
| Costs money: runs only with `--evals` and a key (ANTHROPIC_API_KEY). One
| stock programme per format through StockScripts::write() with the
| coherence gate: Sonnet writes, the judge scores premise, through-line,
| payoff, fit, liveliness and sense; a weak draft is written once more. At
| most `$budget` model calls in all. Prints material, text and every
| judgement (STDERR) as the listening sample of the phase.
|
*/

beforeEach(function () {
    if (($_SERVER['PEST_EVALS'] ?? getenv('PEST_EVALS')) !== '1') {
        $this->markTestSkipped('Eval skipped. Run with [--evals] to evaluate against a real model.');
    }

    if (blank(config('ai.providers.anthropic.key'))) {
        $this->markTestSkipped('No ANTHROPIC_API_KEY.');
    }

    config(['tv.commentary.enabled' => true, 'tv.commentary.ai' => true, 'esports.voice.model' => 'claude-sonnet-5-5', 'esports.voice.timeout_seconds' => 120,
        'tv.commentary.blocks.stock.library.enabled' => false, 'tv.commentary.blocks.stock.daily_cap' => 100, 'esports.voice.monthly_budget_usd' => 5]);
});

it('writes one coherent stock programme per format, judged before it is queued', function () {
    $budget = (int) (getenv('STOCK_EVAL_CALLS') ?: 23);
    $calls = fn (): int => (int) DB::table('voice_usage')->whereIn('channel', [StockScripts::CHANNEL, StockScripts::JUDGE_CHANNEL])->sum('attempts');
    $written = 0;

    foreach (['talk', 'tutorial', 'geschichte', 'liga', 'schwarzbuch', 'inflationsbeichte'] as $format) {
        // A format costs at most four calls: two drafts, two judgements.
        if ($calls() + 4 > $budget) {
            fwrite(STDERR, "\n===== {$format}: skipped, call budget\n");

            continue;
        }

        config(['tv.commentary.blocks.stock.formats' => [$format]]);
        $stock = app(StockScripts::class);
        $talk = $stock->write();
        $written += $talk === null ? 0 : 1;

        foreach ($stock->judgements as $try => $judged) {
            fwrite(STDERR, "\n===== AFTER {$format} try ".($try + 1)."\n[material]\n{$judged['material']}\n[draft]\n{$judged['text']}\n[judge] ".json_encode($judged['answer'], JSON_UNESCAPED_UNICODE)."\n");
        }

        fwrite(STDERR, "\n===== AFTER {$format} result: ".($talk === null ? 'dropped' : 'queued')."\n");
    }

    fwrite(STDERR, "\n[calls] ".$calls()."\n[rejections] ".json_encode(DB::table('voice_rejections')->get(['channel', 'reason', 'count'])->all(), JSON_UNESCAPED_UNICODE)."\n");

    expect($written)->toBeGreaterThan(0)->and(app(VoiceUsage::class)->monthCost())->toBeLessThan(5.0);
});
