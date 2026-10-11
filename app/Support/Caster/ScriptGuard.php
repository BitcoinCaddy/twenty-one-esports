<?php

namespace App\Support\Caster;

/**
 * The spoken-text rules of a commentary script, on top of the league voice's
 * own checks (VoiceHygiene, VoiceGuard): one sentence per line, the number
 * of sentences the kind asks for, short sentences (spoken: at most
 * `max_words_per_sentence` words, one comma), and only the OmniVoice markers
 * VoiceStudio understands. A bracket direction like "(lacht)" makes the
 * model babble (measured 2026-10-11), so it is refused, as is any other
 * square bracket.
 */
final class ScriptGuard
{
    public const SENTENCE_COUNT = 'sentence_count';

    public const SENTENCE_LONG = 'sentence_long';

    public const COMMAS = 'commas';

    public const MARKER_UNKNOWN = 'marker_unknown';

    public const MARKER_COUNT = 'marker_count';

    public const STAGE_DIRECTION = 'stage_direction';

    public const STOCK_PHRASE = 'stock_phrase';

    public const OPENING_REPEAT = 'opening_repeat';

    /** The OmniVoice markers VoiceStudio turns into sound (plan, "Stimmprofile + Ausdruck"). */
    public const MARKERS = [
        'laughter', 'sigh', 'confirmation-en',
        'question-ah', 'question-oh', 'question-ei', 'question-yi', 'question-en',
        'surprise-ah', 'surprise-oh', 'surprise-wa', 'surprise-yo',
        'dissatisfaction-hnn',
    ];

    public const MAX_MARKERS = 2;

    /** Stock phrases of written or machine German (plan, "Alltagssprache"). */
    public const STOCK_PHRASES = [
        'darüber hinaus', 'es ist erwähnenswert', 'erwähnenswert', 'zweifellos', 'nicht zuletzt', 'im folgenden',
        'es bleibt abzuwarten', 'insgesamt lässt sich', 'zusammenfassend', 'in diesem sinne', 'meines erachtens',
        'diesbezüglich', 'hinsichtlich', 'bezüglich', 'spannendes event', 'ultimative', 'sei dabei',
    ];

    /**
     * @param  list<string>  $recentOpenings  the first words of the last scripts (normalised)
     * @return list<string>
     */
    public function check(string $text, int $minSentences, int $maxSentences, int $maxWords, array $recentOpenings = []): array
    {
        $violations = [];
        $lines = self::lines($text);

        if (count($lines) < $minSentences || count($lines) > $maxSentences) {
            $violations[] = self::SENTENCE_COUNT;
        }

        preg_match_all('/\[([^\]]*)\]/u', $text, $markers);
        foreach ($markers[1] as $marker) {
            if (! in_array($marker, self::MARKERS, true)) {
                $violations[] = self::MARKER_UNKNOWN;
                break;
            }
        }

        if (count($markers[0]) > self::MAX_MARKERS) {
            $violations[] = self::MARKER_COUNT;
        }

        if (preg_match('/[()]|\*[^*]+\*/u', $text) === 1) {
            $violations[] = self::STAGE_DIRECTION;
        }

        foreach ($lines as $line) {
            $spoken = self::withoutMarkers($line);

            if (count(preg_split('/\s+/u', trim($spoken), -1, PREG_SPLIT_NO_EMPTY) ?: []) > $maxWords) {
                $violations[] = self::SENTENCE_LONG;
            }

            if (substr_count($spoken, ',') > 1) {
                $violations[] = self::COMMAS;
            }
        }

        $lower = mb_strtolower($text);
        foreach (self::STOCK_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                $violations[] = self::STOCK_PHRASE;
                break;
            }
        }

        $opening = self::opening($text);
        if ($opening !== '' && in_array($opening, $recentOpenings, true)) {
            $violations[] = self::OPENING_REPEAT;
        }

        return array_values(array_unique($violations));
    }

    /**
     * The sentences of a script, one per non-empty line.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));
    }

    /** The text without its markers, as the fact guard and the word count see it. */
    public static function withoutMarkers(string $text): string
    {
        $pattern = '/\s*\[(?:'.implode('|', array_map(fn (string $marker): string => preg_quote($marker, '/'), self::MARKERS)).')\]\s*/u';

        return trim((string) preg_replace('/ {2,}/', ' ', (string) preg_replace($pattern, ' ', $text)));
    }

    /** The first four words of the script, normalised: two scripts may not start alike (spam gate). */
    public static function opening(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(self::withoutMarkers($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, 4));
    }

    /** Whether a sentence ends on an accent (! ? :) or carries a marker: a longer pause follows it. */
    public static function accented(string $sentence): bool
    {
        return preg_match('/[!?:]\s*$|\[[a-z-]+\]/u', trim($sentence)) === 1;
    }
}
