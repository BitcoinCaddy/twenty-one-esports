<?php

use App\Support\Caster\CasterVoice;
use App\Support\Caster\PodcastFeed;
use App\Support\Voice\VoiceUsage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV chat replies with the podcast feed and the web search
|--------------------------------------------------------------------------
|
| Costs money and reads the real podcast feed: runs only with `--evals` and a
| key (ANTHROPIC_API_KEY). "Was lief so im letzten Podcast?" gets the latest
| episodes as data, a current-events question may run one web search
| (counted under CasterVoice::WEB_SEARCH_CHANNEL). Prints both replies and
| the usage counters (STDERR).
|
*/

beforeEach(function () {
    if (($_SERVER['PEST_EVALS'] ?? getenv('PEST_EVALS')) !== '1') {
        $this->markTestSkipped('Eval skipped. Run with [--evals] to evaluate against a real model.');
    }

    if (blank(config('ai.providers.anthropic.key'))) {
        $this->markTestSkipped('No ANTHROPIC_API_KEY.');
    }

    config(['tv.commentary.enabled' => true, 'tv.commentary.chat.web_search' => true, 'esports.voice.monthly_budget_usd' => 5]);
});

it('answers from the podcast feed and looks a current question up once', function () {
    $voice = app(CasterVoice::class);
    $usage = app(VoiceUsage::class);
    $podcast = app(PodcastFeed::class)->block();
    fwrite(STDERR, "[podcast block]\n".($podcast ?? 'NULL')."\n");

    $data = $podcast === null ? [] : ['Die letzten Folgen des EINUNDZWANZIG-Podcasts, neueste zuerst' => $podcast];

    foreach (['Was lief so im letzten Podcast?', 'Wer hat gestern in der Bundesliga gewonnen?'] as $message) {
        $reply = $voice->chatReply($message, 'El Presidento Ben', [], $data);
        $totals = $usage->totals($usage->day(), $usage->day());
        fwrite(STDERR, "[chat] {$message}\n  => ".($reply ?? 'NULL (rejected)')."\n  usage ".json_encode($totals, JSON_UNESCAPED_UNICODE)."\n");
    }

    expect($podcast)->not->toBeNull();
});
