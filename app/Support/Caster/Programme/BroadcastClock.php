<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;

/**
 * The broadcast clock of TWENTY ONE Radio (plan "TWENTY ONE Radio", P1;
 * `tv.commentary.programme`): one hour of fixed slots in Berlin wall-clock
 * time, each from its minute to the next slot's (news at :00, headlines at
 * :30, talk and rubrics between them). SceneDirector and ScriptQueue ask it
 * what is on now and what comes next.
 *
 * DST-safe: a slot is a minute of the local hour, and its start is counted
 * back from the moment asked about in seconds, never rebuilt from a wall
 * clock time (Berlin's offsets are whole hours, so the minute of the hour is
 * the same in UTC). On the night the clocks go back, the hour from two to
 * three runs twice and so do its slots; on the night they go forward, the
 * slots of the skipped hour never come.
 *
 * In the quiet hours (QuietHours, 01 to 09) a slot without `quiet` turns into
 * talk: at night only the full-hour bulletin and the ten-minute rhythm.
 *
 * A show window (P20; user 2026-10-13: "wenn im Programm steht TalkShow von
 * 19:00 bis 19:30, dann muss die TalkShow auch 30 min gehen!"): a long format
 * with `slot_minutes` (Shows::windows()) owns its window from its rubric
 * slot on: every slot inside it is the show's rubric slot, so no headlines
 * bulletin is written or aired there. The window ends at the next full hour at
 * the latest: the news at :00 is never swallowed. Not in the quiet hours.
 *
 * @phpstan-type Slot array{type: string, minute: int, label: string, stinger: bool, starts_at: CarbonImmutable, key: int}
 */
final class BroadcastClock
{
    public const NEWS = 'news';

    public const HEADLINES = 'headlines';

    public const TALK = 'talk';

    public const RUBRIC = 'rubric';

    /** The slot types that are a news bulletin (NewsBulletin). */
    public const BULLETINS = [self::NEWS, self::HEADLINES];

    /** An hour: the longest a show window lasts, and the most a clock change moves its start (see windowAt()). */
    private const WINDOW_REACH = 3600;

    private const HOURS = ['null', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun', 'zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn', 'zwanzig', 'einundzwanzig', 'zweiundzwanzig', 'dreiundzwanzig'];

    private const UNITS = ['', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun'];

    private const TEENS = ['zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn'];

    private const TENS = [2 => 'zwanzig', 3 => 'dreißig', 4 => 'vierzig', 5 => 'fünfzig'];

    /** @var list<array{minute: int, type: string, label: string, stinger: bool, quiet: bool}> */
    private array $slots;

    /**
     * The show windows with what does not depend on the moment asked about: the local start's hour and minute, the
     * window's minutes, the clock's slot at that minute and the start as seconds of the local day.
     *
     * @var list<array{hour: int, minute: int, minutes: int, slot: array{minute: int, type: string, label: string, stinger: bool, quiet: bool}, seconds: int}>
     */
    private array $windowStarts = [];

    /**
     * @param  array<array-key, mixed>  $slots  `tv.commentary.programme.slots`
     * @param  array<string, int>  $windows  show windows: the local start "HH:MM" to its minutes (Shows::windows())
     */
    public function __construct(array $slots, private readonly string $timezone = 'Europe/Berlin', private readonly bool $enabled = true, private readonly array $windows = [])
    {
        $entries = [];

        foreach ($slots as $slot) {
            if (! is_array($slot) || ! is_numeric($slot['minute'] ?? null) || ! is_string($slot['type'] ?? null)) {
                continue;
            }

            $minute = (int) $slot['minute'];
            if ($minute < 0 || $minute > 59) {
                continue;
            }

            $entries[] = self::slot($minute, $slot);
        }

        // One slot per minute, the last entry for a minute winning, in minute order.
        $clean = array_column($entries, null, 'minute');
        ksort($clean);
        $ordered = array_values($clean);
        // No slot at all: the whole hour is talk.
        $this->slots = $ordered === [] ? [['minute' => 0, 'type' => self::TALK, 'label' => 'Talk', 'stinger' => false, 'quiet' => true]] : $ordered;

        foreach ($windows as $time => $minutes) {
            if (preg_match('/^(\d{2}):(\d{2})$/', (string) $time, $match) !== 1) {
                continue;
            }

            $index = array_search((int) $match[2], array_column($this->slots, 'minute'), true);

            // A long show also starts on a talk slot (P21, user 2026-10-13: right after the news at :03).
            if ($index === false || ! in_array($this->slots[$index]['type'], [self::RUBRIC, self::TALK], true)) {
                continue;
            }

            $this->windowStarts[] = ['hour' => (int) $match[1], 'minute' => (int) $match[2], 'minutes' => $minutes, 'slot' => $this->slots[$index], 'seconds' => (int) $match[1] * 3600 + (int) $match[2] * 60];
        }
    }

    /**
     * One programme slot from its config entry, at a valid minute.
     *
     * @param  array<array-key, mixed>  $slot
     * @return array{minute: int, type: string, label: string, stinger: bool, quiet: bool}
     */
    private static function slot(int $minute, array $slot): array
    {
        $type = (string) $slot['type'];
        $label = $slot['label'] ?? null;

        return ['minute' => $minute, 'type' => $type, 'label' => is_scalar($label) ? (string) $label : $type, 'stinger' => ($slot['stinger'] ?? false) === true, 'quiet' => ($slot['quiet'] ?? false) === true];
    }

    public static function fromConfig(): self
    {
        return new self((array) config('tv.commentary.programme.slots', []), (string) config('tv.commentary.programme.timezone', 'Europe/Berlin'),
            config('tv.commentary.programme.enabled') === true, Shows::windows());
    }

    /** Whether the stream runs by the clock at all (`programme.enabled`); off, there are no bulletins. */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The slot on air at `$at`: its type (in the quiet hours a slot without
     * `quiet` is talk), its minute of the hour, its start and a key that is
     * unique per slot start (the start's timestamp).
     *
     * @return Slot
     */
    public function slotAt(CarbonImmutable $at): array
    {
        $window = $this->windowAt($at);

        if ($window !== null) {
            return $window;
        }

        $local = $at->setTimezone($this->timezone);
        $minute = (int) $local->format('i');
        $slot = $this->slots[count($this->slots) - 1];

        foreach ($this->slots as $candidate) {
            if ($candidate['minute'] <= $minute) {
                $slot = $candidate;
            }
        }

        // Before the hour's first slot the last slot of the hour before is still on.
        $back = $slot['minute'] <= $minute ? $minute - $slot['minute'] : $minute + 60 - $slot['minute'];
        $start = CarbonImmutable::createFromTimestamp($at->getTimestamp() - $back * 60 - (int) $local->format('s'));
        $quiet = ! $slot['quiet'] && QuietHours::active($start);

        return [
            'type' => $quiet ? self::TALK : $slot['type'],
            'minute' => $slot['minute'],
            'label' => $quiet ? 'Talk' : $slot['label'],
            'stinger' => ! $quiet && $slot['stinger'],
            'starts_at' => $start,
            'key' => $start->getTimestamp(),
        ];
    }

    /** Whether a show window (Shows::windows()) holds `$at`. */
    public function inShowWindow(CarbonImmutable $at): bool
    {
        return $this->windowAt($at) !== null;
    }

    /**
     * The show window on air at `$at` as its rubric slot (from the window's start), or null: no window holds `$at`, it
     * starts in the quiet hours, or neither a rubric nor a talk slot of the clock begins at its start.
     *
     * @return Slot|null
     */
    private function windowAt(CarbonImmutable $at): ?array
    {
        if ($this->windowStarts === []) {
            return null;
        }

        $local = $at->setTimezone($this->timezone);
        // upcoming() asks this for every minute of the next two hours on every poll of the stream, so the Carbon
        // arithmetic below runs only for a window whose start lies within reach of `$at` in wall-clock seconds: a window
        // lasts an hour at the most (it ends at the next full hour), and a clock change moves a start by an hour either
        // way, so the wall clock is at most an hour before the start and two hours after it. Whatever this lets through
        // is decided by the exact code below.
        $now = (int) $local->format('G') * 3600 + (int) $local->format('i') * 60 + (int) $local->format('s');

        foreach ($this->windowStarts as $window) {
            $since = $now - $window['seconds'];

            if (($since < -self::WINDOW_REACH || $since > self::WINDOW_REACH * 2) && ($since + 86400 < -self::WINDOW_REACH || $since + 86400 > self::WINDOW_REACH * 2)) {
                continue;
            }

            foreach ([$local, $local->subDay()] as $day) {
                $startLocal = $day->setTime($window['hour'], $window['minute']);
                // Never past the next full hour: the news at :00 stays.
                $end = min($startLocal->addMinutes(max(1, $window['minutes']))->getTimestamp(), $startLocal->startOfHour()->addHour()->getTimestamp());

                if ($at->getTimestamp() < $startLocal->getTimestamp() || $at->getTimestamp() >= $end || QuietHours::active($startLocal)) {
                    continue;
                }

                $slot = $window['slot'];
                $start = CarbonImmutable::createFromTimestamp($startLocal->getTimestamp());

                return ['type' => self::RUBRIC, 'minute' => $window['minute'], 'label' => $slot['type'] === self::RUBRIC ? $slot['label'] : 'die Sendung', 'stinger' => $slot['stinger'], 'starts_at' => $start, 'key' => $start->getTimestamp()];
            }
        }

        return null;
    }

    /**
     * The slots that start from `$from` (inclusive, to the second) within the
     * next `$minutes`, in order.
     *
     * @return list<Slot>
     */
    public function upcoming(CarbonImmutable $from, int $minutes = 60): array
    {
        $slots = [];
        $base = $from->getTimestamp() - $from->getTimestamp() % 60;

        for ($i = 0; $i <= $minutes; $i++) {
            $slot = $this->slotAt(CarbonImmutable::createFromTimestamp($base + 60 * $i));

            if ($slot['key'] >= $from->getTimestamp() && $slot['key'] <= $from->getTimestamp() + 60 * $minutes && ! isset($slots[$slot['key']])) {
                $slots[$slot['key']] = $slot;
            }
        }

        return array_values($slots);
    }

    /**
     * The next bulletin slot starting at or after `$from` (within two hours),
     * or null when the clock is off.
     *
     * @return Slot|null
     */
    public function nextBulletin(CarbonImmutable $from): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        foreach ($this->upcoming($from, 120) as $slot) {
            if (self::isBulletin($slot['type'])) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * The minutes of the hour with a slot of `$type`, in order (the rubric rotation counts them).
     *
     * @return list<int>
     */
    public function minutesOf(string $type): array
    {
        return array_column(array_filter($this->slots, fn (array $slot): bool => $slot['type'] === $type), 'minute');
    }

    public static function isBulletin(string $type): bool
    {
        return in_array($type, self::BULLETINS, true);
    }

    /**
     * The local time of `$at` as spoken words: "vierzehn Uhr", "vierzehn Uhr
     * dreißig", "ein Uhr", "null Uhr fünf".
     */
    public function spokenTime(CarbonImmutable $at): string
    {
        $local = $at->setTimezone($this->timezone);
        $minute = (int) $local->format('i');

        return self::HOURS[(int) $local->format('G')].' Uhr'.($minute === 0 ? '' : ' '.self::number($minute));
    }

    /** A number from 1 to 59 in German words ("eins", "einundzwanzig", "dreißig"). */
    public static function number(int $value): string
    {
        return match (true) {
            $value === 1 => 'eins',
            $value < 10 => self::UNITS[$value],
            $value < 20 => self::TEENS[$value - 10],
            default => ($value % 10 === 0 ? '' : self::UNITS[$value % 10].'und').self::TENS[intdiv($value, 10)],
        };
    }
}
