<?php

namespace App\Console\Commands;

use App\Support\Caster\PodcastLibrary;
use App\Support\Caster\PodcastReplay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The podcast replay on the server (plan "TWENTY ONE Radio", P12): reads the
 * feeds of the enabled sources at most every `check_minutes` and stores a
 * newer episode (PodcastLibrary), then puts the airings whose slot is due
 * into the spool (PodcastReplay). `--refresh` reads every feed now, `--no-air`
 * only stores (a measurement on the workstation writes no airing).
 */
#[Signature('caster:podcasts {--refresh : Read every enabled feed now} {--no-air : Store episodes only, schedule no airing}')]
#[Description('Store the newest episode of every podcast replay source and schedule today\'s airings')]
class CasterPodcastsCommand extends Command
{
    public function handle(PodcastLibrary $library, PodcastReplay $replay): int
    {
        if (! PodcastLibrary::enabled()) {
            $this->line('Podcast replay is off.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();

        foreach ($library->refresh($now, (bool) $this->option('refresh')) as $source => $outcome) {
            $this->line("Feed {$source}: {$outcome}".($outcome === PodcastLibrary::STORED ? ' '.json_encode($library->measured[$source] ?? []) : ''));
        }

        if (! $this->option('no-air')) {
            foreach ($replay->schedule($now) as $source => $outcome) {
                $this->line("Airing {$source}: {$outcome}");
            }
        }

        return self::SUCCESS;
    }
}
