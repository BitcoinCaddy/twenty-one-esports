<?php

namespace App\Support\Voice;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The laravel/ai agent behind LeagueVoice and the TV voices: the fixed half of
 * the prompt as cached system instructions and a structured answer {text,
 * canon_id, mood}. Tests fake it with VoiceAgent::fake().
 *
 * The cache breakpoints are its own (systemBlocks(), sent as the Anthropic
 * `system` through providerOptions(), which laravel/ai merges over the request
 * body, BuildsTextRequests): laravel/ai's CacheInstructions stamps one
 * `cache_control` on the last system block only. Here the fixed part is one
 * block with `$cacheTtl`, and a slowly changing `$context` (the world memory
 * frozen per epoch, WorldMemory::epochDigest()) a second one with the 5-minute
 * default: a longer TTL ahead of a shorter one, as the API requires.
 */
final class VoiceAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    /**
     * "Thinking off" as the model takes it: Haiku accepts `disabled`, the
     * newer Sonnet and Opus models only `between_tools` (the API refused
     * `disabled` for claude-sonnet-5-5 with a 400, 2026-10-12).
     *
     * @return array{type: string}
     */
    public static function thinkingOff(string $model): array
    {
        return str_contains($model, 'haiku') ? ['type' => 'disabled'] : ['type' => 'between_tools'];
    }

    use Promptable;

    public const MOODS = ['hype', 'deadpan', 'frage', 'understatement', 'call', 'fakten', 'zitat'];

    /**
     * @param  float|null  $temperatureOverride  sampling temperature (null: the provider's default); the TV voice samples hot for variance
     * @param  int|null  $maxTokensOverride  output tokens (null: `esports.voice.max_output_tokens`); a long TV segment needs more than a post
     * @param  bool  $plan  the TV voice plans its bit before it writes (PLAN_FIELDS ahead of `text`)
     * @param  int|null  $timeoutOverride  seconds (null: `esports.voice.timeout_seconds`); a five-minute show takes longer to write than a post
     * @param  string|null  $context  a second cached system block behind the fixed part (null: none)
     * @param  string|null  $cacheTtl  the fixed part's cache TTL, "1h" or "5m" (null: the API's default, five minutes)
     * @param  string|null  $modelOverride  the model (null: `esports.voice.model`); the talk show (P20) and the day stock (`stock.model`) write with their own
     */
    public function __construct(
        private readonly string $fixedPart = '',
        private readonly ?float $temperatureOverride = null,
        private readonly ?int $maxTokensOverride = null,
        private readonly bool $plan = false,
        private readonly ?int $timeoutOverride = null,
        private readonly ?string $context = null,
        private readonly ?string $cacheTtl = null,
        private readonly ?string $modelOverride = null,
    ) {}

    /**
     * The fields a TV answer fills before `text` (plan "TWENTY ONE Radio" P8): the one point of the bit and what its
     * last sentence lands on. With thinking off and structured output the model cannot work anything out before it
     * answers (Anthropic, "Prompting Claude Sonnet 5.5", Reasoning tasks with JSON output, read 2026-10-12); the
     * fields ahead of `text` are that step. Never spoken.
     */
    public const PLAN_FIELDS = ['punkt', 'pointe'];

    public function temperature(): ?float
    {
        return $this->temperatureOverride;
    }

    /** The whole system prompt as one text (a provider without cache blocks, the fakes). */
    public function instructions(): string
    {
        return $this->context === null || trim($this->context) === '' ? $this->fixedPart : $this->fixedPart."\n\n".$this->context;
    }

    public function context(): ?string
    {
        return $this->context;
    }

    /**
     * The Anthropic system blocks, each with its cache breakpoint: the fixed part, then the context.
     *
     * @return list<array{type: string, text: string, cache_control: array<string, string>}>
     */
    public function systemBlocks(): array
    {
        $blocks = [];

        if ($this->fixedPart !== '') {
            $blocks[] = ['type' => 'text', 'text' => $this->fixedPart, 'cache_control' => array_filter(['type' => 'ephemeral', 'ttl' => $this->cacheTtl])];
        }

        if ($this->context !== null && trim($this->context) !== '') {
            $blocks[] = ['type' => 'text', 'text' => $this->context, 'cache_control' => ['type' => 'ephemeral']];
        }

        return $blocks;
    }

    public function provider(): string
    {
        return (string) config('esports.voice.provider', 'anthropic');
    }

    public function model(): string
    {
        return $this->modelOverride ?? (string) config('esports.voice.model', 'claude-haiku-5-5');
    }

    public function timeout(): int
    {
        return $this->timeoutOverride ?? (int) config('esports.voice.timeout_seconds', 15);
    }

    public function maxTokens(): int
    {
        return $this->maxTokensOverride ?? (int) config('esports.voice.max_output_tokens', 400);
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

        if ($name !== 'anthropic') {
            return [];
        }

        $blocks = $this->systemBlocks();

        return [...($blocks === [] ? [] : ['system' => $blocks]), 'thinking' => VoiceAgent::thinkingOff($this->model()), 'output_config' => ['effort' => 'low']];
    }

    public function schema(JsonSchema $schema): array
    {
        $plan = [];
        foreach ($this->plan ? self::PLAN_FIELDS : [] as $field) {
            $plan[$field] = $schema->string()->required();
        }

        return [
            ...$plan,
            'text' => $schema->string()->required(),
            'canon_id' => $schema->string()->nullable()->required(),
            'mood' => $schema->string()->enum(self::MOODS)->required(),
        ];
    }
}
