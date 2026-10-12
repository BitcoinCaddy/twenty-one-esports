<?php

namespace App\Support\Nostr;

use App\Support\Wallet\PinnedStreamFactory;
use App\Support\Wallet\RelayGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;

/**
 * Reads events from the configured relays (NIP-01 `REQ` until `EOSE`), for
 * the trust job (opponent lists `30000` and reports `1984`).
 *
 * Every event is checked like any input from outside: well-formed
 * (SignedEvent::fromInput), matching a filter the league sent (a relay may
 * ignore `authors` or `since`), with a valid id and signature. Callers ask
 * with one filter per author and a limit, and the reader keeps at most
 * `$perAuthor` events of each author, newest first over every relay, so
 * nobody's events can crowd out anybody else's (security re-check round 3,
 * Q5) and the relay order decides nothing (round 4).
 *
 * Filters go out in REQs of at most the relay's NIP-11
 * `limitation.max_filters`, never more than 10 (rnostr's limit, the default
 * when the relay does not say), one subscription after the other on one
 * connection per relay and fetch. A relay that advertises fewer than 5 is
 * not read at all (round 4: it would need too many REQs). A relay that fails,
 * times out, refuses a REQ with `CLOSED` or uses up its time budget for the
 * fetch is a failed read: logged with its reason, its events of that fetch
 * dropped. The caller keeps what it already archived, so an outage or a
 * refusal never turns into "nobody lists anybody" (stale but valid, not "no
 * data"). Uses the same websocket client as {@see RelayPublisher}.
 */
class RelayReader
{
    /** Filters per REQ when the relay does not say, and the most ever sent (rnostr allows 10). */
    public const MAX_FILTERS = 10;

    /** Fewer filters per REQ than this, and the relay is not read (round 4). */
    public const MIN_FILTERS = 5;

    /** What an untrusted relay (readGuarded()) may send in all, and in one frame. */
    public const UNTRUSTED_BYTES = 2 * 1024 * 1024;

    public const UNTRUSTED_FRAME_BYTES = 256 * 1024;

    /**
     * A one-time, bounded extension past the REQ deadline: a busy host can
     * preempt *this* process for longer than `relay_timeout_seconds` between
     * sending the REQ and the next `receive()` call, so the deadline is
     * already gone before the loop even checks the socket again — even
     * though the relay answered in time and its frame has been sitting,
     * unread, in the socket buffer the whole while. Not counted against
     * `relay_timeout_seconds`/`relay_fetch_budget_seconds`: a relay that is
     * genuinely slow or hung still times out, just up to this much later.
     */
    private const GRACE_SECONDS = 0.5;

    /** @var array<string, int<1, 10>> relay => filters per REQ, from its NIP-11 document */
    private array $maxFilters = [];

    /**
     * @param  list<array<string, mixed>>  $filters  NIP-01 filters; each needs `kinds`
     * @param  list<string>|null  $relays  null = config('esports.relays')
     * @param  array<string, true>  $known  ids the caller has already (checked and stored): skipped
     *                                      before the costly signature check
     * @param  int  $perAuthor  most events kept per author and fetch, newest first
     * @return list<SignedEvent> distinct by id, none of $known, each matching one of the filters
     */
    public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
    {
        return $this->fetchCounted($filters, $relays, $known, $perAuthor)['events'];
    }

    /**
     * fetch(), plus how many relays were read to EOSE: a caller that must
     * tell "nobody has this event" from "no relay answered" (the DM relay
     * lookup, App\Support\Notifications\DmRelays) needs the count, since a
     * failed read and an empty one both leave no events.
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  list<string>|null  $relays
     * @param  array<string, true>  $known
     * @param  float|null  $until  microtime after which no further relay is read (they count as not answered)
     * @return array{events: list<SignedEvent>, answered: int}
     */
    public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
    {
        $collected = [];
        $answered = 0;

        foreach ($relays ?? config('esports.relays', []) as $relay) {
            if ($until !== null && microtime(true) >= $until) {
                break;
            }

            $read = $this->read($relay, $filters, $known + $collected, $perAuthor);

            // A failed read (null): nothing of this relay counts this time.
            if ($read === null) {
                continue;
            }

            $answered++;

            foreach ($read as $event) {
                $collected[$event->id] = $event;
            }
        }

        // Newest first over every relay, then at most $perAuthor per author.
        usort($collected, fn (SignedEvent $a, SignedEvent $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);
        $events = [];
        $kept = [];

        foreach ($collected as $event) {
            if (($kept[$event->pubkey] ?? 0) < $perAuthor) {
                $events[] = $event;
                $kept[$event->pubkey] = ($kept[$event->pubkey] ?? 0) + 1;
            }
        }

        return ['events' => $events, 'answered' => $answered];
    }

    /**
     * The checked events of each relay on its own, for a caller that must
     * know WHICH relays were read to EOSE, not only how many (the league's
     * mute list needs every relay of a quorum: App\Support\Moderation\LeagueMuteList).
     * Each relay is read like in fetch(); the per-author cap is per relay.
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  list<string>  $relays
     * @return array<string, list<SignedEvent>|null> relay => its events, null for a failed read
     */
    public function readEach(array $filters, array $relays, int $perAuthor = 1): array
    {
        $results = [];

        foreach ($relays as $relay) {
            $results[$relay] = $this->read($relay, $filters, [], $perAuthor);
        }

        return $results;
    }

    /**
     * readEach() for relays a player named (a chatter's NIP-65 write relays,
     * App\Support\Caster\ChatterCache): untrusted input that makes the server
     * connect. Each relay passes {@see RelayGuard} (`wss://` on 443 of a public
     * DNS name, the lookup bounded), the socket is pinned to the checked
     * address, and every read stops at the deadline and UNTRUSTED_BYTES in all
     * (UNTRUSTED_FRAME_BYTES a frame), inside a frame too ({@see BoundedSocketStream}).
     * No NIP-11 lookup: that would be a plain HTTP request to the named host;
     * the filters go out in REQs of MAX_FILTERS. A relay the guard refuses, or
     * one not reached before `$until`, is a failed read (null), never contacted.
     * Ids in `$known`, or returned by an earlier relay of the call, are skipped
     * before the signature check, as in fetchCounted().
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  list<string>  $relays
     * @param  array<string, true>  $known
     * @return array<string, list<SignedEvent>|null> relay => its new events, null for a failed or refused read
     */
    public function readGuarded(array $filters, array $relays, int $perAuthor, float $until, array $known = []): array
    {
        $guard = app(RelayGuard::class);
        $results = [];

        foreach ($relays as $relay) {
            $left = $until - microtime(true);
            $pin = $left > 0.2 ? $guard->target($relay, min(2.0, $left)) : null;
            $results[$relay] = $pin === null ? null : $this->read($relay, $filters, $known, $perAuthor, $pin, $until);

            foreach ($results[$relay] ?? [] as $event) {
                $known[$event->id] = true;
            }
        }

        return $results;
    }

    /**
     * NIP-11 `limitation.max_filters` of the relay, at most MAX_FILTERS, and
     * MAX_FILTERS when the document is missing or says nothing.
     *
     * @return int<1, 10>
     */
    private function maxFiltersOf(string $relay): int
    {
        if (isset($this->maxFilters[$relay])) {
            return $this->maxFilters[$relay];
        }

        $limit = self::MAX_FILTERS;

        try {
            $response = Http::withHeaders(['Accept' => 'application/nostr+json'])->connectTimeout(2)->timeout(2)
                ->get((string) preg_replace('#^ws(s?)://#', 'http$1://', $relay));
            $advertised = $response->successful() ? $response->json('limitation.max_filters') : null;

            if (is_int($advertised) && $advertised > 0) {
                $limit = min($advertised, self::MAX_FILTERS);
            }
        } catch (Throwable) {
            // no NIP-11 document: the default
        }

        return $this->maxFilters[$relay] = $limit;
    }

    /**
     * All filters of one fetch on one connection, a subscription per batch,
     * each collected until EOSE, then checked: the signature check (about
     * 0.1 s each) runs after the connection closed, so a flood of junk cannot
     * use up the time the real events need. Per author at most a few times
     * $perAuthor events are collected; the caller keeps $perAuthor. Each REQ
     * gets `relay_timeout_seconds`, the whole read `relay_fetch_budget_seconds`.
     *
     * With a `$pin` (readGuarded()) the relay is untrusted: no NIP-11 lookup,
     * the socket pinned and bounded, the read ending at `$until` at the latest.
     *
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, mixed>  $skip  ids not to return
     * @param  array{host: string, ip: string|null}|null  $pin  the address RelayGuard checked
     * @return list<SignedEvent>|null null for a failed read (unsupported, error, timeout, CLOSED, budget used up)
     */
    private function read(string $relay, array $filters, array $skip, int $perAuthor, ?array $pin = null, ?float $until = null): ?array
    {
        // Not a relay: not read (so it never counts as one that answered, fetchCounted()).
        if (preg_match('#^wss?://#', $relay) !== 1) {
            return null;
        }

        if ($filters === []) {
            return [];
        }

        $batch = $pin === null ? $this->maxFiltersOf($relay) : self::MAX_FILTERS;

        if ($batch < self::MIN_FILTERS) {
            Log::warning('Relay unsupported', ['relay' => $relay, 'reason' => 'NIP-11 max_filters '.$batch.' is below '.self::MIN_FILTERS]);

            return null;
        }

        $timeout = (float) config('esports.relay_timeout_seconds', 5);
        $budget = microtime(true) + (float) config('esports.relay_fetch_budget_seconds', 15);
        $budget = $until === null ? $budget : min($budget, $until);
        $received = [];
        $perPubkey = [];
        $client = null;
        $deadline = null;

        try {
            $client = new Client($relay);

            if ($pin !== null) {
                $client->setStreamFactory($pin['ip'] !== null
                    ? new PinnedStreamFactory($pin['host'], $pin['ip'], $budget, self::UNTRUSTED_BYTES, self::UNTRUSTED_FRAME_BYTES)
                    : new BoundedStreamFactory($budget, self::UNTRUSTED_BYTES, self::UNTRUSTED_FRAME_BYTES));
            }

            foreach (array_chunk($filters, $batch) as $chunk) {
                $deadline = min(microtime(true) + $timeout, $budget);
                $subscription = 'read-'.bin2hex(random_bytes(4));
                $complete = false;
                $graceUsed = false;
                $client->setTimeout(max(0.05, $deadline - microtime(true)));
                $client->text((string) json_encode(['REQ', $subscription, ...$chunk], JSON_UNESCAPED_SLASHES));

                while (($left = $deadline - microtime(true)) > 0 || ! $graceUsed) {
                    $client->setTimeout(max(0.05, $left > 0 ? $left : self::GRACE_SECONDS));

                    try {
                        $frame = $client->receive();
                    } catch (ConnectionTimeoutException $timeout) {
                        // The socket's own wait, not our loop condition, is what just
                        // expired: it can happen only because the relay's or this
                        // process's answer was not scheduled by the OS in time under
                        // host load, not because the relay never intended to answer.
                        // One short, bounded extension (never counted against
                        // relay_timeout_seconds/relay_fetch_budget_seconds) tells the
                        // two apart; a relay that is genuinely slow or hung still
                        // times out here, just up to GRACE_SECONDS later.
                        if ($graceUsed) {
                            throw $timeout;
                        }

                        $graceUsed = true;
                        $deadline = microtime(true) + self::GRACE_SECONDS;

                        continue;
                    }

                    if (! $frame instanceof Text) {
                        continue;
                    }

                    $message = json_decode($frame->getContent(), true);

                    if (! is_array($message) || ($message[1] ?? null) !== $subscription) {
                        continue;
                    }

                    if (($message[0] ?? null) === 'CLOSED') {
                        Log::warning('Relay refused a read', ['relay' => $relay, 'reason' => (string) ($message[2] ?? ''), 'filters' => count($chunk)]);

                        return null;
                    }

                    if (($message[0] ?? null) === 'EOSE') {
                        $complete = true;

                        break;
                    }

                    $event = ($message[0] ?? null) === 'EVENT' ? SignedEvent::fromInput($message[2] ?? null) : null;

                    if ($event !== null && ! isset($skip[$event->id]) && ($perPubkey[$event->pubkey] ?? 0) < 4 * $perAuthor && self::matchesAny($event, $chunk)) {
                        $received[$event->id] = $event;
                        $perPubkey[$event->pubkey] = ($perPubkey[$event->pubkey] ?? 0) + 1;
                    }
                }

                if (! $complete) {
                    return self::timedOut($relay, $deadline >= $budget, count($chunk));
                }

                $client->text((string) json_encode(['CLOSE', $subscription]));
            }
        } catch (Throwable $exception) {
            // The socket timeout is the wait for $deadline running out: classify it by that
            // deadline, not by the clock read afterwards, which can still be a hair before it.
            if ($exception instanceof ConnectionTimeoutException && $deadline !== null) {
                return self::timedOut($relay, $deadline >= $budget, count($filters));
            }

            if (microtime(true) >= $budget) {
                return self::timedOut($relay, true, count($filters));
            }

            Log::warning('Relay read failed', ['relay' => $relay, 'error' => $exception->getMessage()]);

            return null;
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // already closed
            }
        }

        return array_values(array_filter($received, fn (SignedEvent $event): bool => $event->hasValidSignature()));
    }

    /** @return null a failed read */
    private static function timedOut(string $relay, bool $overBudget, int $filters): null
    {
        Log::warning($overBudget ? 'Relay read over its time budget' : 'Relay read timed out before EOSE', ['relay' => $relay, 'filters' => $filters]);

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private static function matchesAny(SignedEvent $event, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (self::matches($event, $filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * kinds, authors, since, until and single-letter tag filters (#d, #L, ...).
     *
     * @param  array<string, mixed>  $filter
     */
    private static function matches(SignedEvent $event, array $filter): bool
    {
        if (! in_array($event->kind, array_map(intval(...), (array) ($filter['kinds'] ?? [])), true)) {
            return false;
        }

        if (isset($filter['authors']) && ! in_array($event->pubkey, (array) $filter['authors'], true)) {
            return false;
        }

        if ((isset($filter['since']) && $event->createdAt < (int) $filter['since']) || (isset($filter['until']) && $event->createdAt > (int) $filter['until'])) {
            return false;
        }

        foreach ($filter as $key => $values) {
            if (preg_match('/^#([a-zA-Z])$/', $key, $letter) === 1) {
                $tagValues = array_map(fn (array $tag): ?string => $tag[0] ?? null, $event->tagsNamed($letter[1]));

                if (array_intersect((array) $values, $tagValues) === []) {
                    return false;
                }
            }
        }

        return true;
    }
}
