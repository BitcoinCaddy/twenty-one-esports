<?php

namespace App\Support\Nostr;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;

/**
 * A live Nostr subscription (NIP-01): one REQ without an end, and the relays push every
 * event the moment it arrives — the stream chat read in real time (user 2026-10-17: "keine
 * Verzögerung mehr": the minute-polled reads answered up to a minute after the message).
 *
 * The loop owns nothing but its own process (caster:chat-live, its own lane): a relay that
 * hangs, dies or goes silent costs this subscription a reconnect — it rotates the relays of
 * the stream chat, one after the other — never another lane's airtime. The filters carry
 * `since` the chat cursor, so a gap left by a dropped socket is closed by the next REQ (and
 * by the polled reads, which stay on as the safety net). A silent relay is a working
 * subscription: most seconds are quiet, and only minutes of quiet end in a reconnect.
 */
final class RelaySubscription
{
    /** Reconnect after this many receive timeouts in a row (each the socket timeout long). */
    private const RECONNECT_AFTER_QUIET = 6;

    /** The receive timeout: how long one wait for a frame lasts before it counts as quiet. */
    private const RECEIVE_SECONDS = 20.0;

    /**
     * @param  list<string>  $relays  rotated on every (re)connect
     * @param  (Closure(string): Client)|null  $connect  how to open a client, tests
     */
    public function __construct(
        private readonly array $relays,
        private readonly ?Closure $connect = null,
    ) {}

    /**
     * Push every event that matches the filters to `$onEvent` until `$seconds` have passed.
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  Closure(SignedEvent): void  $onEvent
     * @param  (Closure(string): void)|null  $log
     * @return int the events pushed
     */
    public function run(int $seconds, array $filters, Closure $onEvent, ?Closure $log = null): int
    {
        $pushed = 0;
        $end = microtime(true) + max(1, $seconds);
        $round = 0;

        while (microtime(true) < $end && $this->relays !== []) {
            $relay = $this->relays[$round % count($this->relays)];
            $round++;

            $client = null;

            try {
                $client = $this->connect !== null ? ($this->connect)($relay) : new Client($relay);
                $id = 'live-'.bin2hex(random_bytes(3));
                $client->setTimeout(self::RECEIVE_SECONDS);
                $client->text((string) json_encode(['REQ', $id, ...$filters], JSON_UNESCAPED_SLASHES));
                $quiet = 0;

                while (microtime(true) < $end) {
                    try {
                        $frame = $client->receive();
                    } catch (ConnectionTimeoutException) {
                        // The socket's own wait expired: no frame in RECEIVE_SECONDS. Quiet
                        // is normal; only minutes of it end the round for a fresh REQ.
                        if (++$quiet >= self::RECONNECT_AFTER_QUIET) {
                            break;
                        }

                        continue;
                    }

                    $quiet = 0;

                    if (! $frame instanceof Text) {
                        continue;
                    }

                    $message = json_decode($frame->getContent(), true);

                    if (! is_array($message) || ($message[0] ?? null) !== 'EVENT') {
                        continue;
                    }

                    $event = SignedEvent::fromInput($message[2] ?? null);

                    if ($event === null) {
                        continue;
                    }

                    $pushed++;
                    $onEvent($event);
                }

                try {
                    $client->text((string) json_encode(['CLOSE', $id]));
                } catch (Throwable) {
                    // Closing is politeness; the socket ends either way.
                }
            } catch (Throwable $exception) {
                if ($log !== null) {
                    $log(sprintf('%s dropped: %s', $relay, $exception->getMessage()));
                }
                Log::warning('Relay subscription dropped', ['relay' => $relay, 'exception' => $exception::class]);

                usleep(500_000);
            } finally {
                try {
                    $client?->close();
                } catch (Throwable) {
                    // Already gone.
                }
            }
        }

        return $pushed;
    }
}
