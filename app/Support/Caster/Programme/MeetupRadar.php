<?php

namespace App\Support\Caster\Programme;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The EINUNDZWANZIG meetup dates for "Der Meetup-Radar" (plan "TWENTY ONE
 * Radio", P9; Shows::MEETUPS), from the portal's public list of every meetup
 * date (`shows.meetup_events_url`, GET /api/meetup-events, no filter; about
 * five megabytes, reachable from prod, checked 2026-10-12). Read at most
 * every `meetup_cache_minutes`; only the next weeks and the set of all
 * meetup cities are kept (the cities let the guard reject a city that has
 * no date in the coming days). A failed read is remembered for
 * `meetup_retry_minutes` and answers null: the show is skipped, never
 * guessed.
 *
 * @phpstan-type MeetupDate array{id: int, starts_at: int, meetup: string, city: string, country: string, title: string|null, attendees: int}
 */
class MeetupRadar
{
    private const CACHE = 'caster:show:meetups';

    private const DOWN = 'caster:show:meetups_down';

    /** How far ahead the cache keeps dates (the show asks for `meetups.days`, at most this). */
    private const KEEP_DAYS = 21;

    /**
     * The dates from `$now` within `$days`, in start order; null when the portal could not be read.
     *
     * @return list<MeetupDate>|null
     */
    public function upcoming(CarbonImmutable $now, int $days): ?array
    {
        $data = $this->data($now);

        if ($data === null) {
            return null;
        }

        $until = $now->getTimestamp() + min(self::KEEP_DAYS, max(1, $days)) * 86_400;

        return array_values(array_filter($data['dates'], fn (array $date): bool => $date['starts_at'] >= $now->getTimestamp() && $date['starts_at'] < $until));
    }

    /**
     * Every city with a meetup date in the portal's list (for the guard), or [] when it could not be read.
     *
     * @return list<string>
     */
    public function cities(CarbonImmutable $now): array
    {
        return $this->data($now)['cities'] ?? [];
    }

    /**
     * @return array{dates: list<MeetupDate>, cities: list<string>}|null
     */
    private function data(CarbonImmutable $now): ?array
    {
        $cached = Cache::get(self::CACHE);

        if (is_array($cached) && is_array($cached['dates'] ?? null) && is_array($cached['cities'] ?? null)) {
            /** @var array{dates: list<MeetupDate>, cities: list<string>} $cached */
            return $cached;
        }

        if (Cache::has(self::DOWN)) {
            return null;
        }

        $data = $this->read($now);

        if ($data === null) {
            Cache::put(self::DOWN, true, now()->addMinutes(max(1, (int) config('tv.commentary.programme.shows.meetup_retry_minutes', 15))));

            return null;
        }

        Cache::put(self::CACHE, $data, now()->addMinutes(max(1, (int) config('tv.commentary.programme.shows.meetup_cache_minutes', 180))));

        return $data;
    }

    /**
     * @return array{dates: list<MeetupDate>, cities: list<string>}|null
     */
    private function read(CarbonImmutable $now): ?array
    {
        try {
            $response = Http::acceptJson()->timeout((int) config('tv.commentary.programme.shows.meetup_timeout_seconds', 20))
                ->get((string) config('tv.commentary.programme.shows.meetup_events_url'));
        } catch (Throwable) {
            return null;
        }

        $rows = $response->successful() ? $response->json() : null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        [$dates, $cities] = [[], []];
        $until = $now->getTimestamp() + self::KEEP_DAYS * 86_400;

        foreach ($rows as $row) {
            $city = is_array($row) && is_string($row['meetup.city'] ?? null) ? trim($row['meetup.city']) : '';
            $meetup = is_array($row) && is_string($row['meetup.name'] ?? null) ? trim($row['meetup.name']) : '';

            if ($city === '' || $meetup === '' || ! is_int($row['id'] ?? null) || ! is_string($row['start_iso'] ?? null)) {
                continue;
            }

            $cities[$city] = true;

            try {
                $start = CarbonImmutable::parse($row['start_iso'])->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            if ($start < $now->getTimestamp() - 86_400 || $start > $until) {
                continue;
            }

            $title = is_string($row['title'] ?? null) && trim($row['title']) !== '' ? mb_substr(trim($row['title']), 0, 80) : null;
            $dates[] = ['id' => $row['id'], 'starts_at' => $start, 'meetup' => mb_substr($meetup, 0, 80), 'city' => mb_substr($city, 0, 60),
                'country' => is_string($row['meetup.country'] ?? null) ? $row['meetup.country'] : '', 'title' => $title, 'attendees' => is_int($row['attendees'] ?? null) ? max(0, $row['attendees']) : 0];
        }

        usort($dates, fn (array $a, array $b): int => [$a['starts_at'], $a['id']] <=> [$b['starts_at'], $b['id']]);

        return ['dates' => $dates, 'cities' => array_map('strval', array_keys($cities))];
    }
}
