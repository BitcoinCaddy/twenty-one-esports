<?php

namespace App\Support\Caster\Blocks;

use App\Support\Caster\Programme\TalkShow;

/**
 * The studio around a show rendered on the workstation (plan "TWENTY ONE
 * Radio", P20; user 2026-10-13: "Was ist mit Klatschgeräuschen und
 * Mood-Bett ganz leise im Hintergrund bei Shows? Also auch bei der
 * Talkshow?" and "es fehlt auch so typische Talkshow-Musik-Geklimper am
 * Anfang und Ende … aber bitte auf keinen Fall zu laut").
 *
 * Decides WHERE, never how loud (BlockRenderer mixes it):
 *
 *  - applause (`idents.applause`) only in a format of `formats`: after the
 *    opening (the talk show: the host's welcome after the cold open; any
 *    other show: its first chunk), after a hit (the talk show host's
 *    reaction, TalkShow::HIT; in any show a line ending in "!" that the next
 *    turn answers with [laughter]) and at the close; the open and the close
 *    first, then the hits in order, at most `max_per_show`, never two within
 *    `min_gap_seconds` (on the shortest possible timing of the script);
 *  - the talk theme (`idents.talk_theme`) only in its `formats`: the intro
 *    before the host's welcome, the sting before the first fact check and
 *    the first closing round (TalkShow::ACT_BREAK), the outro at the end.
 *
 * A position is a chunk of the script (CasterFetcher::plannedChunks()):
 * "after chunk n" is the pause behind it, "before chunk n" the pause in
 * front of it. Off, an unknown format, or a script of one chunk without an
 * end: nothing.
 */
final class StudioAudience
{
    public const OPEN = 'open';

    public const HIT = 'hit';

    public const CLOSE = 'close';

    public const INTRO = 'intro';

    public const STING = 'sting';

    public const OUTRO = 'outro';

    /** The format of a script's topic: a stock script's "stock:<format>" counts as its format. */
    public static function format(mixed $topic): ?string
    {
        if (! is_string($topic) || $topic === '') {
            return null;
        }

        return (string) preg_replace('/^'.preg_quote(StockScripts::TOPIC_PREFIX, '/').'/', '', $topic);
    }

    /** Whether a script of `$topic` gets applause (`applause.enabled`, its format in `applause.formats`). */
    public static function applauds(mixed $topic): bool
    {
        $settings = (array) config('tv.commentary.idents.applause', []);
        $format = self::format($topic);

        return ($settings['enabled'] ?? false) === true && $format !== null && in_array($format, (array) ($settings['formats'] ?? []), true);
    }

    /** Whether a script of `$topic` gets the talk theme (`talk_theme.enabled`, its format in `talk_theme.formats`). */
    public static function themed(mixed $topic): bool
    {
        $settings = (array) config('tv.commentary.idents.talk_theme', []);
        $format = self::format($topic);

        return ($settings['enabled'] ?? false) === true && $format !== null && in_array($format, (array) ($settings['formats'] ?? []), true);
    }

    /**
     * The applause of a script: chunk position => moment (OPEN, HIT, CLOSE), the applause sounding in the pause after
     * that chunk (after the last chunk: behind the script).
     *
     * @param  list<array{speaker: string, lines: list<string>}>  $chunks  the script's chunks in order
     * @param  list<float>  $seconds  each chunk's length
     * @param  float  $shortestGap  the shortest pause between two chunks (the cap is kept on the tightest timing)
     * @return array<int, string>
     */
    public static function applause(mixed $topic, array $chunks, array $seconds, float $shortestGap = 0.1): array
    {
        if (! self::applauds($topic) || $chunks === []) {
            return [];
        }

        $settings = (array) config('tv.commentary.idents.applause', []);
        $max = max(0, (int) ($settings['max_per_show'] ?? 4));
        $minGap = max(0.0, (float) ($settings['min_gap_seconds'] ?? 20));
        $last = count($chunks) - 1;
        $turns = self::turns($chunks);
        $talk = self::format($topic) === TalkShow::ID;
        $candidates = [];

        // The opening: the host's welcome after the cold open, or the first chunk of any other show.
        $open = $talk ? self::welcome($turns) : ['last' => 0];
        if ($open !== null && $open['last'] !== $last) {
            $candidates[] = [$open['last'], self::OPEN];
        }

        $candidates[] = [$last, self::CLOSE];

        foreach ($turns as $index => $turn) {
            $text = implode(' ', array_merge(...array_map(fn (int $position): array => $chunks[$position]['lines'], $turn['positions'])));
            $closing = (string) end($chunks[$turn['last']]['lines']);
            $next = $turns[$index + 1] ?? null;
            $answeredWithLaughter = $next !== null && preg_match('/^\s*\[laughter\]/u', (string) ($chunks[$next['first']]['lines'][0] ?? '')) === 1;

            if ($turn['last'] !== $last && (($talk && $turn['speaker'] === TalkShow::HOST && preg_match(TalkShow::HIT, $text) === 1)
                || (str_ends_with(trim(self::withoutMarkers($closing)), '!') && $answeredWithLaughter))) {
                $candidates[] = [$turn['last'], self::HIT];
            }
        }

        // When each chunk's pause starts on the tightest timing (applause only makes the pauses longer).
        $ends = [];
        $at = 0.0;
        foreach ($chunks as $position => $chunk) {
            $at += (float) ($seconds[$position] ?? 0.0);
            $ends[$position] = $at;
            $at += $shortestGap;
        }

        $chosen = [];
        foreach ($candidates as [$position, $moment]) {
            if (count($chosen) >= $max || isset($chosen[$position])) {
                continue;
            }

            $tooClose = array_filter(array_keys($chosen), fn (int $other): bool => abs($ends[$other] - $ends[$position]) < $minGap);

            if ($tooClose === []) {
                $chosen[$position] = $moment;
            }
        }

        ksort($chosen);

        return $chosen;
    }

    /**
     * The talk theme of a script: chunk position => INTRO or STING (in the pause before that chunk), and the last
     * position => OUTRO (behind the script).
     *
     * @param  list<array{speaker: string, lines: list<string>}>  $chunks
     * @return array{before: array<int, string>, outro: int|null}
     */
    public static function theme(mixed $topic, array $chunks): array
    {
        if (! self::themed($topic) || $chunks === []) {
            return ['before' => [], 'outro' => null];
        }

        $turns = self::turns($chunks);
        $before = [];
        $welcome = self::welcome($turns);

        if ($welcome !== null) {
            $before[$welcome['first']] = self::INTRO;
        }

        // The first fact check and the first closing round each open their act with the sting.
        $seen = [];
        foreach ($turns as $turn) {
            $text = implode(' ', $chunks[$turn['first']]['lines']);

            if ($turn['speaker'] !== TalkShow::HOST || $turn['first'] === 0 || isset($before[$turn['first']]) || preg_match(TalkShow::ACT_BREAK, $text, $match) !== 1) {
                continue;
            }

            $act = mb_strtolower($match[0]) === 'faktencheck' ? 'fact' : 'close';

            if (! isset($seen[$act])) {
                $seen[$act] = true;
                $before[$turn['first']] = self::STING;
            }
        }

        ksort($before);

        return ['before' => $before, 'outro' => count($chunks) - 1];
    }

    /**
     * The next variant of a moment's files (`applause.files.<moment>`) that exist in `$dir`, never `$avoid` when there
     * is another; null when there is none.
     */
    public static function applauseFile(string $moment, string $dir, ?string $avoid = null, float $random = 0.0): ?string
    {
        $files = array_values(array_filter(array_map(
            fn (mixed $file): ?string => is_string($file) && is_file(rtrim($dir, '/').'/'.basename($file)) ? rtrim($dir, '/').'/'.basename($file) : null,
            (array) config('tv.commentary.idents.applause.files.'.$moment, []),
        )));
        $others = array_values(array_filter($files, fn (string $file): bool => $file !== $avoid));
        $pool = $others === [] ? $files : $others;

        return $pool === [] ? null : $pool[(int) floor(max(0.0, min(0.999, $random)) * count($pool))];
    }

    /** A theme file (`talk_theme.intro|outro|sting`) in `$dir`, or null when unset or missing. */
    public static function themeFile(string $part, string $dir): ?string
    {
        $file = config('tv.commentary.idents.talk_theme.'.$part);
        $path = is_string($file) && $file !== '' ? rtrim($dir, '/').'/'.basename($file) : null;

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * The host's welcome: his first turn after a turn of somebody else (the cold open before it).
     *
     * @param  list<array{speaker: string, first: int, last: int, positions: list<int>}>  $turns
     * @return array{speaker: string, first: int, last: int, positions: list<int>}|null
     */
    private static function welcome(array $turns): ?array
    {
        $guestSpoke = false;

        foreach ($turns as $turn) {
            if ($turn['speaker'] !== TalkShow::HOST) {
                $guestSpoke = true;
            } elseif ($guestSpoke) {
                return $turn;
            }
        }

        return null;
    }

    /**
     * The chunks grouped into turns: consecutive chunks of one speaker.
     *
     * @param  list<array{speaker: string, lines: list<string>}>  $chunks
     * @return list<array{speaker: string, first: int, last: int, positions: list<int>}>
     */
    private static function turns(array $chunks): array
    {
        $turns = [];

        foreach ($chunks as $position => $chunk) {
            $current = array_key_last($turns);

            if ($current !== null && $turns[$current]['speaker'] === $chunk['speaker']) {
                $turns[$current]['last'] = $position;
                $turns[$current]['positions'][] = $position;
            } else {
                $turns[] = ['speaker' => $chunk['speaker'], 'first' => $position, 'last' => $position, 'positions' => [$position]];
            }
        }

        return $turns;
    }

    private static function withoutMarkers(string $line): string
    {
        return (string) preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $line);
    }
}
