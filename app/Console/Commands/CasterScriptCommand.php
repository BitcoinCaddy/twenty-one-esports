<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\VoiceBudget;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Keeps the TV commentary queue filled (plan "KI-Stimme der Liga", P6):
 * expires stale scripts, seeds the fillers, and writes scene scripts until
 * `tv.commentary.scripts.ahead_seconds` of airtime wait, after the latest
 * podcast transcript got its digest (PodcastTranscripts::ensureDigest(), once
 * per episode). Scheduled every minute while `tv.commentary.enabled`; off,
 * it does nothing.
 */
#[Signature('caster:script')]
#[Description('Write TV commentary scripts ahead and expire stale ones')]
class CasterScriptCommand extends Command
{
    public function handle(ScriptQueue $queue, PodcastTranscripts $transcripts): int
    {
        if (! CasterVoice::enabled()) {
            $this->line('TV commentary is off (TV_COMMENTARY).');

            return self::SUCCESS;
        }

        // The autopilot runs (P18: the workstation is gone or its VoiceStudio down): nothing written would ever be voiced,
        // so no model call is paid; what is queued expires as usual, so nothing stale airs when the workstation is back.
        if (AutoPilot::active(CarbonImmutable::now())) {
            $expired = $queue->expire(CarbonImmutable::now());
            $this->line("Autopilot: no scripts written; {$expired} expired.");

            return self::SUCCESS;
        }

        if (ScriptQueue::aiEnabled() && $transcripts->ensureDigest()) {
            $this->line('Wrote the digest of the latest podcast episode.');
        }

        $written = $queue->fill(CarbonImmutable::now());
        $this->line(sprintf('Wrote %d script(s); %.0f s of airtime ahead.', $written, $queue->airtimeAhead()));

        if (VoiceBudget::enabled()) {
            $budget = app(VoiceBudget::class)->snapshot(CarbonImmutable::now());
            $this->line(sprintf('Voicing: %.2f s per second (%s), budget %.0f s/h, written %.0f s, voiced %.0f s in the hour%s.',
                $budget['rtf'], $budget['source'], $budget['hour_budget'], $budget['written_hour'], $budget['voiced_hour'], $budget['stock_turn'] ? ', stock\'s turn' : ''));
        }

        return self::SUCCESS;
    }
}
