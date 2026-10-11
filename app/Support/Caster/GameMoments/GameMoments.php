<?php

namespace App\Support\Caster\GameMoments;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\HyperMatch;
use App\Models\PongMatch;
use App\Models\SeriesMatch;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\SceneTopic;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\HyperScene;
use App\Support\TwentyOne\Stream\PongScene;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use Closure;
use Throwable;

/**
 * The game moments of a commentary topic (SceneFacts::forTopic() puts them
 * under `moments`): what the games its slides show are doing right now, as
 * structured facts with a mood (Mood) for the caster's emotion. Live games
 * (chess as the live-games facts name them, the board game, the
 * Hyperbitcoinization matches and series the stream shows), the Pong
 * results, a tournament under way, the score games' week.
 *
 * Only what the games' own state says: every moment class documents its
 * rule. A source that fails leaves its moments out (no moment rather than
 * a wrong one); at night none (the night topics name no player). At most
 * MAX moments, taken in turn from each game so one long game does not
 * crowd out the others.
 */
final class GameMoments
{
    /** Moments per topic. */
    public const MAX = 12;

    /** Chess games read, as SceneFacts names them (the newest active ones with speakable names). */
    public const CHESS_GAMES = 3;

    /** Hyperbitcoinization matches, Pong results, series and tournaments read per topic. */
    public const OTHERS = 2;

    public function __construct(
        private ChessMoments $chess,
        private BoardMoments $board,
        private PongMoments $pong,
        private HyperMatchMoments $hyper,
        private ScoreMoments $scores,
    ) {}

    /**
     * @return list<array<string, string|int>>
     */
    public function forTopic(string $topic, bool $night = false): array
    {
        if ($night) {
            return [];
        }

        $nowMs = (int) (microtime(true) * 1000);
        $sources = match ($topic) {
            SceneTopic::LIVE_GAMES, SceneTopic::MEMPOOL => [fn (): array => $this->chessGames($nowMs), fn (): array => [$this->boardGame()], fn (): array => $this->hyperMatches($nowMs), fn (): array => $this->series()],
            SceneTopic::RESULTS => [fn (): array => $this->pongResults()],
            SceneTopic::LIVE_TOURNAMENT => [fn (): array => $this->tournaments($nowMs)],
            SceneTopic::BLOCKFILL => [fn (): array => [$this->scores->blockfill()]],
            SceneTopic::TMNF => [fn (): array => [$this->scores->tmnf()]],
            default => [],
        };

        $games = [];
        foreach ($sources as $source) {
            foreach (self::safelyAll($source) as $moments) {
                if ($moments !== []) {
                    $games[] = $moments;
                }
            }
        }

        return self::interleave($games, self::MAX);
    }

    /**
     * The moments of several games taken in turn (first of each, then second of each …), at most `$max`.
     *
     * @param  list<list<array<string, string|int>>>  $games
     * @return list<array<string, string|int>>
     */
    public static function interleave(array $games, int $max): array
    {
        $out = [];

        for ($round = 0; count($out) < $max; $round++) {
            $added = false;

            foreach ($games as $moments) {
                if (isset($moments[$round]) && count($out) < $max) {
                    $out[] = $moments[$round];
                    $added = true;
                }
            }

            if (! $added) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<list<array<string, string|int>>>
     */
    private function chessGames(int $nowMs): array
    {
        $games = [];

        foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Active)->whereNotNull('white_id')->whereNotNull('black_id')
            ->orderByDesc('id')->limit(self::CHESS_GAMES * 2)->get() as $game) {
            $white = SceneFacts::spoken($game->white->displayName());
            $black = SceneFacts::spoken($game->black->displayName());

            if ($white !== null && $black !== null && count($games) < self::CHESS_GAMES) {
                $games[] = self::safely(fn (): array => $this->chess->of($game, $white, $black, $nowMs));
            }
        }

        return $games;
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function boardGame(): array
    {
        $game = app(BoardScene::class)->liveGame();
        $white = SceneFacts::spoken($game?->white?->displayName());
        $black = SceneFacts::spoken($game?->black?->displayName());

        return $game === null || $white === null || $black === null ? [] : $this->board->of($game, $white, $black);
    }

    /**
     * @return list<list<array<string, string|int>>>
     */
    private function hyperMatches(int $nowMs): array
    {
        $games = [];

        foreach (array_slice(app(HyperScene::class)->entries($nowMs), 0, self::OTHERS) as $entry) {
            $match = HyperMatch::query()->with('seats.user')->find($entry['id']);

            if ($match !== null) {
                $games[] = self::safely(fn (): array => $this->hyper->of($match, $nowMs));
            }
        }

        return $games;
    }

    /**
     * Series under way: accepted, started, at least one game on the sheet.
     *
     * @return list<list<array<string, string|int>>>
     */
    private function series(): array
    {
        $games = [];

        foreach (SeriesMatch::query()->where('status', SeriesStatus::Accepted)->whereNotNull('live_games')->where('start_at', '<=', now())
            ->orderByDesc('start_at')->limit(self::OTHERS)->get() as $match) {
            $games[] = self::safely(fn (): array => SeriesMoments::of($match));
        }

        return $games;
    }

    /**
     * @return list<list<array<string, string|int>>>
     */
    private function pongResults(): array
    {
        $games = [];

        foreach (array_slice(app(PongScene::class)->entries(), 0, self::OTHERS) as $entry) {
            $match = PongMatch::query()->with(['left', 'right'])->find($entry['id']);
            $left = SceneFacts::spoken($match?->left?->displayName());
            $right = SceneFacts::spoken($match?->right?->displayName());

            if ($match !== null && $left !== null && $right !== null) {
                $games[] = self::safely(fn (): array => $this->pong->of($match, $left, $right));
            }
        }

        return $games;
    }

    /**
     * @return list<list<array<string, string|int>>>
     */
    private function tournaments(int $nowMs): array
    {
        $games = [];

        foreach (array_slice(app(TournamentLiveSlides::class)->all($nowMs), 0, self::OTHERS) as $frame) {
            $games[] = self::safely(fn (): array => TournamentMoments::fromFrame($frame));
        }

        return $games;
    }

    /**
     * The moments of one game, or none when reading them throws.
     *
     * @param  Closure(): list<array<string, string|int>>  $source
     * @return list<array<string, string|int>>
     */
    private static function safely(Closure $source): array
    {
        try {
            return $source();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The moments of a source's games, or none when the source throws.
     *
     * @param  Closure(): list<list<array<string, string|int>>>  $source
     * @return list<list<array<string, string|int>>>
     */
    private static function safelyAll(Closure $source): array
    {
        try {
            return $source();
        } catch (Throwable) {
            return [];
        }
    }
}
