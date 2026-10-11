<?php

namespace App\Console\Commands;

use App\Support\Caster\ChatReactions;
use App\Support\Caster\WebOfTrust;
use App\Support\SeasonChain\TrustJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Spoken reactions to the stream chat (plan "KI-Stimme der Liga", P6): with
 * `--wot`, recompute the web of trust of the league's trust anchors (daily);
 * otherwise read the new chat messages and queue a reaction for each trusted
 * author within the limits (every minute). Both only while
 * `tv.commentary.chat.reactions` is on.
 */
#[Signature('caster:chat {--wot : Recompute the web-of-trust levels instead}')]
#[Description('Queue spoken reactions to trusted stream chatters, or recompute the web of trust')]
class CasterChatCommand extends Command
{
    public function handle(ChatReactions $reactions, WebOfTrust $wot, TrustJob $trust): int
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

        $this->line('Queued '.$reactions->poll(CarbonImmutable::now()).' chat reaction(s).');

        return self::SUCCESS;
    }
}
