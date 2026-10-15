<?php

use App\Support\Caster\OpencodeGateway;
use App\Support\Voice\TextModel;
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

it('switches the workstation model at runtime and back to the config', function () {
    config(['esports.voice.opencode.model' => 'zai-coding-plan/glm-5.3-flash']);
    $this->artisan('caster:text', ['provider' => 'opencode', '--model' => 'zai-coding-plan/glm-5.2'])->assertSuccessful();
    expect((new VoiceAgent('x'))->model())->toBe('zai-coding-plan/glm-5.2');

    $this->artisan('caster:text', ['--model' => 'default'])->assertSuccessful();
    expect((new VoiceAgent('x'))->model())->toBe('zai-coding-plan/glm-5.3-flash');

    $this->artisan('caster:text', ['--model' => 'rm -rf'])->assertFailed();
});

it('sends live chat reactions on their own lane on the workstation, everything else on the shared one', function () {
    TextModel::switchTo('opencode');
    $agent = new VoiceAgent('x');

    expect($agent->provider())->toBe('opencode')
        ->and(TextModel::asChat(fn (): string => $agent->provider()))->toBe('opencode_chat')
        ->and($agent->provider())->toBe('opencode')
        ->and(config('ai.providers.opencode_chat.url'))->toBe('http://127.0.0.1:4099/v1');

    TextModel::switchTo('anthropic');
    expect(TextModel::asChat(fn (): string => $agent->provider()))->toBe('anthropic');
});

it('reads a JSON answer whose text carries raw line breaks, as GLM writes it', function () {
    $raw = "{\"punkt\": \"Ben\", \"text\": \"Erster Satz.\nZweiter Satz mit \\\"Zitat\\\".\", \"mood\": \"hype\"}";

    expect(json_decode((string) OpencodeGateway::jsonObject($raw), true))
        ->toBe(['punkt' => 'Ben', 'text' => "Erster Satz.\nZweiter Satz mit \"Zitat\".", 'mood' => 'hype']);
});

it('names the model-specific regie file after the model, whatever names the model', function () {
    expect(OpencodeGateway::regieFileFor('/srv/regie.md', 'zai-coding-plan/glm-5.3-flash'))->toBe('/srv/regie.zai-coding-plan-glm-5.3-flash.md')
        ->and(OpencodeGateway::regieFileFor('/srv/regie', 'anthropic/claude sonnet:5.5'))->toBe('/srv/regie.anthropic-claude-sonnet-5.5.md');
});

it('sends the model-specific regie after the common one, winning their conflicts', function () {
    $dir = sys_get_temp_dir().'/regie-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $common = $dir.'/regie.md';
    $model = $dir.'/regie.zai-coding-plan-glm-5.3-flash.md';
    file_put_contents($common, "// editor note\nImmer frech bleiben.");
    file_put_contents($model, "// another editor note\nWeniger Sätze als sonst.");
    $seen = [];
    $gateway = new OpencodeGateway('/cfg', '/work', 'zai-coding-plan/glm-5.3-flash', $common, runner: function (string $m, string $prompt) use (&$seen): string {
        $seen = $prompt;

        return json_encode(['type' => 'text', 'part' => ['text' => 'Hallo.']]);
    });

    [$status] = $gateway->handle(['model' => 'zai-coding-plan/glm-5.3-flash', 'messages' => [['role' => 'user', 'content' => 'Ein Satz.']]]);

    expect($status)->toBe(200)
        ->and($seen)->toContain('Immer frech bleiben.')
        ->and($seen)->toContain('# Nur für dieses Modell (überschreibt bei Widerspruch die Regeln darüber)')
        ->and($seen)->toContain('Weniger Sätze als sonst.')
        ->and($seen)->not->toContain('editor note')
        ->and(strpos($seen, 'Immer frech bleiben.'))->toBeLessThan(strpos($seen, 'Weniger Sätze als sonst.'));

    // Another model gets the common rules only, never the flash tuning.
    $seen = '';
    $gateway->handle(['model' => 'zai-coding-plan/glm-5.2', 'messages' => [['role' => 'user', 'content' => 'Ein Satz.']]]);
    expect($seen)->toContain('Immer frech bleiben.')->and($seen)->not->toContain('Weniger Sätze als sonst.');

    // With no model file at all, the common one stands alone.
    unlink($model);
    $seen = '';
    $gateway->handle(['model' => 'zai-coding-plan/glm-5.3-flash', 'messages' => [['role' => 'user', 'content' => 'Ein Satz.']]]);
    expect($seen)->toContain('Immer frech bleiben.')->and($seen)->not->toContain('Nur für dieses Modell');

    unlink($common);
    rmdir($dir);
});
