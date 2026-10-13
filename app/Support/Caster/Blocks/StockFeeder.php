<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Imaging;
use App\Support\Caster\InvalidClip;
use App\Support\Caster\Pcm;
use App\Support\Caster\PodcastLibrary;
use App\Support\Caster\QuietHours;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpoolFull;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The server feeds its own spool from the stock library (plan "TWENTY ONE
 * Radio", P18; `caster:stock-feed`, every `autopilot.poll_seconds`):
 *
 * By day with the workstation there (the hourly airtime budget of
 * DayPlanner, unchanged): an unaired library block goes into the spool as a
 * day-stock block whenever less than `library.feed_below_seconds` is ready
 * and none waits; it plays after every fresh block (ClipSpool::nextBlock()).
 * Nothing in the quiet hours: the night keeps its rhythm.
 *
 * In the autopilot (AutoPilot::active()):
 *  - the scripts the vanished workstation still held go back to the queue
 *    at once (no wait for `fetch.claim_seconds`);
 *  - once the air is free (no block or podcast waiting or on air) for a
 *    music gap (AutoPilot::musicGap()), the next library block goes in,
 *    StockLibrary::pick() with repeats allowed; a repeat opens with the
 *    Anchor's replay jingle (imaging category `replay`, mixed and encoded
 *    here; no such jingle, no repeat: music);
 *  - three minutes before each full hour the news-free station ID
 *    (`autopilot-hour`, else a long station ID) goes in, anchored to the hour
 *    like a rubric: it plays at :00 at the next sentence end;
 *  - every `podcast_every_hours` a podcast episode of the library airs from
 *    a minute past the next full hour (AutoPilot::pickEpisode()).
 *
 * An episode of several library blocks (the 30-minute talk show, P20) goes
 * in whole: its blocks as the parts of one story (`part_group`, `part_index`),
 * so the stream joins them back to back like any story told in parts
 * (SceneDirector::joinNextBlock(), ClipSpool::nextBlock() never starts a
 * later part); no music gap and no replay jingle between them.
 *
 * When the workstation is back (yieldBack(), once): the repeats, station
 * IDs and podcast airings the autopilot put in and that did not start yet
 * leave the spool (a repeat's airing is undone in the library); unaired
 * blocks stay (they are new and play after fresh items). Nothing on air is
 * cut by it.
 */
final class StockFeeder
{
    /** The topic of the autopilot's station ID at :00 (a rubric-like anchored block). */
    public const HOUR_TOPIC = 'autopilot_hour';

    /** The facts hash prefix of an autopilot podcast airing. */
    public const PODCAST_HASH = 'autopilot:';

    private const IDLE_SINCE = 'caster:autopilot:idle_since';

    private const GAP = 'caster:autopilot:gap';

    private const LAST_FORMAT = 'caster:stock_feed:last_format';

    private const PODCAST_AT = 'caster:autopilot:podcast_at';

    private const PODCAST_AIRED = 'caster:autopilot:podcast_aired';

    public function __construct(private readonly ClipSpool $spool) {}

    /**
     * One look at the air; returns what was done (for the command's output and the tests).
     *
     * @return list<string>
     */
    public function tick(CarbonImmutable $now): array
    {
        if (! StockLibrary::enabled()) {
            return [];
        }

        $library = StockLibrary::fromConfig();

        if (! AutoPilot::active($now)) {
            $done = Cache::pull(AutoPilot::WAS_ON) === true ? $this->yieldBack($library, $now) : [];
            $fed = $this->feedByDay($library, $now);

            return $fed === null ? $done : [...$done, $fed];
        }

        $done = [];

        if (Cache::get(AutoPilot::WAS_ON) !== true) {
            Cache::forever(AutoPilot::WAS_ON, true);
            $done[] = 'autopilot on, released '.$this->releaseClaims($now);
        }

        return array_values(array_filter([...$done, $this->hourId($now), $this->podcast($now), $this->feedAutopilot($library, $now)]));
    }

    /** By day, workstation there: an unaired block when the spool runs low and no stock block waits. */
    private function feedByDay(StockLibrary $library, CarbonImmutable $now): ?string
    {
        if (QuietHours::active($now) || $this->spool->readySeconds() >= (float) config('tv.commentary.blocks.stock.library.feed_below_seconds', 900)
            || CasterClip::query()->where('status', CasterClip::READY)->where('kind', CasterClip::BLOCK)->get()->contains(fn (CasterClip $clip): bool => $clip->isStock())) {
            return null;
        }

        $pick = $library->pick($now, repeats: false, previousFormat: Cache::get(self::LAST_FORMAT));

        return $pick === null ? null : $this->insert($library, $pick, $now, autopilot: false);
    }

    /** The autopilot's next block, once the air was free for a music gap. */
    private function feedAutopilot(StockLibrary $library, CarbonImmutable $now): ?string
    {
        if ($this->airBusy($now)) {
            Cache::forget(self::IDLE_SINCE);
            Cache::forget(self::GAP);

            return null;
        }

        $since = Cache::get(self::IDLE_SINCE);
        $since = is_int($since) ? $since : $now->getTimestamp();
        $gap = Cache::get(self::GAP);
        $gap = is_int($gap) ? $gap : AutoPilot::musicGap($now);
        Cache::put(self::IDLE_SINCE, $since, 3600);
        Cache::put(self::GAP, $gap, 3600);

        if ($now->getTimestamp() - $since < $gap) {
            return null;
        }

        $pick = $library->pick($now, repeats: true, previousFormat: Cache::get(self::LAST_FORMAT));

        if ($pick === null) {
            return 'nothing to air';
        }

        $done = $this->insert($library, $pick, $now, autopilot: true);
        Cache::forget(self::IDLE_SINCE);
        Cache::forget(self::GAP);

        return $done;
    }

    /**
     * Whether something of the programme waits or plays: a block (fresh, stock, replay) or a podcast chunk whose time
     * has come, or a podcast airing that starts within eight minutes. An anchored clip still waiting for its slot
     * (the station ID at :00) does not count.
     */
    private function airBusy(CarbonImmutable $now): bool
    {
        $utc = $now->utc();
        $future = CasterScript::query()->where('airs_at', '>', $utc)->select('id');
        $pending = CasterClip::query()->whereIn('status', [CasterClip::READY, CasterClip::PLAYING])->where('expires_at', '>', $utc)
            ->where(fn ($query) => $query->where('kind', CasterClip::BLOCK)->orWhere('kind', CasterScript::PODCAST))
            ->where(fn ($query) => $query->where('status', CasterClip::PLAYING)->orWhereNotIn('caster_script_id', $future))
            ->exists();

        return $pending || CasterClip::query()->where('status', CasterClip::READY)->where('kind', CasterScript::PODCAST)
            ->whereIn('caster_script_id', CasterScript::query()->where('kind', CasterScript::PODCAST)->whereBetween('airs_at', [$utc, $utc->addMinutes(8)])->select('id'))
            ->exists();
    }

    /**
     * A library block into the spool as a day-stock block; a repeat behind the replay jingle. An episode's further blocks
     * follow as the next parts of one story, without a jingle; refused by the spool, the episode ends there (the stream
     * ends a story whose next part does not come). A one-line outcome.
     *
     * @param  array{hash: string, meta: array<string, mixed>, repeat: bool, episode?: list<array{hash: string, meta: array<string, mixed>}>}  $pick
     */
    private function insert(StockLibrary $library, array $pick, CarbonImmutable $now, bool $autopilot): string
    {
        $rest = $pick['episode'] ?? [];
        $group = $rest === [] ? null : substr('lib'.sha1($pick['hash']), 0, 26);
        $done = $this->insertOne($library, $pick, $now, $autopilot, $group, 1);

        if ($group === null || ! str_starts_with($done, $pick['repeat'] ? 'repeat ' : 'block ')) {
            return $done;
        }

        foreach ($rest as $position => $next) {
            $outcome = $this->insertOne($library, ['hash' => $next['hash'], 'meta' => $next['meta'], 'repeat' => false], $now, $autopilot, $group, $position + 2);

            if (! str_starts_with($outcome, 'block ')) {
                return $done.', episode cut after '.($position + 1).': '.$outcome;
            }
        }

        return $done.' + '.count($rest).' more of the episode';
    }

    /**
     * One library block into the spool (see insert()), as part `$partIndex` of `$partGroup` when it belongs to an
     * episode.
     *
     * @param  array{hash: string, meta: array<string, mixed>, repeat: bool}  $pick
     */
    private function insertOne(StockLibrary $library, array $pick, CarbonImmutable $now, bool $autopilot, ?string $partGroup, int $partIndex): string
    {
        $meta = $pick['meta'];
        $opus = (string) @file_get_contents($library->file($pick['hash']));
        $seconds = (float) ($meta['seconds'] ?? 0);
        $ends = array_values(array_map('floatval', (array) ($meta['ends'] ?? [])));
        $boundaries = array_values(array_map('floatval', (array) ($meta['boundaries'] ?? [])));

        if ($pick['repeat']) {
            $dressed = $this->behindReplayJingle($library->file($pick['hash']), $now);

            if ($dressed === null) {
                return 'repeat skipped: no replay jingle';
            }

            $shift = fn (array $times): array => array_values(array_map(fn (float $time): float => round($dressed['offset'] + $time, 3), $times));
            [$opus, $seconds, $ends, $boundaries] = [$dressed['opus'], $dressed['seconds'], [round($dressed['offset'], 3), ...$shift($ends)], $shift($boundaries)];
        }

        $script = CasterScript::query()->create([
            'kind' => CasterScript::SCENE, 'phase' => ScenePhase::BREAK, 'scene' => StockScripts::SCENE, 'priority' => 0,
            'text' => $pick['repeat'] ? '(stock library repeat)' : '(stock library)', 'facts_hash' => 'stock:'.$pick['hash'],
            'topic' => is_string($meta['format'] ?? null) ? StockScripts::TOPIC_PREFIX.$meta['format'] : null,
            'status' => CasterScript::CLAIMED, 'source' => CasterScript::SOURCE_AI, 'estimated_seconds' => $seconds, 'claimed_at' => $now,
            'expires_at' => $now->addHours(max(1, (int) config('tv.commentary.blocks.autopilot.spool_hours', 3))),
            'part_group' => $partGroup, 'part_index' => $partGroup === null ? null : $partIndex,
        ]);
        $before = $library->markAired($pick['hash'], $now);

        try {
            $this->spool->storeBlock([$script], $opus, $seconds, $ends, $boundaries, 0.0, $now, replay: $pick['repeat'], stock: true,
                extra: ['library' => $pick['hash'], 'autopilot' => $autopilot, 'library_before' => $before]);
        } catch (SpoolFull|InvalidClip $exception) {
            $script->delete();

            if ($before !== null) {
                $library->restore($pick['hash'], $before);
            }

            return 'refused by the spool: '.($exception instanceof SpoolFull ? 'full' : $exception->getMessage());
        }

        Cache::put(self::LAST_FORMAT, (string) ($meta['format'] ?? ''), 86_400);

        return sprintf('%s %s %s %.0f s', $pick['repeat'] ? 'repeat' : 'block', substr($pick['hash'], 0, 8), (string) ($meta['format'] ?? '?'), $seconds);
    }

    /**
     * A library block behind the Anchor's replay jingle (the jingle's tail under the first words, as the workstation's
     * replay does it), encoded again; null without a replay jingle or when ffmpeg fails.
     *
     * @return array{opus: string, seconds: float, offset: float}|null
     */
    private function behindReplayJingle(string $file, CarbonImmutable $now): ?array
    {
        $imaging = Imaging::fromConfig();
        $jingle = $imaging?->library()->pick(['replay'], $now);

        if ($imaging === null || $jingle === null) {
            return null;
        }

        $rate = $imaging->rate;
        $intro = $imaging->library()->pcm($jingle['file'], $rate, (float) config('tv.commentary.blocks.jingles.gain_db', -3.0));
        $body = $imaging->pcm($file);

        if ($intro === null || $body === null) {
            return null;
        }

        $start = max(0, intdiv(strlen($intro), Pcm::BYTES_PER_SAMPLE) - (int) round((float) config('tv.commentary.blocks.crossfade_seconds', 0.45) * $rate));
        $pcm = Pcm::overlay($intro, $body, $start);
        $opus = app(CasterFetcher::class)->encode($pcm, $rate);

        return $opus === null ? null : ['opus' => $opus, 'seconds' => round(Pcm::seconds($pcm, $rate), 3), 'offset' => $start / $rate];
    }

    /** The news-free station ID at the next full hour, three minutes ahead, once per hour. */
    private function hourId(CarbonImmutable $now): ?string
    {
        $hour = $now->setTimezone((string) config('tv.commentary.programme.timezone', 'Europe/Berlin'))->startOfHour()->addHour()->utc();

        if ($hour->getTimestamp() - $now->getTimestamp() > 180
            || CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('topic', self::HOUR_TOPIC)->where('airs_at', $hour)->exists()) {
            return null;
        }

        $imaging = Imaging::fromConfig();
        $jingle = $imaging?->library()->pick(['autopilot-hour', 'station-long', 'station'], $now);
        $opus = $jingle === null ? '' : (string) @file_get_contents($jingle['file']);

        if ($jingle === null || $opus === '' || $jingle['seconds'] <= 0) {
            return null;
        }

        $script = CasterScript::query()->create([
            'kind' => CasterScript::RUBRIC, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => '(autopilot station ID)', 'facts_hash' => self::HOUR_TOPIC,
            'topic' => self::HOUR_TOPIC, 'status' => CasterScript::CLAIMED, 'source' => CasterScript::SOURCE_AI, 'estimated_seconds' => $jingle['seconds'],
            'claimed_at' => $now, 'airs_at' => $hour, 'expires_at' => $hour->addSeconds((int) config('tv.commentary.programme.rubrics.late_seconds', 180)),
        ]);

        try {
            $this->spool->storeBlock([$script], $opus, (float) $jingle['seconds'], [(float) $jingle['seconds']], [], (float) $jingle['seconds'], $now, extra: ['autopilot' => true]);
        } catch (SpoolFull|InvalidClip) {
            $script->delete();

            return null;
        }

        return 'station ID '.$jingle['id'].' at '.$hour->toIso8601String();
    }

    /** An episode of the podcast library from a minute past the next full hour, every `podcast_every_hours`. */
    private function podcast(CarbonImmutable $now): ?string
    {
        $last = Cache::get(self::PODCAST_AT);
        $hour = $now->setTimezone((string) config('tv.commentary.programme.timezone', 'Europe/Berlin'))->startOfHour()->addHour()->utc();

        if (! PodcastLibrary::enabled() || ! AutoPilot::podcastDue(is_int($last) ? $last : null, $now) || $hour->getTimestamp() - $now->getTimestamp() > 600
            || CasterClip::query()->where('kind', CasterScript::PODCAST)->whereIn('status', [CasterClip::READY, CasterClip::PLAYING])->exists()) {
            return null;
        }

        $library = app(PodcastLibrary::class);
        $episodes = [];

        // Every stored episode of every source (the current one and those the night loop keeps, PodcastLibrary::episodes()).
        foreach (PodcastLibrary::sources() as $key => $source) {
            foreach ($library->episodes($key) as $episode) {
                $episodes[$episode['guid']] ??= ['key' => (string) $key, 'name' => $source['name'], 'episode' => $episode];
            }
        }

        $aired = array_filter((array) Cache::get(self::PODCAST_AIRED, []), 'is_int');
        $pick = AutoPilot::pickEpisode(array_values(array_map(fn (array $entry): array => ['key' => $entry['key'], 'guid' => $entry['episode']['guid'], 'published_at' => $entry['episode']['published_at']], $episodes)), $aired, $now);

        if ($pick === null) {
            return null;
        }

        ['key' => $key, 'name' => $name, 'episode' => $episode] = $episodes[$pick['guid']];
        $slot = $hour->addMinute();

        DB::transaction(function () use ($key, $name, $episode, $slot, $now): void {
            $script = CasterScript::query()->create([
                'kind' => CasterScript::PODCAST, 'phase' => ScenePhase::BREAK, 'priority' => 0,
                'text' => mb_substr($name.': '.$episode['title'], 0, 500), 'facts_hash' => self::PODCAST_HASH.sha1($episode['guid']),
                'status' => CasterScript::VOICED, 'source' => CasterScript::SOURCE_AI, 'topic' => $key,
                'estimated_seconds' => $episode['seconds'], 'claimed_at' => $now, 'airs_at' => $slot, 'expires_at' => $slot->addSeconds((int) ceil(2 * $episode['seconds']) + 3600),
            ]);

            $this->spool->storePodcast($script, $key, $episode['chunks']);
        });

        Cache::put(self::PODCAST_AT, $now->getTimestamp(), 7 * 86_400);
        Cache::put(self::PODCAST_AIRED, [...$aired, $episode['guid'] => $now->getTimestamp()], 7 * 86_400);

        return 'podcast '.$key.' '.$episode['guid'].' at '.$slot->toIso8601String();
    }

    /** The scripts the vanished workstation held go back to the queue at once (its claims never come back). */
    private function releaseClaims(CarbonImmutable $now): int
    {
        $seen = ClipSpool::cachedTimestamp(Cache::get(ClipSpool::FETCHER_SEEN));
        $queue = app(ScriptQueue::class);
        $released = 0;

        foreach (CasterScript::query()->where('status', CasterScript::CLAIMED)->when($seen !== null, fn ($query) => $query->where('claimed_at', '<=', CarbonImmutable::createFromTimestamp((int) $seen)))->get() as $script) {
            $queue->release($script);
            $released++;
        }

        return $released;
    }

    /**
     * The workstation is back: what the autopilot put in and did not start leaves the spool; unaired blocks stay.
     *
     * @return list<string>
     */
    private function yieldBack(StockLibrary $library, CarbonImmutable $now): array
    {
        $removed = 0;
        $scripts = [];

        foreach (CasterClip::query()->where('status', CasterClip::READY)->get() as $clip) {
            $block = is_array($clip->block) ? $clip->block : [];
            $script = CasterScript::query()->find($clip->caster_script_id);
            $autopilotPodcast = $clip->kind === CasterScript::PODCAST && $script !== null && str_starts_with((string) $script->facts_hash, self::PODCAST_HASH)
                && $script->airs_at !== null && $script->airs_at->greaterThan($now);

            if (($block['autopilot'] ?? false) !== true && ! $autopilotPodcast) {
                continue;
            }

            // An unaired library block is new: it stays and plays after the fresh items.
            if ($clip->isStock() && ! $clip->isReplay()) {
                continue;
            }

            if ($clip->isReplay() && isset($block['library'], $block['library_before'])) {
                $library->restore($block['library'], $block['library_before']);
            }

            $this->spool->played($clip);
            $scripts[] = $clip->caster_script_id;
            $removed++;
        }

        // The stand-in scripts last: a podcast airing's later chunks still find theirs above.
        CasterScript::query()->whereKey(array_unique($scripts))->delete();

        return ['autopilot off, removed '.$removed];
    }
}
