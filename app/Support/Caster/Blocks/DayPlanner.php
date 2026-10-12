<?php

namespace App\Support\Caster\Blocks;

use App\Support\Caster\AirLog;
use Carbon\CarbonImmutable;

/**
 * The hourly airtime budget of TWENTY ONE Radio (user 2026-10-12: current
 * news must never suffer, replays only as a reserve and "auf keinen Fall zu
 * viele Wiederholungen"). Pure decisions, shared by the workstation
 * (CasterFetcher), the server (CasterFetchController) and the day
 * simulation, in this order of precedence:
 *
 *  1. fresh items: what the claim hands out is voiced first (bulletins,
 *     sport and weather, news talk, dated rubrics, chat replies, live
 *     moments jump the queue by priority, and a full spool still hands them
 *     out); the fetcher asks for them every `urgent_poll_seconds` while it
 *     renders anything else;
 *  2. fresh talk the GPU still manages: any other queued script;
 *  3. night stock: with nothing to voice, a stock block goes up while the
 *     server holds less than `stock.upload_below_seconds` (during a fresh
 *     render below `stock.low_seconds`), and on air it plays after every
 *     fresh block (ClipSpool::nextBlock());
 *  4. a replay only when the spool would run dry (below
 *     `replay.below_seconds`, no stock), evergreen, behind the replay
 *     jingle, each block once within `repeat_hours` and never within
 *     `min_age_hours` of its first airing (ReplayArchive::pick()); the
 *     server takes a day replay only within replayAllowed(); beyond it,
 *     music.
 *
 * With the GPU idle and nothing fresh it renders stock: in the stock window
 * (DayStock::windowActive()), and by day while `stock.day_share` is on.
 * While a podcast replay airs (P12, `$podcastGpu` from the claim) nothing
 * goes up: the GPU renders stock (`podcast_replay.gpu` stock) or waits (idle).
 */
final class DayPlanner
{
    public const UPLOAD_STOCK = 'upload_stock';

    public const REPLAY = 'replay';

    public const RENDER_STOCK = 'render_stock';

    public const WAIT = 'wait';

    /**
     * What the workstation does when a claim gave it nothing to voice, in order of preference (a refused step
     * falls through to the next).
     *
     * @return list<string>
     */
    public static function whenIdle(float $spoolSeconds, bool $hasStock, bool $stockFull, bool $quiet, bool $stockWindow, ?string $podcastGpu = null): array
    {
        $stock = (array) config('tv.commentary.blocks.stock', []);
        $replay = (array) config('tv.commentary.blocks.replay', []);
        $stockOn = DayStock::enabled();
        $steps = [];

        if ($podcastGpu !== null) {
            return $podcastGpu === 'stock' && $stockOn && ! $stockFull ? [self::RENDER_STOCK, self::WAIT] : [self::WAIT];
        }

        if ($stockOn && ! $quiet && $hasStock && $spoolSeconds < (float) ($stock['upload_below_seconds'] ?? 900)) {
            $steps[] = self::UPLOAD_STOCK;
        }

        if (ReplayArchive::enabled() && ($quiet || ($replay['day_reserve'] ?? false) === true) && ! ($stockOn && ! $quiet && $hasStock)
            && $spoolSeconds < (float) ($replay['below_seconds'] ?? 120)) {
            $steps[] = self::REPLAY;
        }

        if ($stockOn && ! $stockFull && ($stockWindow || (! $quiet && ($stock['day_share'] ?? false) === true))) {
            $steps[] = self::RENDER_STOCK;
        }

        return [...$steps, self::WAIT];
    }

    /** Whether a stock block goes up before a fresh script is voiced (the server runs low meanwhile). */
    public static function topUpBeforeFresh(float $spoolSeconds, bool $hasStock, bool $quiet): bool
    {
        return DayStock::enabled() && ! $quiet && $hasStock && $spoolSeconds < (float) config('tv.commentary.blocks.stock.low_seconds', 300);
    }

    /**
     * Whether the server takes a day replay of `$candidate` seconds: within
     * `hourly_share` of the hour (3600 s) with the replays aired and waiting
     * this hour, and within `daily_share` of the day's voice airtime.
     * Fails closed: a share of 0 or less takes nothing.
     */
    public static function replayAllowed(float $hourReplay, float $dayReplay, float $dayVoice, float $candidate): bool
    {
        $settings = (array) config('tv.commentary.blocks.replay', []);
        $hourly = (float) ($settings['hourly_share'] ?? 0.10);
        $daily = (float) ($settings['daily_share'] ?? 0.20);

        return $hourly > 0 && $daily > 0 && $candidate > 0
            && $hourReplay + $candidate <= $hourly * 3600
            && $dayReplay + $candidate <= $daily * ($dayVoice + $candidate);
    }

    /**
     * The replay budget's numbers from the air log and the replays still
     * waiting in the spool: this Berlin hour's replay seconds, and since the
     * end of the last quiet hours the replay and all voice seconds.
     *
     * @param  list<array{start: float, end: float, source: string}>  $entries
     * @return array{hour_replay: float, day_replay: float, day_voice: float}
     */
    public static function replayUsage(array $entries, float $waitingReplay, CarbonImmutable $now): array
    {
        $timezone = (string) config('tv.commentary.spool.quiet_hours.timezone', 'Europe/Berlin');
        $local = $now->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', (string) config('tv.commentary.spool.quiet_hours.to', '09:00')) + [1 => 0]);
        $dayStart = $local->setTime($hour, $minute);
        $dayStart = $dayStart->greaterThan($local) ? $dayStart->subDay() : $dayStart;
        $at = (float) $now->getTimestamp();
        $hourSeconds = AirLog::seconds($entries, (float) $local->startOfHour()->getTimestamp(), $at);
        $daySeconds = AirLog::seconds($entries, (float) $dayStart->getTimestamp(), $at);

        return [
            'hour_replay' => $hourSeconds[AirLog::REPLAY] + $waitingReplay,
            'day_replay' => $daySeconds[AirLog::REPLAY] + $waitingReplay,
            'day_voice' => (float) array_sum($daySeconds),
        ];
    }

    /**
     * Whether the server writes stock scripts ahead now: the stock on, its window, and the workstation reporting
     * room in its stock lately (no report, no writing: the model is never paid for a stock nobody renders).
     *
     * @param  array<string, mixed>|null  $report  DayStock::report()
     */
    public static function writeStockAhead(CarbonImmutable $now, ?array $report): bool
    {
        return DayStock::enabled() && DayStock::windowActive($now) && is_array($report) && ($report['full'] ?? true) === false;
    }
}
