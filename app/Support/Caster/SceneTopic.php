<?php

namespace App\Support\Caster;

use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;

/**
 * What a rotation slide shows, as one topic of the commentary (user
 * 2026-10-11: "er soll natürlich die Slides und deren Daten kommentieren"):
 * a scene script is written for a topic with the data that topic's slides
 * render (SceneFacts::forTopic()), and the stream prefers a clip of the
 * topic on air (ClipSpool::next()). The phase of a topic is the phase its
 * slides play in, so a clip never leaves its phase.
 */
final class SceneTopic
{
    /** Chess, board, Hyperbitcoinization games on now (match, gallery, d5, h1). */
    public const LIVE_GAMES = 'live_games';

    /** A tournament past its sign-up: how it runs, the live bracket, who is still standing (t?3..t?5, tv1..tv4). */
    public const LIVE_TOURNAMENT = 'live_tournament';

    /** Results of the last day: the latest win, a mined block, a Pong result, a champion (e1, e5, p1, t?6, tx6). */
    public const RESULTS = 'results';

    /** Tournaments open for sign-up: hero, bracket preview, the next one, pots, cups and prizes (t?1, t?2, t?7, d1, d2, e4). */
    public const TOURNAMENTS = 'tournaments';

    /** The ladders and their climbers, the strongest, rank-ups, streaks (a3, b3, c3, b5, e2, e6, e7, e8). */
    public const LADDERS = 'ladders';

    /** The clan spotlight (b4). */
    public const CLANS = 'clans';

    /** The mempool of every game: what runs and what just finished (m1). */
    public const MEMPOOL = 'mempool';

    /** Trackmania's week (g1..g4). */
    public const TMNF = 'tmnf';

    /** Blockfill's week (f1..f5). */
    public const BLOCKFILL = 'blockfill';

    /** The league as a whole: join, zaps, scan, invites, Nostr, the spotlight game, sign-ups, payouts. */
    public const LEAGUE = 'league';

    public const ALL = [self::LIVE_GAMES, self::LIVE_TOURNAMENT, self::RESULTS, self::TOURNAMENTS, self::LADDERS, self::CLANS, self::MEMPOOL, self::TMNF, self::BLOCKFILL, self::LEAGUE];

    /** Topics whose facts name no player: the only ones at night (ScenePhase: "keine Namen"). */
    public const NIGHT = [self::LEAGUE, self::TOURNAMENTS];

    /**
     * What the slide is, in the words of the facts (the model and the fact check read it).
     */
    public const SLIDES = [
        self::LIVE_GAMES => 'Live-Partien der Liga',
        self::LIVE_TOURNAMENT => 'Laufendes oder gerade beendetes Turnier',
        self::RESULTS => 'Ergebnisse der letzten 24 Stunden',
        self::TOURNAMENTS => 'Turniere mit offener Anmeldung',
        self::LADDERS => 'Ladder-Tabellen',
        self::CLANS => 'Clan im Blick',
        self::MEMPOOL => 'Mempool: laufende und fertige Spiele',
        self::TMNF => 'Trackmania: Strecke der Woche',
        self::BLOCKFILL => 'Blockfill: Woche und Bestenliste',
        self::LEAGUE => 'Die Liga in Zahlen',
    ];

    /**
     * The topic of a rotation slot.
     *
     * @param  array{kind: string, scene: string|null}  $slot  RotationPlanner::at()
     */
    public static function forSlot(array $slot): string
    {
        $scene = (string) $slot['scene'];

        return match (true) {
            in_array($slot['kind'], [RotationPlanner::MATCH, RotationPlanner::GALLERY, RotationPlanner::HYPER, RotationPlanner::BOARD], true) => self::LIVE_GAMES,
            $slot['kind'] === RotationPlanner::PONG => self::RESULTS,
            $slot['kind'] === RotationPlanner::BLOCKFILL => self::BLOCKFILL,
            $slot['kind'] === RotationPlanner::TOURNAMENT => self::tournamentTopic($scene),
            default => self::forScene($scene),
        };
    }

    /** The topic of a teaser or loop scene id. */
    public static function forScene(string $scene): string
    {
        return match (true) {
            in_array($scene, ['a1', 'a2', 'b1', 'b2', 'c1', 'c2', 'd5', 'h1'], true) => self::LIVE_GAMES,
            in_array($scene, ['e1', 'e5', 'p1'], true) => self::RESULTS,
            in_array($scene, ['d1', 'd2', 'e4'], true) => self::TOURNAMENTS,
            in_array($scene, ['a3', 'b3', 'c3', 'b5', 'e2', 'e6', 'e7', 'e8'], true) => self::LADDERS,
            $scene === 'b4' => self::CLANS,
            $scene === 'm1' => self::MEMPOOL,
            str_starts_with($scene, 'g') => self::TMNF,
            str_starts_with($scene, 'f') => self::BLOCKFILL,
            str_starts_with($scene, 't') => self::tournamentTopic($scene),
            default => self::LEAGUE,
        };
    }

    /** The phase a topic's slides play in (ScenePhase::forSlot()); calm topics turn to night at night. */
    public static function phase(string $topic, CarbonImmutable $now): string
    {
        $phase = match ($topic) {
            self::LIVE_GAMES, self::LIVE_TOURNAMENT => ScenePhase::LIVE_MATCH,
            self::RESULTS => ScenePhase::AFTER_RESULT,
            self::TOURNAMENTS => ScenePhase::PRE_SHOW,
            default => ScenePhase::BREAK,
        };

        return in_array($phase, ScenePhase::CALM, true) && ScenePhase::isNight($now) ? ScenePhase::NIGHT : $phase;
    }

    /** Hero, bracket preview and the next one are sign-up slides; the champion is a result; the rest is a tournament under way. */
    private static function tournamentTopic(string $scene): string
    {
        if ($scene === RotationPlanner::CHAMPION_SCENE || preg_match('/^t[abc]6$/', $scene) === 1) {
            return self::RESULTS;
        }

        return preg_match('/^t[abc][127]$/', $scene) === 1 ? self::TOURNAMENTS : self::LIVE_TOURNAMENT;
    }
}
