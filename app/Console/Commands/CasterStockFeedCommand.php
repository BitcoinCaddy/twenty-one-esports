<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\StockFeeder;
use App\Support\Caster\Blocks\StockLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * The server feeds its spool from the stock library (plan "TWENTY ONE Radio",
 * P18, StockFeeder): by day when the spool runs low, and the whole programme
 * while the workstation is gone (the autopilot). Scheduled every minute, it
 * looks every `autopilot.poll_seconds` within it, so a music gap ends within
 * seconds of its length, not up to a minute later.
 */
#[Signature('caster:stock-feed {--once : Look once, then exit}')]
#[Description('Feed the stream spool from the server stock library; run the autopilot while the workstation is gone')]
class CasterStockFeedCommand extends Command
{
    public function handle(StockFeeder $feeder): int
    {
        if (! StockLibrary::enabled()) {
            $this->line('The stock library is off (TV_COMMENTARY_BLOCKS, TV_COMMENTARY_STOCK, TV_COMMENTARY_STOCK_LIBRARY).');

            return self::SUCCESS;
        }

        $interval = max(5, (int) config('tv.commentary.blocks.autopilot.poll_seconds', 15));
        $polls = $this->option('once') ? 1 : max(1, intdiv(60, $interval));
        $start = microtime(true);

        for ($i = 0; $i < $polls; $i++) {
            $wait = $start + $i * $interval - microtime(true);

            if ($i > 0 && $wait > 0) {
                Sleep::for((int) round($wait * 1000))->milliseconds();
            }

            foreach ($feeder->tick(CarbonImmutable::now()) as $done) {
                $this->line(now()->toDateTimeString().' '.$done);
            }
        }

        return self::SUCCESS;
    }
}
