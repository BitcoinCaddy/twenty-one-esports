<?php

namespace App\Support\Voice;

/**
 * A post the league voice wrote and the guards let through: the text as it
 * goes out (mentions as `nostr:npub1…`), the `p` tags of those mentions, the
 * canon entry it quotes and its mood.
 */
final readonly class VoiceLine
{
    /**
     * @param  list<list<string>>  $tags
     */
    public function __construct(
        public string $text,
        public array $tags = [],
        public ?string $canonId = null,
        public string $mood = '',
    ) {}
}
