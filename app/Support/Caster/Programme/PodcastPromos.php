<?php

namespace App\Support\Caster\Programme;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\PodcastLibrary;
use App\Support\Caster\PodcastReplay;
use App\Support\Caster\QuietHours;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Markus and Sirius promote the podcast replay (plan "TWENTY ONE Radio",
 * P12; user 2026-10-12: "Die Podcasts, die wir abspielen, müssen auch
 * regelmäßig angekündigt werden von KI-Markus und Sirius und bevor sie
 * gespielt werden auch explizit."), written by the queue every minute:
 *
 *  - before the airing: a promo duet (PodcastScripts::PROMO) as a teaser of
 *    scene SCENE, from `promos.from` (Berlin) on at most every
 *    `promos.every_minutes` and once more in the hour before the slot (unless
 *    one ran within the ninety minutes before it), up to
 *    `promos.until_minutes_before` it, never in the quiet hours. Only while
 *    the slot really follows: the source enabled, its episode stored, today's
 *    airing neither in the spool nor aired (PodcastReplay::airedToday());
 *    a promo of a source that no longer qualifies leaves the queue and the
 *    spool. Its opener names the slot's true time (PodcastScripts::opener());
 *  - once the airing is in the spool: the announcement duet
 *    (CasterScript::PODCAST_INTRO, SceneDirector plays it right before the
 *    first chunk) and Markus' back-announce (CasterScript::PODCAST_OUTRO,
 *    right after the last), both anchored to the slot, at most
 *    `scripts.tries` attempts each.
 *
 * A source that is off or has no episode gets nothing.
 */
class PodcastPromos
{
    /** The scene of a podcast promo teaser. */
    public const SCENE = 'podcast';

    private const LAST_PROMO = 'caster:podcast_replay:promo:';

    private const TRIES = 'caster:podcast_replay:tries:';

    public function __construct(private readonly PodcastScripts $scripts, private readonly PodcastLibrary $library) {}

    /** @return int scripts written */
    public function fill(ScriptQueue $queue, CarbonImmutable $now): int
    {
        $sources = PodcastLibrary::sources();
        $settings = (array) config('tv.commentary.podcast_replay.promos', []);
        $written = 0;
        $upcoming = [];

        foreach ($sources as $key => $source) {
            $episode = $this->library->episode($key);

            if ($episode === null) {
                continue;
            }

            $slot = PodcastReplay::slotStart($source['starts_at'], $now);

            // A slot outside the replay window never airs: nothing to promote.
            if (! PodcastLibrary::airsAt($slot)) {
                continue;
            }

            $airing = PodcastReplay::airing($key, $slot);

            if ($airing !== null) {
                $written += $this->frame($queue, $airing, $source['spoken'], $episode, $slot, $now);

                continue;
            }

            if (PodcastReplay::airedToday($key, $slot) || $now->greaterThan($slot->subMinutes((int) ($settings['until_minutes_before'] ?? 10)))) {
                continue;
            }

            $upcoming[] = $key;

            if (($settings['enabled'] ?? false) === true && ! QuietHours::active($now) && $this->promoDue($key, $slot, $now, $settings)) {
                $written += $this->promo($queue, $key, $source['spoken'], $episode, $slot, $now, $settings);
            }
        }

        $this->withdraw($upcoming);

        return $written;
    }

    /**
     * Whether a promo of `$key` is due: from `from` on, every `every_minutes` since the last one, and once in the hour
     * before the slot.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private function promoDue(string $key, CarbonImmutable $slot, CarbonImmutable $now, array $settings): bool
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) ($settings['from'] ?? '09:00')) + [1 => 0]);

        if ($now->lessThan($slot->setTime($hour, $minute)) || Cache::has(self::TRIES.'promo:'.$key)) {
            return false;
        }

        $last = Cache::get(self::LAST_PROMO.$key.':'.$slot->format('Y-m-d'));

        // In the hour before the slot once more, unless one ran within the ninety minutes before it.
        return ! is_int($last)
            || $now->getTimestamp() - $last >= 60 * max(1, (int) ($settings['every_minutes'] ?? 120))
            || ($now->getTimestamp() >= $slot->subHour()->getTimestamp() && $last < $slot->subMinutes(90)->getTimestamp());
    }

    /**
     * @param  array{title: string, episode_number?: int|null, notes?: string}  $episode
     * @param  array<array-key, mixed>  $settings
     */
    private function promo(ScriptQueue $queue, string $key, string $name, array $episode, CarbonImmutable $slot, CarbonImmutable $now, array $settings): int
    {
        $promo = $this->scripts->write(PodcastScripts::PROMO, $name, $episode, $slot);

        if ($promo === null) {
            // Tried again in ten minutes, not every minute.
            Cache::put(self::TRIES.'promo:'.$key, true, 600);

            return 0;
        }

        $expires = $now->addMinutes(max(1, (int) ($settings['expire_minutes'] ?? 30)));
        $expires = $expires->greaterThan($slot->subMinute()) ? $slot->subMinute() : $expires;
        $script = $queue->enqueue(CasterScript::TEASER, ScenePhase::BREAK, $promo['text'], 'podcast-promo:'.$key.':'.$now->getTimestamp(), (int) ($settings['priority'] ?? 72), $now,
            scene: self::SCENE, source: CasterScript::SOURCE_AI, topic: $key, speakers: $promo['speakers'], airsAt: $now, expiresAt: $expires);

        if ($script === null) {
            return 0;
        }

        Cache::put(self::LAST_PROMO.$key.':'.$slot->format('Y-m-d'), $now->getTimestamp(), 2 * 86_400);

        return 1;
    }

    /**
     * The announcement and the back-announce of an airing that is in the spool, once each.
     *
     * @param  array{title: string, episode_number?: int|null, notes?: string}  $episode
     */
    private function frame(ScriptQueue $queue, CasterScript $airing, string $name, array $episode, CarbonImmutable $slot, CarbonImmutable $now): int
    {
        // Nothing left of the airing to frame: it is over.
        if (! CasterClip::query()->where('caster_script_id', $airing->id)->exists() || $airing->expires_at->lessThanOrEqualTo($now)) {
            return 0;
        }

        $settings = (array) config('tv.commentary.podcast_replay.scripts', []);
        $written = 0;

        foreach ([CasterScript::PODCAST_INTRO => PodcastScripts::ANNOUNCE, CasterScript::PODCAST_OUTRO => PodcastScripts::BACK] as $kind => $what) {
            $hash = $kind.':'.$airing->id;
            $tries = self::TRIES.$hash;

            if (CasterScript::query()->where('kind', $kind)->where('facts_hash', $hash)->exists() || (int) Cache::get($tries, 0) >= max(1, (int) ($settings['tries'] ?? 3))) {
                continue;
            }

            Cache::put($tries, (int) Cache::get($tries, 0) + 1, 86_400);
            $piece = $this->scripts->write($what, $name, $episode, $slot);

            if ($piece !== null && $queue->enqueue($kind, ScenePhase::BREAK, $piece['text'], $hash, (int) ($settings['priority'] ?? 76), $now,
                source: CasterScript::SOURCE_AI, topic: (string) $airing->topic, speakers: $kind === CasterScript::PODCAST_OUTRO ? array_fill(0, count($piece['speakers']), DuetDirector::MARKUS) : $piece['speakers'],
                airsAt: CarbonImmutable::parse($airing->airs_at), expiresAt: CarbonImmutable::parse($airing->expires_at)) !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Promos of sources whose slot no longer follows today leave the queue and the spool (the truth rule at air time).
     *
     * @param  list<string>  $upcoming
     */
    private function withdraw(array $upcoming): void
    {
        $stale = CasterScript::query()->where('kind', CasterScript::TEASER)->where('scene', self::SCENE)
            ->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED, CasterScript::VOICED])->whereNotIn('topic', $upcoming)->get();

        foreach ($stale as $script) {
            $script->update(['status' => CasterScript::EXPIRED]);

            foreach (CasterClip::query()->where('caster_script_id', $script->id)->where('status', CasterClip::READY)->get() as $clip) {
                app(ClipSpool::class)->played($clip);
            }
        }
    }
}
