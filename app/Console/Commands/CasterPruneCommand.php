<?php

namespace App\Console\Commands;

use App\Support\Caster\ChatMemory;
use App\Support\Caster\ChatterCache;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\ScriptQueue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The safety net of the TV commentary's storage (plan "KI-Stimme der Liga",
 * P6; user 2026-10-11: "Auf dem Server darf es natürlich auch nicht zu einem
 * Speicherproblem kommen"): expires stale scripts, deletes expired, stuck and
 * orphan clips (ClipSpool::prune()), and trims the chatter cache to its caps
 * and TTLs (ChatterCache::prune()) and the rolling chat memory to
 * `memory_hours` (ChatMemory::prune()). Runs whether the commentary is on or not,
 * so switching it off leaves nothing behind.
 */
#[Signature('caster:prune')]
#[Description('Prune the TV commentary spool, its queue and the chatter cache')]
class CasterPruneCommand extends Command
{
    public function handle(ScriptQueue $queue, ClipSpool $spool, ChatterCache $chatters, ChatMemory $memory): int
    {
        $now = CarbonImmutable::now();
        $expired = $queue->expire($now);
        $clips = $spool->prune($now);
        $cache = $chatters->prune($now);
        $lines = $memory->prune($now);

        $this->line(sprintf(
            'Scripts expired %d; clips expired %d, stale %d, orphan files %d, orphan rows %d; chatters removed %d, notes removed %d, chat lines removed %d; spool %d bytes.',
            $expired, $clips['expired'], $clips['stale'], $clips['orphan_files'], $clips['orphan_rows'], $cache['profiles'], $cache['notes'], $lines, $spool->bytes(),
        ));

        return self::SUCCESS;
    }
}
