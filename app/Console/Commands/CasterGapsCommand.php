<?php

namespace App\Console\Commands;

use App\Support\Caster\AirLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The silence between two stretches of voice on air (plan "TWENTY ONE
 * Radio", P6 DoD: "Lücke zwischen Häppchen ≤ 1 s gemessen"), from the air
 * log the stream writes (AirLog): every gap of the last `--minutes`, their
 * count, mean, maximum and how many exceed `--limit`. `--blocks` measures
 * only gaps between two programme blocks (the DoD's number); without it a
 * single clip's music gap counts too. `--mix` prints the hourly airtime
 * budget instead (DayPlanner, user 2026-10-12): per Berlin hour the seconds
 * of fresh voice, day stock, replays and music. Read-only; run it on the server:
 *
 *   php artisan caster:gaps --minutes=120 --blocks
 *   php artisan caster:gaps --minutes=1440 --mix
 */
#[Signature('caster:gaps {--minutes=60 : The window, counted back from now} {--blocks : Only gaps between two programme blocks} {--limit=1.0 : Seconds a gap may last} {--mix : The airtime mix per hour (fresh, stock, replay, music)} {--json : Machine-readable output}')]
#[Description('Measure the gaps between the voice stretches on air (programme blocks)')]
class CasterGapsCommand extends Command
{
    public function handle(): int
    {
        $since = (float) now()->getTimestamp() - 60 * max(1, (int) $this->option('minutes'));
        $limit = (float) $this->option('limit');
        $entries = array_values(array_filter(AirLog::entries(), fn (array $piece): bool => (float) $piece['end'] >= $since));

        if ($this->option('mix')) {
            $hours = AirLog::hourly($entries, $since, (float) now()->getTimestamp(), (string) config('tv.commentary.spool.quiet_hours.timezone', 'Europe/Berlin'));

            if ($this->option('json')) {
                $this->line((string) json_encode(['hours' => $hours]));

                return self::SUCCESS;
            }

            $this->table(['hour', 'fresh (s)', 'stock (s)', 'replay (s)', 'music (s)'], array_map(fn (array $hour): array => [$hour['hour'], $hour['fresh'], $hour['stock'], $hour['replay'], $hour['music']], $hours));

            return self::SUCCESS;
        }
        $gaps = AirLog::gaps($entries, (bool) $this->option('blocks'));
        $values = array_column($gaps, 'gap');
        $summary = [
            'stretches' => count($entries),
            'block_stretches' => count(array_filter($entries, fn (array $piece): bool => (bool) $piece['block'])),
            'gaps' => count($values),
            'mean_seconds' => $values === [] ? null : round(array_sum($values) / count($values), 3),
            'max_seconds' => $values === [] ? null : max($values),
            'over_limit' => count(array_filter($values, fn (float $gap): bool => $gap > $limit)),
            'limit_seconds' => $limit,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'gaps' => $gaps]));

            return self::SUCCESS;
        }

        $this->table(['from clip', 'to clip', 'gap (s)'], array_map(fn (array $gap): array => [$gap['from'], $gap['to'], sprintf('%.3f', $gap['gap'])], array_slice($gaps, -30)));
        $this->line(sprintf('%d stretches (%d of blocks), %d gaps, mean %s s, max %s s, %d over %.1f s',
            $summary['stretches'], $summary['block_stretches'], $summary['gaps'], $summary['mean_seconds'] ?? '-', $summary['max_seconds'] ?? '-', $summary['over_limit'], $limit));

        return self::SUCCESS;
    }
}
