<?php

namespace App\Console\Commands;

use App\Support\Caster\CasterVoice;
use App\Support\Caster\FollowedNotes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reads the kind 1 notes of the profiles the TWENTY ONE TV commentary
 * follows (`tv.commentary.followed_profiles`, FollowedNotes): the new ones,
 * and a bounded stretch further back into the history each run. Scheduled every five minutes while `tv.commentary.enabled` and
 * `tv.commentary.followed.enabled`; off, it does nothing.
 */
#[Signature('caster:followed')]
#[Description('Read the new Nostr notes of the profiles the TV commentary follows')]
class CasterFollowedCommand extends Command
{
    public function handle(FollowedNotes $notes): int
    {
        if (! CasterVoice::enabled() || config('tv.commentary.followed.enabled') !== true) {
            $this->line('The TV followed-profile segment is off (TV_COMMENTARY, TV_COMMENTARY_FOLLOWED).');

            return self::SUCCESS;
        }

        $counts = $notes->fetch(CarbonImmutable::now());
        $this->line(sprintf('%d profile(s); %d note(s) read, %d new, %d filtered, %d pruned; %d history(ies) read to the first note.', $counts['profiles'], $counts['read'], $counts['stored'], $counts['filtered'], $counts['pruned'], $counts['exhausted']));

        return self::SUCCESS;
    }
}
