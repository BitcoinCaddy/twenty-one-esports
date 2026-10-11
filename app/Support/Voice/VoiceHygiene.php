<?php

namespace App\Support\Voice;

/**
 * Text hygiene of a model answer, before the fact guard (VoiceGuard).
 * clean() repairs what is harmless and mechanical (outer whitespace, quotes
 * around the whole answer, doubled spaces, stray line breaks, straight
 * quotes in a German post); check() names the defects that send the caller
 * back to its template: leftover placeholders, Markdown and preambles,
 * assistant talk, the wrong language, a cut-off end, repeated words or
 * clauses, unbalanced quotes or brackets, broken Unicode and a near copy of
 * one of the channel's last posts.
 *
 * Everyday language (plan "KI-Stimme der Liga", P2, user 2026-10-11: "Ein
 * Mensch redet im Alltag nicht in Schachtelsätzen, so wie du als AI"): a
 * sentence has at most `esports.voice.style.max_words` words and
 * `max_commas` commas (a list of short items is exempt), and no phrase
 * of the AI or officialese register (AI_PHRASES, both languages). The
 * stage directions of the spoken commentary ([laughter], "(lacht)") are
 * for the TV voice (P6) only, never in a text post.
 */
final class VoiceHygiene
{
    public const PLACEHOLDER = 'placeholder';

    public const MARKDOWN = 'markdown';

    public const PREAMBLE = 'preamble';

    public const META = 'meta';

    public const WRONG_LANGUAGE = 'wrong_language';

    public const TRUNCATED = 'truncated';

    public const REPEATED = 'repeated';

    public const UNBALANCED = 'unbalanced';

    public const BROKEN_UNICODE = 'broken_unicode';

    public const NEAR_COPY = 'near_copy';

    public const TOO_MANY_SENTENCES = 'too_many_sentences';

    public const LONG_SENTENCE = 'long_sentence';

    public const COMMAS = 'commas';

    public const AI_PHRASE = 'ai_phrase';

    public const TTS_MARKER = 'tts_marker';

    public const OPENER_REPEAT = 'opener_repeat';

    public const SIGN_OFF = 'sign_off';

    /** A text may not open with the first word of any of the channel's last this many posts. */
    public const OPENER_POSTS = 5;

    /**
     * Phrases of the AI and officialese register (user 2026-10-11: „darüber hinaus“, „es ist erwähnenswert“,
     * „spannend“-Floskeln), matched as whole words in any case, German and English alike.
     */
    public const AI_PHRASES = [
        'darüber hinaus', 'es ist erwähnenswert', 'erwähnenswert', 'es sei erwähnt', 'spannend', 'spannende', 'spannenden',
        'spannender', 'spannendes', 'zweifellos', 'in der tat', 'nicht nur', 'zusammenfassend', 'abschließend',
        'im folgenden', 'eintauchen', 'tauche ein', 'taucht ein', 'ein muss', 'unvergesslich', 'unvergessliche',
        'atemberaubend', 'atemberaubende', 'hinsichtlich', 'bezüglich', 'diesbezüglich', 'seitens', 'im rahmen',
        'es lohnt sich', 'ein wahres', 'eine wahre', 'facettenreich', 'nahtlos', 'bahnbrechend', 'ultimativ',
        'ultimative', 'ultimativen', 'verpasse nicht', 'verpasst nicht', 'sei dabei', 'seid dabei',
        'delve', 'delves', 'delving', 'it\'s worth noting', 'it is worth noting', 'worth mentioning', 'furthermore',
        'moreover', 'in conclusion', 'tapestry', 'testament', 'showcase', 'showcases', 'showcasing', 'unleash',
        'elevate', 'game-changer', 'game changer', 'thrilling', 'exciting', 'embark', 'realm', 'vibrant', 'seamless',
        'seamlessly', 'boasts', 'nestled', 'captivating', 'don\'t miss', 'do not miss', 'ultimate', 'not only',
        'additionally', 'notably', 'remarkable', 'look no further', 'buckle up', 'dive into', 'unparalleled',
        'breathtaking', 'nail-biting', 'epic showdown',
    ];

    /** Words that open a subordinate clause after a comma: such a comma joins clauses, it does not list items. */
    private const CLAUSE_OPENERS = [
        'der', 'die', 'das', 'dem', 'den', 'dessen', 'deren', 'weil', 'dass', 'wenn', 'obwohl', 'während', 'nachdem',
        'bevor', 'damit', 'sodass', 'welcher', 'welche', 'welches', 'wobei', 'indem', 'sondern', 'aber', 'denn',
        'which', 'who', 'whom', 'whose', 'that', 'because', 'when', 'while', 'although', 'though', 'since', 'whereas',
        'but', 'so',
    ];

    /** A comma-separated item of at most this many words can be part of a list. */
    private const LIST_ITEM_WORDS = 4;

    /** A post this similar (percent, similar_text) to a recent one adds no variety. */
    public const NEAR_COPY_PERCENT = 80;

    private const GERMAN_WORDS = ['der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'auf', 'für', 'ein', 'eine', 'den', 'dem', 'des', 'im', 'zu', 'von', 'sich', 'noch', 'wer', 'wie', 'was', 'heute', 'gegen', 'gewinnt', 'hat', 'auch', 'aber', 'jetzt', 'schon', 'bei', 'nach'];

    private const ENGLISH_WORDS = ['the', 'and', 'is', 'not', 'with', 'on', 'for', 'a', 'an', 'to', 'of', 'in', 'this', 'that', 'who', 'what', 'today', 'against', 'wins', 'has', 'also', 'but', 'now', 'already', 'at', 'after', 'just', 'it', 'you', 'are'];

    /** Repairs the harmless defects; the result goes through check(). */
    public function clean(string $text, bool $multiline, string $language): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        // Quotes around the whole answer, with none inside.
        if (preg_match('/^[„“”"»«\'](.*)[“”"«»\']$/su', $text, $match) === 1 && preg_match('/[„“”"»«]/u', $match[1]) !== 1) {
            $text = trim($match[1]);
        }

        if ($multiline) {
            $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
            $text = (string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
        } else {
            $text = (string) preg_replace('/\s*\n\s*/u', ' ', $text);
        }

        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);

        if ($language === 'de') {
            $text = (string) preg_replace('/"([^"\n]+)"/u', '„$1“', $text);
            $text = (string) preg_replace('/“([^“”„\n]+)”/u', '„$1“', $text);
        }

        return trim($text);
    }

    /**
     * The defects of an (already cleaned) text.
     *
     * @param  list<string>  $recentTexts  the channel's last posts
     * @param  string|null  $canonWording  the wording of the canon entry the text quotes (exempt from language and repetition checks)
     * @param  list<string>  $factStrings  the facts' strings: one word each for the everyday-language rules (a date's commas are no clause)
     * @return list<string>
     */
    public function check(string $text, string $language, array $recentTexts = [], ?string $canonWording = null, bool $cutOff = false, ?int $maxSentences = null, array $factStrings = []): array
    {
        $violations = [];

        if (! mb_check_encoding($text, 'UTF-8') || preg_match('/\x{FFFD}|[\x{200B}\x{200C}\x{2060}\x{FEFF}]|(?<!\p{Extended_Pictographic}|\x{FE0F})\x{200D}|\x{200D}(?!\p{Extended_Pictographic})/u', $text) === 1) {
            // A text that is not valid UTF-8 cannot be read further.
            return [self::BROKEN_UNICODE];
        }

        if (preg_match('/\{[^}]*\}|\[\[|\]\]|<[^>\s][^>]*>|\bTODO\b|\bPLACEHOLDER\b|\bXXX\b|(?<![\p{L}\p{N}\/]):[a-z][a-z_]+\b/u', $text) === 1) {
            $violations[] = self::PLACEHOLDER;
        }

        if (preg_match('/\*\*|__|`|^\s*(?:[-*•]|\d+\.)\s/mu', $text) === 1) {
            $violations[] = self::MARKDOWN;
        }

        if (preg_match('/^\s*(?:hier ist(?!\s+twenty\s+one\b)|hier kommt|here is|here\'s|klar[,!]|sure[,!]|okay[,!]|ok[,!]|(?:post|text|tweet|nachricht|antwort|answer|caption)\s*:)/iu', $text) === 1) {
            $violations[] = self::PREAMBLE;
        }

        if (preg_match('/\bals (?:ki|sprachmodell|assistent)\b|\bas an ai\b|\blanguage model\b|\bsprachmodell\b|\bi can\'?t\b|\bi cannot\b|\bich kann (?:das )?nicht\b|\bgerne!|\bnatürlich!|\bcertainly!|\bi\'m sorry\b|\bes tut mir leid\b/iu', $text) === 1) {
            $violations[] = self::META;
        }

        if (preg_match('/\[(?:laughter|sigh|confirmation-[a-z]+|question-[a-z]+|surprise-[a-z]+|dissatisfaction-[a-z]+)\]|\((?:lacht|lachen|seufzt|kichert|grinst|räuspert sich|laughs|laughing|sighs|giggles|grins)\)/iu', $text) === 1) {
            $violations[] = self::TTS_MARKER;
        }

        $prose = $canonWording === null ? $text : (string) preg_replace(VoiceGuard::wordingPattern($canonWording), ' ', $text);
        $prose = (string) preg_replace('~https?://\S+~u', ' ', $prose);

        array_push($violations, ...$this->style($prose, $factStrings));

        // No sign-off: a post is no letter ("… Stack Sats and stay bullish. Ciao.", P2 eval run).
        if (preg_match('/(?<![\p{L}])(?:ciao|tschüss|tschau|bye|cheers|bis dann|bis bald)[\s.!…]*$/iu', trim($prose)) === 1) {
            $violations[] = self::SIGN_OFF;
        }

        if ($this->isWrongLanguage($prose, $language)) {
            $violations[] = self::WRONG_LANGUAGE;
        }

        if ($maxSentences !== null && self::sentences($prose) > $maxSentences) {
            $violations[] = self::TOO_MANY_SENTENCES;
        }

        if ($cutOff || $this->looksCutOff($text)) {
            $violations[] = self::TRUNCATED;
        }

        if ($this->repeats($prose)) {
            $violations[] = self::REPEATED;
        }

        if (! $this->balanced($text)) {
            $violations[] = self::UNBALANCED;
        }

        // The opener (its first word) of the channel's last OPENER_POSTS posts is not repeated: "Hey! … Klar." every time is a tic.
        $opener = explode(' ', VoiceGuard::normalize($text))[0];
        foreach (array_slice($recentTexts, 0, self::OPENER_POSTS) as $recent) {
            if ($opener !== '' && explode(' ', VoiceGuard::normalize($recent))[0] === $opener) {
                $violations[] = self::OPENER_REPEAT;
                break;
            }
        }

        $normalized = VoiceGuard::normalize($text);
        foreach ($recentTexts as $recent) {
            $recentNormalized = VoiceGuard::normalize($recent);
            similar_text($normalized, $recentNormalized, $percent);

            if ($normalized === $recentNormalized || $percent >= self::NEAR_COPY_PERCENT) {
                $violations[] = self::NEAR_COPY;
                break;
            }
        }

        return $violations;
    }

    /**
     * The everyday-language rules a text (without its canon wording and links) breaks. Each fact string
     * in it (a tournament's name, a start such as "Sat, 3 Oct 2026, 9:00 PM CEST") counts as one word.
     *
     * @param  list<string>  $factStrings
     * @return list<string>
     */
    public function style(string $prose, array $factStrings = []): array
    {
        $violations = [];
        usort($factStrings, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($factStrings as $fact) {
            if (mb_strlen($fact) >= 2) {
                $prose = str_replace($fact, 'X', $prose);
            }
        }
        $maxWords = (int) config('esports.voice.style.max_words', 14);
        $maxCommas = (int) config('esports.voice.style.max_commas', 1);

        foreach (self::sentenceList($prose) as $sentence) {
            if (count(VoiceGuard::words($sentence)) > $maxWords) {
                $violations[] = self::LONG_SENTENCE;
            }

            // A comma between digits is a decimal mark ("1,5"), not a clause.
            $commas = preg_match_all('/,(?!\d)|(?<!\d),/u', $sentence);

            if ($commas > $maxCommas && ! self::isList($sentence)) {
                $violations[] = self::COMMAS;
            }
        }

        $lower = mb_strtolower($prose);

        foreach (self::AI_PHRASES as $phrase) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $lower) === 1) {
                $violations[] = self::AI_PHRASE;
                break;
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * A text with more sentences than allowed, shortened from its end (P2
     * yield round: the overflow is mostly a trailing "Wer will?" or "Klar."):
     * the last sentence goes while there are too many and every string of
     * `$mustContain` stays in it. Unchanged when that is not possible; the
     * check rejects it then (fail closed). The shortened text runs through
     * every check again, the fact check included.
     *
     * @param  list<string>  $mustContain
     */
    public static function trimSentences(string $text, ?int $maxSentences, array $mustContain = []): string
    {
        if ($maxSentences === null || self::sentences($text) <= $maxSentences) {
            return $text;
        }

        $runs = preg_split('/(?<=[.!?…])\s+/u', trim($text)) ?: [];

        while (count($runs) > 1 && self::sentences(implode(' ', $runs)) > $maxSentences) {
            $shorter = array_slice($runs, 0, -1);
            $candidate = implode(' ', $shorter);

            foreach ($mustContain as $required) {
                if ($required !== '' && ! str_contains($candidate, $required)) {
                    return $text;
                }
            }

            $runs = $shorter;
        }

        return self::sentences(implode(' ', $runs)) <= $maxSentences ? implode(' ', $runs) : $text;
    }

    /**
     * The sentences of a text, split after . ! ? or … (a last run without one counts too).
     *
     * @return list<string>
     */
    public static function sentenceList(string $text): array
    {
        $runs = preg_split('/(?<=[.!?…])\s+|\n+/u', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $runs), fn (string $run): bool => preg_match('/[\p{L}\p{N}]/u', $run) === 1));
    }

    /** Whether the commas of a sentence list short items ("Schach, Pong, Blockli und Mühle") instead of joining clauses. */
    private static function isList(string $sentence): bool
    {
        $items = preg_split('/,(?!\d)/u', $sentence) ?: [];

        foreach ($items as $index => $item) {
            $words = VoiceGuard::words($item);

            if (count($words) > self::LIST_ITEM_WORDS) {
                return false;
            }

            if ($index > 0 && $words !== [] && in_array(mb_strtolower($words[0]), self::CLAUSE_OPENERS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sentences of a text: runs that end with . ! ? or … (a last run without
     * one counts too). A one-word exclamation ("Hey!", "Boah.", "3:1!") is
     * how people talk (P2, everyday language) and does not count.
     */
    public static function sentences(string $text): int
    {
        $runs = preg_split('/[.!?…]+(?=\s|$)/u', $text) ?: [];

        return count(array_filter($runs, fn (string $run): bool => count(VoiceGuard::words($run)) >= 2));
    }

    private function isWrongLanguage(string $prose, string $language): bool
    {
        $words = array_count_values(explode(' ', VoiceGuard::normalize($prose)));
        $german = array_sum(array_intersect_key($words, array_flip(self::GERMAN_WORDS)));
        $english = array_sum(array_intersect_key($words, array_flip(self::ENGLISH_WORDS)));

        return $language === 'en'
            ? $german >= 3 && $german > $english
            : $english >= 3 && $english > $german;
    }

    /** A post ends with a sentence mark, a closing quote or bracket, an emoji or a link. */
    private function looksCutOff(string $text): bool
    {
        if (preg_match('~https?://\S+$~u', $text) === 1) {
            return false;
        }

        $graphemes = preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $last = (string) end($graphemes);

        return preg_match('/^(?:[.!?…)\]“”"»«\']|.*\p{Extended_Pictographic}.*)$/u', $last) !== 1;
    }

    /** The same word twice in a row, or the same clause twice. */
    private function repeats(string $prose): bool
    {
        if (preg_match('/(?<![\p{L}\p{N}])([\p{L}]{2,})\s+\1(?![\p{L}\p{N}])/iu', $prose) === 1) {
            return true;
        }

        $clauses = array_values(array_filter(array_map(
            fn (string $clause): string => VoiceGuard::normalize($clause),
            preg_split('/[.!?;,:–—]+/u', $prose) ?: [],
        ), fn (string $clause): bool => substr_count($clause, ' ') >= 1));

        return count($clauses) !== count(array_unique($clauses));
    }

    private function balanced(string $text): bool
    {
        $count = fn (string $char): int => mb_substr_count($text, $char);

        if ($count('(') !== $count(')') || $count('[') !== $count(']') || $count('»') !== $count('«') || $count('"') % 2 !== 0) {
            return false;
        }

        // German quotes open with „ and close with “; English ones open with “ and close with ”.
        // So every “ is either a German close or an English open.
        return $count('“') === $count('„') + $count('”');
    }
}
