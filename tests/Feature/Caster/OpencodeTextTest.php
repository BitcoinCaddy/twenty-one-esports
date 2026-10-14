<?php

use App\Support\Caster\OpencodeGateway;
use App\Support\Voice\VoiceAgent;
use Illuminate\Support\Facades\Cache;

it('switches every agent between anthropic and the workstation without a deploy', function () {
    config(['esports.voice.provider' => 'anthropic', 'esports.voice.model' => 'claude-sonnet-5-5', 'esports.voice.opencode.model' => 'zai-coding-plan/glm-5.3-flash']);
    $agent = new VoiceAgent('x');

    expect([$agent->provider(), $agent->model(), $agent->timeout()])->toBe(['anthropic', 'claude-sonnet-5-5', 15]);

    $this->artisan('caster:text', ['provider' => 'opencode'])->assertSuccessful();
    expect([$agent->provider(), $agent->model(), $agent->timeout()])->toBe(['opencode', 'zai-coding-plan/glm-5.3-flash', 90])
        ->and($agent->providerOptions('opencode'))->toBe([])
        ->and(config('ai.providers.opencode.driver'))->toBe('openai-compatible');

    $this->artisan('caster:text', ['provider' => 'anthropic'])->assertSuccessful();
    expect($agent->provider())->toBe('anthropic');

    $this->artisan('caster:text', ['provider' => 'gpt'])->assertFailed();
    expect(Cache::get('voice:text_provider'))->toBe('anthropic');
});

it('turns a chat request into one prompt with the regie and answers with the JSON object only', function () {
    $regie = tempnam(sys_get_temp_dir(), 'regie');
    file_put_contents($regie, "// note for the editor\nImmer frech bleiben.");
    $seen = [];
    $gateway = new OpencodeGateway('/cfg', '/work', 'zai-coding-plan/glm-5.3-flash', $regie, runner: function (string $model, string $prompt) use (&$seen): string {
        $seen = [$model, $prompt];

        return json_encode(['type' => 'step_start'])."\n".json_encode(['type' => 'text', 'part' => ['text' => "Klar:\n```json\n{\"text\": \"Hallo {Welt}\", \"mood\": \"hype\"}\n```"]]);
    });

    [$status, $body] = $gateway->handle([
        'model' => 'zai-coding-plan/glm-5.3-flash; rm -rf /',
        'messages' => [['role' => 'system', 'content' => 'Du bist Markus.'], ['role' => 'user', 'content' => 'Zwei Sätze.']],
        'response_format' => ['type' => 'json_schema', 'json_schema' => ['schema' => ['type' => 'object', 'required' => ['text']]]],
    ]);

    expect($status)->toBe(200)
        ->and(json_decode($body['choices'][0]['message']['content'], true))->toBe(['text' => 'Hallo {Welt}', 'mood' => 'hype'])
        ->and($body['choices'][0]['finish_reason'])->toBe('stop')
        ->and($seen[0])->toBe('zai-coding-plan/glm-5.3-flash')
        ->and($seen[1])->toContain("# Anweisungen\n\nDu bist Markus.")
        ->and($seen[1])->toContain('Immer frech bleiben.')
        ->and($seen[1])->not->toContain('note for the editor')
        ->and($seen[1])->toContain('"required":["text"]');

    unlink($regie);
});

it('answers an error when opencode fails or gives no JSON, so the caller plays music instead', function (string $events) {
    $gateway = new OpencodeGateway('/cfg', '/work', 'm/x', runner: fn (): string => $events);

    [$status, $body] = $gateway->handle(['messages' => [['role' => 'user', 'content' => 'x']], 'response_format' => ['json_schema' => ['schema' => ['type' => 'object']]]]);

    expect($status)->toBe(502)->and($body)->toHaveKey('error');
})->with([
    'error event' => [json_encode(['type' => 'error', 'error' => ['message' => 'Model unavailable']])],
    'prose only' => [json_encode(['type' => 'text', 'part' => ['text' => 'Ich kann das nicht.']])],
]);
