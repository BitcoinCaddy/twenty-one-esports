<?php

namespace App\Support\Voice;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The league voice's counters (table `voice_usage`): attempts, outcomes and
 * tokens per channel and Berlin day, numbers only. They drive the daily cap
 * per channel and the monthly soft budget, and `php artisan voice:usage`
 * prints them. No text, no name, no fact ever lands here.
 */
final class VoiceUsage
{
    private const TABLE = 'voice_usage';

    /** The Berlin day a moment falls on (Y-m-d). */
    public function day(?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->setTimezone((string) config('esports.voice.timezone', 'Europe/Berlin'))->toDateString();
    }

    public function attemptsToday(string $channel): int
    {
        return (int) DB::table(self::TABLE)->where('day', $this->day())->where('channel', $channel)->value('attempts');
    }

    /** Counts an attempt before the model is asked, so a failing request still uses up the cap. */
    public function recordAttempt(string $channel): void
    {
        $this->bump($channel, ['attempts' => 1]);
    }

    public function recordOutcome(string $channel, string $outcome): void
    {
        if (in_array($outcome, ['accepted', 'rejected', 'failed'], true)) {
            $this->bump($channel, [$outcome => 1]);
        }
    }

    /**
     * One count per reason code of a rejected text; the text itself is never stored.
     *
     * @param  list<string>  $reasons
     */
    public function recordRejection(string $channel, array $reasons): void
    {
        $day = $this->day();
        $now = now();

        foreach (array_unique($reasons) as $reason) {
            DB::table('voice_rejections')->insertOrIgnore(['day' => $day, 'channel' => $channel, 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('voice_rejections')->where('day', $day)->where('channel', $channel)->where('reason', $reason)->increment('count', 1, ['updated_at' => $now]);
        }
    }

    /**
     * Rejection counts per channel and reason for the days from `$from` to `$to`.
     *
     * @return array<string, array<string, int>>
     */
    public function rejections(string $from, string $to): array
    {
        $counts = [];

        foreach (DB::table('voice_rejections')->whereBetween('day', [$from, $to])->groupBy('channel', 'reason')->orderBy('channel')->orderBy('reason')->selectRaw('channel, reason, sum(count) as total')->get() as $row) {
            $counts[(string) $row->channel][(string) $row->reason] = (int) $row->total;
        }

        return $counts;
    }

    public function recordTokens(string $channel, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): void
    {
        $this->bump($channel, [
            'input_tokens' => max(0, $input),
            'output_tokens' => max(0, $output),
            'cache_read_tokens' => max(0, $cacheRead),
            'cache_write_tokens' => max(0, $cacheWrite),
        ]);
    }

    /**
     * Summed counters for the days from `$from` to `$to` (inclusive, Y-m-d), per channel.
     *
     * @return array<string, array{attempts: int, accepted: int, rejected: int, failed: int, input_tokens: int, cache_read_tokens: int, cache_write_tokens: int, output_tokens: int}>
     */
    public function totals(string $from, string $to): array
    {
        $rows = DB::table(self::TABLE)
            ->whereBetween('day', [$from, $to])
            ->groupBy('channel')
            ->orderBy('channel')
            ->selectRaw('channel, sum(attempts) as attempts, sum(accepted) as accepted, sum(rejected) as rejected, sum(failed) as failed, sum(input_tokens) as input_tokens, sum(cache_read_tokens) as cache_read_tokens, sum(cache_write_tokens) as cache_write_tokens, sum(output_tokens) as output_tokens')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->channel] = [
                'attempts' => (int) $row->attempts,
                'accepted' => (int) $row->accepted,
                'rejected' => (int) $row->rejected,
                'failed' => (int) $row->failed,
                'input_tokens' => (int) $row->input_tokens,
                'cache_read_tokens' => (int) $row->cache_read_tokens,
                'cache_write_tokens' => (int) $row->cache_write_tokens,
                'output_tokens' => (int) $row->output_tokens,
            ];
        }

        return $totals;
    }

    /**
     * Estimated cost in US dollars of the given counters. The provider reports
     * cache reads and writes as a subset of the input tokens (laravel/ai
     * TextUsage); only the rest is billed at the full input price.
     *
     * @param  array{input_tokens: int, cache_read_tokens: int, cache_write_tokens: int, output_tokens: int}  $counters
     */
    public function cost(array $counters): float
    {
        $prices = (array) config('esports.voice.prices_usd_per_mtok');
        $plainInput = max(0, $counters['input_tokens'] - $counters['cache_read_tokens'] - $counters['cache_write_tokens']);

        return ($plainInput * (float) ($prices['input'] ?? 0)
            + $counters['cache_read_tokens'] * (float) ($prices['cache_read'] ?? 0)
            + $counters['cache_write_tokens'] * (float) ($prices['cache_write'] ?? 0)
            + $counters['output_tokens'] * (float) ($prices['output'] ?? 0)) / 1_000_000;
    }

    /** The estimated cost of the running Berlin month, all channels. */
    public function monthCost(): float
    {
        $today = CarbonImmutable::parse($this->day());

        return array_sum(array_map(fn (array $counters): float => $this->cost($counters), $this->totals($today->startOfMonth()->toDateString(), $today->toDateString())));
    }

    /**
     * @param  array<string, int>  $increments
     */
    private function bump(string $channel, array $increments): void
    {
        $day = $this->day();
        $now = now();

        DB::table(self::TABLE)->insertOrIgnore(['day' => $day, 'channel' => $channel, 'created_at' => $now, 'updated_at' => $now]);
        DB::table(self::TABLE)->where('day', $day)->where('channel', $channel)->incrementEach($increments, ['updated_at' => $now]);
    }
}
