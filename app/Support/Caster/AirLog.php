<?php

namespace App\Support\Caster;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the voice put on air, stretch by stretch (plan "TWENTY ONE Radio",
 * P6): SceneDirector records every clip, block or resumed rest of a block
 * with its start and end on the wall clock when it leaves the air, the last
 * KEEP of them, in the cache (one write per clip). `caster:gaps` reads it
 * and measures the silence between two stretches, the DoD's "Lücke zwischen
 * Häppchen ≤ 1 s".
 *
 * A cache that fails loses only the log, never the voice.
 */
final class AirLog
{
    public const KEY = 'caster:air_log';

    /** About a day of stretches at the radio's pace (blocks, single clips, reactions). */
    public const KEEP = 1500;

    /** What a stretch was (the hourly airtime budget, user 2026-10-12): rendered fresh, from the day stock, or a replay. */
    public const FRESH = 'fresh';

    public const STOCK = 'stock';

    public const REPLAY = 'replay';

    /**
     * @param  array{clip: int, kind: string, block: bool, start: float, end: float, source?: string}  $piece
     */
    public static function record(array $piece): void
    {
        try {
            $log = self::entries();
            $log[] = $piece;
            Cache::put(self::KEY, array_slice($log, -self::KEEP), now()->addDays(2));
        } catch (Throwable) {
            // The log is a measurement aid; the stream goes on without it.
        }
    }

    /**
     * @return list<array{clip: int, kind: string, block: bool, start: float, end: float, source: string}>
     */
    public static function entries(): array
    {
        $log = Cache::get(self::KEY, []);
        $entries = [];

        foreach (is_array($log) ? $log : [] as $piece) {
            if (is_array($piece) && is_numeric($piece['start'] ?? null) && is_numeric($piece['end'] ?? null)) {
                $entries[] = ['clip' => (int) ($piece['clip'] ?? 0), 'kind' => (string) ($piece['kind'] ?? ''), 'block' => (bool) ($piece['block'] ?? false), 'start' => (float) $piece['start'], 'end' => (float) $piece['end'],
                    'source' => in_array($piece['source'] ?? null, [self::STOCK, self::REPLAY], true) ? (string) $piece['source'] : self::FRESH];
            }
        }

        return $entries;
    }

    /**
     * The gaps between consecutive stretches, in seconds (negative never: an
     * overlap counts as 0). With `$blocksOnly`, only the gaps between two
     * stretches that both belong to blocks.
     *
     * @param  list<array{clip: int, kind: string, block: bool, start: float, end: float}>  $entries
     * @return list<array{from: int, to: int, gap: float}>
     */
    public static function gaps(array $entries, bool $blocksOnly = false): array
    {
        usort($entries, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $gaps = [];

        for ($index = 1; $index < count($entries); $index++) {
            $previous = $entries[$index - 1];
            $current = $entries[$index];

            if ($blocksOnly && (! $previous['block'] || ! $current['block'])) {
                continue;
            }

            $gaps[] = ['from' => (int) $previous['clip'], 'to' => (int) $current['clip'], 'gap' => round(max(0.0, (float) $current['start'] - (float) $previous['end']), 3)];
        }

        return $gaps;
    }

    /**
     * Seconds of voice per source within [`$from`, `$to`) (unix seconds), a stretch cut at the edges.
     *
     * @param  list<array{start: float, end: float, source: string}>  $entries
     * @return array{fresh: float, stock: float, replay: float}
     */
    public static function seconds(array $entries, float $from, float $to): array
    {
        $seconds = [self::FRESH => 0.0, self::STOCK => 0.0, self::REPLAY => 0.0];

        foreach ($entries as $piece) {
            $overlap = min($to, (float) $piece['end']) - max($from, (float) $piece['start']);

            if ($overlap > 0) {
                $seconds[$piece['source']] = ($seconds[$piece['source']] ?? 0.0) + $overlap;
            }
        }

        return [self::FRESH => round($seconds[self::FRESH], 1), self::STOCK => round($seconds[self::STOCK], 1), self::REPLAY => round($seconds[self::REPLAY], 1)];
    }

    /**
     * The airtime mix hour by hour (Berlin hours) from `$from` to `$to`: fresh, stock and replay seconds and the
     * rest of the hour as music (the stream plays music whenever no voice is on air). An hour cut by the window
     * counts only its part inside it.
     *
     * @param  list<array{start: float, end: float, source: string}>  $entries
     * @return list<array{hour: string, fresh: float, stock: float, replay: float, music: float}>
     */
    public static function hourly(array $entries, float $from, float $to, string $timezone = 'Europe/Berlin'): array
    {
        $hours = [];
        $start = (float) CarbonImmutable::createFromTimestamp((int) floor($from))->setTimezone($timezone)->startOfHour()->getTimestamp();

        for ($hour = $start; $hour < $to; $hour += 3600) {
            [$a, $b] = [max($from, $hour), min($to, $hour + 3600)];
            $seconds = self::seconds($entries, $a, $b);
            $hours[] = ['hour' => CarbonImmutable::createFromTimestamp((int) $hour)->setTimezone($timezone)->format('Y-m-d H:00'), ...$seconds,
                'music' => round(max(0.0, ($b - $a) - array_sum($seconds)), 1)];
        }

        return $hours;
    }
}
