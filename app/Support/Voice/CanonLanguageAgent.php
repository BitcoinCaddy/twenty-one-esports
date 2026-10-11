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
 * The one-off judge behind `voice:mark-english` (plan "KI-Stimme der Liga",
 * P2): which canon entries an English reader understands as a sense
 * translation. Same provider, model and options as VoiceAgent (Claude Haiku,
 * no thinking, effort low); a batch of entries in, a structured list of
 * {id, en_ok} out.
 */
#[CacheInstructions]
final class CanonLanguageAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
            You sort the entries of a meme canon of the German Bitcoin community EINUNDZWANZIG for an English-speaking Nostr audience.
            For each entry decide `en_ok`:
            - true: an English reader gets the joke or point when the line is carried over by its sense (Bitcoin, Nostr and esports memes, general wisdom, English phrases, formats like "one in the chat if …").
            - false: it only works with German context: a German pun or wordplay, dialect, rhymes that die in translation, German media, TV, politics, German institutions or places, German celebrities, insider references to the German podcast that need its back story.
            Decide every entry you are given, exactly once, with its id as given. Answer only in the schema.
            TEXT;
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
        return 120;
    }

    public function maxTokens(): int
    {
        return 8000;
    }

    /**
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
            'decisions' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required(),
                'en_ok' => $schema->boolean()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
