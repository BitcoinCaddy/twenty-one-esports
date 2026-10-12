<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use Carbon\CarbonImmutable;

/**
 * What the TV voice queued in the last `repeat_hours`, sentence by sentence
 * (user 2026-10-12: "'Und gleich geht's weiter mit der Liga' kommt auch
 * ständig! ALLE WIEDERHOLUNGEN RAUS!!!"). A sentence repeats one of them
 * when it has the same words (lowercase, without markers, punctuation and
 * digits), is a near copy (at least NEAR_SHARE of the longer sentence's
 * distinct words shared), has the same first OPENING_WORDS words, or carries
 * a phrase of GRAM_WORDS words that was said GRAM_LIMIT times already ("Und
 * wer zahlt's am Ende" in every other news segment; within `repeat_phrase_hours`,
 * and only with GRAM_CONTENT_WORDS words of content). The writers ask the
 * model once to say such sentences anew (repeated(), CasterVoice::SPOKEN_REPEAT);
 * ScriptQueue::enqueue() then ends a script before its first repeat
 * (coherent(), plan "TWENTY ONE Radio" P8) instead of cutting it out of the
 * middle, and does not queue a script with too little left.
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

    /**
     * … if at least this many of its words carry content (not FUNCTION_WORDS). Measured on the eval drafts of plan
     * "TWENTY ONE Radio" P8 against prod's 24 h (2026-10-12): 22.5 % of fresh sentences carried a worn phrase like
     * "für mich ist das", "so als würde man" or "in den chat und", which no listener hears as a repeat; with two
     * content words 9.3 %, while "wer zahlt das am Ende" still counts.
     */
    public const GRAM_CONTENT_WORDS = 2;

    /** Words without content of their own (lowercase, as normalize() leaves them). */
    private const FUNCTION_WORDS = [
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'einer', 'eines', 'und', 'oder', 'aber', 'denn', 'weil',
        'dass', 'wenn', 'als', 'wie', 'so', 'ja', 'halt', 'auch', 'noch', 'schon', 'nur', 'mal', 'doch', 'ich', 'du', 'er', 'sie', 'es',
        'wir', 'ihr', 'mir', 'mich', 'dir', 'dich', 'uns', 'euch', 'sich', 'mein', 'meine', 'dein', 'deine', 'sein', 'seine', 'unser',
        'euer', 'ist', 'sind', 'war', 'hat', 'haben', 'habe', 'hab', 'wird', 'werden', 'kann', 'können', 'muss', 'nicht', 'kein', 'keine',
        'zu', 'im', 'in', 'am', 'an', 'auf', 'aus', 'bei', 'mit', 'nach', 'von', 'vor', 'für', 'über', 'um', 'bis', 'da', 'dann', 'hier',
        'jetzt', 'heute', 'gerade', 'ganz', 'sehr', 'man', 'was', 'wer', 'wo', 'einfach', 'eben', 'also', 'ob', 'würde', 'wäre', 'gibt',
        'geht', 'immer', 'wieder', 'finde', 'find', 'frag', 'bisschen', 'ungefähr', 'dieser', 'diese', 'dieses', 'zum', 'zur', 'vom', 'beim',
    ];

    /** A repeated sentence of at most this many words is a call or a cry, no statement: it leaves without ending the script. */
    public const CALL_WORDS = 3;

    /** The states of a sentence in walk(). */
    private const FRESH = 'fresh';

    private const REPEAT = 'repeat';

    private const EMPTY = 'empty';

    /** @var list<array{words: list<string>, set: array<string, true>, at: int}>|null */
    private ?array $said = null;

    /** The sentence as the comparison sees it: lowercase words without markers, punctuation and digits. */
    public static function normalize(string $sentence): string
    {
        $text = mb_strtolower(ScriptGuard::withoutMarkers($sentence));

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\s]+/u', ' ', $text)));
    }

    /** The window of the worn-phrase rule (`repeat_phrase_hours`, at most `repeat_hours`). */
    public static function phraseHours(): int
    {
        return min(self::repeatHours(), max(0, (int) config('tv.commentary.repeat_phrase_hours', 6)));
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
        $lines = [];

        foreach ($this->walk($text, $now) as $sentences) {
            $kept = array_column(array_filter($sentences, fn (array $pair): bool => $pair[1] === self::FRESH), 0);

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The sentences of a script that repeat what was said (withoutRepeats() would drop them), in order: what a
     * writer is asked to say anew before the script reaches the queue (plan "TWENTY ONE Radio" P8).
     *
     * @return list<string>
     */
    public function repeated(string $text, CarbonImmutable $now): array
    {
        $repeated = [];

        foreach ($this->walk($text, $now) as $sentences) {
            array_push($repeated, ...array_column(array_filter($sentences, fn (array $pair): bool => $pair[1] === self::REPEAT), 0));
        }

        return $repeated;
    }

    /**
     * The script up to its first repeated sentence, never with a hole (P8, measured on prod 2026-10-12: dropping
     * repeats from the middle left 277 of 746 solo scene scripts with one sentence and many a sentence without
     * the one it built on, "Denn …", "Der Ball liegt also bei euch …"); an empty string when nothing comes
     * before it, or when the script was cut and fewer than `$minWhenCut` sentences are left (a setup without
     * its point). `$cut` tells whether a repeat ended it.
     *
     * @param-out bool $cut
     */
    public function coherent(string $text, CarbonImmutable $now, int $minWhenCut = 1, ?bool &$cut = null): string
    {
        $lines = [];
        $count = 0;
        $cut = false;

        foreach ($this->walk($text, $now) as $sentences) {
            $kept = [];

            foreach ($sentences as [$sentence, $state]) {
                if ($state === self::REPEAT) {
                    $cut = true;
                    $lines[] = implode(' ', $kept);
                    $count += count($kept);

                    break 2;
                }

                if ($state === self::FRESH) {
                    $kept[] = $sentence;
                }
            }

            $count += count($kept);
            $lines[] = implode(' ', $kept);
        }

        $lines = array_values(array_filter($lines, fn (string $line): bool => $line !== ''));

        return $count === 0 || ($cut && $count < $minWhenCut) ? '' : implode("\n", $lines);
    }

    /**
     * Every sentence of the script per line with its state: FRESH, REPEAT (said within `repeat_hours` or earlier
     * in the same script; not counted as said) or EMPTY (no word left, a marker alone).
     *
     * @return list<list<array{0: string, 1: string}>>
     */
    private function walk(string $text, CarbonImmutable $now): array
    {
        $walked = [];

        if (self::repeatHours() === 0) {
            foreach (ScriptGuard::lines($text) as $line) {
                $walked[] = array_map(fn (string $sentence): array => [$sentence, self::FRESH], ScriptGuard::runs($line));
            }

            return $walked;
        }

        $said = $this->said($now);
        $since = $now->subHours(self::repeatHours())->getTimestamp();
        $said = array_values(array_filter($said, fn (array $entry): bool => $entry['at'] >= $since));
        $phraseSince = $now->subHours(self::phraseHours())->getTimestamp();
        $grams = [];
        foreach (array_filter($said, fn (array $entry): bool => $entry['at'] >= $phraseSince) as $entry) {
            foreach (self::grams($entry['words']) as $gram) {
                $grams[$gram] = ($grams[$gram] ?? 0) + 1;
            }
        }

        foreach (ScriptGuard::lines($text) as $line) {
            $pairs = [];

            foreach (ScriptGuard::runs($line) as $sentence) {
                $entry = self::entry($sentence, $now);
                $state = $entry['words'] === [] ? self::EMPTY : (self::repeats($entry, $said) || self::wornPhrase($entry['words'], $grams) ? self::REPEAT : self::FRESH);
                // A repeated call of a name or a one-word cry ("El Presidento Ben!" in front of a chat reply) carries no
                // setup for what follows: it leaves the script without ending it.
                $state = $state === self::REPEAT && count($entry['words']) <= self::CALL_WORDS ? self::EMPTY : $state;
                $pairs[] = [$sentence, $state];

                if ($state === self::FRESH) {
                    $said[] = $entry;
                    foreach (self::grams($entry['words']) as $gram) {
                        $grams[$gram] = ($grams[$gram] ?? 0) + 1;
                    }
                }
            }

            $walked[] = $pairs;
        }

        return $walked;
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
     * Whether a sentence carries a phrase with content (GRAM_CONTENT_WORDS) said GRAM_LIMIT times already.
     *
     * @param  list<string>  $words
     * @param  array<string, int>  $grams
     */
    private static function wornPhrase(array $words, array $grams): bool
    {
        foreach (self::grams($words) as $gram) {
            if (($grams[$gram] ?? 0) >= self::GRAM_LIMIT
                && count(array_diff(explode(' ', $gram), self::FUNCTION_WORDS)) >= self::GRAM_CONTENT_WORDS) {
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
