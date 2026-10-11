<?php

namespace App\Support\Voice;

/**
 * The deterministic check every model text passes before it may go out
 * (plan "KI-Stimme der Liga": the model is never the only judge). check()
 * returns the rules a text breaks; an empty list means it may be posted,
 * anything else sends the caller back to its template.
 *
 * Facts first: the text minus its links, minus the wording of its one canon
 * entry and minus every fact string must not hold a digit, a handle-like
 * word or a capitalised word the league's own vocabulary (canon, example
 * posts, style guide) does not know. That is how an invented number or name
 * is caught without knowing in advance which names exist. The direction is
 * deliberate: a new German noun the vocabulary lacks costs a template post,
 * never a wrong fact. The vocabulary also holds the English of the league's
 * own interface (the source strings of lang/de.json), so an English post's
 * capitalised sentence openers are known words.
 *
 * English posts (plan "KI-Stimme der Liga", P2): only canon entries marked
 * `en_ok` may be used, and only as a sense translation: never in quotation
 * marks, never with the name of the person whose line it is (that would be
 * a made-up quote), unless the original wording itself stands in the text.
 *
 * @phpstan-import-type CanonEntry from VoiceCanon
 */
final class VoiceGuard
{
    public const EMPTY = 'empty';

    public const TOO_LONG = 'too_long';

    public const HASHTAG = 'hashtag';

    public const URL = 'url';

    public const NUMBER = 'number';

    public const NAME = 'name';

    public const PERSON = 'person';

    public const QUOTE = 'quote';

    public const ATTRIBUTION = 'attribution';

    public const EMOJI = 'emoji';

    public const BLOCKLIST = 'blocklist';

    public const ADDRESS = 'address';

    public const CANON_UNKNOWN = 'canon_unknown';

    public const CANON_MISQUOTED = 'canon_misquoted';

    public const CANON_SECOND = 'canon_second';

    public const CANON_WITHOUT_ID = 'canon_without_id';

    public const CANON_NOT_ENGLISH = 'canon_not_english';

    public const CANON_UNTRANSLATED = 'canon_untranslated';

    public const MISSING_FACT = 'missing_fact';

    public const UNSUPPORTED_CLAIM = 'unsupported_claim';

    /** Most emoji one text may carry. */
    public const MAX_EMOJI = 2;

    /** A canon wording counts as recognisable in a text from this many words on. */
    private const DETECTABLE_WORDS = 3;

    /** This many words of a canon wording in a row, without the rest, are a cut or altered quote. */
    private const QUOTED_RUN = 4;

    /** @var array<string, true>|null */
    private ?array $vocabulary = null;

    /** @var array<string, true>|null */
    private ?array $english = null;

    /** @var array<string, string>|null canon ID => wording pattern, for the detectable wordings */
    private ?array $detectable = null;

    /**
     * @param  list<string>  $blocklist  words that never go out (insults, fees, wording the project rules forbid)
     * @param  list<string>  $persons  real people of the community: named only as the speaker of their own canon entry
     */
    public function __construct(
        private readonly VoiceCanon $canon,
        private readonly array $blocklist = [],
        private readonly array $persons = [],
        private readonly string $styleGuidePath = '',
        private readonly string $interfacePath = '',
    ) {}

    /**
     * The rules the text breaks (empty: it may go out).
     *
     * @param  array<array-key, mixed>  $facts
     * @param  list<string>  $mustContain  fact strings the text has to carry word for word (a tournament note names its start)
     * @return list<string>
     */
    public function check(string $text, array $facts, int $maxChars, ?string $canonId, string $language = 'de', array $mustContain = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return [self::EMPTY];
        }

        $violations = [];
        $factStrings = self::factStrings($facts);
        $haystack = mb_strtolower(implode("\n", $factStrings));

        if (mb_strlen($text) > $maxChars) {
            $violations[] = self::TOO_LONG;
        }

        if (str_contains($text, '#')) {
            $violations[] = self::HASHTAG;
        }

        if ($this->countEmoji($text) > self::MAX_EMOJI) {
            $violations[] = self::EMOJI;
        }

        if (preg_match('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+\.[\p{L}]{2,}/u', $text) === 1) {
            $violations[] = self::ADDRESS;
        }

        foreach ($this->blocklist as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($word), '/').'(?![\p{L}\p{N}])/u', mb_strtolower($text)) === 1) {
                $violations[] = self::BLOCKLIST;
                break;
            }
        }

        // Links: each one exactly as a fact has it, then out of the way.
        $residual = $text;
        preg_match_all('~(?:https?://|www\.)\S+|\b[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.[a-z]{2,}/\S*~iu', $text, $urls);
        foreach ($urls[0] as $url) {
            $url = rtrim($url, '.,;:!?)»“”"\'');

            if (! str_contains($haystack, mb_strtolower($url))) {
                $violations[] = self::URL;
            }

            $residual = str_replace($url, ' ', $residual);
        }

        // The canon entry: a real ID, its wording in the text, and no second one.
        $entry = null;
        if ($canonId !== null && $canonId !== '') {
            $entry = $this->canon->find($canonId);

            if ($entry === null) {
                $violations[] = self::CANON_UNKNOWN;
            } elseif ($language === 'en' && ! $entry['en_ok']) {
                $violations[] = self::CANON_NOT_ENGLISH;
            } elseif (preg_match(self::wordingPattern($entry['wording']), $residual) === 1) {
                // In English only a wording that is English already stands word for word; a German one is carried over by its sense.
                if ($language === 'en' && ! $this->isEnglish($entry['wording'])) {
                    $violations[] = self::CANON_UNTRANSLATED;
                }

                $residual = (string) preg_replace(self::wordingPattern($entry['wording']), ' ', $residual);
            } elseif (self::quotesPartOf($text, $entry['wording'])) {
                // An allusion ("Eins in den Chat für …", a 🤙) is fine; the wording half-quoted or altered is not.
                $violations[] = self::CANON_MISQUOTED;
            }
        }

        // Fact strings out (longest first, so a name inside a longer fact goes with it).
        $withoutFacts = $residual;
        $sortedFacts = $factStrings;
        usort($sortedFacts, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($sortedFacts as $fact) {
            if (mb_strlen($fact) >= 2) {
                $withoutFacts = (string) preg_replace('/'.preg_quote($fact, '/').'/iu', ' ', $withoutFacts);
            }
        }

        foreach ($this->detectableWordings() as $id => $pattern) {
            if ($id === ($entry['id'] ?? null) || preg_match($pattern, $withoutFacts) !== 1) {
                continue;
            }

            $violations[] = $entry === null ? self::CANON_WITHOUT_ID : self::CANON_SECOND;
            break;
        }

        // Quotes: only the canon wording (or a fact, such as a tournament's name) may stand in quotation marks.
        preg_match_all('/[„“”"»«]([^„“”"»«]{2,})[„“”"»«]/u', $text, $quotes);
        foreach ($quotes[1] as $quoted) {
            $quoted = self::normalize($quoted);
            $inCanon = $entry !== null && str_contains(self::normalize($entry['wording']), $quoted);
            $inFacts = str_contains(self::normalize($haystack), $quoted);

            if ($quoted !== '' && ! $inCanon && ! $inFacts) {
                $violations[] = $entry === null ? self::QUOTE : self::CANON_MISQUOTED;
                break;
            }
        }

        // Reported speech: "…, sagt der Sieger" puts words into someone's mouth without quotation marks
        // (seen in the first real eval run, 2026-10-11). Only a canon wording may carry such a verb.
        if (preg_match('/(?<![\p{L}])(?:sagt|sagte|meint|meinte|verrät|verriet|says|said)(?![\p{L}])/iu', $withoutFacts) === 1) {
            $violations[] = self::ATTRIBUTION;
        }

        // Numbers: any digit left must be a fact number in another spelling (1500 / 1.500).
        $factNumbers = [];
        preg_match_all('/\d+(?:[.,:]\d+)*/u', $haystack, $found);
        foreach ($found[0] as $number) {
            $factNumbers[preg_replace('/\D/', '', $number)] = true;
        }
        preg_match_all('/\d+(?:[.,:]\d+)*/u', $withoutFacts, $numbers);
        foreach ($numbers[0] as $number) {
            if (! isset($factNumbers[preg_replace('/\D/', '', $number)])) {
                $violations[] = self::NUMBER;
                break;
            }
        }

        // Real people: only as the speaker of the entry the text quotes, or named in its own wording. In English the
        // line is a sense translation, and a translation beside its speaker's name is a quote they never said.
        $quotesOriginal = $entry !== null && preg_match(self::wordingPattern($entry['wording']), $text) === 1;
        foreach ($this->persons as $person) {
            $speaksHere = $entry !== null && ($language !== 'en' || $quotesOriginal) && str_contains(mb_strtolower(($entry['speaker'] ?? '').' '.$entry['wording']), mb_strtolower($person));

            if (! $speaksHere && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($person, '/').'(?![\p{L}\p{N}])/iu', $withoutFacts) === 1) {
                $violations[] = self::PERSON;
                break;
            }
        }

        // Names: no handle-like or unknown capitalised word outside the facts.
        foreach (self::words($withoutFacts) as $word) {
            if ($this->looksLikeInventedName($word, $entry)) {
                $violations[] = self::NAME;
                break;
            }
        }

        foreach ($mustContain as $required) {
            if ($required !== '' && ! str_contains($text, $required)) {
                $violations[] = self::MISSING_FACT;
                break;
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * Every scalar of the facts as a string (keys left out), nested arrays included.
     *
     * @param  array<array-key, mixed>  $facts
     * @return list<string>
     */
    public static function factStrings(array $facts): array
    {
        $strings = [];

        array_walk_recursive($facts, function (mixed $value) use (&$strings): void {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $strings[] = $value;
                }
            }
        });

        return $strings;
    }

    /**
     * A pattern that finds a canon wording in a text: the same words in the same
     * order, any case, punctuation and spacing between them free.
     */
    public static function wordingPattern(string $wording): string
    {
        $segments = [];

        foreach (self::slotSegments($wording) as $segment) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', $segment, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words !== []) {
                $segments[] = implode('[^\p{L}\p{N}]+', array_map(fn (string $word): string => preg_quote($word, '/'), $words));
            }
        }

        if ($segments === []) {
            return '/'.preg_quote($wording, '/').'/u';
        }

        // A slot ("…") in a wording is a gap the post fills: "Eins in den Chat, wenn …, zwei in den Chat, wenn …".
        return '/(?<![\p{L}\p{N}])'.implode('.{1,80}?', $segments).'(?![\p{L}\p{N}])/iu';
    }

    /**
     * The fixed parts of a wording around its slots ("…" or "...").
     *
     * @return list<string>
     */
    public static function slotSegments(string $wording): array
    {
        return preg_split('/…|\.\.\./u', $wording, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Whether the text carries a run of the wording's words (QUOTED_RUN in a
     * row) without the whole wording: a quote cut short or altered.
     */
    public static function quotesPartOf(string $text, string $wording): bool
    {
        $words = explode(' ', self::normalize($wording));

        // A wording with slots is a format, not a sentence: alluding to it is the point.
        if (count(self::slotSegments($wording)) > 1 || str_contains($wording, '…') || count($words) <= self::QUOTED_RUN) {
            return false;
        }

        $haystack = ' '.self::normalize($text).' ';

        for ($i = 0; $i + self::QUOTED_RUN <= count($words); $i++) {
            if (str_contains($haystack, ' '.implode(' ', array_slice($words, $i, self::QUOTED_RUN)).' ')) {
                return true;
            }
        }

        return false;
    }

    /** Lower case, letters and digits only, single spaces. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /**
     * The words of a text as the vocabulary counts them.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_@][\p{L}\p{N}_@\'’-]*/u', $text, $matches);

        return array_map(fn (string $word): string => rtrim($word, '\'’-'), $matches[0]);
    }

    public function countEmoji(string $text): int
    {
        $count = 0;

        foreach (preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $grapheme) {
            if (preg_match('/\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|\x{20E3}/u', $grapheme) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  CanonEntry|null  $entry
     */
    private function looksLikeInventedName(string $word, ?array $entry): bool
    {
        if ($word === '') {
            return false;
        }

        $handleLike = str_contains($word, '_') || str_contains($word, '@')
            || preg_match('/\p{Ll}\p{Lu}/u', $word) === 1
            || (preg_match('/\p{L}/u', $word) === 1 && preg_match('/\p{N}/u', $word) === 1);

        if ($handleLike) {
            return true;
        }

        if (preg_match('/^\p{Lu}/u', $word) !== 1) {
            return false;
        }

        $vocabulary = $this->vocabulary();
        $lower = mb_strtolower($word);

        if (isset($vocabulary[$lower])) {
            return false;
        }

        $parts = preg_split('/[-\'’]/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts === [] || array_filter($parts, fn (string $part): bool => ! isset($vocabulary[$part])) !== [];
    }

    /**
     * Every word the league's own texts use: the canon (wording, meaning,
     * usage; never its source labels, which name podcast guests), the example
     * posts and the style guide.
     *
     * @return array<string, true>
     */
    private function vocabulary(): array
    {
        if ($this->vocabulary !== null) {
            return $this->vocabulary;
        }

        $texts = [];
        foreach ($this->canon->entries() as $entry) {
            $texts[] = $entry['wording'].' '.$entry['meaning'].' '.$entry['usage'];
        }
        foreach ($this->canon->allExamples() as $rows) {
            foreach ($rows as $row) {
                $texts[] = (string) preg_replace('/\{[^}]*\}/u', ' ', $row['line']);
            }
        }
        $styleGuide = $this->styleGuidePath !== '' ? $this->styleGuidePath : resource_path('data/voice/style-guide.md');
        $texts[] = is_file($styleGuide) ? (string) file_get_contents($styleGuide) : '';

        $texts[] = implode(' ', array_keys($this->englishWords()));

        $vocabulary = [];
        foreach (self::words(implode(' ', $texts)) as $word) {
            $word = mb_strtolower($word);
            $vocabulary[$word] = true;

            foreach (preg_split('/[-\'’]/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $vocabulary[$part] = true;
            }
        }

        return $this->vocabulary = $vocabulary;
    }

    /** Whether every word of a canon wording is English ("Few understand" is, "Bester Mann" is not). */
    private function isEnglish(string $wording): bool
    {
        $english = $this->englishWords();

        foreach (self::words($wording) as $word) {
            if (! isset($english[mb_strtolower($word)]) && preg_match('/\p{L}/u', $word) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * English words the league knows: the source strings (keys) of the German
     * translation file, i.e. the English of its own interface, and the
     * everyday openers of resources/data/voice/english-words.txt.
     *
     * @return array<string, true>
     */
    private function englishWords(): array
    {
        if ($this->english !== null) {
            return $this->english;
        }

        $texts = [];
        $interface = $this->interfacePath !== '' ? $this->interfacePath : lang_path('de.json');
        $strings = is_file($interface) ? json_decode((string) file_get_contents($interface), true) : null;
        foreach (is_array($strings) ? array_keys($strings) : [] as $source) {
            $texts[] = (string) preg_replace('/:[a-z_]+/u', ' ', (string) $source);
        }

        $openers = resource_path('data/voice/english-words.txt');
        foreach (is_file($openers) ? (file($openers, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (! str_starts_with(trim($line), '#')) {
                $texts[] = $line;
            }
        }

        $english = [];
        foreach (self::words(implode(' ', $texts)) as $word) {
            $english[mb_strtolower($word)] = true;
        }

        return $this->english = $english;
    }

    /**
     * @return array<string, string>
     */
    private function detectableWordings(): array
    {
        if ($this->detectable !== null) {
            return $this->detectable;
        }

        $detectable = [];
        foreach ($this->canon->entries() as $id => $entry) {
            if (count(preg_split('/[^\p{L}\p{N}]+/u', $entry['wording'], -1, PREG_SPLIT_NO_EMPTY) ?: []) >= self::DETECTABLE_WORDS) {
                $detectable[$id] = self::wordingPattern($entry['wording']);
            }
        }

        return $this->detectable = $detectable;
    }
}
