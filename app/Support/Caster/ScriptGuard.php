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
 *
 * Before the check, two deterministic repairs (P6 yield round, 2026-10-11:
 * the real Haiku drafts failed on commas, long sentences and the sentence
 * count in 5 of 5 scenes): splitSentences() puts every sentence on its own
 * line and cuts a long or comma-heavy one at a clause boundary,
 * trimSentences() drops sentences from the end beyond the maximum. Where no
 * safe cut exists the text stays as it is, and check() rejects it (fail
 * closed).
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

    /** Most commas a spoken sentence may carry. */
    public const MAX_COMMAS = 1;

    /** A part cut off a sentence needs at least this many spoken words, so a list item ("K.-o.") never stands alone. */
    private const MIN_PART_WORDS = 2;

    /** A bare conjunction (no comma before it) joins two clauses only with at least this many words on each side. */
    private const MIN_CLAUSE_WORDS = 3;

    /** Coordinating conjunctions: the clause after one stands on its own. "und" goes when it opens the new sentence. */
    private const CONJUNCTIONS = ['und', 'aber', 'denn', 'doch'];

    /**
     * Words that open a subordinate or relative clause: a comma before one (or a sentence that starts with
     * one) joins clauses that cannot stand alone, so no cut is made there. "der", "die", "das" … before a
     * capitalised word are articles and open a main clause ("…, die Bretter sind frei.").
     */
    private const SUBORDINATORS = [
        'der', 'die', 'das', 'dem', 'den', 'dessen', 'deren', 'welcher', 'welche', 'welches', 'weil', 'dass', 'wenn',
        'ob', 'obwohl', 'während', 'nachdem', 'bevor', 'damit', 'sodass', 'indem', 'wobei', 'falls', 'sobald',
        'solange', 'seit', 'seitdem', 'bis', 'als', 'wie', 'was', 'wer', 'wo', 'warum', 'wieso', 'um', 'ohne', 'statt',
        'anstatt', 'sondern', 'zu',
    ];

    private const ARTICLES = ['der', 'die', 'das', 'dem', 'den'];

    /** Words a clause after a bare conjunction may open with ("… und wir warten"); a noun list ("Schach und Pong") does not. */
    private const CLAUSE_STARTERS = ['ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr', 'man', 'dann', 'jetzt', 'da', 'hier', 'so', 'gleich', 'heute'];

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

            if (substr_count($spoken, ',') > self::MAX_COMMAS) {
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
     * The script with one sentence per line, each cut at clause boundaries
     * until it has at most `$maxWords` spoken words and one comma: first at a
     * colon, then at a comma before "und", "aber", "denn" or "doch", then at
     * any other comma that does not open a subordinate or relative clause,
     * last at a bare conjunction between two clauses. The part before a cut
     * ends with a full stop (before its trailing markers), the part after it
     * starts with a capital, a leading "und" dropped; a marker stays beside
     * the words it stood next to. A sentence without such a cut is left as it
     * is; check() rejects it then.
     */
    public static function splitSentences(string $text, int $maxWords): string
    {
        $sentences = [];

        foreach (self::lines($text) as $line) {
            $runs = preg_split('/(?<=[.!?…])(?<!\d\.)\s+(?=(?:\[[a-z-]+\]\s*)*[\p{Lu}\p{N}„"»])/u', $line) ?: [$line];

            foreach ($runs as $run) {
                array_push($sentences, ...self::splitSentence(trim($run), $maxWords));
            }
        }

        return implode("\n", array_values(array_filter($sentences, fn (string $sentence): bool => $sentence !== '')));
    }

    /**
     * The script shortened from its end to `$maxSentences` lines. Unchanged
     * when a dropped line would take one of `$mustContain` with it; check()
     * rejects it then (fail closed).
     *
     * @param  list<string>  $mustContain
     */
    public static function trimSentences(string $text, int $maxSentences, array $mustContain = []): string
    {
        $lines = self::lines($text);

        if (count($lines) <= $maxSentences) {
            return $text;
        }

        $kept = implode("\n", array_slice($lines, 0, max(0, $maxSentences)));

        foreach ($mustContain as $required) {
            if ($required !== '' && ! str_contains($kept, $required)) {
                return $text;
            }
        }

        return $kept;
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

    /**
     * One sentence cut at the most balanced safe boundary of the best kind, then each part again.
     *
     * @return list<string>
     */
    private static function splitSentence(string $sentence, int $maxWords): array
    {
        if (self::fits($sentence, $maxWords)) {
            return [$sentence];
        }

        $kinds = [
            '/\s*[:;]\s+/u',
            '/,\s+(?=(?:\[[a-z-]+\]\s*)*(?:und|aber|denn|doch)\s)/iu',
            '/(?<!\d),(?!\d)\s*/u',
            '/(?<![,\s])\s+(?=(?:und|aber|denn|doch)\s)/iu',
        ];

        foreach ($kinds as $index => $pattern) {
            preg_match_all($pattern, $sentence, $matches, PREG_OFFSET_CAPTURE);
            $best = null;

            foreach ($matches[0] as [$separator, $offset]) {
                $parts = self::cut($sentence, (int) $offset, strlen($separator), $index === 3);

                if ($parts === null) {
                    continue;
                }

                $balance = abs(self::wordCount($parts[0]) - self::wordCount($parts[1]));
                if ($best === null || $balance < $best[0]) {
                    $best = [$balance, $parts];
                }
            }

            if ($best !== null) {
                return [...self::splitSentence($best[1][0], $maxWords), ...self::splitSentence($best[1][1], $maxWords)];
            }
        }

        return [$sentence];
    }

    /**
     * The two sentences a cut at `$offset` makes, or null when the cut is not safe.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function cut(string $sentence, int $offset, int $length, bool $bareConjunction): ?array
    {
        $left = rtrim(substr($sentence, 0, $offset), " \t,;:");
        $right = ltrim(substr($sentence, $offset + $length));

        preg_match('/^((?:\[[a-z-]+\]\s*)*)(.*)$/su', $right, $match);
        [$markers, $rest] = [$match[1] ?? '', $match[2] ?? $right];
        $restWords = preg_split('/\s+/u', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = mb_strtolower(trim($restWords[0] ?? '', '„"»,.'));
        $leftFirst = mb_strtolower(trim(self::spokenWords($left)[0] ?? '', '„"»,.'));

        if (in_array($leftFirst, self::SUBORDINATORS, true) && ! in_array($leftFirst, self::ARTICLES, true)) {
            return null;
        }

        // A cut right after a subordinate clause the left part opened ("Die Partie, die gerade läuft, | ist …") leaves its main clause headless.
        $segments = preg_split('/,/u', $left) ?: [];
        if (count($segments) > 1 && self::opensSubordinate(trim((string) end($segments)))) {
            return null;
        }

        if (in_array($first, self::CONJUNCTIONS, true)) {
            if ($bareConjunction && ! in_array(mb_strtolower($restWords[1] ?? ''), self::CLAUSE_STARTERS, true)) {
                return null;
            }

            if ($first === 'und') {
                $rest = ltrim(mb_substr(ltrim($rest), 3));
            }
        } elseif (self::opensSubordinate($rest)) {
            return null;
        }

        $minimum = $bareConjunction ? self::MIN_CLAUSE_WORDS : self::MIN_PART_WORDS;
        if (self::wordCount($left) < $minimum || self::wordCount($rest) < $minimum) {
            return null;
        }

        // The full stop goes before the markers that close the left part: "Klar. [laughter]".
        if (preg_match('/[.!?…]$/u', self::withoutMarkers($left)) !== 1) {
            $left = (string) preg_replace('/^(.*?)((?:\s*\[[a-z-]+\])*)$/su', '$1.$2', $left);
        }

        return [$left, trim($markers.mb_ucfirst($rest))];
    }

    /** Whether a clause opens with a subordinator or relative pronoun (an article before a capitalised noun does not count). */
    private static function opensSubordinate(string $clause): bool
    {
        $words = self::spokenWords($clause);
        $first = mb_strtolower(trim($words[0] ?? '', '„"»,.'));

        return in_array($first, self::SUBORDINATORS, true)
            && ! (in_array($first, self::ARTICLES, true) && preg_match('/^\p{Lu}/u', $words[1] ?? '') === 1);
    }

    private static function fits(string $sentence, int $maxWords): bool
    {
        return self::wordCount($sentence) <= $maxWords && substr_count(self::withoutMarkers($sentence), ',') <= self::MAX_COMMAS;
    }

    private static function wordCount(string $text): int
    {
        return count(self::spokenWords($text));
    }

    /**
     * @return list<string>
     */
    private static function spokenWords(string $text): array
    {
        return preg_split('/\s+/u', trim(self::withoutMarkers($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Whether a sentence ends on an accent (! ? :) or carries a marker: a longer pause follows it. */
    public static function accented(string $sentence): bool
    {
        return preg_match('/[!?:]\s*$|\[[a-z-]+\]/u', trim($sentence)) === 1;
    }
}
