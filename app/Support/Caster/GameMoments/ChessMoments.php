<?php

namespace App\Support\Caster\GameMoments;

use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Support\Chess\ChessGameService;

/**
 * What a running chess game gives the caster beyond its move number, read
 * from the game's own positions (the FEN after every accepted move) and
 * clocks; no engine, no evaluation:
 *
 * - `check`: the last move gives check (its SAN ends in "+").
 * - `capture` / `exchange`: the last move took a piece, or the last plies
 *   were captures in a row (two or more).
 * - `comeback`: a side that was MATERIAL_COMEBACK points behind for
 *   SUSTAINED_PLIES plies in a row earlier in the game is level or ahead
 *   now (a trade in progress, a capture and its recapture, is no deficit).
 * - `material` / `level`: who is ahead by the classic count (pawn 1, knight
 *   and bishop 3, rook 5, queen 9) and by what, said as the pieces when the
 *   difference is one kind ("eine Qualität", "zwei Bauern", "die Dame"); or
 *   level after captures.
 * - `clock`: a side is low on time (a live game: under LOW_CLOCK_MS on its
 *   clock; a correspondence game: under LOW_DAY_MS left of its day).
 * - `phase`: opening, middlegame or endgame by the material on the board
 *   (our rule of thumb, see phase()).
 * - `losses`: what each side has lost so far, counted from the captures.
 *
 * Every moment carries the game and both players' spoken names, its `kind`,
 * a `mood` (Mood) where the rule gives one and `lead`, the player the moment
 * is about. At most PER_GAME moments, in the order above.
 */
final class ChessMoments
{
    public const VALUES = ['p' => 1, 'n' => 3, 'b' => 3, 'r' => 5, 'q' => 9];

    /** Moments per game. */
    public const PER_GAME = 4;

    /** Points behind that make being level again a comeback. */
    public const MATERIAL_COMEBACK = 3;

    /** Plies in a row a deficit has to last to count for a comeback (longer than a capture and its recapture). */
    public const SUSTAINED_PLIES = 4;

    /** A material edge of this many points or more is dominance; from TENSION_POINTS it is tension, below close. */
    public const DOMINANCE_POINTS = 5;

    public const TENSION_POINTS = 3;

    /** Non-pawn material of both sides together at or below which a game is an endgame (start: 62). */
    public const ENDGAME_MATERIAL = 26;

    /** Non-pawn material at or above which, within OPENING_MOVES, a game is still in its opening. */
    public const OPENING_MATERIAL = 56;

    public const OPENING_MOVES = 10;

    /** A live clock under 30 seconds is low. */
    public const LOW_CLOCK_MS = 30_000;

    /** A correspondence move with under two hours of its day left is late. */
    public const LOW_DAY_MS = 7_200_000;

    private const COLORS = ['w' => 'Weiß', 'b' => 'Schwarz'];

    /** A captured piece, spoken as the object of "schlägt". */
    private const CAPTURED = ['p' => 'einen Bauern', 'n' => 'einen Springer', 'b' => 'einen Läufer', 'r' => 'einen Turm', 'q' => 'die Dame'];

    public function __construct(private ChessGameService $games) {}

    /**
     * The moments of a running game whose players' spoken names are `$white` and `$black`.
     *
     * @return list<array<string, string|int>>
     */
    public function of(ChessGame $game, string $white, string $black, int $nowMs): array
    {
        $names = ['w' => $white, 'b' => $black];
        $base = ['game' => 'Schach', 'white' => $white, 'black' => $black];
        $moves = array_values($game->moves()->orderBy('ply')->get(['ply', 'san', 'fen'])->all());
        $history = self::history($game->startFen(), $moves);
        $pieces = self::pieces($game->fen);
        $moments = [];

        $last = $history === [] ? null : $history[count($history) - 1];
        if ($last !== null && str_ends_with($last['san'], '+')) {
            $moments[] = [...$base, 'kind' => 'check', 'mood' => Mood::TENSION, 'lead' => $names[$last['mover']], 'check_by' => $names[$last['mover']], 'check_to' => $names[self::other($last['mover'])]];
        }

        $streak = 0;
        for ($i = count($history) - 1; $i >= 0 && $history[$i]['captured'] !== null; $i--) {
            $streak++;
        }
        if ($last !== null && $streak >= 2) {
            $moments[] = [...$base, 'kind' => 'exchange', 'mood' => Mood::TENSION, 'lead' => $names[$last['mover']], 'exchange_captures' => $streak, 'exchange' => Words::count($streak, 'ein Schlagzug', 'Schlagzüge')];
        } elseif ($last !== null && $last['captured'] !== null) {
            $moments[] = [...$base, 'kind' => 'capture', 'mood' => Mood::TENSION, 'lead' => $names[$last['mover']], 'capture_by' => $names[$last['mover']], 'captured' => self::CAPTURED[$last['captured']]];
        }

        $balance = self::material($pieces['w']) - self::material($pieces['b']);
        foreach (['w' => 1, 'b' => -1] as $side => $sign) {
            $worst = -self::sustainedDeficit(array_map(fn (array $ply): int => $sign * $ply['balance'], $history));

            if ($sign * $balance >= 0 && -$worst >= self::MATERIAL_COMEBACK) {
                $moments[] = [...$base, 'kind' => 'comeback', 'mood' => Mood::COMEBACK, 'lead' => $names[$side], 'comeback_by' => $names[$side], 'comeback_from' => Words::count(-$worst, 'einen Punkt', 'Punkte'), 'comeback_points' => -$worst];
            }
        }

        $lost = self::lost($history);
        if ($balance !== 0) {
            $ahead = $balance > 0 ? 'w' : 'b';
            $points = abs($balance);
            $moments[] = [...$base, 'kind' => 'material', 'mood' => self::materialMood($points), 'lead' => $names[$ahead],
                'ahead' => $names[$ahead], 'ahead_color' => self::COLORS[$ahead], 'behind' => $names[self::other($ahead)],
                'edge' => self::edge($pieces[$ahead], $pieces[self::other($ahead)]), 'edge_points' => $points];
        } elseif (array_sum($lost['w']) + array_sum($lost['b']) >= 2) {
            $moments[] = [...$base, 'kind' => 'level', 'mood' => Mood::CLOSE, 'level_off' => Words::count(array_sum($lost['w']) + array_sum($lost['b']), 'ein Stein', 'Steine')];
        }

        $low = $this->lowClock($game, $nowMs);
        if ($low !== null) {
            $moments[] = [...$base, 'kind' => 'clock', 'mood' => Mood::TENSION, 'lead' => $names[$low], 'clock_low' => $names[$low]];
        }

        $phase = self::phase($pieces, (int) $game->ply);
        $moments[] = array_filter([...$base, 'kind' => 'phase', 'mood' => $phase === 'Endspiel' ? Mood::TENSION : null, 'phase' => $phase, 'phase_in' => $phase === 'Eröffnung' ? 'in der Eröffnung' : 'im '.$phase, 'move' => intdiv((int) $game->ply, 2) + 1], fn (mixed $value): bool => $value !== null);

        if (array_sum($lost['w']) > 0 && array_sum($lost['b']) > 0) {
            $moments[] = [...$base, 'kind' => 'losses', 'white_lost' => self::lostPhrase($lost['w']), 'black_lost' => self::lostPhrase($lost['b'])];
        }

        return array_slice($moments, 0, self::PER_GAME);
    }

    /**
     * The pieces of both sides on a FEN's board, by kind (p, n, b, r, q; kings left out).
     *
     * @return array{w: array<string, int>, b: array<string, int>}
     */
    public static function pieces(string $fen): array
    {
        $count = ['w' => array_fill_keys(array_keys(self::VALUES), 0), 'b' => array_fill_keys(array_keys(self::VALUES), 0)];

        foreach (str_split(explode(' ', $fen)[0]) as $char) {
            $kind = strtolower($char);

            if (isset(self::VALUES[$kind])) {
                $count[ctype_upper($char) ? 'w' : 'b'][$kind]++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, int>  $pieces
     */
    public static function material(array $pieces): int
    {
        $sum = 0;

        foreach (self::VALUES as $kind => $value) {
            $sum += $value * ($pieces[$kind] ?? 0);
        }

        return $sum;
    }

    /**
     * What the side ahead has more, spoken: one kind of piece when only that
     * kind differs ("zwei Bauern", "eine Figur", "einen Turm", "die Dame"),
     * "eine Qualität" for a rook against a knight or bishop, else the points
     * ("drei Punkte Material").
     *
     * @param  array<string, int>  $ahead
     * @param  array<string, int>  $behind
     */
    public static function edge(array $ahead, array $behind): string
    {
        $pawns = $ahead['p'] - $behind['p'];
        $minors = $ahead['n'] + $ahead['b'] - $behind['n'] - $behind['b'];
        $rooks = $ahead['r'] - $behind['r'];
        $queens = $ahead['q'] - $behind['q'];

        return match (true) {
            $minors === 0 && $rooks === 0 && $queens === 0 && $pawns > 0 => Words::count($pawns, 'einen Bauern', 'Bauern'),
            $pawns === 0 && $queens === 0 && $rooks === 1 && $minors === -1 => 'eine Qualität',
            $pawns === 0 && $queens === 0 && $rooks === 0 && $minors === 1 => 'eine Figur',
            $pawns === 0 && $queens === 0 && $minors === 0 && $rooks === 1 => 'einen Turm',
            $pawns === 0 && $rooks === 0 && $minors === 0 && $queens === 1 => 'die Dame',
            default => Words::count(self::material($ahead) - self::material($behind), 'einen Punkt Material', 'Punkte Material'),
        };
    }

    /**
     * Opening, middlegame or endgame, by our rule of thumb (no source; an
     * engine's phase would weigh more): ENDGAME_MATERIAL or less non-pawn
     * material on the board is an endgame; within the first OPENING_MOVES
     * moves with at least OPENING_MATERIAL it is the opening; else the middlegame.
     *
     * @param  array{w: array<string, int>, b: array<string, int>}  $pieces
     */
    public static function phase(array $pieces, int $ply): string
    {
        $nonPawn = self::material($pieces['w']) - $pieces['w']['p'] + self::material($pieces['b']) - $pieces['b']['p'];

        return match (true) {
            $nonPawn <= self::ENDGAME_MATERIAL => 'Endspiel',
            intdiv($ply, 2) + 1 <= self::OPENING_MOVES && $nonPawn >= self::OPENING_MATERIAL => 'Eröffnung',
            default => 'Mittelspiel',
        };
    }

    /**
     * Every move with its mover, the piece it took (null: none) and the material balance after it (White minus Black).
     *
     * @param  list<ChessMove>  $moves
     * @return list<array{ply: int, san: string, mover: 'w'|'b', captured: string|null, balance: int}>
     */
    public static function history(string $startFen, array $moves): array
    {
        $history = [];
        $previousFen = $startFen;

        foreach ($moves as $move) {
            $mover = (explode(' ', $previousFen)[1] ?? 'w') === 'b' ? 'b' : 'w';
            $before = self::pieces($previousFen)[self::other($mover)];
            $after = self::pieces($move->fen);
            $captured = null;

            foreach (self::VALUES as $kind => $value) {
                if ($after[self::other($mover)][$kind] < $before[$kind]) {
                    $captured = $kind;
                }
            }

            $history[] = ['ply' => (int) $move->ply, 'san' => (string) $move->san, 'mover' => $mover, 'captured' => $captured,
                'balance' => self::material($after['w']) - self::material($after['b'])];
            $previousFen = $move->fen;
        }

        return $history;
    }

    /**
     * The largest deficit a side had for SUSTAINED_PLIES plies in a row (0 when none), from its balance after every ply.
     *
     * @param  list<int>  $balances  the side's material minus the other's, ply by ply
     */
    public static function sustainedDeficit(array $balances): int
    {
        $deficit = 0;

        for ($i = 0; $i + self::SUSTAINED_PLIES <= count($balances); $i++) {
            $best = $balances[$i];

            for ($j = $i + 1; $j < $i + self::SUSTAINED_PLIES; $j++) {
                $best = max($best, $balances[$j]);
            }

            $deficit = max($deficit, -$best);
        }

        return $deficit;
    }

    /**
     * The side whose clock is low, or null.
     *
     * @return 'w'|'b'|null
     */
    private function lowClock(ChessGame $game, int $nowMs): ?string
    {
        if (! $game->isActive()) {
            return null;
        }

        $clocks = $this->games->clocks($game, $nowMs);

        if ($game->isCorrespondence()) {
            return $game->deadline_ms !== null && $clocks[$game->turn()] < self::LOW_DAY_MS ? $game->turn() : null;
        }

        if (! $game->clocksRunning()) {
            return null;
        }

        $low = array_filter($clocks, fn (int $ms): bool => $ms < self::LOW_CLOCK_MS);
        asort($low);

        return array_key_first($low);
    }

    /**
     * What each side has lost, by kind (from the captures of the other side).
     *
     * @param  list<array{ply: int, san: string, mover: 'w'|'b', captured: string|null, balance: int}>  $history
     * @return array{w: array<string, int>, b: array<string, int>}
     */
    private static function lost(array $history): array
    {
        $lost = ['w' => array_fill_keys(array_keys(self::VALUES), 0), 'b' => array_fill_keys(array_keys(self::VALUES), 0)];

        foreach ($history as $ply) {
            if ($ply['captured'] !== null) {
                $lost[self::other($ply['mover'])][$ply['captured']]++;
            }
        }

        return $lost;
    }

    /**
     * "zwei Figuren und einen Bauern": pieces (knight, bishop, rook, queen) and pawns lost.
     *
     * @param  array<string, int>  $lost
     */
    private static function lostPhrase(array $lost): string
    {
        $pieces = $lost['n'] + $lost['b'] + $lost['r'] + $lost['q'];
        $parts = array_filter([
            $pieces > 0 ? Words::count($pieces, 'eine Figur', 'Figuren') : null,
            $lost['p'] > 0 ? Words::count($lost['p'], 'einen Bauern', 'Bauern') : null,
        ]);

        return implode(' und ', $parts);
    }

    private static function materialMood(int $points): string
    {
        return match (true) {
            $points >= self::DOMINANCE_POINTS => Mood::DOMINANCE,
            $points >= self::TENSION_POINTS => Mood::TENSION,
            default => Mood::CLOSE,
        };
    }

    /**
     * @param  'w'|'b'  $side
     * @return 'w'|'b'
     */
    private static function other(string $side): string
    {
        return $side === 'w' ? 'b' : 'w';
    }
}
