<?php

namespace App\Support\Series;

use App\Models\SeriesMatch;
use App\Models\User;

/**
 * The members of a series' match room as its chat knows them (pages::matches.room, resources/js/roomChat.js): every
 * active seat of both lineups and every roster player (a tournament's mix team or 1v1, a casual 1v1), once per
 * pubkey, with their side. "Your chats" reads the same list (App\Support\Chat\ChatInbox).
 */
final class RoomMembers
{
    /**
     * @return list<array{pubkey: string, name: string, side: string}>
     */
    public static function of(SeriesMatch $match): array
    {
        $members = [];

        foreach (SeriesMatch::SIDES as $side) {
            foreach ($match->lineup($side)?->activeSeats() ?? [] as $seat) {
                $members[$seat->user->pubkey] = ['pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side];
            }

            // A tournament's roster side (mix team, RL 1v1 player) has no lineup (P8b).
            foreach (User::query()->whereIn('id', $match->rosterSide($side))->get() as $player) {
                $members[$player->pubkey] = ['pubkey' => $player->pubkey, 'name' => $player->displayName(), 'side' => $side];
            }
        }

        return array_values($members);
    }
}
