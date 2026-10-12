<?php

namespace Tests\Support;

use App\Support\Caster\Blocks\DayPlanner;
use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;

/**
 * A second-by-second model of one broadcast day of TWENTY ONE Radio (night
 * stock render, day air) at a VoiceStudio real-time factor `$rtf` (measured
 * 2026-10-12: 1.0 to 1.5 s of GPU per spoken second). The decisions are the
 * real ones (DayPlanner::whenIdle(), topUpBeforeFresh(), replayAllowed(),
 * QuietHours, the stock and replay config); the demand is an assumption,
 * named in demand(): what a day of the radio programme asks the GPU for.
 *
 * Model limits: one GPU (VoiceStudio), an urgent item is voiced at the next
 * chunk boundary of a running render (about 10 s of speech, every urgent
 * poll), the air plays items back to back (blocks flow on), a slot item
 * cuts in at the next sentence end (8 s), a reaction at the next segment
 * boundary (every 90 s) or after `chat.max_hold_seconds`, a cut item
 * resumes after. LLM writing time is a fixed lead (`write_seconds`).
 */
final class DayStockSimulator
{
    /** @var list<array{kind: string, priority: int, speech: float, available: int, airs_at: ?int, arrived: int}> */
    private array $jobs = [];

    /** @var list<array{kind: string, seconds: float, airs_at: ?int, ready_at: int, arrived: int, played: float}> */
    private array $spool = [];

    /** @var list<float> */
    private array $stock = [];

    /** @var array<int, array{fresh: float, stock: float, replay: float, music: float}> */
    public array $hours = [];

    /** @var list<float> */
    public array $chatLatency = [];

    /** @var list<float> */
    public array $slotLateness = [];

    public float $stockAtDayStart = 0.0;

    public float $stockRenderedNight = 0.0;

    public float $stockRenderedDay = 0.0;

    public int $emptySecondsDay = 0;

    public float $replayDay = 0.0;

    public float $voiceDay = 0.0;

    public function __construct(private readonly float $rtf = 1.2, private readonly int $seed = 21, private readonly float $blockSeconds = 270.0) {}

    /**
     * Runs from 01:00 (the stock window opens) to 24:00 Berlin of `$day`.
     */
    public function run(string $day = '2026-10-13'): self
    {
        mt_srand($this->seed);
        $start = CarbonImmutable::parse($day.' 01:00', 'Europe/Berlin');
        $end = CarbonImmutable::parse($day.' 24:00', 'Europe/Berlin');
        $this->demand($start, $end);

        $gpu = null;
        $air = null;
        $lastPoll = 0;
        $dayStart = CarbonImmutable::parse($day.' 09:00', 'Europe/Berlin')->getTimestamp();

        for ($t = $start->getTimestamp(); $t < $end->getTimestamp(); $t++) {
            $now = CarbonImmutable::createFromTimestamp($t);
            $quiet = QuietHours::active($now);
            $stockFull = array_sum($this->stock) >= (float) config('tv.commentary.blocks.stock.target_hours', 6) * 3600;

            if ($t === $dayStart) {
                $this->stockAtDayStart = array_sum($this->stock);
            }

            // GPU: finish, then pick (urgent first, then fresh talk, then the idle steps).
            if ($gpu !== null) {
                $gpu['left']--;

                // An urgent item waits at most until the next chunk boundary / urgent poll of a running render.
                if ($gpu['kind'] === 'stock' || $gpu['kind'] === 'talk') {
                    if ($t - $lastPoll >= 15) {
                        $lastPoll = $t;
                        $urgent = $this->takeJob($t, true);

                        if ($urgent !== null) {
                            $this->deliver($urgent, $t + (int) ceil($urgent['speech'] * $this->rtf));
                        }

                        $this->keepAirFed($t, $quiet);
                    }
                }

                if ($gpu['left'] <= 0) {
                    if ($gpu['kind'] === 'stock') {
                        $this->stock[] = $gpu['speech'];
                        $t < $dayStart ? $this->stockRenderedNight += $gpu['speech'] : $this->stockRenderedDay += $gpu['speech'];
                    } else {
                        $this->deliver($gpu['job'], $t);
                    }

                    $gpu = null;
                }
            }

            if ($gpu === null) {
                $job = $this->takeJob($t, false);

                if ($job !== null) {
                    if (DayPlanner::topUpBeforeFresh($this->spoolSeconds(), $this->stock !== [], $quiet)) {
                        $this->uploadStock($t);
                    }

                    $gpu = ['kind' => $job['priority'] > 0 ? 'urgent' : 'talk', 'left' => (int) ceil($job['speech'] * $this->rtf), 'job' => $job, 'speech' => $job['speech']];
                } else {
                    foreach (DayPlanner::whenIdle($this->spoolSeconds(), $this->stock !== [], $stockFull, $quiet, $quiet) as $step) {
                        if ($step === DayPlanner::UPLOAD_STOCK && $this->uploadStock($t)) {
                            break;
                        }

                        if ($step === DayPlanner::REPLAY && ! $quiet && $this->replay($t)) {
                            break;
                        }

                        if ($step === DayPlanner::RENDER_STOCK) {
                            $speech = $this->blockSeconds * (0.67 + 0.66 * mt_rand(0, 1000) / 1000);
                            $gpu = ['kind' => 'stock', 'left' => (int) ceil($speech * $this->rtf), 'speech' => $speech];
                            break;
                        }

                        if ($step === DayPlanner::WAIT) {
                            break;
                        }
                    }
                }
            }

            // Air (day only; the night air keeps the quiet hours' rhythm and is not part of the budget).
            if ($quiet) {
                // The night air takes what it gets (one clip every ten minutes, the bulletin at :00); not measured here.
                $this->spool = array_values(array_filter($this->spool, fn (array $item): bool => $item['airs_at'] !== null && $item['airs_at'] > $t));

                continue;
            }

            $air = $this->airStep($t, $air);
            $hour = (int) $now->setTimezone('Europe/Berlin')->format('G');
            $this->hours[$hour] ??= ['fresh' => 0.0, 'stock' => 0.0, 'replay' => 0.0, 'music' => 0.0];
            $source = $air === null ? 'music' : (in_array($air['kind'], ['stock', 'replay'], true) ? $air['kind'] : 'fresh');
            $this->hours[$hour][$source]++;

            if ($source === 'music' && $this->spoolSeconds() <= 0) {
                $this->emptySecondsDay++;
            }

            if ($source !== 'music') {
                $this->voiceDay++;
                $this->replayDay += $source === 'replay' ? 1 : 0;
            }
        }

        return $this;
    }

    /** The assumed demand of a day (see the class doc): bulletins, sport and weather, news talk, rubrics, teasers, chat, live moments, fresh talk. */
    private function demand(CarbonImmutable $start, CarbonImmutable $end): void
    {
        for ($hour = $start->copy(); $hour < $end; $hour = $hour->addHour()) {
            $at = $hour->getTimestamp();
            $quiet = QuietHours::active($hour);
            $this->slotJob('bulletin', 150, $at, 75);

            if ($quiet) {
                // The night air: one clip every ten minutes, written ahead.
                for ($m = 5; $m < 60; $m += 10) {
                    $this->jobs[] = ['kind' => 'night', 'priority' => 0, 'speech' => 30.0, 'available' => $at + 60 * $m - 240, 'airs_at' => null, 'arrived' => $at + 60 * $m - 240];
                }

                $this->chat($at, 1);

                continue;
            }

            $this->slotJob('sport_weather', 90, $at + 154, 70);
            $this->slotJob('news_talk', 240, $at + 250, 70);
            $this->slotJob('rubric', 120, $at + 900, 70);
            $this->slotJob('headlines', 90, $at + 1800, 75);
            $this->slotJob('rubric', 120, $at + 2700, 70);
            $this->slotJob('teaser', 15, $at + 1500, 72);
            $this->slotJob('teaser', 15, $at + 3300, 72);
            $this->chat($at, 6);

            for ($i = 0; $i < 2; $i++) {
                $when = $at + mt_rand(0, 3599);
                $this->jobs[] = ['kind' => 'live', 'priority' => 100, 'speech' => 15.0, 'available' => $when + 10, 'airs_at' => null, 'arrived' => $when];
            }

            // Fresh talk (news segments every 10 min, a callback every 12 min, a history segment an hour).
            foreach ([[600, 60.0], [720, 40.0], [3600, 120.0]] as [$every, $speech]) {
                for ($s = 0; $s < 3600; $s += $every) {
                    $this->jobs[] = ['kind' => 'talk', 'priority' => 0, 'speech' => $speech, 'available' => $at + $s, 'airs_at' => null, 'arrived' => $at + $s];
                }
            }
        }
    }

    private function slotJob(string $kind, float $speech, int $airsAt, int $priority): void
    {
        $this->jobs[] = ['kind' => $kind, 'priority' => $priority, 'speech' => $speech, 'available' => $airsAt - 420, 'airs_at' => $airsAt, 'arrived' => $airsAt - 420];
    }

    private function chat(int $hourStart, int $perHour): void
    {
        for ($i = 0; $i < $perHour; $i++) {
            $when = $hourStart + mt_rand(0, 3599);
            $this->jobs[] = ['kind' => 'chat', 'priority' => 100, 'speech' => 20.0, 'available' => $when + 15, 'airs_at' => null, 'arrived' => $when];
        }
    }

    /**
     * @return array{kind: string, priority: int, speech: float, available: int, airs_at: ?int, arrived: int}|null
     */
    private function takeJob(int $t, bool $urgentOnly): ?array
    {
        $best = null;

        foreach ($this->jobs as $key => $job) {
            // Fresh talk and the night clips expire unvoiced after half an hour (news.max_age_minutes).
            if ($job['priority'] === 0 && $t - $job['available'] > 1800) {
                unset($this->jobs[$key]);

                continue;
            }

            if ($job['available'] > $t || ($urgentOnly && $job['priority'] === 0)) {
                continue;
            }

            if ($best === null || $job['priority'] > $this->jobs[$best]['priority']) {
                $best = $key;
            }
        }

        if ($best === null) {
            return null;
        }

        $job = $this->jobs[$best];
        unset($this->jobs[$best]);

        return $job;
    }

    /** @param  array{kind: string, priority: int, speech: float, available: int, airs_at: ?int, arrived: int}  $job */
    private function deliver(array $job, int $readyAt): void
    {
        $this->spool[] = ['kind' => $job['kind'], 'seconds' => $job['speech'], 'airs_at' => $job['airs_at'], 'ready_at' => $readyAt, 'arrived' => $job['arrived'], 'played' => 0.0];
    }

    private function uploadStock(int $t): bool
    {
        if ($this->stock === [] || QuietHours::active(CarbonImmutable::createFromTimestamp($t))) {
            return false;
        }

        $this->spool[] = ['kind' => 'stock', 'seconds' => array_shift($this->stock), 'airs_at' => null, 'ready_at' => $t, 'arrived' => $t, 'played' => 0.0];

        return true;
    }

    private function replay(int $t): bool
    {
        $hourReplay = 0.0;

        foreach ($this->hours[(int) CarbonImmutable::createFromTimestamp($t)->setTimezone('Europe/Berlin')->format('G')] ?? [] as $source => $seconds) {
            $hourReplay += $source === 'replay' ? $seconds : 0.0;
        }

        $waiting = array_sum(array_map(fn (array $item): float => $item['kind'] === 'replay' ? $item['seconds'] - $item['played'] : 0.0, $this->spool));
        $candidate = $this->blockSeconds;

        if (! DayPlanner::replayAllowed($hourReplay + $waiting, $this->replayDay + $waiting, $this->voiceDay, $candidate)) {
            return false;
        }

        $this->spool[] = ['kind' => 'replay', 'seconds' => $candidate, 'airs_at' => null, 'ready_at' => $t, 'arrived' => $t, 'played' => 0.0];

        return true;
    }

    private function keepAirFed(int $t, bool $quiet): void
    {
        if (DayPlanner::topUpBeforeFresh($this->spoolSeconds(), $this->stock !== [], $quiet)) {
            $this->uploadStock($t);
        } elseif ($this->stock === [] && ! $quiet && $this->spoolSeconds() < (float) config('tv.commentary.blocks.replay.below_seconds', 120)) {
            $this->replay($t);
        }
    }

    private function spoolSeconds(): float
    {
        return array_sum(array_map(fn (array $item): float => $item['airs_at'] === null ? $item['seconds'] - $item['played'] : 0.0, $this->spool));
    }

    /**
     * One second of air: keep playing, cut for a slot or an overdue reaction, else pick the next item.
     *
     * @param  array<string, mixed>|null  $air
     * @return array<string, mixed>|null
     */
    private function airStep(int $t, ?array $air): ?array
    {
        $slot = $this->find(fn (array $item): bool => $item['airs_at'] !== null && $item['airs_at'] <= $t && $item['ready_at'] <= $t);
        $live = $this->find(fn (array $item): bool => in_array($item['kind'], ['chat', 'live'], true) && $item['ready_at'] <= $t);

        if ($air !== null) {
            $key = $air['key'];
            $item = $this->spool[$key];
            $intoItem = (int) $item['played'];
            $cutForSlot = $slot !== null && $item['airs_at'] === null && $intoItem % 8 === 0;
            $cutForLive = $live !== null && $item['airs_at'] === null && ! in_array($item['kind'], ['chat', 'live'], true)
                && ($intoItem % 90 === 0 || $t - $this->spool[$live]['ready_at'] >= (int) config('tv.commentary.chat.max_hold_seconds', 600));

            if (! $cutForSlot && ! $cutForLive) {
                $this->spool[$key]['played']++;

                if ($this->spool[$key]['played'] >= $this->spool[$key]['seconds']) {
                    unset($this->spool[$key]);

                    return null;
                }

                return $air;
            }
        }

        $next = $slot ?? $live
            ?? $this->find(fn (array $item): bool => ! in_array($item['kind'], ['stock', 'replay'], true) && $item['airs_at'] === null && $item['ready_at'] <= $t)
            ?? $this->find(fn (array $item): bool => $item['kind'] === 'stock' && $item['ready_at'] <= $t)
            ?? $this->find(fn (array $item): bool => $item['kind'] === 'replay' && $item['ready_at'] <= $t);

        if ($next === null) {
            return null;
        }

        $item = $this->spool[$next];

        if ($item['played'] <= 0.0) {
            if (in_array($item['kind'], ['chat', 'live'], true)) {
                $this->chatLatency[] = $t - $item['arrived'];
            }

            if ($item['airs_at'] !== null && in_array($item['kind'], ['bulletin', 'headlines'], true)) {
                $this->slotLateness[] = $t - $item['airs_at'];
            }
        }

        $this->spool[$next]['played']++;

        return ['key' => $next, 'kind' => $item['kind']];
    }

    private function find(callable $test): ?int
    {
        foreach ($this->spool as $key => $item) {
            if ($test($item)) {
                return $key;
            }
        }

        return null;
    }
}
