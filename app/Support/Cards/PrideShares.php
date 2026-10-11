<?php

namespace App\Support\Cards;

use App\Models\Tournament;
use App\Models\User;

/**
 * The share posts of the pride pictures (plan "KI-Stimme der Liga", P4),
 * built for the share button (SharePosts::post() hands `player-card` and
 * `tape` here; template, signing check and archive stay there):
 *
 * - `player-card`: the player's own card, id = their npub; only a player
 *   who has one (PrideMoments::hasCard());
 * - `tape`: the tale of the tape of a final the player plays in, id = the
 *   tournament; it mentions the other side (NIP-27, like a won game's post).
 *
 * Both link a page of this site; no fee, no hashtag, no gamer tag.
 */
final class PrideShares
{
    public const TYPES = ['player-card', 'tape'];

    public function post(User $user, string $type, string $id): ?SharePost
    {
        return match ($type) {
            'player-card' => $this->playerCard($user, $id),
            'tape' => $this->tape($user, $id),
            default => null,
        };
    }

    private function playerCard(User $user, string $npub): ?SharePost
    {
        if ($npub !== $user->npub) {
            return null;
        }

        $card = PrideCard::player($user);

        if (! PrideMoments::hasCard($card->facts)) {
            return null;
        }

        return new SharePost(
            type: 'player-card',
            sentence: __('My player card on TWENTY ONE Esports: :title.', ['title' => rtrim($card->line, '.!?')]),
            cardUrl: $card->url(SharePosts::FORMAT),
            dimensions: PrideCard::FORMATS[SharePosts::FORMAT],
            storyPath: $card->path('story'),
            link: self::absolute(route('players.show', $user->npub, false)),
        );
    }

    private function tape(User $user, string $id): ?SharePost
    {
        $tournament = ctype_digit($id) ? Tournament::query()->find((int) $id) : null;
        $final = $tournament === null ? null : PrideMoments::upcomingFinal($tournament);

        if ($tournament === null || $final === null) {
            return null;
        }

        $final->loadMissing('slots.participant');
        $sides = $final->slots->sortBy('slot')->values();
        $mine = $sides->search(fn ($slot): bool => in_array($user->id, $slot->participant->memberIds(), true));

        if (! is_int($mine)) {
            return null;
        }

        $other = $sides[1 - $mine]->participant;
        $opponents = User::query()->whereKey($other->memberIds())->whereNotNull('pubkey')->orderBy('id')->limit(SharePosts::MAX_MENTIONS)->get();
        $card = PrideCard::tape($tournament, $final);

        return new SharePost(
            type: 'tape',
            sentence: __('Final of :tournament: me against :opponent.', ['tournament' => $tournament->name, 'opponent' => $other->name]),
            cardUrl: $card->url(SharePosts::FORMAT),
            dimensions: PrideCard::FORMATS[SharePosts::FORMAT],
            storyPath: $card->path('story'),
            link: self::absolute(route('tournaments.show', $tournament, false)),
            mentions: array_values($opponents->map(fn (User $opponent): array => ['pubkey' => (string) $opponent->pubkey, 'name' => $opponent->displayName()])->all()),
        );
    }

    private static function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }
}
