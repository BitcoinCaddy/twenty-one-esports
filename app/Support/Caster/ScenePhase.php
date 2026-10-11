<?php

namespace App\Support\Caster;

use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;

/**
 * The programme phase a rotation slot belongs to, in the vocabulary of the
 * cast research (cast-research/pacing.md, "Energiekurve"): a script is
 * written for a phase and plays on a slot of the same phase, so the spoken
 * commentary always fits the picture.
 *
 * Night (00:00–08:00 Europe/Berlin) replaces the calm phases (break,
 * pre-show): quiet explainers, no names.
 */
final class ScenePhase
{
    public const LIVE_MATCH = 'live_match';

    public const AFTER_RESULT = 'after_result';

    public const PRE_SHOW = 'pre_show';

    public const BREAK = 'break';

    public const NIGHT = 'night';

    public const ALL = [self::LIVE_MATCH, self::AFTER_RESULT, self::PRE_SHOW, self::BREAK, self::NIGHT];

    /** Phases whose clips may stand in for one another: none of them speaks about a particular match. */
    public const CALM = [self::BREAK, self::PRE_SHOW, self::NIGHT];

    /** The canon moment (App\Support\Voice\VoiceCanon::MOMENTS) whose entries suit the phase. */
    public const MOMENTS = [
        self::LIVE_MATCH => 'close_game',
        self::AFTER_RESULT => 'win',
        self::PRE_SHOW => 'signup_open',
        self::BREAK => 'general',
        self::NIGHT => 'night_pause',
    ];

    /**
     * @param  array{kind: string, scene: string|null}  $slot  RotationPlanner::at()
     */
    public static function forSlot(array $slot, CarbonImmutable $now): string
    {
        $scene = (string) $slot['scene'];

        $phase = match ($slot['kind']) {
            RotationPlanner::MATCH, RotationPlanner::GALLERY, RotationPlanner::HYPER => self::LIVE_MATCH,
            RotationPlanner::PONG => self::AFTER_RESULT,
            RotationPlanner::TOURNAMENT => self::tournamentPhase($scene),
            RotationPlanner::TEASER => $scene === 'e1' ? self::AFTER_RESULT : self::BREAK,
            default => self::BREAK,
        };

        return in_array($phase, [self::BREAK, self::PRE_SHOW], true) && self::isNight($now) ? self::NIGHT : $phase;
    }

    public static function isNight(CarbonImmutable $now): bool
    {
        return (int) $now->setTimezone('Europe/Berlin')->format('G') < 8;
    }

    /** tv1..tv4 (a running tournament) and the live bracket and standings are live; the champion slides follow a result. */
    private static function tournamentPhase(string $scene): string
    {
        if (str_starts_with($scene, 'tv') || preg_match('/^t[abc][45]$/', $scene) === 1) {
            return self::LIVE_MATCH;
        }

        return $scene === RotationPlanner::CHAMPION_SCENE || preg_match('/^t[abc]6$/', $scene) === 1 ? self::AFTER_RESULT : self::PRE_SHOW;
    }
}
