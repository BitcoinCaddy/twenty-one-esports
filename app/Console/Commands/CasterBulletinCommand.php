<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ScriptQueue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The next news bulletin in its own lane (live 2026-10-16): caster:script writes shows, stock
 * programmes and rubrics in one process, and a long write (a talk show's acts ran fifty minutes)
 * held the writer past the bulletin's window - the 16:30 Berlin slot was never written, the 17:00
 * one was written 2:18 before its slot and expired unvoiced while the GPU still rendered it. This
 * command writes only fillBulletin(), every minute, in parallel: the news never queue behind a
 * show. fillBulletin's own hash check keeps the two lanes from writing one slot twice.
 */
#[Signature('caster:bulletin')]
#[Description('Write the next news bulletin alone, never behind a long show or stock write')]
class CasterBulletinCommand extends Command
{
    public function handle(ScriptQueue $queue): int
    {
        if (! CasterVoice::enabled()) {
            $this->line('TV commentary is off (TV_COMMENTARY).');

            return self::SUCCESS;
        }

        // The autopilot runs (the workstation is gone or its VoiceStudio down): nobody could voice the bulletin. The lane
        // split of 2026-10-16 left this out of caster:script's guard; prod 2026-10-18, VoiceStudio down from 11:27 to
        // 15:00 Berlin, five bulletins were written and expired unvoiced while the slides promised them.
        if (AutoPilot::active(CarbonImmutable::now())) {
            return self::SUCCESS;
        }

        $queue->fillBulletin(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
