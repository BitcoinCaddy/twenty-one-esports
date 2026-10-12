<?php

namespace App\Support\Caster\Programme;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\WorldMemory;
use Carbon\CarbonImmutable;

/**
 * "Die Partie des Tages" (plan "TWENTY ONE Radio", P9; Shows::PARTIE): the
 * most dramatic finished league match of the last `partie.hours`, from our
 * own rows only. Chess games carry the most (moves, clocks, how it ended),
 * the other games their score from the mempool (WorldMemory results).
 * drama() ranks them: length, a clock run nearly dry, a timeout, a mate, a
 * resignation or an abandoned game early ("rage quit"), a one-point score.
 *
 * Players by their display name as /matches shows them (SceneFacts::spoken
 * of User::displayName(), never a Nostr profile); a game with a player whose
 * name cannot be spoken is left out. The chess moves are given in German
 * words ("Springer f drei"), so the replay needs no digit.
 *
 * @phpstan-type MatchPick array{key: string, data: string, names: list<string>, drama: int}
 */
class MatchOfTheDay
{
    private const PIECES = ['N' => 'Springer', 'B' => 'Läufer', 'R' => 'Turm', 'Q' => 'Dame', 'K' => 'König'];

    private const DIGITS = ['1' => 'eins', '2' => 'zwei', '3' => 'drei', '4' => 'vier', '5' => 'fünf', '6' => 'sechs', '7' => 'sieben', '8' => 'acht'];

    /**
     * The best match of the window not in `$used` (keys), or null when there is none.
     *
     * @param  array<string, int>  $used
     * @return MatchPick|null
     */
    public function pick(CarbonImmutable $now, array $used = []): ?array
    {
        $since = $now->subHours(max(1, (int) config('tv.commentary.programme.shows.formats.partie.hours', 24)));
        $candidates = [...$this->chess($since, $now), ...$this->others($since, $now)];
        $candidates = array_values(array_filter($candidates, fn (array $candidate): bool => ! isset($used[$candidate['key']])));
        usort($candidates, fn (array $a, array $b): int => $b['drama'] <=> $a['drama']);

        return $candidates[0] ?? null;
    }

    /**
     * @return list<MatchPick>
     */
    private function chess(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $picks = [];

        foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Finished)->whereNotNull('white_id')->whereNotNull('black_id')
            ->where('ended_at', '>=', $since)->where('ended_at', '<=', $now)->orderByDesc('ended_at')->limit(50)->get() as $game) {
            $reason = $game->end_reason instanceof ChessEndReason ? $game->end_reason->value : null;
            $white = SceneFacts::spoken($game->white->displayName());
            $black = SceneFacts::spoken($game->black->displayName());

            if ($white === null || $black === null || in_array($reason, ['aborted', 'voided', 'director', 'forfeit'], true) || ! in_array($game->result, ['1-0', '0-1', '1/2-1/2'], true)) {
                continue;
            }

            $moves = intdiv((int) $game->ply + 1, 2);
            $winnerColour = match ($game->result) {
                '1-0' => 'w',
                '0-1' => 'b',
                default => null,
            };
            [$winner, $loser] = $winnerColour === 'b' ? [$black, $white] : [$white, $black];
            $clock = $winnerColour === null ? null : (int) ($winnerColour === 'w' ? $game->white_ms : $game->black_ms);
            $timed = $game->mode !== ChessGame::CORRESPONDENCE;
            $drama = min(60, $moves)
                + ($timed && $clock !== null && $clock < 10_000 ? 30 : 0)
                + ($reason === 'timeout' ? 20 : 0)
                + ($reason === 'checkmate' ? 15 : 0)
                + (in_array($reason, ['resignation', 'abandoned'], true) && $moves <= 20 ? 25 : 0);

            /** @var list<ChessMove> $all */
            $all = $game->moves()->orderBy('ply')->get(['ply', 'san'])->all();
            $opening = implode(', ', array_map(fn (ChessMove $move): string => self::spokenMove($move->san), array_slice($all, 0, 6)));
            $ending = implode(', ', array_map(fn (ChessMove $move): string => self::spokenMove($move->san), array_slice($all, -4)));
            $mode = SceneFacts::MODES[$game->mode] ?? ucfirst($game->mode);
            $how = match ($reason) {
                'checkmate' => 'durch Matt',
                'resignation' => 'durch Aufgabe',
                'timeout' => 'auf Zeit',
                'abandoned' => 'weil die Gegenseite das Brett verlassen hat',
                'stalemate' => 'im Patt',
                'agreement' => 'durch Einigung',
                'threefold_repetition' => 'durch dreifache Stellungswiederholung',
                'fifty_move_rule' => 'nach der Fünfzig-Züge-Regel',
                'insufficient_material' => 'mangels Material',
                default => null,
            };
            $minutes = $game->created_at !== null && $game->ended_at !== null ? max(1, intdiv((int) $game->created_at->diffInSeconds($game->ended_at, true), 60)) : null;

            $lines = [
                "Schach ({$mode}), beendet ".WorldMemory::relative((int) $game->ended_at?->getTimestamp(), $now),
                $winnerColour === null ? "Ergebnis: {$white} mit Weiß und {$black} mit Schwarz trennen sich remis".($how !== null ? " {$how}" : '')
                    : "Ergebnis: {$winner} gewinnt mit ".($winnerColour === 'w' ? 'Weiß' : 'Schwarz')." gegen {$loser}".($how !== null ? " {$how}" : ''),
                "Länge: {$moves} Züge".($minutes !== null && $timed ? ", {$minutes} Minuten" : ''),
                $timed && $clock !== null ? 'Restzeit des Siegers am Ende: '.intdiv($clock, 1000).' Sekunden' : null,
                $opening !== '' ? "Eröffnung: {$opening}" : null,
                $ending !== '' && count($all) > 6 ? "Die letzten Züge: {$ending}" : null,
            ];

            $picks[] = ['key' => 'chess:'.$game->id, 'data' => implode("\n", array_map(fn (string $line): string => '- '.$line, array_values(array_filter($lines)))), 'names' => [$white, $black], 'drama' => $drama];
        }

        return $picks;
    }

    /**
     * The other games' results with a score (WorldMemory reads them from the mempool), a one-point score ranked up.
     *
     * @return list<MatchPick>
     */
    private function others(CarbonImmutable $since, CarbonImmutable $now): array
    {
        $picks = [];

        foreach (app(WorldMemory::class)->items($now) as $item) {
            if ($item['kind'] !== WorldMemory::RESULT || str_starts_with($item['key'], 'chess:') || $item['at'] < $since->getTimestamp()
                || preg_match('/^(.+?): (.+?) gewinnt gegen (.+?) \((\d+) zu (\d+)\)$/u', $item['line'], $match) !== 1) {
                continue;
            }

            $gap = abs((int) $match[4] - (int) $match[5]);
            $picks[] = [
                'key' => $item['key'],
                'data' => '- '.$match[1].', beendet '.WorldMemory::relative($item['at'], $now)."\n- Ergebnis: {$match[2]} gewinnt gegen {$match[3]}, {$match[4]} zu {$match[5]}",
                'names' => [$match[2], $match[3]],
                'drama' => 10 + ($gap <= 1 ? 25 : 0) + min(20, (int) $match[4] + (int) $match[5]),
            ];
        }

        return $picks;
    }

    /** A move in standard notation as spoken German: "Nxf3+" -> "Springer schlägt f drei, Schach". */
    public static function spokenMove(string $san): string
    {
        $suffix = str_ends_with($san, '#') ? ', Matt' : (str_ends_with($san, '+') ? ', Schach' : '');
        $san = rtrim($san, '+#!?');

        if (str_starts_with($san, 'O-O-O')) {
            return 'lange Rochade'.$suffix;
        }

        if (str_starts_with($san, 'O-O')) {
            return 'kurze Rochade'.$suffix;
        }

        if (preg_match('/^([NBRQK])?([a-h])?([1-8])?(x)?([a-h])([1-8])(?:=([NBRQ]))?$/', $san, $match) !== 1) {
            return $san.$suffix;
        }

        $words = array_filter([
            $match[1] !== '' ? self::PIECES[$match[1]] : ($match[4] !== '' && $match[2] !== '' ? 'Bauer '.$match[2] : null),
            $match[1] !== '' && $match[2] !== '' ? 'von '.$match[2] : null,
            $match[4] !== '' ? 'schlägt' : null,
            $match[5].' '.self::DIGITS[$match[6]],
            ($match[7] ?? '') !== '' ? 'mit Umwandlung in '.self::PIECES[$match[7]] : null,
        ]);

        return implode(' ', $words).$suffix;
    }
}
