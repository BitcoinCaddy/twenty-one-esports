<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\ScriptQueue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The podcast digest on its own lane (live 2026-10-17): the transcript-sized text call over the workstation's
 * gateway can run for many minutes, and while it held the caster:script writer (do_poll, no hard timeout at the
 * gateway), the minute ticker behind the writer's overlap lock never wrote the news follow-ups — the 16:00 Sport
 * und Wetter never aired. PodcastTranscripts::ensureDigest() keeps its own retry lock (thirty minutes) and writes
 * nothing else; hanging here costs this lane its overlap lock and nobody else's airtime. The autopilot keeps it
 * off, as it kept it off in the writer (P18: what is not voiced is not paid).
 */
#[Signature('caster:podcast-digest')]
#[Description('Write the digest of the latest podcast episode (PodcastTranscripts::ensureDigest)')]
class CasterPodcastDigestCommand extends Command
{
    public function handle(PodcastTranscripts $transcripts): int
    {
        if (! CasterVoice::enabled() || AutoPilot::active(CarbonImmutable::now())) {
            return self::SUCCESS;
        }

        if (ScriptQueue::aiEnabled() && $transcripts->ensureDigest()) {
            $this->line('Wrote the digest of the latest podcast episode.');
        }

        return self::SUCCESS;
    }
}
