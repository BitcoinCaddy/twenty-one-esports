<?php

namespace App\Support\Voice;

/**
 * The two halves of every voice prompt. fixedPart() is the system prompt:
 * style guide, rules and a fixed set of example posts, byte-identical on
 * every call so Anthropic's prompt cache can serve it (VoiceAgent marks it
 * with CacheInstructions). variablePart() is the user message: the moment,
 * its facts, the channel's limits and the canon entries chosen for it.
 *
 * @phpstan-import-type CanonEntry from VoiceCanon
 */
final class VoicePrompt
{
    /** Example posts per moment in the fixed part. */
    private const FIXED_EXAMPLES_PER_MOMENT = 2;

    /** Example posts of the moment itself in the variable part. */
    private const MOMENT_EXAMPLES = 6;

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
            foreach (array_slice($rows, 0, self::FIXED_EXAMPLES_PER_MOMENT) as $row) {
                $lines[] = "- [{$section} · {$row['form']} · ".($row['canon_id'] ?? 'kein Meme')."] {$row['line']}";
            }
        }

        return $this->fixed = $styleGuide."\n\n## Beispiele aus der Community (`{…}` füllen nur die Fakten)\n\n".implode("\n", $lines)."\n";
    }

    /**
     * @param  array<array-key, mixed>  $facts
     * @param  list<CanonEntry>  $selection
     */
    public function variablePart(string $moment, array $facts, string $channel, int $maxChars, string $language, array $selection): string
    {
        $examples = array_map(
            fn (array $row): string => "- [{$row['form']} · ".($row['canon_id'] ?? 'kein Meme')."] {$row['line']}",
            array_slice($this->canon->examplesFor($moment), self::FIXED_EXAMPLES_PER_MOMENT, self::MOMENT_EXAMPLES),
        );

        $languageLine = $language === 'en'
            ? 'Sprache: Englisch. Kanon-Wortlaute bleiben im Original.'
            : 'Sprache: Deutsch.';

        $parts = [
            "## Moment\n\n{$moment}",
            "## Kanal\n\n{$channel}, höchstens {$maxChars} Zeichen. {$languageLine}",
            "## Fakten (die einzige Quelle für Zahlen, Namen und Links)\n\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if ($examples !== []) {
            $parts[] = "## Weitere Beispiele für diesen Moment\n\n".implode("\n", $examples);
        }

        $parts[] = "## Kanon-Auswahl für diesen Moment (ID: Wortlaut — Verwendung)\n\n".implode("\n", array_map(VoiceCanon::renderLine(...), $selection));
        $parts[] = 'Schreibe jetzt einen Text für diesen Moment.';

        return implode("\n\n", $parts);
    }
}
