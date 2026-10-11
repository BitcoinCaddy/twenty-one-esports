<?php

namespace App\Support\Caster;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * What the caster said lately, across every script (pattern or league
 * voice): fact atoms (`slot=value` of a pattern line) for
 * `patterns.fact_memory_minutes`, person names for
 * `patterns.name_memory_minutes`, tournament, track, clan and ladder names
 * for `patterns.entity_memory_minutes`. The user, 2026-10-11: "Er liest zu viele
 * Daten vor, die sich auch wiederholen" and "er muss echt nicht immer die
 * Namen vorlesen". One cache entry per kind, key => expiry timestamp; an
 * unreadable entry counts as empty (nothing remembered, the next script
 * may say a fact again, never fails the fill).
 */
final class RecentlySaid
{
    public const FACTS = 'facts';

    public const NAMES = 'names';

    public const ENTITIES = 'entities';

    /** The fact lines the pattern scripts spoke, as text: the league voice's prompt lists them so it does not say them again. */
    public const FACT_LINES = 'fact_lines';

    /**
     * The keys of `$kind` still remembered at `$now`.
     *
     * @return array<string, true>
     */
    public static function active(string $kind, CarbonImmutable $now): array
    {
        $stored = Cache::get(self::key($kind));
        $active = [];

        foreach (is_array($stored) ? $stored : [] as $key => $until) {
            if (is_string($key) && is_int($until) && $until > $now->getTimestamp()) {
                $active[$key] = true;
            }
        }

        return $active;
    }

    /**
     * Remembers `$keys` of `$kind` for `$minutes` from `$now`.
     *
     * @param  list<string>  $keys
     */
    public static function remember(string $kind, array $keys, int $minutes, CarbonImmutable $now): void
    {
        if ($keys === []) {
            return;
        }

        $stored = Cache::get(self::key($kind));
        $kept = [];

        foreach (is_array($stored) ? $stored : [] as $key => $until) {
            if (is_string($key) && is_int($until) && $until > $now->getTimestamp()) {
                $kept[$key] = $until;
            }
        }

        foreach ($keys as $key) {
            $kept[$kind === self::FACT_LINES ? trim($key) : self::normalize($key)] = $now->addMinutes($minutes)->getTimestamp();
        }

        Cache::put(self::key($kind), $kept, max(60, $minutes * 60));
    }

    /** Names and atoms compare case-insensitively ("Ada" and "ada" are one person). */
    public static function normalize(string $key): string
    {
        return mb_strtolower(trim($key));
    }

    public static function factMinutes(): int
    {
        return max(1, (int) config('tv.commentary.patterns.fact_memory_minutes', 20));
    }

    public static function nameMinutes(): int
    {
        return max(1, (int) config('tv.commentary.patterns.name_memory_minutes', 30));
    }

    public static function entityMinutes(): int
    {
        return max(1, (int) config('tv.commentary.patterns.entity_memory_minutes', 15));
    }

    private static function key(string $kind): string
    {
        return 'caster:said:'.$kind;
    }
}
