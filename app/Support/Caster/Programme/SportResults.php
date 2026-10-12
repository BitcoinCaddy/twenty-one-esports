<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\ReactionLines;
use App\Support\Caster\WorldMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The sport half of "Sport und Wetter" (plan "TWENTY ONE Radio"; user
 * 2026-10-12: "Wetter und Sport im Radio Programm aus echten Daten"): for
 * each league of `sport_weather.sport.leagues` the finished matches of the
 * current matchday (of the one before when none is finished yet), the next
 * fixtures and the top and bottom of the table (not for a cup, `table`
 * false), from OpenLigaDB (keyless,
 * reachable from prod, checked 2026-10-12), cached `cache_minutes`; plus the
 * TWENTY ONE league's own results of the last `esports_minutes`
 * (WorldMemory). A match that is running has no final score and is left out.
 *
 * Numbers reach the model as German words ("zwei zu eins", "dreizehn
 * Punkte") and team names without their digits ("FC Schalke", not "FC
 * Schalke 04"), so the script can carry them verbatim and SportWeather can
 * check every score against `scores`. A league whose matchday cannot be read
 * is left out; no league and no league result, no sport (null).
 *
 * @phpstan-type SportMaterial array{data: string, scores: list<array{0: int, 1: int}>}
 */
class SportResults
{
    public const CACHE_KEY = 'caster:sport_weather:sport';

    private const WEEKDAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];

    /**
     * The sport material at `$now`, or null when nothing real is there.
     *
     * @return SportMaterial|null
     */
    public function material(CarbonImmutable $now): ?array
    {
        $settings = (array) config('tv.commentary.programme.sport_weather.sport', []);

        if (($settings['enabled'] ?? false) !== true) {
            return null;
        }

        $stored = Cache::get(self::CACHE_KEY);

        if (! is_array($stored)) {
            $stored = ['leagues' => $this->leagues($settings, $now)];
            Cache::put(self::CACHE_KEY, $stored, $stored['leagues'] === [] ? max(1, (int) ($settings['retry_seconds'] ?? 60)) : max(1, (int) ($settings['cache_minutes'] ?? 15)) * 60);
        }

        $blocks = array_column((array) $stored['leagues'], 'data');
        $scores = [];
        foreach (array_column((array) $stored['leagues'], 'scores') as $leagueScores) {
            foreach ((array) $leagueScores as $score) {
                if (is_array($score) && is_int($score[0] ?? null) && is_int($score[1] ?? null)) {
                    $scores[] = [$score[0], $score[1]];
                }
            }
        }
        $esports = $this->esports($now, max(1, (int) ($settings['esports_minutes'] ?? 60)));

        if ($esports !== []) {
            $blocks[] = "TWENTY ONE eSports-Liga, Ergebnisse der letzten Stunde:\n".implode("\n", $esports);
        }

        return $blocks === [] ? null : ['data' => implode("\n\n", $blocks), 'scores' => $scores];
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return list<array{data: string, scores: list<array{0: int, 1: int}>}>
     */
    private function leagues(array $settings, CarbonImmutable $now): array
    {
        $leagues = [];

        foreach ((array) ($settings['leagues'] ?? []) as $league) {
            if (! is_array($league) || ! is_string($league['shortcut'] ?? null) || ! is_string($league['name'] ?? null)) {
                continue;
            }

            $block = $this->league($settings, $league['shortcut'], $league['name'], is_string($league['round'] ?? null) ? $league['round'] : 'Spieltag', ($league['table'] ?? true) !== false, $now);

            if ($block !== null) {
                $leagues[] = $block;
            }
        }

        return $leagues;
    }

    /**
     * One league's block, or null when its matchday cannot be read.
     *
     * @param  array<array-key, mixed>  $settings
     * @return array{data: string, scores: list<array{0: int, 1: int}>}|null
     */
    private function league(array $settings, string $shortcut, string $name, string $round, bool $withTable, CarbonImmutable $now): ?array
    {
        $base = rtrim((string) ($settings['url'] ?? 'https://api.openligadb.de'), '/');
        $current = $this->get($settings, $base.'/getmatchdata/'.$shortcut);

        if ($current === null || $current === [] || ! is_array($current[0] ?? null)) {
            return null;
        }

        $season = (int) ($current[0]['leagueSeason'] ?? 0);
        $group = (int) ($current[0]['group']['groupOrderID'] ?? 0);
        $finished = array_values(array_filter($current, fn (mixed $match): bool => self::score($match) !== null));
        $finishedGroup = $group;

        if ($finished === [] && $season > 0 && $group > 1) {
            $finishedGroup = $group - 1;
            $finished = array_values(array_filter($this->get($settings, "{$base}/getmatchdata/{$shortcut}/{$season}/".($group - 1)) ?? [], fn (mixed $match): bool => self::score($match) !== null));
        }

        $upcoming = array_values(array_filter($current, fn (mixed $match): bool => self::kickoff($match)?->isAfter($now) === true));

        if ($upcoming === [] && $season > 0 && $group > 0) {
            $upcoming = array_values(array_filter($this->get($settings, "{$base}/getmatchdata/{$shortcut}/{$season}/".($group + 1)) ?? [], fn (mixed $match): bool => self::kickoff($match)?->isAfter($now) === true));
        }

        $oldest = $now->subDays(max(1, (int) ($settings['results_max_days'] ?? 8)));
        $finished = array_values(array_filter($finished, fn (mixed $match): bool => self::kickoff($match)?->isAfter($oldest) === true));
        usort($upcoming, fn (array $a, array $b): int => self::kickoff($a) <=> self::kickoff($b));
        $finished = array_slice($finished, 0, max(1, (int) ($settings['results'] ?? 9)));
        $upcoming = array_slice($upcoming, 0, max(0, (int) ($settings['fixtures'] ?? 5)));
        $lines = [];
        $scores = [];

        if ($finished !== []) {
            $lines[] = "{$name}, {$round} ".ReactionLines::spokenNumber($finishedGroup).', Endergebnisse:';

            foreach ($finished as $match) {
                [$home, $away] = self::score($match) ?? [0, 0];
                $scores[] = [$home, $away];
                $day = self::kickoff($match);
                $lines[] = '- '.($day === null ? '' : 'am '.self::WEEKDAYS[(int) $day->setTimezone('Europe/Berlin')->format('w')].': ')
                    .self::team($match['team1'] ?? null).' gegen '.self::team($match['team2'] ?? null).' '.ReactionLines::spokenNumber($home).' zu '.ReactionLines::spokenNumber($away);
            }
        }

        if ($upcoming !== []) {
            $lines[] = "{$name}, die nächsten Spiele:";
            $clock = new BroadcastClock([]);

            foreach ($upcoming as $match) {
                $kickoff = self::kickoff($match);
                $lines[] = '- '.($kickoff === null ? '' : 'am '.self::WEEKDAYS[(int) $kickoff->setTimezone('Europe/Berlin')->format('w')].' um '.$clock->spokenTime($kickoff).': ')
                    .self::team($match['team1'] ?? null).' gegen '.self::team($match['team2'] ?? null);
            }
        }

        $table = $withTable && $season > 0 ? $this->get($settings, "{$base}/getbltable/{$shortcut}/{$season}") : null;
        $rows = array_values(array_filter($table ?? [], fn (mixed $row): bool => is_array($row) && is_string($row['teamName'] ?? null) && is_numeric($row['points'] ?? null)));

        if (count($rows) >= 5) {
            $place = fn (int $index): string => 'Platz '.ReactionLines::spokenNumber($index + 1).' '.self::team($rows[$index]).' mit '.ReactionLines::spokenNumber((int) $rows[$index]['points']).' Punkten';
            $last = count($rows) - 1;
            $lines[] = "{$name}, Tabelle: oben ".implode(', ', array_map($place, [0, 1, 2])).'; unten '.implode(', ', array_map($place, [$last - 1, $last])).'.';
        }

        return $lines === [] ? null : ['data' => implode("\n", $lines), 'scores' => $scores];
    }

    /**
     * A JSON list from OpenLigaDB, or null (down, not a list).
     *
     * @param  array<array-key, mixed>  $settings
     * @return list<mixed>|null
     */
    private function get(array $settings, string $url): ?array
    {
        try {
            $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')->acceptJson()
                ->connectTimeout(4)->timeout(max(1, (int) ($settings['timeout_seconds'] ?? 8)))->get($url);
            $body = $response->successful() ? $response->json() : null;
        } catch (Throwable $exception) {
            WeatherReport::logFailure($exception::class);

            return null;
        }

        return is_array($body) && array_is_list($body) ? $body : null;
    }

    /**
     * The final score of a finished match (the "Endergebnis", else its last result), or null.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function score(mixed $match): ?array
    {
        if (! is_array($match) || ($match['matchIsFinished'] ?? false) !== true || ! is_array($match['matchResults'] ?? null)) {
            return null;
        }

        $results = array_values(array_filter($match['matchResults'], fn (mixed $result): bool => is_array($result) && is_int($result['pointsTeam1'] ?? null) && is_int($result['pointsTeam2'] ?? null)));
        usort($results, fn (array $a, array $b): int => [(int) (($a['resultTypeID'] ?? 0) === 2), (int) ($a['resultOrderID'] ?? 0)] <=> [(int) (($b['resultTypeID'] ?? 0) === 2), (int) ($b['resultOrderID'] ?? 0)]);
        $final = $results === [] ? null : $results[count($results) - 1];

        return $final === null ? null : [$final['pointsTeam1'], $final['pointsTeam2']];
    }

    private static function kickoff(mixed $match): ?CarbonImmutable
    {
        if (! is_array($match) || ! is_string($match['matchDateTimeUTC'] ?? null)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($match['matchDateTimeUTC'])->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** A team's full name without the digits the voice would stumble over ("1. FC Köln" → "FC Köln", "FC Schalke 04" → "FC Schalke"). */
    public static function team(mixed $team): string
    {
        $name = is_array($team) ? (string) ($team['teamName'] ?? $team['shortName'] ?? '') : '';

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/(?<![\p{L}])\d+\.?(?![\p{L}])/u', ' ', $name))) ?: 'ein Verein';
    }

    /**
     * The TWENTY ONE league's results of the last `$minutes` (WorldMemory), newest first, at most three.
     *
     * @return list<string>
     */
    private function esports(CarbonImmutable $now, int $minutes): array
    {
        try {
            $since = $now->subMinutes($minutes)->getTimestamp();
            $items = array_filter(app(WorldMemory::class)->items($now), fn (array $item): bool => $item['kind'] === WorldMemory::RESULT && $item['at'] >= $since);
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (array $item): string => '- '.$item['line'], array_slice(array_values($items), 0, 3));
    }
}
