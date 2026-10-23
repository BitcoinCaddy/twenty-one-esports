<?php

namespace App\Console\Commands;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Tournaments\TournamentDesk;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The profile and DM relay list of every open tournament desk's key (TournamentDesk::publishProfile()): published
 * once per tournament name, so another client names the desk's extra group member and sends its copy to the chat
 * relays. Scheduled every five minutes; a desk already published costs one cache read.
 */
#[Signature('esports:desk-profiles')]
#[Description('Publish the profile and DM relays of each open tournament desk key')]
class DeskProfilesCommand extends Command
{
    public function handle(): int
    {
        $published = 0;

        $tournaments = Tournament::query()->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running, TournamentStatus::Finished])
            ->latest('id')->limit(200)->get();

        foreach ($tournaments as $tournament) {
            if (TournamentDesk::isOpen($tournament) && TournamentDesk::publishProfile($tournament)) {
                $published++;
            }
        }

        $this->info("Published {$published} desk profile(s).");

        return self::SUCCESS;
    }
}
