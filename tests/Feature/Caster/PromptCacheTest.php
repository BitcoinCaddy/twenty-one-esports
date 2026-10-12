<?php

use App\Models\CasterNews;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\WorldMemory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the caster's prompt layout for Anthropic's prompt cache
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Nutzen wir auch effektiv und effizient den Sonnet Prompt
| Cache?" The cache is a prefix match: one changed byte re-bills everything
| behind it. So the request carries the static system prompt (1-hour
| breakpoint), then the world memory frozen for the epoch (5-minute
| breakpoint), and only then the per-call user message. Through the real
| laravel/ai request builder against a faked HTTP endpoint, never the network.
|
*/

const PROMPT_CACHE_SCRIPT = "Vorhin lief ja eine Schlagzeile über den Bundestag, und ich muss immer noch darüber lachen.\nDer Staat druckt sich die Welt, wie sie ihm gefällt, und wir stapeln einfach weiter Sats.\nDas ist der Unterschied zwischen Fiatgeld und echtem Geld, den jeder irgendwann versteht.";

beforeEach(function () {
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.scripts.materials' => ['league' => 1],
        'tv.commentary.cache.static_ttl' => '1h',
        'tv.commentary.cache.world_epoch_minutes' => 10,
        'esports.voice.model' => 'claude-sonnet-5-5',
        'ai.providers.anthropic.key' => 'test-key',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:03', 'Europe/Berlin'));
    WorldMemory::forget();

    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => json_encode(['punkt' => 'Fiatgeld', 'pointe' => 'Sats', 'text' => PROMPT_CACHE_SCRIPT, 'canon_id' => null, 'mood' => 'deadpan'])]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 900, 'output_tokens' => 120, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 12_000],
    ])]);
});

/** A headline the news segment aired `$minutesAgo` minutes ago (a world memory item). */
function promptCacheHeadline(string $title, int $minutesAgo): void
{
    CasterNews::query()->create([
        'hash' => hash('sha256', $title), 'section' => CasterNews::GERMANY, 'feed' => 'tagesschau-inland', 'source' => 'tagesschau', 'language' => 'de',
        'title' => $title, 'link' => null, 'published_at' => now()->subHours(30), 'used_at' => now()->subMinutes($minutesAgo), 'skipped' => null,
    ]);
}

/**
 * The bodies of the Anthropic requests sent so far.
 *
 * @return list<array<string, mixed>>
 */
function promptCacheBodies(): array
{
    return array_values(array_map(fn (array $pair): array => $pair[0]->data(), Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.anthropic.com'))->all()));
}

/** The text of a request's user message. */
function promptCacheUserText(array $body): string
{
    $content = $body['messages'][0]['content'];

    return is_string($content) ? $content : implode("\n", array_column($content, 'text'));
}

it('sends two scene calls with different facts and a new world item between them with byte-identical cached system blocks', function () {
    promptCacheHeadline('Bundestag beschließt neues Heizungsgesetz', 120);
    // 27 minutes old: "vor fünfundzwanzig Minuten" now, "vor dreißig Minuten" a minute later, if it were counted per call.
    promptCacheHeadline('Rentenkasse meldet neues Loch', 27);
    $voice = app(CasterVoice::class);

    $voice->write('scene', ScenePhase::PRE_SHOW, ['sender' => 'TWENTY ONE TV', 'players' => 34], 2, 8);

    // A minute later: something new aired (the rolling memory grows) and the slide's facts moved on.
    $this->travel(1)->minutes();
    promptCacheHeadline('Notenbank druckt wieder fleißig', 0);
    WorldMemory::forget();
    $voice->write('scene', ScenePhase::PRE_SHOW, ['sender' => 'TWENTY ONE TV', 'players' => 35], 2, 8);

    $bodies = promptCacheBodies();
    $first = $bodies[0];
    $last = $bodies[array_key_last($bodies)];

    expect(count($bodies))->toBeGreaterThanOrEqual(2)
        // The cached part: byte for byte the same, static prompt first with the long TTL, the frozen world after it.
        ->and(json_encode($last['system']))->toBe(json_encode($first['system']))
        ->and($first['system'])->toHaveCount(2)
        ->and($first['system'][0]['cache_control'])->toBe(['type' => 'ephemeral', 'ttl' => '1h'])
        ->and($first['system'][1]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($first['system'][0]['text'])->toBe($voice->fixedPart())
        ->and($first['system'][1]['text'])->toContain('Bundestag beschließt neues Heizungsgesetz')
        ->and($first['system'][1]['text'])->toContain('Stand 15:00 Uhr')
        ->and($first['system'][1]['text'])->toContain('Rentenkasse meldet neues Loch')
        // Per call: the facts and what came since the epoch began, in the uncached user message only.
        ->and(promptCacheUserText($first))->toContain('"players": 34')
        ->and(promptCacheUserText($last))->toContain('"players": 35')
        ->and(promptCacheUserText($last))->toContain('Notenbank druckt wieder fleißig')
        ->and(json_encode($last['system'], JSON_UNESCAPED_UNICODE))->not->toContain('Notenbank')
        ->and(json_encode($last['system']))->not->toContain('players');
});

it('rewrites only the world block when the epoch rolls, never the static prompt', function () {
    promptCacheHeadline('Bundestag beschließt neues Heizungsgesetz', 120);
    $voice = app(CasterVoice::class);
    $voice->write('scene', ScenePhase::PRE_SHOW, ['players' => 34], 2, 8);

    $this->travel(10)->minutes();
    promptCacheHeadline('Notenbank druckt wieder fleißig', 5);
    WorldMemory::forget();
    $voice->write('scene', ScenePhase::PRE_SHOW, ['players' => 34], 2, 8);

    $bodies = promptCacheBodies();
    $first = $bodies[0];
    $last = $bodies[array_key_last($bodies)];

    expect($last['system'][0])->toBe($first['system'][0])
        ->and($last['system'][1]['text'])->not->toBe($first['system'][1]['text'])
        ->and($last['system'][1]['text'])->toContain('Stand 15:10 Uhr')
        ->and($last['system'][1]['text'])->toContain('Notenbank druckt wieder fleißig');
});

it('keeps the whole world block in the user message with the epochs off', function () {
    config(['tv.commentary.cache.world_epoch_minutes' => 0, 'tv.commentary.cache.static_ttl' => '5m']);
    promptCacheHeadline('Bundestag beschließt neues Heizungsgesetz', 120);

    app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, ['players' => 34], 2, 8);

    $body = promptCacheBodies()[0];

    expect($body['system'])->toHaveCount(1)
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral', 'ttl' => '5m'])
        ->and(promptCacheUserText($body))->toContain('Bundestag beschließt neues Heizungsgesetz');
});
