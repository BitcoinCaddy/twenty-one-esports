<?php

namespace App\Console\Commands;

use App\Support\Caster\ChatReactions;
use App\Support\Nostr\RelaySubscription;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\StreamChat\StreamChat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream chat read in real time (user 2026-10-17: "Realtime rein, keine Verzögerung
 * mehr" — the polled reads answered up to a minute after the message): one live NIP-01
 * subscription over the /live chat's own relays, kind 1311 messages and 9735 zap receipts
 * of the running stream, every event handed to ChatReactions::ingest() the moment the
 * relay pushes it. The REQ carries `since` the chat cursor, so a socket's gap closes with
 * the next round; the polled reads (caster:chat) stay on as the safety net behind it.
 *
 * A round lasts `live_seconds` (default 270) in its own lane, and the schedule starts the
 * next one five minutes sharp: the relay sockets and their failures live here and nowhere
 * else — the lesson of the day — and a fresh round also picks up deployed code within
 * minutes, which a real daemon would not.
 */
#[Signature('caster:chat-live')]
#[Description('Read the stream chat in real time (a live relay subscription) and queue reactions at once')]
class CasterChatLiveCommand extends Command
{
    public function handle(ChatReactions $reactions): int
    {
        if (! ChatReactions::enabled()) {
            $this->line('Chat reactions are off (TV_COMMENTARY, TV_COMMENTARY_CHAT).');

            return self::SUCCESS;
        }

        $stream = StreamCoordinates::fromConfig();
        $relays = $stream === null ? [] : StreamChat::relaysFor($stream);

        if ($stream === null || $relays === []) {
            $this->line('No stream or no chat relays: nothing to subscribe to.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $filters = [['kinds' => config('tv.commentary.chat.zaps', true) === true ? [1311, 9735] : [1311], '#a' => [$stream->address()], 'since' => ChatReactions::subscriptionSince($now), 'limit' => 1]];
        $seconds = max(30, (int) config('tv.commentary.chat.live_seconds', 270));
        $queued = 0;

        $pushed = (new RelaySubscription($relays))->run(
            $seconds,
            $filters,
            function (SignedEvent $event) use ($reactions, &$queued): void {
                $queued += $reactions->ingest([$event], CarbonImmutable::now());
            },
            function (string $note): void {
                $this->line($note);
            },
        );

        $this->line("Live chat: {$pushed} event(s), {$queued} reaction(s) queued, {$seconds} s.");

        return self::SUCCESS;
    }
}
