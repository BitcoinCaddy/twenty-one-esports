<?php

namespace App\Support\Caster;

use Illuminate\Support\Facades\Cache;

/**
 * Which script of a kind is a long variant (user 2026-10-11: "Die Längen wie
 * jetzt und dann öfters auch mal viel längere Texte, damit es interessanter
 * wird"): two counters per kind (scripts queued, long ones among them), and
 * the next script is long while the long ones lag behind
 * `tv.commentary.length.shares.<kind>` of all of them counted with it (a
 * share of 0.25 makes every fourth one long, 0.5 every second one), so the
 * long ones are spread evenly instead of clustering.
 *
 * isDue() only looks; written() counts a script that was actually queued, so
 * a long variant that could not be built (and was written short) is not
 * lost: the next script of the kind is long instead (at most one is owed).
 * A share outside 0..1 is clamped.
 */
final class LengthMix
{
    public const SCENE = 'scene';

    public const NEWS = 'news';

    public const HISTORY = 'history';

    private const KEY = 'caster:length:';

    /** Whether the next script of `$kind` is the long variant. */
    public static function isDue(string $kind): bool
    {
        $total = (int) Cache::get(self::KEY.$kind.':total', 0);
        $long = (int) Cache::get(self::KEY.$kind.':long', 0);

        return $long < floor(($total + 1) * self::share($kind) + 1e-9);
    }

    /** A script of `$kind` was queued, long or not. */
    public static function written(string $kind, bool $long): void
    {
        $total = (int) Cache::get(self::KEY.$kind.':total', 0) + 1;
        $count = (int) Cache::get(self::KEY.$kind.':long', 0) + ($long ? 1 : 0);

        Cache::forever(self::KEY.$kind.':total', $total);
        // At most one long variant owed: a kind whose long ones kept failing does not burst into a row of them later.
        Cache::forever(self::KEY.$kind.':long', max($count, (int) floor($total * self::share($kind) + 1e-9) - 1));
    }

    public static function share(string $kind): float
    {
        return max(0.0, min(1.0, (float) config('tv.commentary.length.shares.'.$kind, 0)));
    }
}
