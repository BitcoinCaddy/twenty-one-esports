<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The podcast replay's night loop (`tv.commentary.podcast_replay.night`; user
 * 2026-10-12 23:30: "warum nicht heute Nacht ausnahmsweise mehrere
 * Podcastfolgen im Loop"): inside the window the `episodes` newest stored
 * episodes of a source (PodcastLibrary::episodes()) air back to back, newest
 * first, each at most once a night. Each goes into the spool as its own
 * CasterScript::PODCAST airing (topic "n-<source>", so the day's slot still
 * counts as not aired) once the one before has at most `queue_ahead_seconds`
 * of ready chunks left; SceneDirector plays it like the daytime replay: the
 * podcast opener on its first chunk, the back-announce after its last, the
 * music fully ducked, a bulletin stopping it at a pause and the rest resuming.
 * Its airings keep PodcastReplay::active() true: no slide scenes, the GPU
 * renders stock. Fails closed: off, past `until` (or an unreadable one),
 * outside the window or without stored episodes nothing is queued.
 */
final class PodcastNight
{
    public const QUEUED = 'queued';

    public const NOT_DUE = 'not_due';

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const NO_EPISODE = 'no_episode';

    private const AIRED = 'caster:podcast_night:aired:';

    public function __construct(private readonly PodcastLibrary $library) {}

    /**
     * The settings of `tv.commentary.podcast_replay.night`.
     *
     * @return array<array-key, mixed>
     */
    public static function settings(): array
    {
        return (array) config('tv.commentary.podcast_replay.night', []);
    }

    /**
     * How many episodes of `$source` the night loop plays (and the library keeps one more of): 0 while it is off,
     * past `until`, or the source is not one of its sources.
     */
    public static function episodeCount(string $source, ?CarbonImmutable $now = null): int
    {
        $settings = self::settings();

        if (($settings['enabled'] ?? false) !== true || ! PodcastLibrary::enabled() || ! in_array($source, (array) ($settings['sources'] ?? []), true) || self::pastUntil($now ?? CarbonImmutable::now())) {
            return 0;
        }

        return max(1, min(20, (int) ($settings['episodes'] ?? 6)));
    }

    /** Whether `until` has passed; an unreadable one counts as passed, an empty one never. */
    public static function pastUntil(CarbonImmutable $now): bool
    {
        $until = self::settings()['until'] ?? null;

        if ($until === null || $until === '') {
            return false;
        }

        try {
            return ! $now->lessThan(CarbonImmutable::parse((string) $until, self::timezone()));
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The window `$now` lies in (`from` to `to`, Berlin; one that passes midnight too), or null outside it.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}|null
     */
    public static function window(CarbonImmutable $now): ?array
    {
        $settings = self::settings();
        $local = $now->setTimezone(self::timezone());
        $at = function (CarbonImmutable $day, mixed $time): CarbonImmutable {
            [$hour, $minute] = array_map('intval', explode(':', is_string($time) ? $time : '00:00') + [1 => 0]);

            return $day->setTime($hour, $minute);
        };
        $from = $at($local, $settings['from'] ?? '01:00');
        $to = $at($local, $settings['to'] ?? '09:00');

        if ($from->lessThan($to)) {
            return $local->greaterThanOrEqualTo($from) && $local->lessThan($to) ? ['from' => $from, 'to' => $to] : null;
        }

        if ($local->greaterThanOrEqualTo($from)) {
            return ['from' => $from, 'to' => $to->addDay()];
        }

        return $local->lessThan($to) ? ['from' => $from->subDay(), 'to' => $to] : null;
    }

    /**
     * Queues the next episode of every night source when it is due; returns what happened per source.
     *
     * @return array<string, string>
     */
    public function schedule(CarbonImmutable $now): array
    {
        $outcomes = [];
        $settings = self::settings();
        $counts = array_filter(array_map(fn (string $key): int => self::episodeCount($key, $now), array_combine(array_keys(PodcastLibrary::sources()), array_keys(PodcastLibrary::sources()))));
        $window = $counts === [] ? null : self::window($now);

        if ($window === null) {
            return array_map(fn (): string => self::NOT_DUE, $counts);
        }

        // One loop across all its sources (user 2026-10-13: Re:set and EINUNDZWANZIG fill a whole day): the next
        // episode waits for whatever podcast is still to play, a night episode of either source or a day slot's airing.
        $tonight = fn (array $topics) => CasterScript::query()->where('kind', CasterScript::PODCAST)->whereIn('topic', $topics)->where('airs_at', '>=', $window['from']->utc());
        $pending = (float) CasterClip::query()->where('kind', CasterScript::PODCAST)->where('status', CasterClip::READY)->where('expires_at', '>', $now->utc())
            ->sum('duration_seconds');

        if ($pending > (float) ($settings['queue_ahead_seconds'] ?? 900)) {
            return array_map(fn (): string => self::WAITING, $counts);
        }

        // The sources take turns: the one with the fewest airings tonight first.
        $airings = array_map(fn (string $key): int => $tonight([self::topic($key)])->count(), array_combine(array_keys($counts), array_keys($counts)));
        $order = array_keys($counts);
        usort($order, fn (string $a, string $b): int => [$airings[$a], array_search($a, array_keys($counts), true)] <=> [$airings[$b], array_search($b, array_keys($counts), true)]);
        $night = $window['from']->format('Y-m-d');
        $queued = false;

        foreach ($order as $key) {
            if ($queued) {
                $outcomes[$key] = self::WAITING;

                continue;
            }

            $topic = self::topic($key);
            $aired = $tonight([$topic])->pluck('facts_hash')->all();
            $daytime = ($settings['skip_daytime_episode'] ?? false) === true ? ($this->library->episode($key)['guid'] ?? null) : null;
            $episodes = array_slice($this->library->episodes($key), 0, $counts[$key]);
            $next = null;

            foreach ($episodes as $episode) {
                $hash = sha1('night:'.$episode['guid']);

                if ($episode['guid'] !== $daytime && ! in_array($hash, $aired, true) && ! Cache::has(self::AIRED.$key.':'.$night.':'.$hash)) {
                    $next = $episode;
                    break;
                }
            }

            if ($next === null) {
                $outcomes[$key] = $episodes === [] ? self::NO_EPISODE : self::DONE;

                continue;
            }

            // None starts that would run far into the morning programme.
            if ($now->addSeconds((int) ceil($next['seconds']))->greaterThan($window['to']->addMinutes((int) ($settings['overrun_minutes'] ?? 30)))) {
                $outcomes[$key] = self::DONE;

                continue;
            }

            $this->air($key, $topic, PodcastLibrary::sources()[$key]['name'], $next, $now);
            Cache::put(self::AIRED.$key.':'.$night.':'.sha1('night:'.$next['guid']), 1, 2 * 86_400);
            $outcomes[$key] = self::QUEUED.' '.$next['title'];
            $queued = true;
        }

        return array_replace(array_map(fn (): string => self::WAITING, $counts), $outcomes);
    }

    /** The airing topic of a source's night loop (16 characters at most, the column's width). */
    public static function topic(string $key): string
    {
        return mb_substr('n-'.$key, 0, 16);
    }

    /**
     * One episode as an airing from now: the stand-in script and its chunk clips.
     *
     * @param  array{guid: string, title: string, published_at: int, seconds: float, chunks: list<array{path: string, seconds: float, bytes: int, pauses: list<float>}>}  $episode
     */
    private function air(string $key, string $topic, string $name, array $episode, CarbonImmutable $now): void
    {
        // Bulletins cut into it: twice its length plus an hour is room enough, as for the daytime airing.
        $expires = $now->addSeconds((int) ceil(2 * $episode['seconds']) + 3600);

        DB::transaction(function () use ($key, $topic, $name, $episode, $now, $expires): void {
            $script = CasterScript::query()->create([
                'kind' => CasterScript::PODCAST, 'phase' => ScenePhase::BREAK, 'priority' => 0,
                'text' => mb_substr($name.': '.$episode['title'], 0, 500), 'facts_hash' => sha1('night:'.$episode['guid']),
                'status' => CasterScript::VOICED, 'source' => CasterScript::SOURCE_AI, 'topic' => $topic,
                'estimated_seconds' => $episode['seconds'], 'claimed_at' => $now, 'airs_at' => $now->utc(), 'expires_at' => $expires->utc(),
            ]);

            app(ClipSpool::class)->storePodcast($script, $key, $episode['chunks']);
        });
    }

    private static function timezone(): string
    {
        return (string) config('tv.commentary.programme.timezone', 'Europe/Berlin');
    }
}
