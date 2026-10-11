<?php

namespace App\Support\Nostr;

use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How long `nostr:republish` keeps trying a relay, and whether a public copy
 * is still on its way (ChessStates "Public record delayed").
 *
 * A relay that has not accepted an event is tried again with backoff:
 * 5, 10, 20, 40 and 80 minutes after the previous try, then never again
 * (MAX_ATTEMPTS in all). A regular event is only retried within WINDOW_HOURS
 * of being signed; the current version of a replaceable or addressable event
 * at any age, but with the same cap per relay (a relay added later starts
 * with no attempt).
 *
 * Measured on prod 2026-10-11: damus answers every send with
 * "Invalid status code 403" and took 549 sends in 24 hours, nos.lol and
 * nostr.mom answered 245 and 203 sends with 429; without a cap the retry
 * itself kept the league rate-limited.
 */
final class RelayRetries
{
    public const MAX_ATTEMPTS = 6;

    public const FIRST_DELAY_MINUTES = 5;

    public const WINDOW_HOURS = 24;

    public static function isReplaceable(int $kind): bool
    {
        return in_array($kind, [0, 3], true) || ($kind >= 10000 && $kind < 20000) || ($kind >= 30000 && $kind < 40000);
    }

    /**
     * Whether the relay of this delivery gets another try now. No delivery
     * yet: always. The delay doubles per attempt; a minute of slack keeps a
     * five-minute schedule from slipping a whole run.
     */
    public static function due(?RelayDelivery $delivery, CarbonInterface $now): bool
    {
        if ($delivery === null) {
            return true;
        }

        if ($delivery->accepted === true || $delivery->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $delay = self::FIRST_DELAY_MINUTES * 2 ** max(0, $delivery->attempts - 1) - 1;

        return $delivery->attempted_at->lte($now->copy()->subMinutes($delay));
    }

    /**
     * The configured relays this event is due at now.
     *
     * @param  Collection<int, RelayDelivery>  $deliveries
     * @param  list<string>  $relays
     * @return list<string>
     */
    public static function dueRelays(Collection $deliveries, array $relays, CarbonInterface $now): array
    {
        return array_values(array_filter($relays, fn (string $relay): bool => self::due($deliveries->firstWhere('relay', $relay), $now)));
    }

    /**
     * Whether `nostr:republish` will still send this event to some configured
     * relay that has not accepted it: false once every relay accepted or ran
     * out of attempts, and for a regular event past the window.
     *
     * @param  Collection<int, RelayDelivery>  $deliveries
     * @param  list<string>  $relays
     */
    public static function pending(NostrEvent $event, Collection $deliveries, array $relays, CarbonInterface $now): bool
    {
        if (! self::isReplaceable($event->kind) && $event->created_at !== null && $event->created_at->lt($now->copy()->subHours(self::WINDOW_HOURS))) {
            return false;
        }

        foreach ($relays as $relay) {
            $delivery = $deliveries->firstWhere('relay', $relay);

            if ($delivery === null || ($delivery->accepted !== true && $delivery->attempts < self::MAX_ATTEMPTS)) {
                return true;
            }
        }

        return false;
    }
}
