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
 * The second look at a TV news comment (App\Support\Caster\CasterVoice::news()):
 * like VoiceFactCheck, but for opinion on a headline. VoiceFactCheck lists
 * every claim of a league post, and on a comment it flagged rules of thumb
 * and sarcasm ("Wenn die Politik Autos zur Weltordnung erklärt, wird's
 * teuer.") as unsupported in 8 of 9 real drafts (2026-10-11). This one lists
 * only statements of fact about the story the headline does not make; one
 * listed claim rejects the comment, a failed or unreadable answer too (fail
 * closed). Same provider, model and options as VoiceFactCheck; tests fake it.
 */
#[CacheInstructions]
final class NewsFactCheck implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
            You check a short spoken comment on a news headline. The headline was read out word for word just before the comment; it is the only fact.
            The comment is meant to be opinion: sarcasm, irony, mockery of a policy, a party or an institution, value judgements, rules of thumb ("Wenn …, dann …"), obvious exaggerations, rhetorical questions, Bitcoin or freedom slogans and general views on money, taxes, the state or politics. None of these is a claim.
            List only statements of FACT about the story that the headline does not make: an event, number, name, place, date, quote, statement someone made, cause or consequence of this particular story. Restating the headline in other words is supported.
            Answer only in the schema: `claims` lists them, each with `claim` (word for word from the comment), `evidence` (the supporting words of the headline, or empty) and `supported` (false when the headline does not state it). No such statement: an empty list.
            TEXT;
    }

    public function provider(): string
    {
        return (string) config('esports.voice.provider', 'anthropic');
    }

    public function model(): string
    {
        return (string) config('esports.voice.fact_check.model', config('esports.voice.model', 'claude-haiku-5-5'));
    }

    public function timeout(): int
    {
        return (int) config('esports.voice.timeout_seconds', 15);
    }

    public function maxTokens(): int
    {
        return (int) config('esports.voice.fact_check.max_tokens', 1200);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        return $name === 'anthropic' ? ['thinking' => VoiceAgent::thinkingOff($this->model()), 'output_config' => ['effort' => (string) config('esports.voice.fact_check.effort', 'low')]] : [];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'claims' => $schema->array()->items($schema->object([
                'claim' => $schema->string()->required(),
                'evidence' => $schema->string()->required(),
                'supported' => $schema->boolean()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }

    /** The user message: the source, the headline and the comment. */
    public static function message(string $source, string $headline, string $comment): string
    {
        return "Source: {$source}\n\nHeadline:\n{$headline}\n\nComment:\n{$comment}";
    }
}
