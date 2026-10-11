<?php

namespace App\Games;

use App\Support\Board\BlockliRules;
use App\Support\Board\BoardRules;

/**
 * Blockli, a race with blocks on 9 x 9 (Quoridor-type rules), blitz 5+3,
 * rapid 10+5 or one move a day, played on the board game core (blitz was
 * dropped on 2026-10-07 and came back with rapid on 2026-10-11, user:
 * "Blockli braucht unbedingt einen Rapid Modus, wie Schach ihn auch hat";
 * "am besten noch Blitz auch anschalten"). Registered through
 * `config('esports.board_games.games.blockli')`, off until
 * ESPORTS_BOARD_GAME_BLOCKLI switches it on; the rules are
 * {@see BlockliRules}.
 */
final class Blockli extends BoardGame
{
    public const SLUG = 'blockli';

    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    public function rules(): BoardRules
    {
        return new BlockliRules;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Blockli';
    }

    public function modes(): array
    {
        return [
            // As chess has them, in chess's order (user, 2026-10-11): each its own rating pool and ladder (blockli/blitz, blockli/rapid).
            'blitz' => new GameMode('blitz', 'Blitz 5+3', 1, [], [], 'player', true, '300+3'),
            'rapid' => new GameMode('rapid', 'Rapid 10+5', 1, [], [], 'player', true, '600+5'),
            // Correspondence: one move per day, as for the other board games; the rules end every game.
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', true, '1/86400'),
        ];
    }

    public function resultSchema(GameMode $mode): array
    {
        return ['result' => 'one of '.implode(', ', self::RESULTS)];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'];
    }

    /**
     * The first move is an advantage (DerCaddy's open point 5; user,
     * 2026-10-07): a tournament pairing plays twice with the colours swapped,
     * and at 1:1 a third game with drawn colours decides, in the tournament's
     * mode (blitz, rapid or correspondence).
     */
    public function playsTwoLegs(): bool
    {
        return true;
    }

    public function assets(): GameAssets
    {
        return new GameAssets('blockli', 'var(--color-edge)', 'var(--color-line)', 'Blockli', new GameCover('blockli'), $this->credit(), $this->creditUrl());
    }
}
