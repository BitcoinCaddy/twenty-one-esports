<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Programme\MusicTeasers;
use App\Support\Caster\ScriptQueue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One music announcement attempt a minute (MusicTeasers), on its own lane: the caster:script run that owns the
 * other queue writes can sit for minutes in its podcast digest (a transcript-sized text over the workstation's
 * gateway, live 2026-10-17: four times that day the minute ticker hung behind it and no music announcement was
 * even tried). This lane stays free of that: it only rolls the window gates and, when they open, writes the one
 * announcement. Its own cooldown, chance and cap (tv.commentary.programme.music_teasers) keep it from piling up.
 */
#[Signature('caster:music-teaser')]
#[Description('Try one music announcement (MusicTeasers) when the window to the music is open')]
class CasterMusicTeaserCommand extends Command
{
    public function handle(ScriptQueue $queue): int
    {
        // Nothing written in the autopilot: nobody could voice it (as caster:script and caster:bulletin).
        if (! ScriptQueue::aiEnabled() || ! MusicTeasers::enabled() || AutoPilot::active(CarbonImmutable::now())) {
            return self::SUCCESS;
        }

        $written = $queue->fillMusicTeaser(CarbonImmutable::now());

        if ($written > 0 && $this->output->isVerbose()) {
            $this->line('music announcement written: '.$written);
        }

        return self::SUCCESS;
    }
}
