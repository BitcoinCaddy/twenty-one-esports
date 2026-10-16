<?php

namespace App\Console\Commands;

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
        $queue->fillBulletin(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
