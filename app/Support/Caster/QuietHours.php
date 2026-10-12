<?php

namespace App\Support\Caster;

use Carbon\CarbonImmutable;

/**
 * The stream's quiet hours (tv.commentary.spool.quiet_hours): one clip every
 * `every_minutes` and a small queue, so the commentary stays rare at night.
 */
final class QuietHours
{
    public static function active(CarbonImmutable $now): bool
    {
        $settings = (array) config('tv.commentary.spool.quiet_hours', []);

        return self::within($now, (string) ($settings['from'] ?? ''), (string) ($settings['to'] ?? ''), (string) ($settings['timezone'] ?? 'Europe/Berlin'));
    }

    /** Whether `$now` lies in the daily window `$from` to `$to` ("HH:MM", may cross midnight) in `$timezone`; an empty window never. */
    public static function within(CarbonImmutable $now, string $from, string $to, string $timezone = 'Europe/Berlin'): bool
    {
        if ($from === '' || $to === '' || $from === $to) {
            return false;
        }

        $clock = $now->setTimezone($timezone)->format('H:i');

        return $from < $to ? ($clock >= $from && $clock < $to) : ($clock >= $from || $clock < $to);
    }

    /** Seconds from the start of one clip to the start of the next while quiet. */
    public static function everySeconds(): float
    {
        return max(60.0, 60.0 * (float) config('tv.commentary.spool.quiet_hours.every_minutes', 10));
    }

    public static function aheadSeconds(): float
    {
        return (float) config('tv.commentary.spool.quiet_hours.ahead_seconds', 90);
    }
}
