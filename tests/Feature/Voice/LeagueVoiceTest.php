<?php

use App\Support\Nostr\NostrKeys;
use App\Support\Voice\LeagueVoice;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceFactCheck;
use App\Support\Voice\VoiceGuard;
use App\Support\Voice\VoiceHygiene;
use App\Support\Voice\VoiceMentions;
use App\Support\Voice\VoiceUsage;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

/*
|--------------------------------------------------------------------------
| LeagueVoice (plan "KI-Stimme der Liga", P1)
|--------------------------------------------------------------------------
|
| With the laravel/ai fake, never the network: a guarded post comes back as
| a VoiceLine; every failure (switch off, cap, budget, timeout, 429, 500,
| rejected text) is null without an exception, so the caller posts its
| template. The prompt is split into a fixed, cached half and a variable
| half; the canon selection stays at 50–100 entries and far below 100k
| tokens.
|
*/

beforeEach(function () {
    config(['esports.voice.channels.stream_chat.enabled' => true]);
});

/**
 * @return array<string, mixed>
 */
function voiceFacts(): array
{
    return [
        'game' => 'Chess',
        'score' => '3:1',
        'url' => 'https://esports.einundzwanzig.space/matches/42',
        'people' => [
            ['role' => 'winner', 'name' => 'Satoshi', 'pubkey' => str_repeat('a', 64), 'mentionable' => true],
            ['role' => 'loser', 'name' => 'Hal', 'pubkey' => str_repeat('b', 64), 'mentionable' => false],
        ],
    ];
}

function leagueVoice(): LeagueVoice
{
    return app(LeagueVoice::class);
}

it('returns a guarded post with its mentions resolved', function () {
    VoiceAgent::fake([['text' => '{@winner} schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'fakten']]);

    $line = leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect($line)->not->toBeNull()
        ->and($line->text)->toBe('nostr:'.NostrKeys::hexToNpub(str_repeat('a', 64)).' schlägt Hal 3:1. Stark gespielt!')
        ->and($line->tags)->toBe([['p', str_repeat('a', 64)]])
        ->and($line->mood)->toBe('fakten')
        ->and(DB::table('voice_usage')->value('accepted'))->toBe(1);
});

it('returns null without asking the model when the channel is off', function () {
    config(['esports.voice.channels.stream_chat.enabled' => false]);
    VoiceAgent::fake();

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull();
    VoiceAgent::assertNeverPrompted();
});

it('returns null for an unknown channel or moment', function () {
    VoiceAgent::fake();

    expect(leagueVoice()->say('win', voiceFacts(), 'nowhere'))->toBeNull()
        ->and(leagueVoice()->say('no-such-moment', voiceFacts(), 'stream_chat'))->toBeNull();
    VoiceAgent::assertNeverPrompted();
});

it('returns null once the daily cap is reached', function () {
    config(['esports.voice.channels.stream_chat.daily_cap' => 2]);
    VoiceAgent::fake([
        ['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => 'Hal verliert gegen Satoshi, 3:1 im Schach.', 'canon_id' => null, 'mood' => 'deadpan'],
    ]);

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->not->toBeNull()
        ->and(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->not->toBeNull()
        ->and(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull();
    VoiceAgent::assertPromptedTimes(2);
});

it('returns null once the monthly budget is used up', function () {
    config(['esports.voice.monthly_budget_usd' => 0.001]);
    app(VoiceUsage::class)->recordTokens('stream_chat', 10_000, 0);
    VoiceAgent::fake();

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull();
    VoiceAgent::assertNeverPrompted();
});

it('returns null without an exception when the request fails', function (Closure $failure) {
    VoiceAgent::fake(fn () => $failure());

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull()
        ->and(DB::table('voice_usage')->value('failed'))->toBe(1)
        ->and(DB::table('voice_usage')->value('attempts'))->toBe(1);
})->with([
    'timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    '429' => fn () => fn () => throw RateLimitedException::forProvider('anthropic', 429),
    '500' => fn () => fn () => throw new RequestException(new Response(new Psr7Response(500, [], '{"error":{"message":"boom"}}'))),
]);

it('returns null and counts the reasons when the guard rejects the text twice', function () {
    VoiceAgent::fake([
        ['text' => 'Satoshi schlägt Hal 7:0. #bitcoin', 'canon_id' => null, 'mood' => 'hype'],
        ['text' => 'Satoshi schlägt Hal 7:0. #bitcoin', 'canon_id' => null, 'mood' => 'hype'],
    ]);

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull()
        ->and(DB::table('voice_rejections')->orderBy('reason')->pluck('reason')->all())->toBe([VoiceGuard::HASHTAG, VoiceGuard::NUMBER, VoiceHygiene::TRUNCATED])
        ->and(DB::table('voice_usage')->value('rejected'))->toBe(2)
        ->and(DB::table('voice_usage')->value('attempts'))->toBe(2);
    VoiceAgent::assertPromptedTimes(2);
});

it('writes once more after a rejection, telling the model why, and posts the second text', function () {
    VoiceAgent::fake([
        ['text' => 'Satoshi schlägt Hal 7:0. #bitcoin', 'canon_id' => null, 'mood' => 'hype'],
        ['text' => 'Satoshi schlägt Hal 3:1. Stark!', 'canon_id' => null, 'mood' => 'hype'],
    ]);

    $line = leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect($line?->text)->toBe('Satoshi schlägt Hal 3:1. Stark!')
        ->and(DB::table('voice_usage')->first(['attempts', 'accepted', 'rejected']))->toEqual((object) ['attempts' => 2, 'accepted' => 1, 'rejected' => 1]);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Dein erster Entwurf ging nicht durch') && str_contains($prompt->prompt, 'Kein # im Text.'));
});

it('asks no second time once the daily cap is used up by the first', function () {
    config(['esports.voice.channels.stream_chat.daily_cap' => 1]);
    VoiceAgent::fake([['text' => 'Satoshi schlägt Hal 7:0. #bitcoin', 'canon_id' => null, 'mood' => 'hype']]);

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull();
    VoiceAgent::assertPromptedTimes(1);
});

it('returns null when the model mentions a person whose mentions are off', function () {
    VoiceAgent::fake([['text' => 'Satoshi schlägt {@loser} 3:1.', 'canon_id' => null, 'mood' => 'fakten']]);

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull();
});

it('records the tokens the provider reports', function () {
    VoiceAgent::fake([new StructuredTextResponse(
        ['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'fakten'],
        '{}',
        new TextUsage(9000, 60, 2500, 0),
        new Meta('anthropic', 'claude-haiku-5-5'),
    )]);

    leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect((array) DB::table('voice_usage')->first(['input_tokens', 'output_tokens', 'cache_read_tokens']))
        ->toBe(['input_tokens' => 9000, 'output_tokens' => 60, 'cache_read_tokens' => 2500]);
});

it('keeps the fixed half identical and puts facts and canon only in the variable half', function () {
    VoiceAgent::fake([
        ['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => 'Ada gewinnt das Finale.', 'canon_id' => null, 'mood' => 'hype'],
    ]);

    leagueVoice()->say('win', voiceFacts(), 'stream_chat');
    leagueVoice()->say('champion', ['people' => [['role' => 'champion', 'name' => 'Ada', 'pubkey' => null, 'mentionable' => false]]], 'stream_chat');

    $prompts = [];
    VoiceAgent::assertPrompted(function (AgentPrompt $prompt) use (&$prompts) {
        $prompts[] = $prompt;

        return count($prompts) === 2;
    });

    [$first, $second] = $prompts;
    expect($first->agent->instructions())->toBe($second->agent->instructions())
        ->and($first->agent->instructions())->not->toContain('Satoshi')
        ->and($first->agent->instructions())->toContain('Stil-Leitfaden')
        ->and(VoiceCanon::estimateTokens($first->agent->instructions()))->toBeGreaterThan(1024)
        ->and($first->prompt)->toContain('Satoshi')
        ->and($first->prompt)->toContain('{@winner}')
        ->and($first->prompt)->not->toContain(str_repeat('a', 64))
        ->and($first->prompt)->toContain('## Kanon-Auswahl')
        ->and($second->prompt)->toContain('Ada');
});

it('never gives the model a gamer tag or a Lightning address', function () {
    VoiceAgent::fake([['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'fakten']]);

    leagueVoice()->say('win', [...voiceFacts(), 'gamer_tag' => 'xXSatoshiXx', 'lud16' => 'satoshi@example.com', 'winner_game_account' => 'Sato#123'], 'stream_chat');

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt) => ! str_contains($prompt->prompt, 'xXSatoshiXx') && ! str_contains($prompt->prompt, 'satoshi@example.com') && ! str_contains($prompt->prompt, 'Sato#123'));
});

it('sends the fixed half with cache_control, thinking off, low effort and the answer schema', function () {
    config(['ai.providers.anthropic.key' => 'test-key']);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => json_encode(['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'fakten'])]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 4000, 'output_tokens' => 40, 'cache_creation_input_tokens' => 2600, 'cache_read_input_tokens' => 0],
    ])]);

    $line = leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect($line?->text)->toStartWith('Satoshi schlägt Hal');
    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $body['model'] === 'claude-haiku-5-5'
            && $body['system'][0]['cache_control'] === ['type' => 'ephemeral']
            && str_contains($body['system'][0]['text'], 'Stil-Leitfaden')
            && ! str_contains($body['system'][0]['text'], 'Satoshi')
            && $body['output_config']['effort'] === 'low'
            && $body['thinking'] === ['type' => 'disabled']
            && $body['output_config']['format']['type'] === 'json_schema'
            && str_contains($body['messages'][0]['content'][0]['text'] ?? json_encode($body['messages'][0]['content']), 'Satoshi');
    });
});

it('selects 50 to 100 canon entries that fit the moment, far below 100k tokens', function () {
    $selection = (new VoiceCanon)->select('upset', [], 50, 100, 12_000);
    $rendered = implode("\n", array_map(VoiceCanon::renderLine(...), $selection));
    $fitting = array_filter($selection, fn (array $entry) => array_intersect($entry['contexts'], VoiceCanon::MOMENTS['upset']['contexts']) !== []);

    expect(count($selection))->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100)
        ->and(VoiceCanon::estimateTokens($rendered))->toBeLessThanOrEqual(12_000)
        ->and(count($fitting))->toBeGreaterThanOrEqual(30);
});

it('leaves the canon entries of the last posts out of the next selection', function () {
    $canon = new VoiceCanon;
    $avoid = array_slice($canon->idsFor('Upset'), 0, 20);

    foreach (range(1, 5) as $run) {
        expect(array_intersect(array_column($canon->select('upset', $avoid), 'id'), $avoid))->toBe([]);
    }
});

it('rotates the selection between calls', function () {
    $canon = new VoiceCanon;

    expect(array_column($canon->select('general'), 'id'))->not->toBe(array_column($canon->select('general'), 'id'));
});

it('remembers the canon entry a post used so the next selection skips it', function () {
    VoiceAgent::fake([['text' => 'Satoshi schlägt Hal 3:1. Heute ist ein guter Tag um Sats zu stapeln.', 'canon_id' => 'e21-sats-stapeln', 'mood' => 'zitat']]);

    leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect(leagueVoice()->recent('stream_chat', 'canon'))->toBe(['e21-sats-stapeln']);
});

it('loads all 1,311 canon entries with an index by context', function () {
    $canon = new VoiceCanon;

    expect($canon->entries())->toHaveCount(1311)
        ->and($canon->idsFor('allgemein'))->not->toBeEmpty()
        ->and(array_sum(array_map('count', $canon->allExamples())))->toBe(304);
});

it('prints today\'s and the month\'s usage', function () {
    app(VoiceUsage::class)->recordAttempt('stream_chat');
    app(VoiceUsage::class)->recordTokens('stream_chat', 8000, 150, 3000, 0);
    app(VoiceUsage::class)->recordRejection('spam_chat', ['interval']);

    $this->artisan('voice:usage')
        ->expectsOutputToContain('stream_chat')
        ->expectsOutputToContain('spam_chat: interval 1')
        ->expectsOutputToContain('Month estimate: $0.0006 of $60.00 soft budget')
        ->assertSuccessful();
});

/*
| The fact check (P2): a second Haiku look lists claims the facts do not
| support; one claim rejects the text, a failed check sends the template.
*/

it('rejects a text with a claim the facts do not support, writes once more and posts the clean one', function () {
    VoiceAgent::fake([
        ['text' => 'Satoshi schlägt Hal 3:1 und bleibt ungeschlagen.', 'canon_id' => null, 'mood' => 'hype'],
        ['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'hype'],
    ]);
    VoiceFactCheck::fake([
        ['claims' => [['claim' => 'Satoshi schlägt Hal 3:1', 'evidence' => '3:1', 'supported' => true], ['claim' => 'bleibt ungeschlagen', 'evidence' => '', 'supported' => false]]],
        ['claims' => [['claim' => 'Satoshi schlägt Hal 3:1', 'evidence' => '3:1', 'supported' => true]]],
    ]);

    $line = leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect($line?->text)->toBe('Satoshi schlägt Hal 3:1. Stark gespielt!')
        ->and(DB::table('voice_rejections')->where('reason', VoiceGuard::UNSUPPORTED_CLAIM)->value('count'))->toBe(1)
        ->and(DB::table('voice_usage')->where('channel', LeagueVoice::FACT_CHECK)->value('attempts'))->toBe(2);
    VoiceFactCheck::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Satoshi schlägt Hal 3:1 und bleibt ungeschlagen.') && str_contains($prompt->prompt, '"score": "3:1"'));
});

it('sends the template when the fact check fails or answers nothing readable', function (Closure|array $answer) {
    VoiceAgent::fake([['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'hype'], ['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'hype']]);
    VoiceFactCheck::fake($answer);

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->toBeNull();
})->with([
    'timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    'unreadable' => fn () => [['claims' => 'none'], ['claims' => 'none']],
]);

it('asks no fact check for a text the guard already rejected, nor with the check switched off', function () {
    VoiceAgent::fake([['text' => 'Satoshi schlägt Hal 7:0.', 'canon_id' => null, 'mood' => 'hype'], ['text' => 'Satoshi schlägt Hal 7:0.', 'canon_id' => null, 'mood' => 'hype']]);
    VoiceFactCheck::fake();
    leagueVoice()->say('win', voiceFacts(), 'stream_chat');
    VoiceFactCheck::assertNeverPrompted();

    config(['esports.voice.fact_check.enabled' => false]);
    VoiceAgent::fake([['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'hype']]);

    expect(leagueVoice()->say('win', voiceFacts(), 'stream_chat'))->not->toBeNull();
    VoiceFactCheck::assertNeverPrompted();
});

it('tells the model the openers of the channel\'s last posts and rejects a repeated one', function () {
    VoiceAgent::fake([
        ['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'hype'],
        ['text' => 'Satoshi gewinnt wieder, 3:1 gegen Hal.', 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => 'Hal verliert 3:1 gegen Satoshi.', 'canon_id' => null, 'mood' => 'fakten'],
    ]);

    leagueVoice()->say('win', voiceFacts(), 'stream_chat');
    $second = leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect($second?->text)->toBe('Hal verliert 3:1 gegen Satoshi.')
        ->and(DB::table('voice_rejections')->where('reason', VoiceHygiene::OPENER_REPEAT)->value('count'))->toBe(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Die letzten Posts begannen mit „Satoshi“'));
});

it('repairs a draft before the checks: the name beside its token goes, a sentence too many is dropped, then the fact check reads the repaired text', function () {
    config(['esports.voice.channels.stream_chat.max_sentences' => 2]);
    VoiceAgent::fake([['text' => 'Satoshi {@winner} schlägt Hal 3:1. Stark gespielt. Wer will als Nächstes?', 'canon_id' => null, 'mood' => 'hype']]);

    $line = leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect($line?->text)->toBe('nostr:'.NostrKeys::hexToNpub(str_repeat('a', 64)).' schlägt Hal 3:1. Stark gespielt.')
        ->and(DB::table('voice_rejections')->where('channel', 'stream_chat_repaired')->orderBy('reason')->pluck('count', 'reason')->all())
        ->toBe([VoiceMentions::REPEATED => 1, VoiceHygiene::TOO_MANY_SENTENCES => 1]);
    VoiceAgent::assertPromptedTimes(1);
    VoiceFactCheck::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "Post:\nSatoshi schlägt Hal 3:1. Stark gespielt."));
});

it('counts the fact check\'s tokens into the monthly cost', function () {
    VoiceAgent::fake([['text' => 'Satoshi schlägt Hal 3:1. Stark gespielt!', 'canon_id' => null, 'mood' => 'hype']]);
    VoiceFactCheck::fake([new StructuredTextResponse(['claims' => []], '{}', new TextUsage(1200, 20, 0, 0), new Meta('anthropic', 'claude-haiku-5-5'))]);

    leagueVoice()->say('win', voiceFacts(), 'stream_chat');

    expect((array) DB::table('voice_usage')->where('channel', LeagueVoice::FACT_CHECK)->first(['input_tokens', 'output_tokens', 'accepted']))
        ->toBe(['input_tokens' => 1200, 'output_tokens' => 20, 'accepted' => 1])
        ->and(app(VoiceUsage::class)->monthCost())->toBeGreaterThan(0.0);
});
