<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Blocks\StockLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The server's stock library in numbers (plan "TWENTY ONE Radio", P18): hours of
 * unique audio, what counts toward the target, what never aired, bytes against
 * the cap, blocks per format, and whether the autopilot runs right now.
 */
#[Signature('caster:stock-library')]
#[Description('Show how many hours of stock the server library holds')]
class CasterStockLibraryCommand extends Command
{
    public function handle(): int
    {
        $summary = StockLibrary::fromConfig()->summary();

        $this->line(sprintf('Stock library %s: %d blocks, %.1f h unique (%.1f h toward the target of %.0f h, %.1f h never aired), %.2f of %.2f GB%s.',
            StockLibrary::enabled() ? 'on' : 'off', $summary['blocks'], $summary['seconds'] / 3600, $summary['counting_seconds'] / 3600, $summary['target_seconds'] / 3600,
            $summary['unaired_seconds'] / 3600, $summary['bytes'] / 1024 ** 3, $summary['max_bytes'] / 1024 ** 3, $summary['full'] ? ', full: the workstation pauses stock rendering' : ''));

        foreach ($summary['formats'] as $format => $count) {
            $this->line(sprintf('  %-18s %d', $format, $count));
        }

        $this->line('Autopilot: '.(AutoPilot::active(CarbonImmutable::now()) ? 'ON (workstation gone or VoiceStudio down)' : (AutoPilot::enabled() ? 'standing by' : 'off')));

        return self::SUCCESS;
    }
}
