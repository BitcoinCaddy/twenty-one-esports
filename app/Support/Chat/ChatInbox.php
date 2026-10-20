<?php

namespace App\Support\Chat;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Dock\OpenMatches;
use App\Support\GameNames;
use App\Support\Notifications\DmRelays;
use App\Support\Series\RoomMembers;
use App\Support\Tournaments\TournamentDesk;

/**
 * "Your chats" (DerCaddy, 2026-10-18: "Nachrichten in Chats sollten auch noch besser zu sehen sein … ein Reiter
 * Deine Chats, wo relevante Chats angezeigt werden, in denen ich mich beteiligt habe bzw. jemand mir geschrieben
 * hat"): the private chats a player is in, from the account menu (pages::me.chats).
 *
 * The league cannot read them (NIP-17 between the browsers, nostrChat.js), so it names the chats and what the
 * browser needs to find their messages in the player's inbox (resources/js/chatInbox.js): the desk of every
 * tournament whose desk is open to the player, the chat of every own game and the room of every own series that
 * runs or ended within DAYS days. The browser shows each one's newest message, its time and what is new.
 *
 * @phpstan-type Chat array{kind: 'desk'|'game'|'room', key: string, title: string, subtitle: string, href: string, at: int, since: int|null, settled: int|null, me: string, match: string|null, desk: int|null, opponent: string|null, members: list<string>, opponents: list<string>, managers: list<string>, names: array<string, string>}
 */
final class ChatInbox
{
    /** Ended games and series stay this many days. */
    public const DAYS = 14;

    /** At most this many chats, the most recently active first. */
    public const LIMIT = 40;

    /**
     * @return list<Chat>
     */
    public static function for(User $user): array
    {
        if (! filled($user->pubkey)) {
            return [];
        }

        $chats = [...self::desks($user), ...self::games($user), ...self::rooms($user)];
        usort($chats, fn (array $a, array $b): int => [$b['at'], $a['key']] <=> [$a['at'], $b['key']]);

        return array_slice($chats, 0, self::LIMIT);
    }

    /**
     * What the page hands the browser: the chats, the relays to read them on and the player's mutes.
     *
     * @return array<string, mixed>
     */
    public static function config(User $user): array
    {
        return [
            'me' => (string) $user->pubkey,
            'meName' => $user->displayName(),
            'chats' => self::for($user),
            'relays' => array_values(config('esports.chat.relays', [])),
            'lookupRelays' => DmRelays::lookupRelays(),
            'muted' => $user->mutedPubkeys(),
            // Gift wraps are backdated up to two days (NIP-59): the browser reads that far before the window.
            'since' => now()->subDays(self::DAYS)->getTimestamp(),
        ];
    }

    /** @return list<Chat> */
    private static function desks(User $user): array
    {
        $signedUp = TournamentSignup::query()->active()->where(fn ($query) => $query->where('user_id', $user->id)->orWhereJsonContains('members', $user->id))->pluck('tournament_id');
        $entered = TournamentParticipant::query()->where(fn ($query) => $query->where('user_id', $user->id)->orWhereJsonContains('members', $user->id))->pluck('tournament_id');
        $tournaments = Tournament::query()
            ->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running, TournamentStatus::Finished])
            ->where(fn ($query) => $query->whereIn('id', $signedUp->concat($entered)->unique()->values())
                ->orWhere('created_by_id', $user->id)
                ->orWhereHas('directors', fn ($directors) => $directors->where('users.id', $user->id)))
            ->latest('id')->limit(10)->get();
        $desks = [];

        foreach ($tournaments as $tournament) {
            $desk = TournamentDesk::for($tournament, $user);

            if ($desk === null) {
                continue;
            }

            $desks[] = self::chat('desk', 'desk:'.$tournament->id, $user, [
                'title' => $tournament->name,
                'subtitle' => __('Tournament desk'),
                'href' => $desk['url'],
                'at' => ($tournament->updated_at ?? $tournament->created_at)?->getTimestamp() ?? 0,
                'since' => $desk['since'],
                'desk' => $tournament->id,
                'members' => array_column($desk['members'], 'pubkey'),
                // The direction is never muted at its desk (deskChat.js): the browser keeps its messages.
                'managers' => array_column(array_filter($desk['members'], fn (array $member): bool => $member['manager']), 'pubkey'),
                'names' => array_column($desk['members'], 'name', 'pubkey'),
            ]);
        }

        return $desks;
    }

    /** @return list<Chat> */
    private static function games(User $user): array
    {
        $since = now()->subDays(self::DAYS);
        $games = [];

        $chess = ChessGame::query()->playedBy($user)->where(fn ($query) => $query->where('status', ChessGameStatus::Active)->orWhere('ended_at', '>=', $since))
            ->with(['white', 'black'])->latest('id')->limit(self::LIMIT)->get();

        foreach ($chess as $game) {
            $opponent = $game->opponentOf($user);

            if ($opponent !== null && filled($opponent->pubkey)) {
                $games[] = self::game($user, $opponent, 'chess:'.$game->id, (string) $game->id, GameNames::game('chess'), $game->isActive(), route('games.show', ['game' => $game]), $game);
            }
        }

        $boards = BoardGame::query()->playedBy($user)->where(fn ($query) => $query->where('status', BoardGameStatus::Active)->orWhere('ended_at', '>=', $since))
            ->with(['white', 'black'])->latest('id')->limit(self::LIMIT)->get();

        foreach ($boards as $game) {
            $opponent = $game->opponentOf($user);

            if ($opponent !== null && filled($opponent->pubkey)) {
                // Chess games and board games are numbered apart: the chat's `match` tag carries the prefix.
                $games[] = self::game($user, $opponent, 'board:'.$game->id, 'board:'.$game->id, GameNames::game($game->game), $game->isActive(), route('board.show', $game), $game);
            }
        }

        return $games;
    }

    /** @return Chat */
    private static function game(User $user, User $opponent, string $key, string $match, string $name, bool $live, string $href, ChessGame|BoardGame $game): array
    {
        return self::chat('game', $key, $user, [
            'title' => $opponent->displayName(),
            'subtitle' => $live ? __(':game, running', ['game' => $name]) : __(':game, over', ['game' => $name]),
            'href' => $href,
            'at' => ($game->updated_at ?? $game->created_at)?->getTimestamp() ?? 0,
            'since' => $game->created_at?->getTimestamp(),
            'settled' => $game->ended_at?->getTimestamp(),
            'match' => $match,
            'opponent' => $opponent->pubkey,
            'members' => [$user->pubkey, $opponent->pubkey],
            'names' => [$opponent->pubkey => $opponent->displayName()],
        ]);
    }

    /** @return list<Chat> */
    private static function rooms(User $user): array
    {
        $running = array_map(fn (SeriesStatus $status): string => $status->value, array_filter(SeriesStatus::cases(), fn (SeriesStatus $status): bool => $status->isRunning()));
        $series = OpenMatches::involving(SeriesMatch::query(), $user, OpenMatches::lineupsOf($user))
            ->where(fn ($query) => $query->whereIn('status', $running)->orWhere('finished_at', '>=', now()->subDays(self::DAYS)))
            // RoomMembers reads both lineups' seats per series: loaded here at once, not once per room.
            ->with(['challengerLineup.seats.user.clanMember', 'challengedLineup.seats.user.clanMember'])
            ->latest('id')->limit(10)->get();
        $rooms = [];

        foreach ($series as $match) {
            $members = array_values(array_filter(RoomMembers::of($match), fn (array $member): bool => filled($member['pubkey'])));
            $mine = collect($members)->firstWhere('pubkey', $user->pubkey);

            if ($mine === null) {
                continue;
            }

            $others = array_values(array_filter($members, fn (array $member): bool => $member['pubkey'] !== $user->pubkey && $member['side'] !== $mine['side']));

            $rooms[] = self::chat('room', 'room:'.$match->number, $user, [
                'title' => __('Match #:number', ['number' => $match->number]),
                'subtitle' => GameNames::game((string) $match->game).($others === [] ? '' : ' · '.implode(', ', array_column($others, 'name'))),
                'href' => route('matches.room', $match),
                'at' => ($match->updated_at ?? $match->created_at)?->getTimestamp() ?? 0,
                'since' => $match->created_at?->getTimestamp(),
                'settled' => $match->finished_at?->getTimestamp(),
                'match' => (string) $match->number,
                'members' => array_column($members, 'pubkey'),
                'opponents' => array_column($others, 'pubkey'),
                'names' => array_column($members, 'name', 'pubkey'),
            ]);
        }

        return $rooms;
    }

    /**
     * @param  'desk'|'game'|'room'  $kind
     * @param  array{title: string, subtitle: string, href: string, at: int, since?: int|null, settled?: int|null, match?: string|null, desk?: int|null, opponent?: string|null, members?: list<string>, opponents?: list<string>, managers?: list<string>, names?: array<string, string>}  $fields
     * @return Chat
     */
    private static function chat(string $kind, string $key, User $user, array $fields): array
    {
        return [
            'kind' => $kind, 'key' => $key, 'title' => '', 'subtitle' => '', 'href' => '', 'at' => 0, 'since' => null, 'settled' => null,
            'me' => (string) $user->pubkey, 'match' => null, 'desk' => null, 'opponent' => null, 'members' => [], 'opponents' => [], 'managers' => [], 'names' => [],
            ...$fields,
        ];
    }
}
