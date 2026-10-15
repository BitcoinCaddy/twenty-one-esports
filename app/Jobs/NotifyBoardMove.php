<?php

namespace App\Jobs;

use App\Models\BoardGame;
use App\Support\Notifications\BoardNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tells the player to move of a correspondence move (BoardNotifications::yourMove()) once the mover can no longer
 * take it back: dispatched by BoardGameService, delayed by `esports.board_games.take_back_seconds`. A move taken
 * back in the meantime (or already answered) is no longer the game's last, and nothing is sent for it.
 */
class NotifyBoardMove implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $gameId, public int $moveId)
    {
        $this->afterCommit();
    }

    public function handle(BoardNotifications $notifications): void
    {
        $game = BoardGame::query()->find($this->gameId);

        if ($game !== null && self::stands($game, $this->moveId)) {
            $notifications->yourMove($game);
        }
    }

    /** Whether the move is still the last of a running game: not taken back, not answered, the game not over. */
    public static function stands(BoardGame $game, int $moveId): bool
    {
        return $game->isActive() && (int) $game->moves()->reorder('ply', 'desc')->value('id') === $moveId;
    }
}
