<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use Carbon\CarbonImmutable;

/**
 * What the TV voice queued in the last `repeat_hours`, sentence by sentence
 * (user 2026-10-12: "'Und gleich geht's weiter mit der Liga' kommt auch
 * ständig! ALLE WIEDERHOLUNGEN RAUS!!!"). ScriptQueue::enqueue() drops every
 * sentence of a new script, from any source, that repeats one of them: the
 * same words (lowercase, without markers, punctuation and digits), a near
 * copy (at least NEAR_SHARE of the longer sentence's distinct words shared),
 * the same first OPENING_WORDS words, or a phrase of GRAM_WORDS words that
 * was said GRAM_LIMIT times already ("Und wer zahlt's am Ende" in every
 * other news segment). A script left without a sentence is not queued at all.
 *
 * The memory is the queue table itself (it keeps a day of scripts, fillers
 * left out), read once per object and extended with every script queued
 * through it, so nothing else needs storing or pruning.
 */
final class SpokenMemory
{
    /** Shared words (of the longer sentence's distinct words) from which a sentence is a near copy. */
    public const NEAR_SHARE = 0.8;

    /** Two sentences that open with the same this many words repeat each other. */
    public const OPENING_WORDS = 5;

    /** A phrase of this many words … */
    public const GRAM_WORDS = 4;

    /** … said this many times within the window makes every further sentence with it a copy. */
    public const GRAM_LIMIT = 2;

    /** @var list<array{words: list<string>, set: array<string, true>, at: int}>|null */
    private ?array $said = null;

    /** The sentence as the comparison sees it: lowercase words without markers, punctuation and digits. */
    public static function normalize(string $sentence): string
    {
        $text = mb_strtolower(ScriptGuard::withoutMarkers($sentence));

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\s]+/u', ' ', $text)));
    }

    public static function repeatHours(): int
    {
        return max(0, (int) config('tv.commentary.repeat_hours', 24));
    }

    /**
     * The script without its sentences said within `repeat_hours` (or earlier
     * in the same script); an empty string when none is left.
     */
    public function withoutRepeats(string $text, CarbonImmutable $now): string
    {
        if (self::repeatHours() === 0) {
            return $text;
        }

        $said = $this->said($now);
        $since = $now->subHours(self::repeatHours())->getTimestamp();
        $said = array_values(array_filter($said, fn (array $entry): bool => $entry['at'] >= $since));
        $grams = [];
        foreach ($said as $entry) {
            foreach (self::grams($entry['words']) as $gram) {
                $grams[$gram] = ($grams[$gram] ?? 0) + 1;
            }
        }
        $lines = [];

        foreach (ScriptGuard::lines($text) as $line) {
            $kept = [];

            foreach (ScriptGuard::runs($line) as $sentence) {
                $entry = self::entry($sentence, $now);

                if ($entry['words'] === [] || self::repeats($entry, $said) || self::wornPhrase($entry['words'], $grams)) {
                    continue;
                }

                $kept[] = $sentence;
                $said[] = $entry;
                foreach (self::grams($entry['words']) as $gram) {
                    $grams[$gram] = ($grams[$gram] ?? 0) + 1;
                }
            }

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        return implode("\n", $lines);
    }

    /** Remembers a queued script for the rest of this object's life (the table has it from now on). */
    public function remember(string $text, CarbonImmutable $now): void
    {
        $this->said($now);

        foreach (ScriptGuard::lines($text) as $line) {
            foreach (ScriptGuard::runs($line) as $sentence) {
                $this->said[] = self::entry($sentence, $now);
            }
        }
    }

    /**
     * The openings (first OPENING_WORDS words) of the last queued sentences,
     * newest first, each once: what a prompt tells the model never to reuse.
     *
     * @return list<string>
     */
    public static function recentOpenings(int $count = 30): array
    {
        $openings = [];
        $texts = CasterScript::query()->where('kind', '!=', CasterScript::FILLER)->orderByDesc('id')->limit($count)->pluck('text');

        foreach ($texts as $text) {
            foreach (ScriptGuard::lines((string) $text) as $line) {
                foreach (ScriptGuard::runs(ScriptGuard::withoutMarkers($line)) as $sentence) {
                    $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $opening = rtrim(implode(' ', array_slice($words, 0, self::OPENING_WORDS)), ' ,.;:!?');

                    if ($opening !== '') {
                        $openings[mb_strtolower($opening)] = $opening;
                    }

                    if (count($openings) >= $count) {
                        return array_values($openings);
                    }
                }
            }
        }

        return array_values($openings);
    }

    /**
     * @return list<array{words: list<string>, set: array<string, true>, at: int}>
     */
    private function said(CarbonImmutable $now): array
    {
        if ($this->said !== null) {
            return $this->said;
        }

        $this->said = [];
        $rows = CasterScript::query()->where('kind', '!=', CasterScript::FILLER)
            ->where('created_at', '>=', $now->subHours(self::repeatHours()))->get(['text', 'created_at']);

        foreach ($rows as $row) {
            $at = CarbonImmutable::parse($row->created_at);

            foreach (ScriptGuard::lines((string) $row->text) as $line) {
                foreach (ScriptGuard::runs($line) as $sentence) {
                    $this->said[] = self::entry($sentence, $at);
                }
            }
        }

        return $this->said;
    }

    /**
     * @return array{words: list<string>, set: array<string, true>, at: int}
     */
    private static function entry(string $sentence, CarbonImmutable $at): array
    {
        $words = preg_split('/\s+/u', self::normalize($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return ['words' => $words, 'set' => array_fill_keys($words, true), 'at' => $at->getTimestamp()];
    }

    /**
     * The distinct phrases of GRAM_WORDS words of a sentence.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private static function grams(array $words): array
    {
        $grams = [];
        for ($i = 0; $i + self::GRAM_WORDS <= count($words); $i++) {
            $grams[implode(' ', array_slice($words, $i, self::GRAM_WORDS))] = true;
        }

        return array_keys($grams);
    }

    /**
     * Whether a sentence carries a phrase said GRAM_LIMIT times already.
     *
     * @param  list<string>  $words
     * @param  array<string, int>  $grams
     */
    private static function wornPhrase(array $words, array $grams): bool
    {
        foreach (self::grams($words) as $gram) {
            if (($grams[$gram] ?? 0) >= self::GRAM_LIMIT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{words: list<string>, set: array<string, true>, at: int}  $entry
     * @param  list<array{words: list<string>, set: array<string, true>, at: int}>  $said
     */
    private static function repeats(array $entry, array $said): bool
    {
        $opening = count($entry['words']) >= self::OPENING_WORDS ? implode(' ', array_slice($entry['words'], 0, self::OPENING_WORDS)) : null;

        foreach ($said as $old) {
            if ($old['words'] === $entry['words']) {
                return true;
            }

            if ($opening !== null && count($old['words']) >= self::OPENING_WORDS && implode(' ', array_slice($old['words'], 0, self::OPENING_WORDS)) === $opening) {
                return true;
            }

            $longer = max(count($entry['set']), count($old['set']));
            if ($longer >= 4 && count(array_intersect_key($entry['set'], $old['set'])) / $longer >= self::NEAR_SHARE) {
                return true;
            }
        }

        return false;
    }
}
