<?php

namespace App\Support\Caster\Programme;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bitcoin and cypherpunk history on a calendar day for "Die Bitcoin-Zeitreise"
 * (plan "TWENTY ONE Radio", P9; Shows::JAHRESTAG): the curated dated list in
 * resources/data/voice/bitcoin-history-dates.json (each with its source),
 * plus the events of Wikipedia's German "on this day" feed that are about
 * bitcoin (BITCOIN_EVENT). The feed is optional: down or switched off, the
 * curated list alone counts; a day with nothing is skipped by the caller.
 *
 * @phpstan-type DatedEvent array{year: int, fact: string, source: string}
 */
class OnThisDay
{
    /** A Wikipedia event that belongs on the show: it names bitcoin, a blockchain, a cryptocurrency, Satoshi or the cypherpunks. */
    public const BITCOIN_EVENT = '/bitcoin|blockchain|kryptowährung|satoshi|cypherpunk/iu';

    private const CACHE = 'caster:show:on_this_day:';

    /**
     * The events of the Berlin calendar day of `$now`, oldest first.
     *
     * @return list<DatedEvent>
     */
    public function events(CarbonImmutable $now): array
    {
        $local = $now->setTimezone('Europe/Berlin');
        $date = $local->format('m-d');
        $events = [];

        foreach (self::curated() as $event) {
            if ($event['date'] === $date && $event['year'] < (int) $local->format('Y')) {
                $events[] = ['year' => $event['year'], 'fact' => $event['fact'], 'source' => $event['source']];
            }
        }

        if (config('tv.commentary.programme.shows.formats.jahrestag.wikipedia') === true) {
            foreach ($this->wikipedia($local) as $event) {
                $duplicate = array_filter($events, fn (array $known): bool => $known['year'] === $event['year']);

                if ($duplicate === [] && $event['year'] < (int) $local->format('Y')) {
                    $events[] = $event;
                }
            }
        }

        usort($events, fn (array $a, array $b): int => $a['year'] <=> $b['year']);

        return $events;
    }

    /**
     * The curated list.
     *
     * @return list<array{date: string, year: int, fact: string, source: string}>
     */
    public static function curated(): array
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/bitcoin-history-dates.json')), true);
        $events = [];

        foreach (is_array($data) && is_array($data['events'] ?? null) ? $data['events'] : [] as $event) {
            if (is_array($event) && is_string($event['date'] ?? null) && preg_match('/^\d{2}-\d{2}$/', $event['date']) === 1 && is_int($event['year'] ?? null)
                && is_string($event['fact'] ?? null) && is_string($event['source'] ?? null) && str_starts_with($event['source'], 'https://')) {
                $events[] = ['date' => $event['date'], 'year' => $event['year'], 'fact' => $event['fact'], 'source' => $event['source']];
            }
        }

        return $events;
    }

    /**
     * The bitcoin events of the feed for the day (cached twelve hours; a failed read an hour, as none).
     *
     * @return list<DatedEvent>
     */
    private function wikipedia(CarbonImmutable $local): array
    {
        $key = self::CACHE.$local->format('m-d');
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var list<DatedEvent> $cached */
            return $cached;
        }

        $events = [];
        $ok = false;

        try {
            $response = Http::acceptJson()->withUserAgent('TwentyOneRadio/1.0 (esports.einundzwanzig.space)')
                ->timeout((int) config('tv.commentary.programme.shows.on_this_day_timeout_seconds', 8))
                ->get(rtrim((string) config('tv.commentary.programme.shows.on_this_day_url'), '/').'/'.$local->format('m').'/'.$local->format('d'));
            $rows = $response->successful() ? $response->json('events') : null;
            $ok = is_array($rows);

            foreach (is_array($rows) ? $rows : [] as $row) {
                $text = is_array($row) && is_string($row['text'] ?? null) ? trim($row['text']) : '';

                if ($text !== '' && is_int($row['year'] ?? null) && preg_match(self::BITCOIN_EVENT, $text) === 1) {
                    $title = is_array($row['pages'][0] ?? null) && is_string($row['pages'][0]['title'] ?? null) ? $row['pages'][0]['title'] : null;
                    $events[] = ['year' => $row['year'], 'fact' => $text, 'source' => 'https://de.wikipedia.org/wiki/'.($title ?? $local->format('j._F'))];
                }
            }
        } catch (Throwable) {
            $ok = false;
        }

        Cache::put($key, $events, $ok ? now()->addHours(12) : now()->addHour());

        return $events;
    }
}
