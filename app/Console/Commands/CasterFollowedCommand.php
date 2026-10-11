<?php

namespace App\Console\Commands;

use App\Support\Caster\CasterVoice;
use App\Support\Caster\FollowedNotes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reads the new kind 1 notes of the profiles the TWENTY ONE TV commentary
 * follows (`tv.commentary.followed_profiles`, FollowedNotes) and prunes the
 * old ones. Scheduled every five minutes while `tv.commentary.enabled` and
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
        $this->line(sprintf('%d profile(s); %d new note(s), %d filtered, %d pruned.', $counts['profiles'], $counts['stored'], $counts['filtered'], $counts['pruned']));

        return self::SUCCESS;
    }
}
