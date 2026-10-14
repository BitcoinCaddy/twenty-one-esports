<?php

namespace App\Support\Voice;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The second, cheap look at a voice text (plan "KI-Stimme der Liga", P2):
 * Claude Haiku lists every factual claim of the text with the fact that
 * supports it (asked for the evidence, it stopped flagging paraphrases such
 * as "leads" for "new first place", measured 2026-10-11). The deterministic guard (VoiceGuard) catches invented numbers,
 * names and links; a claim without any ("Blockfill Week 42 is done" for a
 * week that runs on, measured in the first real eval run) only a reader
 * catches. LeagueVoice asks it after the guard passed a text; one listed
 * claim rejects the text, a failed request sends the template (fail closed).
 * Same provider, model and options as VoiceAgent; tests fake it.
 */
#[CacheInstructions]
final class VoiceFactCheck implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
            You check a short post of an esports league against the facts it was written from.
            List every factual claim in the post (a result, state, time, record, streak, score, reason, who won or lost, whether something is open, running or finished, anything said about a person) and, for each, the fact that supports it.
            A claim is supported when the facts state it, in any words: "first place" is "leads", "on top", "takes the lead"; "wins" is "takes". The `template` fact says what the moment is. A date in the facts after "now" is still to come: whatever ends then is still running.
            Not a claim: greetings and shout-outs ("Grüße gehen raus …"), exclamations, jokes and sayings, questions (also rhetorical ones and calls to write in the chat: "Wer von euch …?", "Schreibt in den Chat, wer …"), invitations to play, watch, log in, sign up or bring friends, pride or praise without a fact in it ("Nice one!", "Das ist unsere Liga."), and the quoted canon line given with the post (a known community meme).
            A sentence that says what a line under `league_basics` says is supported by it.
            A person's mention token like {@winner} stands for the person of that role in the facts.
            Answer only in the schema: `claims` lists them, each with `claim` (word for word from the post), `evidence` (the supporting fact, word for word from the facts, or empty) and `supported` (false when no fact supports it or a fact contradicts it).
            TEXT;
    }

    public function provider(): string
    {
        return TextModel::provider();
    }

    public function model(): string
    {
        return TextModel::model((string) config('esports.voice.fact_check.model', config('esports.voice.model', 'claude-haiku-5-5')));
    }

    public function timeout(): int
    {
        return TextModel::timeout((int) config('esports.voice.timeout_seconds', 15));
    }

    /** Room for one claim per sentence of an eight-sentence script; a cut-off answer is unreadable and fails closed. */
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

    /**
     * The user message: the facts, the canon line the post quotes (if any) and the post.
     *
     * @param  array<array-key, mixed>  $facts
     */
    public static function message(array $facts, string $text, ?string $canonWording, string $moment = 'general', ?CarbonImmutable $now = null): string
    {
        return 'Moment: '.$moment.($now === null ? '' : ' · now: '.$now->toIso8601String())."\n\nFacts:\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nCanon line the post may quote: ".($canonWording ?? 'none')
            ."\n\nPost:\n".$text;
    }
}
