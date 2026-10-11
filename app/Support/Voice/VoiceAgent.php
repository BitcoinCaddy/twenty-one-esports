<?php

namespace App\Support\Voice;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The laravel/ai agent behind LeagueVoice: Claude Haiku with the fixed half
 * of the prompt as cached system instructions (CacheInstructions stamps
 * `cache_control` on the system block, laravel/ai BuildsTextRequests) and a
 * structured answer {text, canon_id, mood}. Only LeagueVoice uses it; tests
 * fake it with VoiceAgent::fake().
 */
#[CacheInstructions]
final class VoiceAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public const MOODS = ['hype', 'deadpan', 'frage', 'understatement', 'call', 'fakten', 'zitat'];

    public function __construct(private readonly string $fixedPart = '') {}

    public function instructions(): string
    {
        return $this->fixedPart;
    }

    public function provider(): string
    {
        return (string) config('esports.voice.provider', 'anthropic');
    }

    public function model(): string
    {
        return (string) config('esports.voice.model', 'claude-haiku-5-5');
    }

    public function timeout(): int
    {
        return (int) config('esports.voice.timeout_seconds', 15);
    }

    public function maxTokens(): int
    {
        return (int) config('esports.voice.max_output_tokens', 400);
    }

    /**
     * Short posts need no thinking. Haiku 5.5 thinks adaptively by default,
     * and the thinking counts against `max_tokens`: measured 2026-10-11 in
     * the first real eval run, all three answers spent the whole 400-token
     * budget on thinking and came back empty. Thinking off (accepted at
     * effort `high` or below) and effort `low`.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        return $name === 'anthropic' ? ['thinking' => ['type' => 'disabled'], 'output_config' => ['effort' => 'low']] : [];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required(),
            'canon_id' => $schema->string()->nullable()->required(),
            'mood' => $schema->string()->enum(self::MOODS)->required(),
        ];
    }
}
