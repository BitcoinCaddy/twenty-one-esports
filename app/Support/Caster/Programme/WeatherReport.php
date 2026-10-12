<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\ReactionLines;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The weather half of "Sport und Wetter" (plan "TWENTY ONE Radio"; user
 * 2026-10-12: "Wetter und Sport im Radio Programm aus echten Daten"): the
 * current weather and today's and tomorrow's forecast of the cities in
 * `sport_weather.weather.cities`, from Open-Meteo (keyless, one request for
 * all cities), cached `cache_minutes`.
 *
 * The numbers reach the model as German words ("vierzehn Grad", "null Komma
 * sieben Millimeter"), the way the voice says them, so the script can carry
 * them verbatim and SportWeather can check them. A city without a usable
 * answer is left out; no city, no weather (null): never a guessed value.
 *
 * @phpstan-type WeatherCity array{name: string, now: int, code_now: int, wind_now: int, days: list<array{label: string, min: int, max: int, code: int, rain: float, wind: int}>}
 */
class WeatherReport
{
    public const CACHE_KEY = 'caster:sport_weather:weather';

    /** WMO weather interpretation codes (Open-Meteo `weather_code`) in words. */
    private const CODES = [
        0 => 'klarer Himmel', 1 => 'überwiegend klar', 2 => 'teils bewölkt', 3 => 'bedeckt', 45 => 'Nebel', 48 => 'Nebel mit Reif',
        51 => 'leichter Nieselregen', 53 => 'Nieselregen', 55 => 'dichter Nieselregen', 56 => 'gefrierender Nieselregen', 57 => 'gefrierender Nieselregen',
        61 => 'leichter Regen', 63 => 'Regen', 65 => 'starker Regen', 66 => 'gefrierender Regen', 67 => 'gefrierender Regen',
        71 => 'leichter Schneefall', 73 => 'Schneefall', 75 => 'starker Schneefall', 77 => 'Schneegriesel',
        80 => 'leichte Regenschauer', 81 => 'Regenschauer', 82 => 'heftige Regenschauer', 85 => 'Schneeschauer', 86 => 'heftige Schneeschauer',
        95 => 'Gewitter', 96 => 'Gewitter mit Hagel', 99 => 'Gewitter mit schwerem Hagel',
    ];

    /**
     * The cities' weather, or null when the source gave nothing usable (cached either way for its window; a failure
     * only `retry_seconds`).
     *
     * @return list<WeatherCity>|null
     */
    public function cities(): ?array
    {
        $settings = (array) config('tv.commentary.programme.sport_weather.weather', []);

        if (($settings['enabled'] ?? false) !== true) {
            return null;
        }

        $stored = Cache::get(self::CACHE_KEY);

        if (is_array($stored)) {
            return $stored['cities'] === [] ? null : $stored['cities'];
        }

        $cities = $this->fetch($settings);
        Cache::put(self::CACHE_KEY, ['cities' => $cities], $cities === [] ? max(1, (int) ($settings['retry_seconds'] ?? 60)) : max(1, (int) ($settings['cache_minutes'] ?? 30)) * 60);

        return $cities === [] ? null : $cities;
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return list<WeatherCity>
     */
    private function fetch(array $settings): array
    {
        $places = array_values(array_filter((array) ($settings['cities'] ?? []), fn (mixed $city): bool => is_array($city) && is_string($city['name'] ?? null)
            && is_numeric($city['lat'] ?? null) && is_numeric($city['lon'] ?? null)));

        if ($places === []) {
            return [];
        }

        try {
            $response = Http::withUserAgent('TwentyOneTV/1.0 (+https://esports.einundzwanzig.space)')
                ->connectTimeout(4)->timeout(max(1, (int) ($settings['timeout_seconds'] ?? 8)))
                ->get((string) ($settings['url'] ?? 'https://api.open-meteo.com/v1/forecast'), [
                    'latitude' => implode(',', array_map(fn (array $city): string => (string) $city['lat'], $places)),
                    'longitude' => implode(',', array_map(fn (array $city): string => (string) $city['lon'], $places)),
                    'current' => 'temperature_2m,weather_code,wind_speed_10m',
                    'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max',
                    'timezone' => 'Europe/Berlin',
                    'forecast_days' => 2,
                ]);

            $body = $response->successful() ? $response->json() : null;
        } catch (Throwable $exception) {
            $body = null;
            self::logFailure($exception::class);
        }

        if (! is_array($body)) {
            return [];
        }

        // One city answers with an object, several with a list in request order.
        $answers = array_is_list($body) ? $body : [$body];
        $cities = [];

        foreach ($places as $index => $place) {
            $city = is_array($answers[$index] ?? null) ? self::city((string) $place['name'], $answers[$index]) : null;

            if ($city !== null) {
                $cities[] = $city;
            }
        }

        return $cities;
    }

    /**
     * One city from its Open-Meteo answer, or null when a value is missing.
     *
     * @param  array<array-key, mixed>  $answer
     * @return WeatherCity|null
     */
    public static function city(string $name, array $answer): ?array
    {
        $current = is_array($answer['current'] ?? null) ? $answer['current'] : [];
        $daily = is_array($answer['daily'] ?? null) ? $answer['daily'] : [];

        if (! is_numeric($current['temperature_2m'] ?? null) || ! is_numeric($current['weather_code'] ?? null) || ! is_numeric($current['wind_speed_10m'] ?? null)) {
            return null;
        }

        $days = [];
        $field = function (string $name, int $index) use ($daily): ?float {
            $value = is_array($daily[$name] ?? null) ? ($daily[$name][$index] ?? null) : null;

            return is_numeric($value) ? (float) $value : null;
        };

        foreach (['heute', 'morgen'] as $index => $label) {
            [$min, $max, $code, $rain, $wind] = [$field('temperature_2m_min', $index), $field('temperature_2m_max', $index), $field('weather_code', $index),
                $field('precipitation_sum', $index), $field('wind_speed_10m_max', $index)];

            if ($min !== null && $max !== null && $code !== null && $rain !== null && $wind !== null) {
                $days[] = ['label' => $label, 'min' => (int) round($min), 'max' => (int) round($max), 'code' => (int) $code, 'rain' => round($rain, 1), 'wind' => (int) round($wind)];
            }
        }

        return $days === [] ? null : ['name' => $name, 'now' => (int) round((float) $current['temperature_2m']), 'code_now' => (int) $current['weather_code'],
            'wind_now' => (int) round((float) $current['wind_speed_10m']), 'days' => $days];
    }

    /**
     * The cities as data lines, every number in words.
     *
     * @param  list<WeatherCity>  $cities
     */
    public static function data(array $cities): string
    {
        return implode("\n", array_map(function (array $city): string {
            $days = array_map(fn (array $day): string => $day['label'].' zwischen '.ReactionLines::spokenNumber($day['min']).' und '.self::degrees($day['max']).', '.self::code($day['code'])
                .', '.($day['rain'] <= 0.0 ? 'kein Niederschlag' : self::decimal($day['rain']).' Millimeter Niederschlag').', Wind bis '.ReactionLines::spokenNumber($day['wind']).' Kilometer pro Stunde', $city['days']);

            return '- '.$city['name'].': jetzt '.self::degrees($city['now']).', '.self::code($city['code_now']).', Wind '.ReactionLines::spokenNumber($city['wind_now']).' Kilometer pro Stunde; '.implode('; ', $days).'.';
        }, $cities));
    }

    /**
     * Every temperature the data names (rounded degrees), for SportWeather's check.
     *
     * @param  list<WeatherCity>  $cities
     * @return list<int>
     */
    public static function temperatures(array $cities): array
    {
        $values = [];
        foreach ($cities as $city) {
            $values[] = $city['now'];

            foreach ($city['days'] as $day) {
                array_push($values, $day['min'], $day['max']);
            }
        }

        return array_values(array_unique($values));
    }

    public static function code(int $code): string
    {
        return self::CODES[$code] ?? 'wechselhaftes Wetter';
    }

    private static function degrees(int $value): string
    {
        return ReactionLines::spokenNumber($value).' Grad';
    }

    /** "null Komma sieben", "zwölf Komma drei", "drei". */
    public static function decimal(float $value): string
    {
        $tenths = (int) round($value * 10);
        $whole = intdiv($tenths, 10);

        return ReactionLines::spokenNumber($whole).($tenths % 10 === 0 ? '' : ' Komma '.ReactionLines::spokenNumber($tenths % 10));
    }

    public static function logFailure(string $reason): void
    {
        if (Cache::add('caster:sport_weather:failed-logged', true, 3_600)) {
            Log::warning('Caster sport and weather source failed', ['reason' => $reason]);
        }
    }
}
