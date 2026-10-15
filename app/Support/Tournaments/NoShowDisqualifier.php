<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use Throwable;

/**
 * The league disqualifies an entry that lost `auto_disqualify_noshows` matches of the same tournament by not
 * showing up (user 2026-10-15: "Was passiert bei wiederholten No-Show von Mitspielern in einem Turnier, haben wir
 * ein Auto-Disqualify, damit das Turnier dann auch mal weitergehen kann?"): its open matches go to the opponents
 * (TournamentControl::disqualifyByLeague(), TournamentRunner::forfeitWithdrawn()), it is told, the moderation log
 * names the league.
 *
 * A no-show loss is a match decided by forfeit against the entry: a result naming it in `noshow` (a missed first
 * move, the double no-show rule), else any other forfeit it lost (a series no-show, a chess first-move forfeit, a
 * director's no-show). A disqualification's or a withdrawal's own forfeits do not count. Players mode only: in
 * director mode the directors decide. 0 switches it off.
 */
final class NoShowDisqualifier
{
    public function __construct(private TournamentControl $control) {}

    public static function threshold(): int
    {
        return max(0, (int) config('esports.tournaments.auto_disqualify_noshows', 2));
    }

    /** One pass over the running tournaments; returns how many entries were disqualified. */
    public function tick(): int
    {
        if (self::threshold() === 0) {
            return 0;
        }

        $done = 0;

        foreach (Tournament::query()->where('status', TournamentStatus::Running)->get() as $tournament) {
            if ($tournament->isDirectorMode()) {
                continue;
            }

            foreach ($this->due($tournament) as $participantId => $count) {
                try {
                    $done += $this->control->disqualifyByLeague($tournament, $participantId,
                        __('Disqualified by the league: :count matches lost by not showing up.', ['count' => $count])) ? 1 : 0;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $done;
    }

    /**
     * The entries still in at or over the threshold, with their no-show losses.
     *
     * @return array<int, int>
     */
    public function due(Tournament $tournament): array
    {
        $counts = self::counts($tournament);
        $out = TournamentParticipant::query()->where('tournament_id', $tournament->id)->whereNotNull('disqualified_at')->pluck('id')->all();

        return array_filter($counts, fn (int $count, int $id): bool => $count >= self::threshold() && ! in_array($id, $out, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * No-show losses per entry of a tournament.
     *
     * @return array<int, int>
     */
    public static function counts(Tournament $tournament): array
    {
        $counts = [];

        foreach (TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotNull('result')->with('slots')->get() as $match) {
            foreach (self::noShowSlots((array) $match->result) as $slot) {
                $id = $match->slots->firstWhere('slot', $slot)?->tournament_participant_id;

                if ($id !== null) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * The slots a stored result counts as not showing up.
     *
     * @param  array<string, mixed>  $result
     * @return list<int>
     */
    public static function noShowSlots(array $result): array
    {
        if (($result['forfeit'] ?? false) !== true || in_array($result['decided'] ?? null, ['disqualified', 'withdrawn'], true)) {
            return [];
        }

        if (isset($result['noshow']) && is_array($result['noshow'])) {
            return array_values(array_map(intval(...), $result['noshow']));
        }

        if (($result['double_loss'] ?? false) === true) {
            return [0, 1];
        }

        $winner = $result['winner'] ?? null;

        return is_int($winner) ? [1 - $winner] : [];
    }
}
