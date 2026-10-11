<?php

namespace App\Console\Commands;

use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The league voice's counters for today and the running month (Berlin
 * days): attempts, outcomes, tokens and the estimated cost per channel, the
 * monthly soft budget, and why texts were rejected or posts skipped
 * (reason codes of the guards and the spam gate). Numbers only.
 */
#[Signature('voice:usage')]
#[Description('Show the league voice usage, cost estimate and rejection reasons')]
class VoiceUsageCommand extends Command
{
    public function handle(VoiceUsage $usage): int
    {
        $today = CarbonImmutable::parse($usage->day());
        $monthStart = $today->startOfMonth()->toDateString();

        foreach (['Today '.$today->toDateString() => [$today->toDateString(), $today->toDateString()], 'Month from '.$monthStart => [$monthStart, $today->toDateString()]] as $label => [$from, $to]) {
            $totals = $usage->totals($from, $to);
            $this->info($label);

            if ($totals === []) {
                $this->line('  no calls');
            } else {
                $this->table(
                    ['Channel', 'Attempts', 'Accepted', 'Rejected', 'Failed', 'Input', 'Cache read', 'Cache write', 'Output', 'Cost $'],
                    array_map(fn (string $channel, array $row): array => [
                        $channel, $row['attempts'], $row['accepted'], $row['rejected'], $row['failed'],
                        $row['input_tokens'], $row['cache_read_tokens'], $row['cache_write_tokens'], $row['output_tokens'],
                        number_format($usage->cost($row), 4),
                    ], array_keys($totals), $totals),
                );
            }

            $rejections = $usage->rejections($from, $to);
            foreach ($rejections as $channel => $reasons) {
                $this->line("  {$channel}: ".implode(', ', array_map(fn (string $reason, int $count): string => "{$reason} {$count}", array_keys($reasons), $reasons)));
            }
        }

        $this->line(sprintf('Month estimate: $%.4f of $%.2f soft budget', $usage->monthCost(), (float) config('esports.voice.monthly_budget_usd')));

        return self::SUCCESS;
    }
}
