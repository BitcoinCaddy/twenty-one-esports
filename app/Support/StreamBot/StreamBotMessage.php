<?php

namespace App\Support\StreamBot;

/**
 * One chat message a builder offers: which builder, which fact (the
 * dedup key: the same fact is not posted again within
 * `esports.stream_bot.repeat_hours`), the text and the `p` tags of the
 * players it names (none for most messages). The template, its wording and
 * values it was rendered from go along, so the league voice can write the
 * same facts in its own words (BotVoice, plan "KI-Stimme der Liga", P2).
 */
final readonly class StreamBotMessage
{
    public function __construct(
        public string $builder,
        public string $factKey,
        public string $content,
        /** @var list<list<string>> */
        public array $tags = [],
        public ?string $template = null,
        public int $variant = 0,
        /** @var array<string, string|int|null> */
        public array $values = [],
    ) {}

    /**
     * The same message with the text and tags the post goes out with.
     *
     * @param  list<list<string>>  $tags
     */
    public function withContent(string $content, array $tags): self
    {
        return new self($this->builder, $this->factKey, $content, $tags, $this->template, $this->variant, $this->values);
    }
}
