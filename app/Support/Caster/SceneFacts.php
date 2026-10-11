<?php

namespace App\Support\Caster;

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\User;
use App\Support\TwentyOne\Stream\TournamentSlides;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The public facts a commentary script of a phase may speak about, read
 * from the league's own tables: live chess games (who plays whom), results of
 * the last hours, tournaments open for sign-up, the size of the league.
 * Players are named by their league display name only, never with a gamer
 * tag, a game account or a Lightning address.
 *
 * Only facts that change with the programme go in (no viewer count, no
 * clock): `hash()` of the facts tells the queue when the scripts of a phase
 * were written for a state that is gone (superseded scripts expire).
 *
 * A read that fails leaves its part out; the phase then has fewer facts.
 */
class SceneFacts
{
    /** Live games named in a live-match script. */
    private const LIVE_GAMES = 3;

    /** Results of this many hours count as fresh for an after-result script. */
    private const RESULT_HOURS = 2;

    public function __construct(private TournamentSlides $tournaments) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $phase): array
    {
        $facts = [
            'sender' => 'TWENTY ONE TV',
            'website_spoken' => 'esports punkt einundzwanzig punkt space',
            ...$this->league(),
        ];

        return match ($phase) {
            ScenePhase::LIVE_MATCH => [...$facts, 'live_games' => $this->liveGames()],
            ScenePhase::AFTER_RESULT => [...$facts, 'results' => $this->results()],
            ScenePhase::PRE_SHOW => [...$facts, 'tournaments_open' => $this->openTournaments()],
            default => [...$facts, 'tournaments_open' => $this->openTournaments()],
        };
    }

    /**
     * The phases worth writing ahead for now: the calm phase always, the
     * others while there is something to say about them.
     *
     * @return list<string>
     */
    public function likelyPhases(CarbonImmutable $now): array
    {
        $phases = [ScenePhase::isNight($now) ? ScenePhase::NIGHT : ScenePhase::BREAK];

        if ($this->liveGames() !== []) {
            array_unshift($phases, ScenePhase::LIVE_MATCH);
        }

        if ($this->results() !== []) {
            $phases[] = ScenePhase::AFTER_RESULT;
        }

        if (! ScenePhase::isNight($now) && $this->openTournaments() !== []) {
            $phases[] = ScenePhase::PRE_SHOW;
        }

        return $phases;
    }

    /**
     * @param  array<array-key, mixed>  $facts
     */
    public static function hash(array $facts): string
    {
        return hash('sha256', (string) json_encode($facts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, int>
     */
    protected function league(): array
    {
        try {
            return ['players' => User::query()->count(), 'clans' => Clan::query()->count()];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{white: string, black: string, mode: string}>
     */
    protected function liveGames(): array
    {
        try {
            $games = [];

            foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Active)->whereNotNull('white_id')->whereNotNull('black_id')
                ->orderBy('id')->limit(self::LIVE_GAMES)->get() as $game) {
                $games[] = ['white' => $game->white->displayName(), 'black' => $game->black->displayName(), 'mode' => $game->isCorrespondence() ? 'Fernschach' : 'Schach live'];
            }

            return $games;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{winner: string, loser: string}|array{draw: list<string>}>
     */
    protected function results(): array
    {
        try {
            $results = [];

            foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Finished)->whereNotNull('white_id')->whereNotNull('black_id')
                ->where('ended_at', '>=', now()->subHours(self::RESULT_HOURS))->orderByDesc('ended_at')->limit(3)->get() as $game) {
                $white = $game->white->displayName();
                $black = $game->black->displayName();
                $results[] = match ($game->result) {
                    '1-0' => ['winner' => $white, 'loser' => $black],
                    '0-1' => ['winner' => $black, 'loser' => $white],
                    default => ['draw' => [$white, $black]],
                };
            }

            return $results;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{name: string, game: string}>
     */
    protected function openTournaments(): array
    {
        try {
            $open = [];

            foreach ($this->tournaments->upcoming()->take(2) as $tournament) {
                $open[] = ['name' => $tournament->name, 'game' => $tournament->game];
            }

            return $open;
        } catch (Throwable) {
            return [];
        }
    }
}
