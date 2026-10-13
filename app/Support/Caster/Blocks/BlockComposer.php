<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterScript;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\SportWeather;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The running order of a programme block (plan "TWENTY ONE Radio", P6; user
 * 2026-10-12: "wie ein echter Radiosender"): which of the Anchor's jingle
 * categories stand where between the scripts. Pure: no audio, no I/O.
 *
 *   news block    news jingle (headlines at :30) -> bulletin -> Sport und Wetter (no jingle between) -> back-announce
 *                 -> the talk after it
 *   rubric block  the rubric's opener (rubric-<topic>) -> the rubric (its parts back to back)
 *   teaser        coming-up -> teaser
 *   talk block    station ID, time of day or talk opener -> scripts; between two scripts a jingle of the
 *                 next one's kind (Geschichtsstunde before a history segment, coming-up before a teaser),
 *                 else a sweeper on about `sweeper_share` of the transitions, else a breath
 *
 * Never a jingle between two parts of one story, nor in front of a block
 * that goes on with a story of the block before. A category list names its
 * fallbacks in order (JingleLibrary::pick() takes the first that has one).
 */
final class BlockComposer
{
    public const STEP_JINGLE = 'jingle';

    public const STEP_SCRIPT = 'script';

    /**
     * @param  list<array<array-key, mixed>>  $scripts  claimed scripts (the claim's `script` payloads), the first the lead
     * @param  CarbonImmutable  $airAt  when the block is expected on air (the time-of-day jingle)
     * @param  bool  $evergreen  a block for the day stock (DayStock): it airs at an unknown hour, so never a time-of-day jingle
     * @param  (Closure(): float)|null  $random  a number in [0, 1), mt_rand by default
     * @return list<array{type: string, categories?: list<string>, index?: int}>
     */
    public static function plan(array $scripts, CarbonImmutable $airAt, float $sweeperShare = 0.5, ?Closure $random = null, ?BroadcastClock $clock = null, bool $evergreen = false): array
    {
        $random ??= fn (): float => mt_rand(0, 999) / 1000;
        $steps = [];

        foreach ($scripts as $index => $script) {
            $kind = (string) ($script['kind'] ?? '');
            $previous = $index > 0 ? $scripts[$index - 1] : null;
            $sameStory = $previous !== null && is_array($script['part'] ?? null) && is_array($previous['part'] ?? null)
                && ($script['part']['group'] ?? null) === ($previous['part']['group'] ?? null);

            if ($index === 0 && is_array($script['part'] ?? null) && (int) ($script['part']['index'] ?? 1) > 1) {
                // A block that goes on with a story of the block before (the next ten minutes of the talk show) is no new
                // programme: no opener (P20 probe: three format jingles in the middle of one episode).
            } elseif ($index === 0) {
                $steps[] = ['type' => self::STEP_JINGLE, 'categories' => self::opener($script, $scripts, $airAt, $random, $clock, $evergreen)];
            } elseif ($previous !== null && ($previous['kind'] ?? null) === CasterScript::BULLETIN && ($script['topic'] ?? null) === SportWeather::TOPIC) {
                // Sport und Wetter belongs to the news: it follows the bulletin without a jingle.
            } elseif ($previous !== null && (($previous['kind'] ?? null) === CasterScript::BULLETIN || (! $sameStory && ($previous['topic'] ?? null) === SportWeather::TOPIC))) {
                $steps[] = ['type' => self::STEP_JINGLE, 'categories' => ['back-announce', 'sweeper']];
            } elseif (! $sameStory) {
                $categories = self::between($script, $kind);

                if ($categories !== null) {
                    $steps[] = ['type' => self::STEP_JINGLE, 'categories' => $categories];
                } elseif ($random() < $sweeperShare) {
                    $steps[] = ['type' => self::STEP_JINGLE, 'categories' => ['sweeper', 'station']];
                }
            }

            $steps[] = ['type' => self::STEP_SCRIPT, 'index' => $index];
        }

        return $steps;
    }

    /**
     * The jingle a block opens with.
     *
     * @param  array<array-key, mixed>  $lead
     * @param  list<array<array-key, mixed>>  $scripts
     * @param  Closure(): float  $random
     * @return list<string>
     */
    private static function opener(array $lead, array $scripts, CarbonImmutable $airAt, Closure $random, ?BroadcastClock $clock, bool $evergreen = false): array
    {
        $kind = (string) ($lead['kind'] ?? '');

        if ($kind === CasterScript::BULLETIN) {
            $slotAt = is_string($lead['airs_at'] ?? null) ? CarbonImmutable::parse($lead['airs_at']) : $airAt;
            $type = ($clock ?? BroadcastClock::fromConfig())->slotAt($slotAt)['type'];

            return $type === BroadcastClock::HEADLINES ? ['headlines', 'news'] : ['news'];
        }

        $between = self::between($lead, $kind);

        if ($between !== null) {
            return $between;
        }

        $duet = collect($scripts)->contains(fn (array $script): bool => is_array($script['speakers'] ?? null) && count(array_unique(array_filter($script['speakers'], 'is_string'))) > 1);
        $roll = $random();

        return match (true) {
            $roll < 0.4 => ['station', 'sweeper'],
            $roll < 0.75 && ! $evergreen => ['time-'.self::daypart($airAt), 'station'],
            $duet => ['talk', 'station'],
            default => ['sweeper', 'station'],
        };
    }

    /**
     * The jingle a script of `$kind` brings with it, or null when it has none of its own.
     *
     * @param  array<array-key, mixed>  $script
     * @return list<string>|null
     */
    private static function between(array $script, string $kind): ?array
    {
        $topic = is_string($script['topic'] ?? null) ? $script['topic'] : '';

        return match ($kind) {
            CasterScript::BULLETIN => ['news'],
            // The news talk after a bulletin (P5, topic nachrichten_talk) is talk; every other rubric has its opener.
            'rubric' => $topic === 'nachrichten_talk' ? ['talk', 'sweeper'] : ['rubric-'.$topic, 'sweeper'],
            'teaser' => ['coming-up', 'sweeper'],
            // The announcement and back-announce of a podcast replay (P12): the Anchor's podcast opener follows on the server.
            CasterScript::PODCAST_INTRO, CasterScript::PODCAST_OUTRO => [],
            CasterScript::HISTORY => ['rubric-geschichte', 'sweeper'],
            // A programme of the day stock opens with its format's jingle line (StockScripts::jinglesFor()).
            default => StockScripts::jinglesFor($topic),
        };
    }

    /** The part of the day in Berlin a time-of-day jingle names. */
    public static function daypart(CarbonImmutable $at): string
    {
        $hour = (int) $at->setTimezone((string) config('tv.commentary.spool.quiet_hours.timezone', 'Europe/Berlin'))->format('G');

        return match (true) {
            $hour >= 5 && $hour < 11 => 'morning',
            $hour >= 11 && $hour < 17 => 'day',
            $hour >= 17 && $hour < 23 => 'evening',
            default => 'night',
        };
    }
}
