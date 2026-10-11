<?php

namespace App\Support\Caster\GameMoments;

/**
 * The emotion a game moment carries, as a hint for the caster (the prompt
 * reads it from the facts, the pattern lines pick it up as a `{mood_*}`
 * slot). Each value is derived from the game's own state by a fixed rule
 * of the moment class that sets it, never from an engine evaluation.
 */
final class Mood
{
    /** Something is about to happen or just did: a check, a capture, a toppled bank, little time left. */
    public const TENSION = 'tension';

    /** A side that was clearly behind is level again or ahead. */
    public const COMEBACK = 'comeback';

    /** One side is far ahead. */
    public const DOMINANCE = 'dominance';

    /** Both sides are level or nearly so. */
    public const CLOSE = 'close';

    /** The lower-seeded side beat the higher-seeded one. */
    public const UPSET = 'upset';

    public const ALL = [self::TENSION, self::COMEBACK, self::DOMINANCE, self::CLOSE, self::UPSET];
}
