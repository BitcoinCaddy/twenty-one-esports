<?php

namespace App\Support\Caster\Blocks;

use App\Support\Caster\ClipSpool;
use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the server runs the programme on its own (plan "TWENTY ONE Radio",
 * P18; user 2026-10-13: "den Laptop ohne Probleme runterfahren … und dann der
 * Auto-Modus von selbst loslegt … wenn er wieder da ist und VoiceStudio wieder
 * da ist, seamless übernehmen für Aktuelles"): on while the workstation has
 * not claimed for `fetch.online_seconds` (shut down, asleep, offline), or
 * while it reports VoiceStudio down (header `X-Caster-Voice: down` on its
 * heartbeat, CasterFetcher::voiceStudioUp()); off again with its next claim
 * that reports VoiceStudio up. No manual step either way.
 *
 * Fails towards the programme: with the library or `autopilot.enabled` off
 * the autopilot never runs (the stream plays music as before), and a
 * workstation never seen counts as gone.
 */
final class AutoPilot
{
    /** The header the workstation sends with every claim and heartbeat: `up` or `down` (its VoiceStudio). */
    public const HEADER = 'X-Caster-Voice';

    /** When the workstation last reported VoiceStudio down (unix seconds). */
    public const VOICE_DOWN = 'caster:voice_down_at';

    /** Whether the last feeder run found the autopilot on (StockFeeder::yieldBack() runs once when it goes off). */
    public const WAS_ON = 'caster:autopilot:on';

    public static function enabled(): bool
    {
        return StockLibrary::enabled() && config('tv.commentary.blocks.autopilot.enabled') === true;
    }

    /** Remembers what the workstation said about its VoiceStudio; anything but `up` or `down` changes nothing. */
    public static function rememberVoice(?string $header, CarbonImmutable $now): void
    {
        match (is_string($header) ? strtolower(trim($header)) : '') {
            'down' => Cache::put(self::VOICE_DOWN, $now->getTimestamp(), 3600),
            'up' => Cache::forget(self::VOICE_DOWN),
            default => null,
        };
    }

    /** Whether the workstation reported VoiceStudio down within `fetch.online_seconds`. */
    public static function voiceDown(CarbonImmutable $now): bool
    {
        $at = Cache::get(self::VOICE_DOWN);

        return is_int($at) && $now->getTimestamp() - $at <= (int) config('tv.commentary.fetch.online_seconds', 120);
    }

    public static function active(CarbonImmutable $now): bool
    {
        return self::enabled() && (! app(ClipSpool::class)->fetcherOnline($now) || self::voiceDown($now));
    }

    /**
     * The music between two autopilot items: a random length in `music_gap_seconds`, in the quiet hours in
     * `night_gap_seconds` (the night keeps its slower rhythm).
     *
     * @param  (callable(int, int): int)|null  $random
     */
    public static function musicGap(CarbonImmutable $now, ?callable $random = null): int
    {
        $settings = (array) config('tv.commentary.blocks.autopilot', []);
        $range = (array) (QuietHours::active($now) ? ($settings['night_gap_seconds'] ?? ['min' => 240, 'max' => 420]) : ($settings['music_gap_seconds'] ?? ['min' => 20, 'max' => 75]));
        $min = max(0, (int) ($range['min'] ?? 0));
        $max = max($min, (int) ($range['max'] ?? $min));

        return ($random ?? mt_rand(...))($min, $max);
    }

    /**
     * Whether an autopilot podcast airing is due: none within `podcast_every_hours` of the last one started.
     */
    public static function podcastDue(?int $lastAiredAt, CarbonImmutable $now): bool
    {
        $every = (float) config('tv.commentary.blocks.autopilot.podcast_every_hours', 4);

        return $every > 0 && ($lastAiredAt === null || $now->getTimestamp() - $lastAiredAt >= 3600 * $every);
    }

    /**
     * The episode to air: none within `podcast_repeat_hours` of its last airing, the least recently aired (never
     * aired first, then the newest), or null.
     *
     * @param  list<array{key: string, guid: string, published_at: int}>  $episodes
     * @param  array<string, int>  $airedAt  last airing by guid
     * @return array{key: string, guid: string, published_at: int}|null
     */
    public static function pickEpisode(array $episodes, array $airedAt, CarbonImmutable $now): ?array
    {
        $repeat = 3600 * (float) config('tv.commentary.blocks.autopilot.podcast_repeat_hours', 24);
        $free = array_values(array_filter($episodes, fn (array $episode): bool => ! isset($airedAt[$episode['guid']]) || $now->getTimestamp() - $airedAt[$episode['guid']] >= $repeat));
        usort($free, fn (array $a, array $b): int => [$airedAt[$a['guid']] ?? 0, -$a['published_at']] <=> [$airedAt[$b['guid']] ?? 0, -$b['published_at']]);

        return $free[0] ?? null;
    }
}
