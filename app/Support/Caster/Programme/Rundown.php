<?php

namespace App\Support\Caster\Programme;

use Carbon\CarbonImmutable;

/**
 * The programme of the next hour (plan "TWENTY ONE Radio", P1): what the
 * broadcast clock plays from now on, slot by slot, so a teaser ("gleich nach
 * den Nachrichten …", "um halb gibt's …", P5) only ever names a slot that
 * really follows. Built from the clock alone, no state of its own.
 *
 * @phpstan-import-type Slot from BroadcastClock
 *
 * @phpstan-type RundownEntry array{type: string, label: string, minute: int, stinger: bool, starts_at: CarbonImmutable, in_minutes: int, spoken_time: string}
 */
final class Rundown
{
    public function __construct(private readonly BroadcastClock $clock) {}

    public static function fromConfig(): self
    {
        return new self(BroadcastClock::fromConfig());
    }

    /**
     * The slots that start after `$now` within the next `$minutes`, each with
     * the whole minutes until it starts and its start as spoken words.
     *
     * @return list<RundownEntry>
     */
    public function next(CarbonImmutable $now, int $minutes = 60): array
    {
        $entries = [];

        foreach ($this->clock->upcoming($now->addSecond(), $minutes) as $slot) {
            $entries[] = [
                'type' => $slot['type'],
                'label' => $slot['label'],
                'minute' => $slot['minute'],
                'stinger' => $slot['stinger'],
                'starts_at' => $slot['starts_at'],
                'in_minutes' => intdiv(max(0, $slot['key'] - $now->getTimestamp()) + 59, 60),
                'spoken_time' => $this->clock->spokenTime($slot['starts_at']),
            ];
        }

        return $entries;
    }

    /**
     * The first slot of `$type` within the next `$minutes`, or null when none follows (a teaser for it would lie).
     *
     * @return RundownEntry|null
     */
    public function nextOf(string $type, CarbonImmutable $now, int $minutes = 60): ?array
    {
        foreach ($this->next($now, $minutes) as $entry) {
            if ($entry['type'] === $type) {
                return $entry;
            }
        }

        return null;
    }
}
