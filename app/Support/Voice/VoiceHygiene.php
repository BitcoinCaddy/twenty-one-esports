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
     * @return list<string>
     */
    public function check(string $text, string $language, array $recentTexts = [], ?string $canonWording = null, bool $cutOff = false, ?int $maxSentences = null): array
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

        if (preg_match('/^\s*(?:hier ist|hier kommt|here is|here\'s|klar[,!]|sure[,!]|okay[,!]|ok[,!]|(?:post|text|tweet|nachricht|antwort|answer|caption)\s*:)/iu', $text) === 1) {
            $violations[] = self::PREAMBLE;
        }

        if (preg_match('/\bals (?:ki|sprachmodell|assistent)\b|\bas an ai\b|\blanguage model\b|\bsprachmodell\b|\bi can\'?t\b|\bi cannot\b|\bich kann (?:das )?nicht\b|\bgerne!|\bnatürlich!|\bcertainly!|\bi\'m sorry\b|\bes tut mir leid\b/iu', $text) === 1) {
            $violations[] = self::META;
        }

        $prose = $canonWording === null ? $text : (string) preg_replace(VoiceGuard::wordingPattern($canonWording), ' ', $text);
        $prose = (string) preg_replace('~https?://\S+~u', ' ', $prose);

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

    /** Sentences of a text: runs that end with . ! ? or … (a last run without one counts too). */
    public static function sentences(string $text): int
    {
        $runs = preg_split('/[.!?…]+(?=\s|$)/u', $text) ?: [];

        return count(array_filter($runs, fn (string $run): bool => preg_match('/[\p{L}\p{N}]/u', $run) === 1));
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
