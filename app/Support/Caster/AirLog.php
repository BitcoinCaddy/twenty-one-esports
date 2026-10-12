<?php

namespace App\Support\Caster;

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

    public const KEEP = 400;

    /**
     * @param  array{clip: int, kind: string, block: bool, start: float, end: float}  $piece
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
     * @return list<array{clip: int, kind: string, block: bool, start: float, end: float}>
     */
    public static function entries(): array
    {
        $log = Cache::get(self::KEY, []);
        $entries = [];

        foreach (is_array($log) ? $log : [] as $piece) {
            if (is_array($piece) && is_numeric($piece['start'] ?? null) && is_numeric($piece['end'] ?? null)) {
                $entries[] = ['clip' => (int) ($piece['clip'] ?? 0), 'kind' => (string) ($piece['kind'] ?? ''), 'block' => (bool) ($piece['block'] ?? false), 'start' => (float) $piece['start'], 'end' => (float) $piece['end']];
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
}
