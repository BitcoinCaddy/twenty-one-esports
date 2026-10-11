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
 * The second look at Markus' take on a followed profile's Nostr note
 * (App\Support\Caster\CasterVoice::followedNote()): like NewsFactCheck, with
 * the note as the only fact. The take retells the note and reacts to it;
 * opinion, jokes and slogans are no claims, but anything it says the note
 * says that the note does not say is one, and so is any new fact about the
 * world. One listed claim rejects the take, a failed or unreadable answer
 * too (fail closed). Same provider, model and options as NewsFactCheck;
 * tests fake it.
 */
#[CacheInstructions]
final class NoteFactCheck implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
            You check a short spoken take on a social media note (Nostr). The take first retells the note in the speaker's own words, possibly with a short word-for-word quote, then reacts to it. The note is the only fact.
            The reaction is meant to be opinion: jokes, irony, teasing, value judgements, rules of thumb, obvious exaggerations, rhetorical questions, Bitcoin or freedom slogans and general views on money, the state or politics. None of these is a claim.
            A playful conclusion drawn from the note is a joke, not a claim either: "so he never wants to get to the top", "so I am an algorithm with an accent now", a self-ironic aside of the speaker, a made-up image or comparison. The speaker may be the note's author himself.
            List only (a) statements presented as what the note says or shows that the note does not support (an invented detail, a wrong number, a reversed or changed meaning, words quoted that the note does not contain), and (b) statements of FACT about the world, real people or real events that the note does not make: an event, number, name, place, date, quote, statement someone made, cause or consequence. A faithful retelling in other words, also translated, is supported.
            Answer only in the schema: `claims` lists them, each with `claim` (word for word from the take), `evidence` (the supporting words of the note, or empty) and `supported` (false when the note does not support it). No such statement: an empty list.
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

        return $name === 'anthropic' ? ['thinking' => ['type' => 'disabled'], 'output_config' => ['effort' => (string) config('esports.voice.fact_check.effort', 'low')]] : [];
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

    /** The user message: the author, the note and the take. */
    public static function message(string $author, string $note, string $take): string
    {
        return "Author: {$author}\n\nNote:\n{$note}\n\nTake:\n{$take}";
    }
}
