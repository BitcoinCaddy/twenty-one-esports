<?php

namespace App\Support\Voice;

/**
 * The two halves of every voice prompt. fixedPart() is the system prompt:
 * style guide, rules and a fixed set of example posts, byte-identical on
 * every call so Anthropic's prompt cache can serve it (VoiceAgent marks it
 * with CacheInstructions). variablePart() is the user message: the moment,
 * its facts, the channel's limits and the canon entries chosen for it.
 *
 * Only example posts that keep to the everyday-language rules (VoiceHygiene
 * style()) are shown: an approved post with a nested sentence would teach
 * exactly what the guard then rejects.
 *
 * @phpstan-import-type CanonEntry from VoiceCanon
 * @phpstan-import-type Example from VoiceCanon
 */
final class VoicePrompt
{
    /** Example posts per moment in the fixed part. */
    private const FIXED_EXAMPLES_PER_MOMENT = 2;

    /** Example posts of the moment itself in the variable part. */
    private const MOMENT_EXAMPLES = 6;

    /**
     * What a rejection code means, for the second try: the model fixes a
     * reason it can read, not a code (first P2 eval run, 2026-10-11).
     *
     * @var array<string, string>
     */
    private const REASON_HINTS = [
        VoiceGuard::NUMBER => 'Eine Zahl stand nicht in den Fakten.',
        VoiceGuard::NAME => 'Ein großgeschriebenes Wort oder ein Name stand nicht in den Fakten.',
        VoiceGuard::URL => 'Kein Link im Text.',
        VoiceGuard::HASHTAG => 'Kein # im Text.',
        VoiceGuard::TOO_LONG => 'Der Text war zu lang.',
        VoiceGuard::QUOTE => 'Keine Anführungszeichen um eigene Sätze.',
        VoiceGuard::ATTRIBUTION => 'Niemand „sagt“ etwas: keine erfundenen Zitate.',
        VoiceGuard::PERSON => 'Keine echte Person nennen.',
        VoiceGuard::CANON_MISQUOTED => 'Ein Kanon-Spruch war verändert oder halb zitiert: ganz und wörtlich oder gar nicht.',
        VoiceGuard::CANON_SECOND => 'Höchstens ein Kanon-Spruch.',
        VoiceGuard::CANON_WITHOUT_ID => 'Ein Kanon-Spruch ohne seine ID in canon_id.',
        VoiceGuard::CANON_UNKNOWN => 'Die canon_id gibt es nicht.',
        VoiceGuard::CANON_NOT_ENGLISH => 'Dieser Kanon-Spruch passt nicht in einen englischen Text.',
        VoiceGuard::CANON_UNTRANSLATED => 'Ein deutscher Kanon-Spruch stand wörtlich im englischen Text: sinngemäß auf Englisch oder weglassen.',
        VoiceGuard::MISSING_FACT => 'Ein Pflicht-Fakt fehlte oder war umformuliert: wörtlich übernehmen.',
        VoiceGuard::BLOCKLIST => 'Ein gesperrtes Wort.',
        VoiceGuard::EMOJI => 'Höchstens zwei Emoji.',
        VoiceHygiene::TOO_MANY_SENTENCES => 'Zu viele Sätze.',
        VoiceHygiene::LONG_SENTENCE => 'Ein Satz war zu lang: höchstens 14 Wörter.',
        VoiceHygiene::COMMAS => 'Zu viele Kommas in einem Satz: lieber zwei kurze Sätze.',
        VoiceHygiene::AI_PHRASE => 'Eine KI- oder Behördenfloskel.',
        VoiceHygiene::WRONG_LANGUAGE => 'Falsche Sprache.',
        VoiceHygiene::TRUNCATED => 'Der Text hörte mitten im Satz auf.',
        VoiceHygiene::NEAR_COPY => 'Fast derselbe Text wie ein früherer Post.',
        VoiceMentions::REPEATED => 'Eine Person stand doppelt im Text: nur einmal, mit ihrem Token.',
        VoiceMentions::UNKNOWN => 'Ein Token, das es in den Fakten nicht gibt.',
        VoiceMentions::NOT_ALLOWED => 'Diese Person darf nicht mit Token erwähnt werden: nur mit Namen.',
    ];

    private ?string $fixed = null;

    public function __construct(
        private readonly VoiceCanon $canon,
        private readonly string $styleGuidePath = '',
    ) {}

    public function fixedPart(): string
    {
        if ($this->fixed !== null) {
            return $this->fixed;
        }

        $path = $this->styleGuidePath !== '' ? $this->styleGuidePath : resource_path('data/voice/style-guide.md');
        $styleGuide = trim((string) file_get_contents($path));

        $lines = [];
        foreach ($this->canon->allExamples() as $section => $rows) {
            foreach (array_slice(self::everyday($rows), 0, self::FIXED_EXAMPLES_PER_MOMENT) as $row) {
                $lines[] = "- [{$section} · {$row['form']} · ".($row['canon_id'] ?? 'kein Meme')."] {$row['line']}";
            }
        }

        return $this->fixed = $styleGuide."\n\n## Beispiele aus der Community (`{…}` füllen nur die Fakten)\n\n".implode("\n", $lines)."\n";
    }

    /**
     * @param  array<array-key, mixed>  $facts
     * @param  list<CanonEntry>  $selection
     * @param  list<string>  $rejected  the reason codes of a first draft the checks turned down (one more try)
     */
    public function variablePart(string $moment, array $facts, string $channel, int $maxChars, string $language, array $selection, string $instruction = '', array $rejected = []): string
    {
        $examples = array_map(
            fn (array $row): string => "- [{$row['form']} · ".($row['canon_id'] ?? 'kein Meme')."] {$row['line']}",
            array_slice(self::everyday($this->canon->examplesFor($moment)), self::FIXED_EXAMPLES_PER_MOMENT, self::MOMENT_EXAMPLES),
        );

        $languageLine = $language === 'en'
            ? 'Sprache: Englisch, Alltagsenglisch („Hey!“, „Yep.“, „Wow.“). Einen Kanon-Eintrag überträgst du sinngemäß ins Englische: ohne Anführungszeichen und ohne den Namen der Person, von der er stammt. Wörtlich bleibt nur ein Wortlaut, der schon englisch ist.'
            : 'Sprache: Deutsch, Alltagsdeutsch („Hey!“, „Klar.“, „Boah.“).';

        $parts = [
            "## Moment\n\n{$moment}",
            "## Kanal\n\n{$channel}, höchstens {$maxChars} Zeichen. {$languageLine}".($instruction === '' ? '' : "\n\n{$instruction}"),
            "## Fakten (die einzige Quelle für Zahlen, Namen und Links)\n\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($examples !== []) {
            $parts[] = "## Weitere Beispiele für diesen Moment\n\n".implode("\n", $examples);
        }

        $parts[] = "## Kanon-Auswahl für diesen Moment (ID: Wortlaut — Verwendung)\n\n".implode("\n", array_map(VoiceCanon::renderLine(...), $selection));
        if ($rejected !== []) {
            $reasons = array_map(fn (string $code): string => '- '.(self::REASON_HINTS[$code] ?? $code), $rejected);
            $parts[] = "## Dein erster Entwurf ging nicht durch\n\n".implode("\n", $reasons)."\n\nSchreib neu: kürzer, einfacher, nur die Fakten.";
        }

        $parts[] = 'Schreibe jetzt einen Text für diesen Moment.';

        return implode("\n\n", $parts);
    }

    /**
     * The example posts that keep to the everyday-language rules, a placeholder read as one word.
     *
     * @param  list<Example>  $rows
     * @return list<Example>
     */
    private static function everyday(array $rows): array
    {
        $hygiene = new VoiceHygiene;

        return array_values(array_filter($rows, fn (array $row): bool => $hygiene->style((string) preg_replace('/\{[^}]*\}/u', 'X', $row['line'])) === []));
    }
}
