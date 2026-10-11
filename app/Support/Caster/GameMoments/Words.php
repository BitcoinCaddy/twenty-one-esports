<?php

namespace App\Support\Caster\GameMoments;

/**
 * Counts as the voice says them: "einen Bauern", "zwei Bauern", "13 Gebiete".
 * Up to twelve as a German number word, above that in digits (the number
 * is in the facts beside the phrase, so the fact guard knows it).
 */
final class Words
{
    private const NUMBERS = [2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs', 7 => 'sieben', 8 => 'acht', 9 => 'neun', 10 => 'zehn', 11 => 'elf', 12 => 'zwölf'];

    /**
     * `$count` with its noun: `$one` for one ("einen Bauern", "eine Figur"), the number word and `$plural` otherwise.
     */
    public static function count(int $count, string $one, string $plural): string
    {
        return $count === 1 ? $one : self::number($count).' '.$plural;
    }

    /** The number as a word up to twelve, else in digits. */
    public static function number(int $count): string
    {
        return self::NUMBERS[$count] ?? (string) $count;
    }

    /** A time gap in milliseconds, spoken: "0,42 Sekunden", "drei Sekunden" (whole seconds), "1,5 Sekunden". */
    public static function seconds(int $ms): string
    {
        if ($ms % 1000 === 0) {
            return self::count(intdiv($ms, 1000), 'eine Sekunde', 'Sekunden');
        }

        return rtrim(rtrim(number_format($ms / 1000, 2, ',', ''), '0'), ',').' Sekunden';
    }
}
