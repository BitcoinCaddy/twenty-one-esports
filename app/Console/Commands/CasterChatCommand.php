<?php

namespace App\Console\Commands;

use App\Support\Caster\ChatCadence;
use App\Support\Caster\ChatReactions;
use App\Support\Caster\WebOfTrust;
use App\Support\SeasonChain\TrustJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Spoken reactions to the stream chat (plan "KI-Stimme der Liga", P6): with
 * `--wot`, recompute the web of trust of the league's trust anchors (daily);
 * otherwise read the new chat messages and zaps and queue the reactions
 * (every minute, reading every `poll_seconds` within it), then keep the chat
 * cadence (ChatCadence: a YouTube summary or a call-out when no chat item
 * aired for ten minutes). Both only while `tv.commentary.chat.reactions` is on.
 */
#[Signature('caster:chat {--wot : Recompute the web-of-trust levels instead}')]
#[Description('Queue spoken reactions to trusted stream chatters, or recompute the web of trust')]
class CasterChatCommand extends Command
{
    public function handle(ChatReactions $reactions, WebOfTrust $wot, TrustJob $trust, ChatCadence $cadence): int
    {
        if (! ChatReactions::enabled()) {
            $this->line('Chat reactions are off (TV_COMMENTARY, TV_COMMENTARY_CHAT).');

            return self::SUCCESS;
        }

        if ($this->option('wot')) {
            $anchors = $trust->anchors();
            $count = $anchors === null ? null : $wot->run($anchors, CarbonImmutable::now());
            $this->line($count === null ? 'Web of trust not recomputed: anchors or their follow lists could not be read; the last levels stay.' : "Web of trust: {$count} pubkeys at level 1 or 2.");

            return self::SUCCESS;
        }

        // Several reads within the minute of the schedule (`poll_seconds`), so a reaction is written seconds after
        // the message or zap, not up to a minute later; the last one starts in time for the next run.
        $interval = max(5, (int) config('tv.commentary.chat.poll_seconds', 60));
        $polls = max(1, intdiv(60, $interval));
        $start = microtime(true);
        $queued = 0;

        for ($i = 0; $i < $polls; $i++) {
            if ($i > 0) {
                $wait = $start + $i * $interval - microtime(true);
                if ($wait > 0) {
                    Sleep::for((int) round($wait * 1000))->milliseconds();
                }
            }

            $queued += $reactions->poll(CarbonImmutable::now());
        }

        $this->line("Queued {$queued} chat reaction(s) in {$polls} read(s).");

        // No chat item for ten minutes: a YouTube summary or a call-out keeps the chat on air.
        $kept = $cadence->tick(CarbonImmutable::now());

        if ($kept !== null) {
            $this->line("Chat cadence: queued a {$kept} chat item.");
        }

        return self::SUCCESS;
    }
}
