<?php

use App\Support\Voice\VoiceUsage;

it('prices the month at the configured model, Sonnet twenty times Haiku', function () {
    $counters = ['input_tokens' => 1_000_000, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 1_000_000];

    config(['esports.voice.model' => 'claude-haiku-5-5']);
    $haiku = app(VoiceUsage::class)->cost($counters);
    config(['esports.voice.model' => 'claude-sonnet-5-5']);

    expect($haiku)->toBe(0.6)->and(app(VoiceUsage::class)->cost($counters))->toBe(12.0);
});
