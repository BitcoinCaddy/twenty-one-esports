<?php

use App\Support\Voice\LeagueVoice;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceMentions;
use App\Support\Voice\VoiceUsage;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| League voice against the real model (plan "KI-Stimme der Liga", P1)
|--------------------------------------------------------------------------
|
| Costs money: runs only with `--evals` and a key (ANTHROPIC_API_KEY), a
| handful of Haiku calls. Prints the provider's usage of every call
| (STDERR) so the run documents its own cost and the prompt cache.
|
*/

beforeEach(function () {
    if (($_SERVER['PEST_EVALS'] ?? getenv('PEST_EVALS')) !== '1') {
        $this->markTestSkipped('Eval skipped. Run with [--evals] to evaluate against a real model.');
    }

    if (blank(config('ai.providers.anthropic.key'))) {
        $this->markTestSkipped('No ANTHROPIC_API_KEY.');
    }
});

/**
 * @return array<string, mixed>
 */
function evalFacts(): array
{
    return [
        'game' => 'Schach (Rapid)',
        'score' => '1:0',
        'elo_winner' => 1412,
        'elo_loser' => 1688,
        'url' => 'https://esports.einundzwanzig.space/matches/4211',
        'people' => [
            ['role' => 'winner', 'name' => 'BlockBauer', 'pubkey' => str_repeat('a', 64), 'mentionable' => true],
            ['role' => 'loser', 'name' => 'Kurswaechter', 'pubkey' => str_repeat('b', 64), 'mentionable' => false],
        ],
    ];
}

it('serves the fixed half from the prompt cache on the second call', function () {
    $voice = app(LeagueVoice::class);
    $canon = new VoiceCanon;
    $fixed = $voice->prompt()->fixedPart();
    $usages = [];

    foreach (['upset', 'champion'] as $moment) {
        $variable = $voice->prompt()->variablePart($moment, VoiceMentions::forModel(LeagueVoice::publicFacts(evalFacts())), 'stream_chat', 180, 'de', $canon->select($moment));
        $response = (new VoiceAgent($fixed))->prompt($variable);
        $usages[] = $response->usage;
        fwrite(STDERR, sprintf("[cache] %s input=%d cache_write=%s cache_read=%s output=%d text=%s\n", $moment, $response->usage->inputTokens, var_export($response->usage->cacheWriteInputTokens, true), var_export($response->usage->cacheReadInputTokens, true), $response->usage->outputTokens, $response->text));
    }

    expect($usages[0]->cacheWriteInputTokens + $usages[0]->cacheReadInputTokens)->toBeGreaterThan(0)
        ->and($usages[1]->cacheReadInputTokens)->toBeGreaterThan(0);
});

it('writes posts the guards let through from the facts of a moment', function () {
    config(['esports.voice.channels.stream_chat.enabled' => true]);
    $voice = app(LeagueVoice::class);
    $lines = [];

    foreach (['upset', 'win', 'first_win'] as $moment) {
        $line = $voice->say($moment, evalFacts(), 'stream_chat');
        $lines[] = $line;
        fwrite(STDERR, "[voice] {$moment}: ".($line === null ? 'null (template)' : $line->text.' | canon='.($line->canonId ?? '-').' | mood='.$line->mood)."\n");
    }

    $usage = app(VoiceUsage::class);
    $today = $usage->day();
    fwrite(STDERR, '[voice] usage '.json_encode($usage->totals($today, $today))."\n");
    fwrite(STDERR, '[voice] rejections '.json_encode($usage->rejections($today, $today))."\n");
    fwrite(STDERR, sprintf("[voice] estimated cost \$%.6f\n", $usage->monthCost()));

    expect(DB::table('voice_usage')->sum('attempts'))->toBe(3)
        ->and(array_filter($lines))->not->toBeEmpty();
});

it('keeps to the facts and the league tone, judged by Claude', function () {
    $voice = app(LeagueVoice::class);
    $variable = $voice->prompt()->variablePart('upset', VoiceMentions::forModel(LeagueVoice::publicFacts(evalFacts())), 'stream_chat', 180, 'de', (new VoiceCanon)->select('upset'));

    expect(new VoiceAgent($voice->prompt()->fixedPart()))
        ->prompt($variable)
        ->toSatisfy('The output is a JSON object whose "text" is one or two short German sentences about the given facts only (winner BlockBauer or the token {@winner}, loser Kurswaechter, score 1:0, the Elo numbers 1412 and 1688 or none, no other numbers or names), friendly to both players, without hashtags, with at most two emoji, and not written like an advertisement.');
});
