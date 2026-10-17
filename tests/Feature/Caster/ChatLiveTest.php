<?php

use App\Console\Commands\CasterChatLiveCommand;
use App\Models\CasterScript;
use App\Models\WotLevel;
use App\Support\Caster\ChatReactions;
use App\Support\Caster\ClipSpool;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelaySubscription;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Message;
use WebSocket\Message\Text;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the stream chat read in real time
|--------------------------------------------------------------------------
|
| User 2026-10-17: "Realtime rein, keine Verzögerung mehr" - a live NIP-01
| subscription (RelaySubscription) pushes every 1311 message and 9735 zap of
| the running stream into ChatReactions::ingest() the moment a relay sends
| it, down the same road as the polled reads: same gates, same cursors, a
| pushed message cannot be answered twice.
|
*/

beforeEach(function () {
    $this->streamKey = new TestSigner;
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.chat.reactions' => true,
        'tv.commentary.chat.per_person_minutes' => 0,
        'esports.stream_bot.chat_relays' => ['wss://chat.test'],
        'esports.stream_chat.relays' => null,
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->streamKey->pubkey),
        'twentyone.nostr.nsec' => null,
        'twentyone.stream.relays' => ['wss://one.test'],
    ]);
});

/** A trusted chatter's chat message of the running stream. */
function liveMessage(int $at = 0): SignedEvent
{
    $signer = new TestSigner;

    if (! WotLevel::query()->whereKey($signer->pubkey)->exists()) {
        WotLevel::query()->create(['pubkey' => $signer->pubkey, 'level' => 1, 'computed_at' => now()]);
    }

    return SignedEvent::fromInput($signer->sign(1311, [['a', StreamCoordinates::fromConfig()->address(), '', 'root']], 'Live aus dem Chat, echt zeitig!', $at ?: CarbonImmutable::now()->getTimestamp()));
}

it('answers a pushed message at once, and never the same one twice', function () {
    VoiceAgent::fake(fn (): array => ['text' => 'Danke für die Live-Zeile, das Radio hört zu.', 'canon_id' => null, 'mood' => 'fakten']);
    $event = liveMessage();

    expect(app(ChatReactions::class)->ingest([$event], CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->where('kind', CasterScript::CHAT)->count())->toBe(1)
        // The cursor moved past the message: the polled safety net will not read it again.
        ->and(Cache::get('caster:chat_cursor'))->toBe($event->createdAt + 1)
        ->and(app(ChatReactions::class)->ingest([$event], CarbonImmutable::now()))->toBe(0)
        ->and(CasterScript::query()->where('kind', CasterScript::CHAT)->count())->toBe(1);
});

it('stays silent with the reactions off, and moves no cursor while the autopilot runs', function () {
    $event = liveMessage();

    config(['tv.commentary.chat.reactions' => false]);
    expect(app(ChatReactions::class)->ingest([$event], CarbonImmutable::now()))->toBe(0);

    config(['tv.commentary.chat.reactions' => true, 'tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.stock.enabled' => true, 'tv.commentary.blocks.stock.library.enabled' => true]);
    Illuminate\Support\Facades\Cache::put(ClipSpool::FETCHER_SEEN, CarbonImmutable::now()->subHour()->getTimestamp(), 600);
    expect(app(ChatReactions::class)->ingest([$event], CarbonImmutable::now()))->toBe(0)
        ->and(Cache::get('caster:chat_cursor'))->toBeNull();
});

it('pushes only well-formed events of the subscription, rotating a silent relay away', function () {
    $chatter = liveMessage();
    $frame = static fn (string $json): Text => new Text($json);
    $event = ['EVENT', 'live-x', ['id' => str_repeat('a', 64), 'pubkey' => $chatter->pubkey, 'created_at' => $chatter->createdAt, 'kind' => 1311, 'tags' => [['a', StreamCoordinates::fromConfig()->address()]], 'content' => 'push', 'sig' => str_repeat('b', 128)]];
    $malformed = ['EVENT', 'live-x', ['id' => 'nope']];
    $seen = [];
    $clients = 0;

    $connect = function () use (&$clients): object {
        $clients++;

        return new class($clients === 1 ? [$frame((string) json_encode($event)), $frame((string) json_encode($malformed))] : [])
        {
            /** @var list<Text> */
            public array $remaining;

            /** @param list<Text> $frames */
            public function __construct(array $frames = [])
            {
                $this->remaining = $frames;
            }

            public function setTimeout(float $seconds): void {}

            public function text(string $payload): void {}

            public function receive(): Message
            {
                if ($this->remaining !== []) {
                    return array_shift($this->remaining);
                }

                throw new ConnectionTimeoutException;
            }

            public function close(): void {}
        };
    };

    $subscription = new RelaySubscription(['wss://one.test', 'wss://two.test'], $connect);
    $pushed = $subscription->run(1, [['kinds' => [1311], 'limit' => 1]], function (SignedEvent $event) use (&$seen): void {
        $seen[] = $event->createdAt;
    });

    expect($pushed)->toBe(1)->and($seen)->toBe([$chatter->createdAt])->and($clients)->toBeGreaterThanOrEqual(2);
});

it('says so and stays put without a stream or chat relays', function () {
    config(['twentyone.stream.event.d' => null]);
    $this->artisan(CasterChatLiveCommand::class)->expectsOutputToContain('nothing to subscribe')->assertSuccessful();
});
