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
        $from = (string) ($settings['from'] ?? '');
        $to = (string) ($settings['to'] ?? '');

        if ($from === '' || $to === '' || $from === $to) {
            return false;
        }

        $clock = $now->setTimezone((string) ($settings['timezone'] ?? 'Europe/Berlin'))->format('H:i');

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
