<?php

namespace App\Support\Caster;

/**
 * The spoken-text rules of a commentary script, on top of the league voice's
 * own checks (VoiceHygiene, VoiceGuard): one sentence per line, the number
 * of sentences the kind asks for, sentences of a speakable length (spoken: at
 * least `min_words_per_sentence` words, so no fragment or two-word
 * exclamation reaches the voice, user 2026-10-12: "komplette Sätze ab jetzt
 * nur noch"; at most `max_words_per_sentence` words and `max_commas` commas,
 * a decimal comma not counted), and only the OmniVoice markers
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

    public const SENTENCE_SHORT = 'sentence_short';

    public const COMMAS = 'commas';

    public const MARKER_UNKNOWN = 'marker_unknown';

    public const MARKER_COUNT = 'marker_count';

    public const STAGE_DIRECTION = 'stage_direction';

    public const STOCK_PHRASE = 'stock_phrase';

    public const OPENING_REPEAT = 'opening_repeat';

    public const NAME_COUNT = 'name_count';

    public const NAME_REPEAT = 'name_repeat';

    /** Fact keys whose value is a person (SceneFacts, ChatReactions). */
    private const PERSON_KEYS = ['white', 'black', 'a', 'b', 'winner', 'loser', 'draw', 'champion', 'roster', 'chatter_name'];

    /** Lists whose rows' `name` is a person. */
    private const PERSON_ROWS = ['table', 'top', 'board'];

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

    /** Most commas a spoken sentence may carry, unless `tv.commentary.scripts.max_commas` says otherwise (user 2026-10-11: "zu streng"). */
    public const MAX_COMMAS = 2;

    /** The fewest spoken words of a sentence, unless `tv.commentary.scripts.min_words_per_sentence` says otherwise (user 2026-10-12). */
    public const MIN_WORDS = 7;

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
     * @param  int|null  $minWords  the fewest spoken words per sentence; null: minWords(), 0: no minimum (a chat reply runs free)
     * @return list<string>
     */
    public function check(string $text, int $minSentences, int $maxSentences, int $maxWords, array $recentOpenings = [], ?int $minWords = null): array
    {
        $violations = [];
        $minWords ??= self::minWords();
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

            // Every sentence of the line, also two in one line ("Hey! Schön, dass du da bist."): a fragment sounds choppy.
            foreach (self::runs($spoken) as $sentence) {
                if (self::wordCount($sentence) < $minWords) {
                    $violations[] = self::SENTENCE_SHORT;
                }
            }

            if (self::commas($spoken) > self::maxCommas()) {
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
            foreach (self::runs($line) as $run) {
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
     * The script without its lines that hold a sentence under minWords() (a
     * model's "Boah." or "Läuft."): the repair before the check. Unchanged
     * when fewer than `$minSentences` lines would stay; check() rejects it
     * then (fail closed).
     */
    public static function withoutShort(string $text, int $minSentences): string
    {
        $minWords = self::minWords();
        $kept = array_values(array_filter(self::lines($text), function (string $line) use ($minWords): bool {
            foreach (self::runs(self::withoutMarkers($line)) as $sentence) {
                if (self::wordCount($sentence) < $minWords) {
                    return false;
                }
            }

            return true;
        }));

        return count($kept) < max(1, $minSentences) ? $text : implode("\n", $kept);
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

    /**
     * The sentences of one line: a cut after a closing mark before a capital, a digit or a quote ("Klar. Weiter.").
     *
     * @return list<string>
     */
    public static function runs(string $line): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?…])(?<!\d\.)\s+(?=(?:\[[a-z-]+\]\s*)*[\p{Lu}\p{N}„"»])/u', $line) ?: [$line]), fn (string $run): bool => $run !== ''));
    }

    /** The fewest spoken words a sentence of a non-chat script may have (`tv.commentary.scripts.min_words_per_sentence`). */
    public static function minWords(): int
    {
        return max(0, (int) config('tv.commentary.scripts.min_words_per_sentence', self::MIN_WORDS));
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

        // Neither part may fall below the sentence minimum: a cut that leaves a fragment is no repair.
        $minimum = max($bareConjunction ? self::MIN_CLAUSE_WORDS : self::MIN_PART_WORDS, self::minWords());
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
        return self::wordCount($sentence) <= $maxWords && self::commas(self::withoutMarkers($sentence)) <= self::maxCommas();
    }

    public static function maxCommas(): int
    {
        return (int) config('tv.commentary.scripts.max_commas', self::MAX_COMMAS);
    }

    /** The commas of a sentence; one between two digits is a decimal mark ("16,13 Sekunden"), not a clause. */
    private static function commas(string $sentence): int
    {
        return (int) preg_match_all('/,(?!\d)|(?<!\d),/u', $sentence);
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

    /**
     * The person names in a scene's or moment's facts: players of a live game
     * or result, a champion, the rows of a table, ladder or board, a roster,
     * the chatter of a chat greeting. Tournament, clan, track and ladder
     * names are no persons.
     *
     * @param  array<array-key, mixed>  $facts
     * @return list<string>
     */
    public static function personNames(array $facts): array
    {
        $names = [];
        $walk = function (mixed $value, string|int|null $key, string|int|null $parent) use (&$walk, &$names): void {
            if (is_array($value)) {
                // The nearest named key above: a list (roster, draw) hands its own down, a row of a table the table's.
                foreach ($value as $childKey => $child) {
                    $walk($child, $childKey, is_string($key) ? $key : $parent);
                }

                return;
            }

            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $own = is_string($key) ? $key : $parent;
            if (in_array($own, self::PERSON_KEYS, true) || ($key === 'name' && in_array($parent, self::PERSON_ROWS, true))) {
                $names[RecentlySaid::normalize($value)] = trim($value);
            }
        };
        $walk($facts, null, null);

        return array_values($names);
    }

    /**
     * The person names a text says, in order, a name said twice listed twice.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function namesIn(string $text, array $names): array
    {
        $found = [];

        foreach ($names as $name) {
            if (mb_strlen($name) < 2) {
                continue;
            }

            preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', self::withoutMarkers($text), $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$match, $offset]) {
                $found[(int) $offset] = $name;
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * The script with at most `$allowed` person name mentions (a name said
     * twice counts twice) and none of `$blocked` (named in the last
     * `name_memory_minutes`): a sentence that would go over is dropped. Null
     * when fewer than `$minSentences` sentences stay (rejected, fail closed).
     *
     * @param  list<string>  $names  ScriptGuard::personNames() of the facts
     * @param  array<string, true>  $blocked  normalised names
     */
    public static function limitNames(string $text, array $names, int $allowed, array $blocked, int $minSentences): ?string
    {
        $kept = [];
        $mentions = 0;

        foreach (self::lines($text) as $line) {
            $here = self::namesIn($line, $names);
            $isBlocked = array_filter($here, fn (string $name): bool => isset($blocked[RecentlySaid::normalize($name)])) !== [];

            if ($here !== [] && ($isBlocked || $mentions + count($here) > $allowed)) {
                continue;
            }

            $mentions += count($here);
            $kept[] = $line;
        }

        return count($kept) < $minSentences ? null : implode("\n", $kept);
    }

    /**
     * The tournament, track, clan and ladder names of the facts, with the
     * noun a later mention becomes: name => [noun, gender (n, m, f)].
     *
     * @param  array<array-key, mixed>  $facts
     * @return array<string, array{0: string, 1: string}>
     */
    public static function entityNames(array $facts): array
    {
        $names = [];
        $add = function (mixed $name, string $noun, string $gender) use (&$names): void {
            if (is_string($name) && mb_strlen(trim($name)) >= 2) {
                $names[trim($name)] = [$noun, $gender];
            }
        };

        foreach (['tournaments_open', 'tournaments_under_way'] as $key) {
            foreach (is_array($facts[$key] ?? null) ? $facts[$key] : [] as $tournament) {
                $add(is_array($tournament) ? ($tournament['name'] ?? null) : null, 'Turnier', 'n');
            }
        }

        foreach (is_array($facts['ladders'] ?? null) ? $facts['ladders'] : [] as $ladder) {
            $add(is_array($ladder) ? ($ladder['ladder'] ?? null) : null, 'Ladder', 'f');
        }

        $add(is_array($facts['clan_spotlight'] ?? null) ? ($facts['clan_spotlight']['name'] ?? null) : null, 'Clan', 'm');
        $add(is_array($facts['tmnf_week'] ?? null) ? ($facts['tmnf_week']['track'] ?? null) : null, 'Strecke', 'f');

        return $names;
    }

    /**
     * The script with each tournament, track, clan or ladder name said once
     * (the user, 2026-10-11: "Die Turniernamen z. B. 3 bis 4 Mal
     * hintereinander … Das nervt."), none of `$blocked` (named in the last
     * `entity_memory_minutes`): a later or blocked mention becomes the noun
     * where a preposition or the sentence start makes that grammatical ("bei
     * X" → "beim Turnier", "X startet" → "Das Turnier startet", "der Ladder X"
     * → "der Ladder"); a sentence where it cannot is dropped. Null when fewer
     * than `$minSentences` stay.
     *
     * @param  array<string, array{0: string, 1: string}>  $names  entityNames()
     * @param  array<string, true>  $blocked  normalised names
     */
    public static function dedupeEntities(string $text, array $names, array $blocked, int $minSentences): ?string
    {
        $seen = [];
        $kept = [];
        $articles = ['n' => 'Das', 'm' => 'Der', 'f' => 'Die'];
        $prepositions = [
            'n' => ['bei' => 'beim', 'von' => 'vom', 'in' => 'im', 'im' => 'im', 'für' => 'fürs', 'auf' => 'auf dem'],
            'm' => ['bei' => 'beim', 'von' => 'vom', 'in' => 'im', 'im' => 'im', 'für' => 'für den', 'auf' => 'auf dem'],
            'f' => ['bei' => 'bei der', 'von' => 'von der', 'in' => 'in der', 'für' => 'für die', 'auf' => 'auf der'],
        ];
        uksort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach (self::lines($text) as $line) {
            $drop = false;

            foreach ($names as $name => [$noun, $gender]) {
                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu';
                $key = RecentlySaid::normalize($name);
                $first = ! isset($seen[$key]) && ! isset($blocked[$key]);

                if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                // Mentions in order: the first fresh one stays, every other becomes the noun or drops the sentence.
                $offset = 0;
                $out = '';
                foreach ($matches[0] as [$match, $position]) {
                    $before = substr($line, $offset, $position - $offset);
                    $offset = $position + strlen($match);

                    if ($first) {
                        $out .= $before.$match;
                        $first = false;
                        $seen[$key] = true;

                        continue;
                    }

                    if (preg_match('/(?:^|\s)((?:[Dd]er )?Ladder)\s*$/u', $before) === 1 && $noun === 'Ladder') {
                        $out .= rtrim($before);
                    } elseif (preg_match('/(?:^|\s)(\p{L}+)\s+$/u', $before, $prep) === 1 && isset($prepositions[$gender][mb_strtolower($prep[1])])) {
                        $word = $prepositions[$gender][mb_strtolower($prep[1])];
                        $out .= substr($before, 0, -strlen($prep[0])).($prep[0][0] === ' ' ? ' ' : '').(preg_match('/^\p{Lu}/u', $prep[1]) === 1 ? mb_ucfirst($word) : $word).' '.$noun;
                    } elseif (trim(self::withoutMarkers($before)) === '') {
                        $out .= $before.$articles[$gender].' '.$noun;
                    } else {
                        $drop = true;
                        break;
                    }
                }

                if ($drop) {
                    break;
                }

                $line = $out.substr($line, $offset);
            }

            if (! $drop) {
                $kept[] = $line;
            }
        }

        return count($kept) < $minSentences ? null : implode("\n", $kept);
    }

    /** Whether a sentence ends on an accent (! ? :) or carries a marker: a longer pause follows it. */
    public static function accented(string $sentence): bool
    {
        return preg_match('/[!?:]\s*$|\[[a-z-]+\]/u', trim($sentence)) === 1;
    }
}
