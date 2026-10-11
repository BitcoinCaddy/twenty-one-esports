<?php

namespace App\Support\Tournaments;

use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\LobbyWords;

/**
 * League-made lobbies for every tournament match (plan "Refactor und Design-Revamp", P5, user decision
 * 2026-10-10: all tournament matches, 1v1 cups too, get the league's lobby name and password at the draw, as
 * lobby tournaments already do, so the directors can join any match as spectators; casual 1v1 keeps the
 * lobby the host shares in the chat). A game whose players meet in a private lobby with a name and a password
 * (Rocket League, Age of Empires II) gets them; EA Sports FC meets by EA ID and gets none.
 *
 * The host is the higher seed. Name and password are stored encrypted on the series (SeriesMatch casts) and
 * shown only to its two sides and to the tournament's directors and the admins ({@see visibleTo()}).
 */
final class LeagueLobby
{
    /** Games whose players meet in a lobby the host creates with a name and a password. */
    public const GAMES = ['rocket-league', 'age-of-empires-2'];

    public static function sharesLobby(string $game): bool
    {
        return in_array($game, self::GAMES, true);
    }

    /**
     * The lobby fields of a new tournament series of match number `$number`: name, password and the host
     * (the higher seed), or nothing for a game without a lobby.
     *
     * @return array{lobby_name?: string, lobby_password?: string, host_side?: string}
     */
    public static function forSeries(string $game, int $number, TournamentParticipant $challenger, TournamentParticipant $challenged): array
    {
        if (! self::sharesLobby($game)) {
            return [];
        }

        return [
            'lobby_name' => LobbyWords::matchName($number),
            'lobby_password' => LobbyWords::password(),
            'host_side' => self::hostSide($challenger, $challenged),
        ];
    }

    /** The higher seed hosts (seed 1 above seed 2); without seeds the challenger (slot 0) does. */
    public static function hostSide(TournamentParticipant $challenger, TournamentParticipant $challenged): string
    {
        $a = $challenger->seed ?? PHP_INT_MAX;
        $b = $challenged->seed ?? PHP_INT_MAX;

        return $b < $a ? 'challenged' : 'challenger';
    }

    /** A tournament series whose lobby the league made: it carries a name nobody of the players set. */
    public static function isLeagueMade(SeriesMatch $series): bool
    {
        return $series->tournament_match_id !== null && $series->lobby_name !== null && $series->lobby_updated_by_id === null
            && self::sharesLobby($series->game);
    }

    /**
     * Who may read the league lobby: the players of its two sides, the tournament's directors and the admins.
     */
    public static function visibleTo(SeriesMatch $series, ?User $user): bool
    {
        if ($user === null || ! self::isLeagueMade($series)) {
            return false;
        }

        if ($series->participantSideOf($user) !== null || $user->isAdmin()) {
            return true;
        }

        $tournament = $series->tournamentMatch?->tournament;

        return $tournament instanceof Tournament && $tournament->isDirectedBy($user);
    }
}
