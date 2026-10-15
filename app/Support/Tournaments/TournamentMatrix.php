<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * The live page's overview of every round robin group and Swiss stage as a cross table (user 2026-10-15: "Ein
 * Turnier-Admin muss eine ewiglange Liste durchscrollen … Eine Matrix aller Spiele wäre doch genial mit
 * Ampel-System, wo was steht und wo nachhak-Bedarf besteht"): one row and one column per player, a cell per
 * pairing with its score from the row's side and a traffic light.
 *
 * - RED, the direction should step in: a result held, a reported or disputed series, one with the admins; a board
 *   or chess game without a first move after RED_FIRST_MOVE_HOURS, a game restarted for a missing first move that
 *   still has none, a move deadline less than RED_DEADLINE_HOURS away.
 * - YELLOW, keep an eye on it: someone on the move for more than YELLOW_ON_MOVE_HOURS, the second game of a two-leg
 *   pairing under way, a ready match without a game yet.
 * - GREEN: under way and moving. DONE: decided. WAITING: its sides are not known yet, or it was not needed.
 *
 * Reads every game of the tournament in one query per kind, never one per match.
 */
final class TournamentMatrix
{
    public const RED = 'red';

    public const YELLOW = 'yellow';

    public const GREEN = 'green';

    public const DONE = 'done';

    public const WAITING = 'waiting';

    /** No first move this long after the game started: red. */
    public const RED_FIRST_MOVE_HOURS = 6;

    /** The move deadline is less than this away: red. */
    public const RED_DEADLINE_HOURS = 6;

    /** On the move this long: yellow. */
    public const YELLOW_ON_MOVE_HOURS = 12;

    /** @var Collection<int, TournamentParticipant> */
    private Collection $participants;

    /** @var array<int|string, EloquentCollection<int, BoardGame>> board games by tournament match */
    private array $boardGames = [];

    /** @var array<int|string, EloquentCollection<int, ChessGame>> chess games by tournament match */
    private array $chessGames = [];

    public function __construct(private Tournament $tournament)
    {
        $this->participants = $tournament->participants()->get()->keyBy('id');
    }

    /**
     * One entry per round robin group or Swiss stage: its players (in seed order) and the cells by row and column
     * participant, plus how many pairings stand at each light.
     *
     * @return array{groups: list<array{title: string|null, players: list<array{id: int, name: string}>, cells: array<int, array<int, array{state: string, score: string, reason: string|null, href: string|null, key: string}>>}>, counts: array<string, int>, attention: list<array{state: string, group: string|null, names: string, reason: string, href: string|null, key: string}>}
     */
    public function overview(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stages = TournamentStage::query()->where('tournament_id', $this->tournament->id)
            ->whereIn('format', [TournamentFormat::RoundRobin, TournamentFormat::Swiss])->orderBy('number')->get();
        $counts = array_fill_keys([self::RED, self::YELLOW, self::GREEN, self::DONE], 0);
        $empty = ['groups' => [], 'counts' => $counts, 'attention' => []];

        if ($stages->isEmpty()) {
            return $empty;
        }

        $matches = TournamentMatch::query()->where('tournament_id', $this->tournament->id)
            ->whereHas('round', fn ($query) => $query->whereIn('tournament_stage_id', $stages->pluck('id')))
            ->with(['round', 'slots', 'seriesMatch'])->orderBy('id')->get();
        $ids = $matches->pluck('id');
        $this->boardGames = BoardGame::query()->whereIn('tournament_match_id', $ids)->orderBy('id')->get()->groupBy('tournament_match_id')->all();
        $this->chessGames = ChessGame::query()->whereIn('tournament_match_id', $ids)->orderBy('id')->get()->groupBy('tournament_match_id')->all();
        $groups = [];
        $attention = [];

        foreach ($stages as $stage) {
            $own = $matches->filter(fn (TournamentMatch $match): bool => $match->round->tournament_stage_id === $stage->id);

            foreach ($own->groupBy(fn (TournamentMatch $match): int => (int) ($match->group ?? 0))->sortKeys() as $group => $groupMatches) {
                $title = (int) $group > 0 ? (string) __('Group :group', ['group' => chr(64 + max(1, min(26, (int) $group)))]) : ($stages->count() > 1 ? $stage->format->label() : null);
                $players = $this->players($groupMatches);
                $cells = [];

                foreach ($groupMatches as $match) {
                    $slots = $match->slots->sortBy('slot')->values();
                    $a = $slots[0]->tournament_participant_id ?? null;
                    $b = $slots[1]->tournament_participant_id ?? null;

                    if ($a === null || $b === null) {
                        continue;
                    }

                    [$state, $reason] = $this->light($match, $now);
                    [$scoreA, $scoreB] = $this->scores($match);
                    $href = $this->href($match);
                    $cells[$a][$b] = ['state' => $state, 'score' => $scoreA, 'reason' => $reason, 'href' => $href, 'key' => strtoupper($match->key)];
                    $cells[$b][$a] = ['state' => $state, 'score' => $scoreB, 'reason' => $reason, 'href' => $href, 'key' => strtoupper($match->key)];

                    if (isset($counts[$state])) {
                        $counts[$state]++;
                    }

                    if (in_array($state, [self::RED, self::YELLOW], true) && $reason !== null) {
                        $attention[] = ['state' => $state, 'group' => $title, 'names' => $this->name($a).' – '.$this->name($b), 'reason' => $reason, 'href' => $href, 'key' => strtoupper($match->key)];
                    }
                }

                $groups[] = ['title' => $title, 'players' => $players, 'cells' => $cells];
            }
        }

        // Red first, then yellow; within a light in bracket order.
        usort($attention, fn (array $x, array $y): int => ($x['state'] === self::RED ? 0 : 1) <=> ($y['state'] === self::RED ? 0 : 1));

        return ['groups' => $groups, 'counts' => $counts, 'attention' => $attention];
    }

    /**
     * The players of a group's matches, in seed order.
     *
     * @param  Collection<int, TournamentMatch>  $matches
     * @return list<array{id: int, name: string}>
     */
    private function players(Collection $matches): array
    {
        $ids = $matches->flatMap(fn (TournamentMatch $match) => $match->slots->pluck('tournament_participant_id'))->filter()->unique();

        return array_values($ids->map(fn (int $id): ?TournamentParticipant => $this->participants->get($id))->filter()
            ->sortBy(fn (TournamentParticipant $participant): array => [$participant->seed ?? PHP_INT_MAX, $participant->id])
            ->map(fn (TournamentParticipant $participant): array => ['id' => $participant->id, 'name' => (string) $participant->name])->all());
    }

    private function name(int $participantId): string
    {
        return (string) ($this->participants->get($participantId)->name ?? '?');
    }

    /**
     * The light of one pairing and why, for the direction.
     *
     * @return array{0: string, 1: string|null}
     */
    private function light(TournamentMatch $match, CarbonImmutable $now): array
    {
        if ($match->held !== null) {
            return [self::RED, (string) __('Result on hold: decide it on the control')];
        }

        if ($match->result !== null || $match->status === 'done') {
            return [self::DONE, null];
        }

        if ($match->status !== 'ready') {
            return [self::WAITING, null];
        }

        $series = $match->seriesMatch;

        if ($series !== null) {
            return match (true) {
                $series->overdue_at !== null => [self::RED, (string) __('No result by the deadline: with the admins')],
                $series->status === SeriesStatus::Disputed => [self::RED, (string) __('Result disputed')],
                $series->status === SeriesStatus::Reported => [self::YELLOW, (string) __('Result reported, waiting for the confirmation')],
                default => [self::GREEN, null],
            };
        }

        $board = ($this->boardGames[$match->id] ?? new EloquentCollection)->filter(fn (BoardGame $game): bool => $game->id > (int) $match->replaced_through);
        $chess = ($this->chessGames[$match->id] ?? new EloquentCollection);
        $active = $board->first(fn (BoardGame $game): bool => $game->status === BoardGameStatus::Active)
            ?? $chess->first(fn (ChessGame $game): bool => $game->status === ChessGameStatus::Active);

        if ($active === null) {
            return [self::YELLOW, (string) __('No game started yet')];
        }

        $nowMs = $now->getTimestampMs();
        $whiteToMove = $active instanceof BoardGame ? $active->turn === 'w' : $active->ply % 2 === 0;
        $mover = $this->moverName($match, (int) ($whiteToMove ? $active->white_id : $active->black_id));
        $restarted = $board->contains(fn (BoardGame $game): bool => $game->status === BoardGameStatus::Aborted);
        $since = (int) floor(max(0, $nowMs - (int) $active->turn_started_ms) / 3_600_000);

        if ($active->ply === 0 && $restarted) {
            return [self::RED, (string) __('Restarted for a missing first move, still none: :name to move', ['name' => $mover])];
        }

        if ($active->ply === 0 && $now->diffInHours($active->created_at, true) >= self::RED_FIRST_MOVE_HOURS) {
            return [self::RED, (string) __('No first move for :hours h: :name to move', ['hours' => (int) floor($now->diffInHours($active->created_at, true)), 'name' => $mover])];
        }

        if ($active->deadline_ms !== null && $active->deadline_ms - $nowMs < self::RED_DEADLINE_HOURS * 3_600_000) {
            return [self::RED, (string) __('Move deadline in :hours h: :name to move', ['hours' => max(0, (int) floor(($active->deadline_ms - $nowMs) / 3_600_000)), 'name' => $mover])];
        }

        if ($since >= self::YELLOW_ON_MOVE_HOURS) {
            return [self::YELLOW, (string) __(':name on the move for :hours h', ['name' => $mover, 'hours' => $since])];
        }

        if ($board->contains(fn (BoardGame $game): bool => $game->status === BoardGameStatus::Finished)) {
            return [self::YELLOW, (string) __('Game :n under way', ['n' => (int) ($active->tournament_game ?? 2)])];
        }

        return [self::GREEN, null];
    }

    /** The name of the entry a user plays for in this match. */
    private function moverName(TournamentMatch $match, int $userId): string
    {
        foreach ($match->slots as $slot) {
            $participant = $this->participants->get((int) $slot->tournament_participant_id);

            if ($participant !== null && in_array($userId, $participant->memberIds(), true)) {
                return (string) $participant->name;
            }
        }

        return '?';
    }

    /**
     * Each side's score: the stored result, the legs so far of a two-leg pairing under way, else a dash.
     *
     * @return array{0: string, 1: string}
     */
    private function scores(TournamentMatch $match): array
    {
        $result = $match->result;

        if ($result !== null) {
            if (($result['double_loss'] ?? false) === true) {
                return ['0', '0'];
            }

            $won = $result['games_won'] ?? [];

            if (count($won) === 2) {
                return [self::number((float) $won[0]), self::number((float) $won[1])];
            }

            $winner = $result['winner'] ?? null;

            return $winner === null ? ['½', '½'] : ($winner === 0 ? ['1', '0'] : ['0', '1']);
        }

        $finished = ($this->boardGames[$match->id] ?? new EloquentCollection)
            ->filter(fn (BoardGame $game): bool => $game->id > (int) $match->replaced_through && $game->status === BoardGameStatus::Finished);

        if ($finished->isEmpty()) {
            return ['–', '–'];
        }

        $members = $this->participants->get((int) $match->slots->firstWhere('slot', 0)?->tournament_participant_id)?->memberIds() ?? [];
        $points = [0.0, 0.0];

        foreach ($finished->take(2) as $leg) {
            $whiteSlot = in_array((int) $leg->white_id, $members, true) ? 0 : 1;
            [$white, $black] = match ($leg->result) {
                '1-0' => [1.0, 0.0],
                '0-1' => [0.0, 1.0],
                default => [0.5, 0.5],
            };
            $points[$whiteSlot] += $white;
            $points[1 - $whiteSlot] += $black;
        }

        return [self::number($points[0]), self::number($points[1])];
    }

    /** Where a cell leads: the game on now (or the last one), the series room. */
    private function href(TournamentMatch $match): ?string
    {
        if ($match->seriesMatch !== null) {
            return route('matches.show', $match->seriesMatch);
        }

        $board = ($this->boardGames[$match->id] ?? new EloquentCollection);
        $game = $board->first(fn (BoardGame $game): bool => $game->status === BoardGameStatus::Active) ?? $board->last();

        if ($game !== null) {
            return Route::has('board.show') ? route('board.show', $game) : null;
        }

        $chess = ($this->chessGames[$match->id] ?? new EloquentCollection);
        $game = $chess->first(fn (ChessGame $game): bool => $game->status === ChessGameStatus::Active) ?? $chess->last();

        return $game !== null ? route('games.show', $game) : null;
    }

    private static function number(float $value): string
    {
        $whole = (int) floor($value);
        $half = $value - $whole >= 0.5;

        return $half ? ($whole === 0 ? '½' : $whole.'½') : (string) $whole;
    }
}
