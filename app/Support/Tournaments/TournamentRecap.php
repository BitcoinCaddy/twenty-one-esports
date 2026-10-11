<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use Illuminate\Support\Facades\Cache;

/**
 * The recap of a finished tournament (plan "KI-Stimme der Liga", P3): the
 * facts its profile post (TournamentRecapNotes) and its page section are
 * written from. Real data only, read from the stored bracket:
 *
 * - `upsets`: decided matches whose winner was seeded with a lower Elo than
 *   the loser (TournamentParticipant::$rating, the Elo at sign-up close),
 *   the largest gap first, at most UPSETS. Mix teams carry no Elo of their
 *   own and are left out;
 * - `closest`: the decided match with the smallest margin, by games won in
 *   a series of more than one game, else by the points of a single game's
 *   score ("21:19"); a smaller margin first, then the longer match, then
 *   the later one. None when no match has a margin to compare (chess: one
 *   game, 1–0);
 * - `path`: the champion's played matches in bracket order, as the champion
 *   moment on the page has them (TournamentChampionMoment).
 *
 * Forfeits, double losses, draws and results the league decided on its own
 * (a withdrawn side, a no-show) are no upset and no close match: nobody
 * played them out. Heats and lobbies have no two sides and are skipped.
 *
 * Null while the tournament is not finished, for a league week (its own
 * weekly note has its podium) and when there is nothing to tell (no single
 * champion, no upset, no close match).
 *
 * cached() keeps the facts as scalars and arrays of scalars only (the
 * production cache refuses objects, `cache.serializable_classes` = false),
 * per locale (round labels are worded in it) and per state of the bracket
 * (a correction after the final changes the key).
 *
 * @phpstan-type RecapSide array{id: int, name: string, rating: int|null, user_id: int|null}
 * @phpstan-type RecapUpset array{round: string, winner: RecapSide, loser: RecapSide, gap: int, score: string|null}
 * @phpstan-type RecapClosest array{round: string, winner: RecapSide, loser: RecapSide, score: string, margin: int}
 * @phpstan-type RecapStep array{round: string, opponent: string, result: string, score: string|null}
 * @phpstan-type RecapFacts array{tournament: string, game: string, entrants: int, played: int, champion: RecapSide|null, path: list<RecapStep>, upsets: list<RecapUpset>, closest: RecapClosest|null}
 */
final class TournamentRecap
{
    /** Upsets a recap names at most. */
    public const UPSETS = 3;

    private const CACHE_SECONDS = 86_400;

    public function __construct(private readonly GameRegistry $games) {}

    /**
     * The facts, read once per bracket state and locale (see the class comment).
     *
     * @return RecapFacts|null
     */
    public function cached(Tournament $tournament): ?array
    {
        if ($tournament->status !== TournamentStatus::Finished || $tournament->isLeagueWeek()) {
            return null;
        }

        $state = (string) TournamentMatch::query()->where('tournament_id', $tournament->id)->max('updated_at');
        $key = 'tournament-recap:'.$tournament->id.':'.app()->getLocale().':'.sha1($state.'|'.$tournament->updated_at?->getTimestamp());
        $stored = Cache::get($key);

        if (is_array($stored)) {
            return $stored['facts'] ?? null;
        }

        $facts = $this->of($tournament);
        Cache::put($key, ['facts' => $facts], self::CACHE_SECONDS);

        return $facts;
    }

    /**
     * @return RecapFacts|null
     */
    public function of(Tournament $tournament): ?array
    {
        if ($tournament->status !== TournamentStatus::Finished || $tournament->isLeagueWeek()) {
            return null;
        }

        $rounds = $this->rounds($tournament);
        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'done')
            ->whereNotIn('bracket', ['bye', 'heat'])->with('slots.participant')->orderBy('id')->get();
        $upsets = [];
        $closest = null;
        $played = 0;

        foreach ($matches as $match) {
            $decided = $this->decided($match);

            if ($decided === null) {
                continue;
            }

            $played++;
            [$winner, $loser] = $decided;
            $round = $rounds[$match->key] ?? '';
            $score = $this->score($match, $winner);

            if (! $winner->isMixTeam() && ! $loser->isMixTeam() && $loser->rating > $winner->rating) {
                $upsets[] = [
                    'round' => $round,
                    'winner' => self::side($winner),
                    'loser' => self::side($loser),
                    'gap' => $loser->rating - $winner->rating,
                    'score' => $score,
                    'order' => $match->id,
                ];
            }

            $margin = $this->margin($match);

            if ($margin !== null && $score !== null) {
                $candidate = [
                    'round' => $round,
                    'winner' => self::side($winner),
                    'loser' => self::side($loser),
                    'score' => $score,
                    'margin' => $margin[0],
                    'rank' => [$margin[0], -$margin[1], -$match->id],
                ];

                if ($closest === null || $candidate['rank'] < $closest['rank']) {
                    $closest = $candidate;
                }
            }
        }

        usort($upsets, fn (array $a, array $b): int => [$b['gap'], $b['order']] <=> [$a['gap'], $a['order']]);
        $upsets = array_map(function (array $upset): array {
            unset($upset['order']);

            return $upset;
        }, array_slice($upsets, 0, self::UPSETS));

        if ($closest !== null) {
            unset($closest['rank']);
        }

        $championEntry = app(TournamentChampion::class)->of($tournament);
        $champion = $championEntry === null ? null : self::side($championEntry);
        $path = $champion === null ? [] : (TournamentChampionMoment::of($tournament, null)['path'] ?? []);

        if ($champion === null && $upsets === [] && $closest === null) {
            return null;
        }

        return [
            'tournament' => $tournament->title(),
            'game' => $this->games->name($tournament->game),
            'entrants' => $tournament->participants()->count(),
            'played' => $played,
            'champion' => $champion,
            'path' => $path,
            'upsets' => $upsets,
            'closest' => $closest,
        ];
    }

    /**
     * A played-out match of two known sides with a winner: [winner, loser];
     * null for a forfeit, a double loss, a draw or a result the league
     * decided on its own.
     *
     * @return array{0: TournamentParticipant, 1: TournamentParticipant}|null
     */
    private function decided(TournamentMatch $match): ?array
    {
        $result = $match->result;

        if (! is_array($result) || ! isset($result['winner']) || ! in_array((int) $result['winner'], [0, 1], true)
            || ($result['forfeit'] ?? false) || ($result['double_loss'] ?? false) || ($result['by'] ?? null) === 'league') {
            return null;
        }

        $sides = $match->slots->keyBy('slot');
        $winner = $sides->get((int) $result['winner'])?->participant;
        $loser = $sides->get(1 - (int) $result['winner'])?->participant;

        return $match->slots->count() === 2 && $winner !== null && $loser !== null ? [$winner, $loser] : null;
    }

    /**
     * The match's score from the winner's side: the stored label, else the games won ("3:1").
     */
    private function score(TournamentMatch $match, TournamentParticipant $winner): ?string
    {
        $result = (array) $match->result;
        $label = is_string($result['label'] ?? null) ? trim($result['label']) : '';

        if (preg_match('/^(\d+)\s*[:\-–]\s*(\d+)$/u', $label, $parts) === 1) {
            return max((int) $parts[1], (int) $parts[2]).':'.min((int) $parts[1], (int) $parts[2]);
        }

        $games = array_map(floatval(...), (array) ($result['games_won'] ?? []));

        if (count($games) === 2 && $games[0] + $games[1] > 1) {
            return self::number(max($games)).':'.self::number(min($games));
        }

        return $label === '' ? null : $label;
    }

    /**
     * [margin, length] of a match that has one: games in a series of more
     * than one game, else the points of a single game's "21:19" score.
     *
     * @return array{0: int, 1: int}|null
     */
    private function margin(TournamentMatch $match): ?array
    {
        $result = (array) $match->result;
        $games = array_map(floatval(...), (array) ($result['games_won'] ?? []));

        if (count($games) === 2 && $games[0] + $games[1] > 1) {
            return [(int) round(abs($games[0] - $games[1])), (int) round($games[0] + $games[1])];
        }

        $label = is_string($result['label'] ?? null) ? trim($result['label']) : '';

        if (preg_match('/^(\d+)\s*:\s*(\d+)$/', $label, $parts) === 1 && (int) $parts[1] !== (int) $parts[2]) {
            return [abs((int) $parts[1] - (int) $parts[2]), (int) $parts[1] + (int) $parts[2]];
        }

        return null;
    }

    /**
     * Match key => round label, as the bracket on the page words it.
     *
     * @return array<string, string>
     */
    private function rounds(Tournament $tournament): array
    {
        $rounds = [];

        foreach ((new TournamentTv($tournament))->stages() as $stage) {
            foreach (TournamentTv::boxesOf($stage) as $box) {
                if (is_string($box['key'] ?? null)) {
                    $rounds[$box['key']] = (string) ($box['round'] ?? '');
                }
            }
        }

        return $rounds;
    }

    /**
     * @return RecapSide
     */
    private static function side(TournamentParticipant $participant): array
    {
        return [
            'id' => $participant->id,
            'name' => $participant->name,
            'rating' => $participant->isMixTeam() ? null : $participant->rating,
            // Only a solo player is mentioned by their account; a team is named.
            'user_id' => $participant->lineup_id === null && ! $participant->isMixTeam() ? $participant->user_id : null,
        ];
    }

    private static function number(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
