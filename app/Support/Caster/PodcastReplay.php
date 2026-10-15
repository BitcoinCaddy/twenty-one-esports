<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The podcast replay's airings (plan "TWENTY ONE Radio", P12): once a day
 * per enabled source, from `ahead_minutes` before its `starts_at` (Berlin)
 * until `late_minutes` after it, the stored episode (PodcastLibrary) goes
 * into the spool as one CasterScript::PODCAST stand-in script with its slot
 * as `airs_at` and one clip per chunk, in order (ClipSpool::storePodcast()).
 * SceneDirector plays them from the slot on as one piece; nothing is voiced.
 *
 * Never the same source twice a day (a cache mark per Berlin day and the
 * airing row itself), so a newer episode airs the day after it came in and
 * the same episode never twice a day. active() tells the queue and the claim
 * that an airing is on or comes within `ahead_minutes`: no slide scenes,
 * teasers or talk are written for it, the workstation's GPU renders stock or
 * idles (`gpu`).
 */
final class PodcastReplay
{
    public const SCHEDULED = 'scheduled';

    public const NOT_DUE = 'not_due';

    public const DONE_TODAY = 'done_today';

    public const NO_EPISODE = 'no_episode';

    public const FULL = 'full';

    private const AIRED = 'caster:podcast_replay:aired:';

    public function __construct(private readonly PodcastLibrary $library) {}

    /**
     * Puts every source whose slot is due today into the spool once; returns what happened per source.
     *
     * @return array<string, string>
     */
    public function schedule(CarbonImmutable $now): array
    {
        $settings = PodcastLibrary::settings();
        $outcomes = [];

        foreach (PodcastLibrary::sources() as $key => $source) {
            $slot = self::slotStart($source['starts_at'], $now);
            $day = $slot->setTimezone(self::timezone())->format('Y-m-d');

            // A slot outside the replay window never airs (PodcastLibrary::airsAt()).
            if (! PodcastLibrary::airsAt($slot) || $now->lessThan($slot->subMinutes((int) ($settings['ahead_minutes'] ?? 15))) || $now->greaterThan($slot->addMinutes((int) ($settings['late_minutes'] ?? 60)))) {
                $outcomes[$key] = self::NOT_DUE;

                continue;
            }

            if (self::airedToday($key, $slot)) {
                $outcomes[$key] = self::DONE_TODAY;

                continue;
            }

            $episode = $this->library->episode($key);

            if ($episode === null) {
                $outcomes[$key] = self::NO_EPISODE;

                continue;
            }

            $outcomes[$key] = $this->air($key, $source['name'], $episode, $slot, $now) ? self::SCHEDULED : self::FULL;

            if ($outcomes[$key] === self::SCHEDULED) {
                Cache::put(self::AIRED.$key.':'.$day, $episode['guid'], 2 * 86_400);
            }
        }

        return $outcomes;
    }

    /**
     * The stand-in script and the chunk clips of one airing.
     *
     * @param  array{guid: string, title: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>}  $episode
     */
    private function air(string $key, string $name, array $episode, CarbonImmutable $slot, CarbonImmutable $now): bool
    {
        // The bulletins and replies around it make an airing longer than the episode: twice its length is room enough.
        $expires = $slot->addSeconds((int) ceil(2 * $episode['seconds']) + 3600);

        return DB::transaction(function () use ($key, $name, $episode, $slot, $now, $expires): bool {
            $script = CasterScript::query()->create([
                'kind' => CasterScript::PODCAST, 'phase' => ScenePhase::BREAK, 'priority' => 0,
                'text' => mb_substr($name.': '.$episode['title'], 0, 500), 'facts_hash' => sha1($episode['guid']),
                'status' => CasterScript::VOICED, 'source' => CasterScript::SOURCE_AI, 'topic' => $key,
                'estimated_seconds' => $episode['seconds'], 'claimed_at' => $now, 'airs_at' => $slot->utc(), 'expires_at' => $expires->utc(),
            ]);

            app(ClipSpool::class)->storePodcast($script, $key, $episode['chunks']);

            return true;
        });
    }

    /** Whether `$key` aired (or was put into the spool) on the Berlin day of `$slot` already. */
    public static function airedToday(string $key, CarbonImmutable $slot): bool
    {
        return Cache::has(self::AIRED.$key.':'.$slot->setTimezone(self::timezone())->format('Y-m-d')) || self::airing($key, $slot) !== null;
    }

    /** The airing of `$key` with its slot on the Berlin day of `$slot`, or null. */
    public static function airing(string $key, CarbonImmutable $slot): ?CasterScript
    {
        $local = $slot->setTimezone(self::timezone());

        return CasterScript::query()->where('kind', CasterScript::PODCAST)->where('topic', $key)
            ->whereBetween('airs_at', [$local->startOfDay()->utc(), $local->endOfDay()->utc()])->orderBy('id')->first();
    }

    /**
     * Whether an airing is on air or starts within `ahead_minutes`: one of its chunks still waits or plays.
     */
    public static function active(CarbonImmutable $now): bool
    {
        if (! PodcastLibrary::enabled()) {
            return false;
        }

        $ahead = (int) config('tv.commentary.podcast_replay.ahead_minutes', 15);

        return CasterClip::query()->where('kind', CasterScript::PODCAST)->where('expires_at', '>', $now->utc())
            ->whereIn('caster_script_id', CasterScript::query()->where('kind', CasterScript::PODCAST)->where('airs_at', '<=', $now->utc()->addMinutes($ahead))->select('id'))
            ->exists();
    }

    /** What the workstation's GPU does while an airing is active: `stock` or `idle`. */
    public static function gpu(): string
    {
        return config('tv.commentary.podcast_replay.gpu') === 'idle' ? 'idle' : 'stock';
    }

    /** Today's (Berlin) start of a slot `HH:MM`. */
    public static function slotStart(string $startsAt, CarbonImmutable $now): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $startsAt) + [1 => 0]);

        return $now->setTimezone(self::timezone())->setTime($hour, $minute);
    }

    private static function timezone(): string
    {
        return (string) config('tv.commentary.programme.timezone', 'Europe/Berlin');
    }
}
