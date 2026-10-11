<?php

namespace App\Support\Voice;

use RuntimeException;

/**
 * The meme canon of the league voice (resources/data/voice/meme-canon.json,
 * approved by the user on 2026-10-11 with all 1,311 entries): short quotes
 * and formats with their source, indexed by the contexts each entry is meant
 * for. A prompt never carries the whole canon (about 250k tokens); select()
 * picks the entries that fit one moment.
 *
 * `en_ok` (plan "KI-Stimme der Liga", P2): whether an entry makes sense to
 * an English reader as a sense translation (false for puns, dialect, German
 * media and politics). Marked once by `voice:mark-english`; an entry without
 * the mark counts as German only, so the English channels never get it.
 *
 * The example posts (resources/data/voice/examples.json, from the same
 * research) are grouped by moment; examplesFor() returns a moment's lines.
 *
 * @phpstan-type CanonEntry array{id: string, wording: string, meaning: string, usage: string, category: string, type: string, contexts: list<string>, speaker: ?string, en_ok: bool}
 * @phpstan-type Example array{line: string, canon_id: ?string, form: string}
 */
final class VoiceCanon
{
    /**
     * Moment types the callers pass, each with the canon contexts that fit it
     * (strongest first) and its section in examples.json.
     *
     * @var array<string, array{contexts: list<string>, examples: ?string}>
     */
    public const MOMENTS = [
        'upset' => ['contexts' => ['Upset', 'Sieg', 'Elo steigt'], 'examples' => 'Upset (Außenseiter schlägt Favorit)'],
        'comeback' => ['contexts' => ['Comeback', 'Sieg'], 'examples' => 'Comeback'],
        'streak' => ['contexts' => ['Serie', 'Sieg', 'Rekord'], 'examples' => 'Lange Serie'],
        'streak_broken' => ['contexts' => ['Serie gerissen', 'Niederlage'], 'examples' => 'Serie gerissen'],
        'tournament_final' => ['contexts' => ['Finale', 'Turnierfinale', 'Spitzenspiel', 'Turnier'], 'examples' => 'Turnierfinale'],
        'champion' => ['contexts' => ['Champion', 'Sieg', 'Turnier'], 'examples' => 'Champion'],
        'season_start' => ['contexts' => ['Saisonstart', 'neue Saison'], 'examples' => 'Neue Saison'],
        'season_end' => ['contexts' => ['Saisonende'], 'examples' => 'Saisonende'],
        'blockfill_week' => ['contexts' => ['Blockfill', 'Blockfill-Woche', 'Wochenstart'], 'examples' => 'Blockfill-Woche'],
        'pong_deuce' => ['contexts' => ['knapp', 'Pong'], 'examples' => 'Pong 21:20 / 20:22'],
        'close_game' => ['contexts' => ['knapp', 'Sieg'], 'examples' => 'Knappes Spiel'],
        'draw' => ['contexts' => ['Remis'], 'examples' => 'Remis'],
        'forfeit' => ['contexts' => ['Aufgabe'], 'examples' => 'Aufgabe / Nichterscheinen'],
        'first_win' => ['contexts' => ['Neuling', 'erster Sieg'], 'examples' => 'Erster Sieg eines Neulings'],
        'rematch' => ['contexts' => ['Rematch'], 'examples' => 'Rivalen-Rematch'],
        'night_pause' => ['contexts' => ['Nachtpause'], 'examples' => 'Nachtpause'],
        'signup_open' => ['contexts' => ['Anmeldung', 'Turnierstart', 'Turnier'], 'examples' => 'Anmeldung offen'],
        'last_free_place' => ['contexts' => ['Anmeldeschluss', 'Anmeldung'], 'examples' => 'Letzter freier Platz'],
        'pot_growing' => ['contexts' => ['Topf', 'Turniertopf', 'Topf wächst', 'Zap'], 'examples' => 'Turniertopf wächst'],
        'win' => ['contexts' => ['Sieg'], 'examples' => null],
        'loss' => ['contexts' => ['Niederlage'], 'examples' => null],
        'zap' => ['contexts' => ['Zap'], 'examples' => null],
        'stream_start' => ['contexts' => ['Stream-Start', 'Stream'], 'examples' => null],
        'stream_end' => ['contexts' => ['Stream-Ende', 'Stream'], 'examples' => null],
        'weekly_recap' => ['contexts' => ['Wochenrückblick'], 'examples' => null],
        'general' => ['contexts' => [], 'examples' => null],
    ];

    /** The context every entry without a better fit carries. */
    private const GENERAL = 'allgemein';

    /** @var array<string, CanonEntry>|null */
    private ?array $entries = null;

    /** @var array<string, list<string>>|null context => entry IDs */
    private ?array $byContext = null;

    /** @var array<string, list<Example>>|null */
    private ?array $examples = null;

    public function __construct(
        private readonly string $canonPath = '',
        private readonly string $examplesPath = '',
    ) {}

    /**
     * @return array<string, CanonEntry>
     */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        $byContext = [];

        foreach ($this->readJson($this->canonPath !== '' ? $this->canonPath : resource_path('data/voice/meme-canon.json')) as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! is_string($row['wording'] ?? null) || trim($row['wording']) === '') {
                continue;
            }

            $contexts = array_values(array_filter((array) ($row['contexts'] ?? []), 'is_string'));
            $entries[$row['id']] = [
                'id' => $row['id'],
                'wording' => trim($row['wording']),
                'meaning' => (string) ($row['meaning'] ?? ''),
                'usage' => (string) ($row['usage'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'contexts' => $contexts,
                'speaker' => is_string($row['speaker'] ?? null) ? $row['speaker'] : null,
                'en_ok' => ($row['en_ok'] ?? false) === true,
            ];

            foreach ($contexts as $context) {
                $byContext[$context][] = $row['id'];
            }
        }

        $this->byContext = $byContext;

        return $this->entries = $entries;
    }

    /**
     * @return CanonEntry|null
     */
    public function find(?string $id): ?array
    {
        return $id === null ? null : ($this->entries()[$id] ?? null);
    }

    /**
     * The IDs of the entries tagged with the given context.
     *
     * @return list<string>
     */
    public function idsFor(string $context): array
    {
        $this->entries();

        return $this->byContext[$context] ?? [];
    }

    /**
     * Between `min` and `max` entries for one moment: first those whose
     * contexts fit the moment (in the order of MOMENTS), then general ones,
     * then any other, each group shuffled, leaving out `$avoid` (the IDs a
     * channel's last posts used) as long as enough others remain. The rendered
     * selection stays under `$tokenBudget` (estimated as chars/4). For an
     * English channel (`$language` en) only the entries marked `en_ok`.
     *
     * @param  list<string>  $avoid
     * @return list<CanonEntry>
     */
    public function select(string $moment, array $avoid = [], int $min = 50, int $max = 100, int $tokenBudget = 12_000, string $language = 'de'): array
    {
        $entries = $language === 'en' ? array_filter($this->entries(), fn (array $entry): bool => $entry['en_ok']) : $this->entries();
        $avoided = array_flip($avoid);
        $contexts = self::MOMENTS[$moment]['contexts'] ?? [];

        $groups = [];
        $usable = fn (array $ids): array => array_values(array_filter($ids, fn (string $id): bool => isset($entries[$id])));

        foreach ($contexts as $context) {
            $groups[] = $usable($this->idsFor($context));
        }
        $groups[] = $usable($this->idsFor(self::GENERAL));
        $groups[] = array_keys($entries);

        $picked = [];
        $tokens = 0;

        // Two passes: fresh entries first, the avoided ones only if the fresh ones cannot reach `min`.
        foreach ([false, true] as $allowAvoided) {
            foreach ($groups as $index => $ids) {
                $isFill = $index === array_key_last($groups);
                shuffle($ids);

                foreach ($ids as $id) {
                    if (count($picked) >= $max) {
                        break 3;
                    }

                    if (isset($picked[$id]) || (! $allowAvoided && isset($avoided[$id]))) {
                        continue;
                    }

                    // The generic fill stops once `min` is reached: the fitting entries are the point.
                    if ($isFill && count($picked) >= $min) {
                        continue;
                    }

                    $cost = self::estimateTokens(self::renderLine($entries[$id]));

                    if ($tokens + $cost > $tokenBudget) {
                        break 3;
                    }

                    $picked[$id] = $entries[$id];
                    $tokens += $cost;
                }
            }

            if (count($picked) >= $min) {
                break;
            }
        }

        return array_values($picked);
    }

    /**
     * One canon entry as the prompt shows it.
     *
     * @param  CanonEntry  $entry
     */
    public static function renderLine(array $entry): string
    {
        $speaker = $entry['speaker'] === null ? '' : " (Spruch von {$entry['speaker']})";

        return "- {$entry['id']}: „{$entry['wording']}“{$speaker} — {$entry['usage']}";
    }

    /** A rough token estimate: four characters per token. */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * The example posts of a moment (empty when it has none).
     *
     * @return list<Example>
     */
    public function examplesFor(string $moment): array
    {
        $section = self::MOMENTS[$moment]['examples'] ?? null;

        return $section === null ? [] : ($this->allExamples()[$section] ?? []);
    }

    /**
     * @return array<string, list<Example>>
     */
    public function allExamples(): array
    {
        if ($this->examples !== null) {
            return $this->examples;
        }

        $examples = [];

        foreach ($this->readJson($this->examplesPath !== '' ? $this->examplesPath : resource_path('data/voice/examples.json')) as $section => $rows) {
            foreach ((array) $rows as $row) {
                if (is_array($row) && is_string($row['line'] ?? null)) {
                    $examples[(string) $section][] = [
                        'line' => $row['line'],
                        'canon_id' => is_string($row['canon_id'] ?? null) ? $row['canon_id'] : null,
                        'form' => (string) ($row['form'] ?? ''),
                    ];
                }
            }
        }

        return $this->examples = $examples;
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException("Unreadable voice data file [{$path}].");
        }

        return $data;
    }
}
