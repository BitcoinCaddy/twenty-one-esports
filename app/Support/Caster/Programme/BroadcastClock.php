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

    private const HOURS = ['null', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun', 'zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn', 'zwanzig', 'einundzwanzig', 'zweiundzwanzig', 'dreiundzwanzig'];

    private const UNITS = ['', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun'];

    private const TEENS = ['zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn'];

    private const TENS = [2 => 'zwanzig', 3 => 'dreißig', 4 => 'vierzig', 5 => 'fünfzig'];

    /** @var list<array{minute: int, type: string, label: string, stinger: bool, quiet: bool}> */
    private array $slots;

    /**
     * @param  array<array-key, mixed>  $slots  `tv.commentary.programme.slots`
     */
    public function __construct(array $slots, private readonly string $timezone = 'Europe/Berlin', private readonly bool $enabled = true)
    {
        $clean = [];

        foreach ($slots as $slot) {
            if (! is_array($slot) || ! is_numeric($slot['minute'] ?? null) || ! is_string($slot['type'] ?? null)) {
                continue;
            }

            $minute = (int) $slot['minute'];
            if ($minute < 0 || $minute > 59) {
                continue;
            }

            $clean[$minute] = ['minute' => $minute, 'type' => $slot['type'], 'label' => (string) ($slot['label'] ?? $slot['type']), 'stinger' => ($slot['stinger'] ?? false) === true, 'quiet' => ($slot['quiet'] ?? false) === true];
        }

        ksort($clean);
        // No slot at all: the whole hour is talk.
        $this->slots = $clean === [] ? [['minute' => 0, 'type' => self::TALK, 'label' => 'Talk', 'stinger' => false, 'quiet' => true]] : array_values($clean);
    }

    public static function fromConfig(): self
    {
        return new self((array) config('tv.commentary.programme.slots', []), (string) config('tv.commentary.programme.timezone', 'Europe/Berlin'),
            config('tv.commentary.programme.enabled') === true);
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
