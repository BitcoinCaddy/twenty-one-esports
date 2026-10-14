<?php

namespace App\Jobs;

use App\Support\Caster\ChatReactions;
use App\Support\Nostr\SignedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes the spoken reaction to one stream chat message (ChatReactions::reactTo()) on the `caster-chat` queue, so
 * `caster:chat` keeps reading the chat while it is written and a burst is written side by side (user 2026-10-14:
 * "erkennt er neue Chat Messages im Nostr Chat eventuell einfach zu spät?"; written in the reading loop, one reaction
 * held every read behind it for 30 s to over two minutes). Unique per event; one try.
 */
class ReactToChatMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /**
     * @param  array<string, mixed>  $event  the signed kind 1311 event as received (checked again here)
     */
    public function __construct(public array $event)
    {
        $this->onConnection('redis-chat')->onQueue('caster-chat');
    }

    public function uniqueId(): string
    {
        return (string) ($this->event['id'] ?? '');
    }

    public function handle(ChatReactions $reactions): void
    {
        $event = SignedEvent::fromInput($this->event);

        if ($event !== null) {
            $reactions->reactTo($event, CarbonImmutable::now());
        }
    }
}
