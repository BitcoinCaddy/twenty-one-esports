<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\DayPlanner;
use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\Programme\Announcements;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\MusicTeasers;
use App\Support\Caster\Programme\NewsBulletin;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\PodcastPromos;
use App\Support\Caster\Programme\ProgrammePreview;
use App\Support\Caster\Programme\Roasts;
use App\Support\Caster\Programme\RoastScripts;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\RubricScripts;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\ShowScripts;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\Programme\TalkShowScripts;
use App\Support\Caster\Programme\TournamentLive;
use App\Support\Voice\VoiceHygiene;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The server's queue of commentary scripts (plan "Puffer und Länge", stage 1):
 * scene scripts are written ahead per slide topic (SceneTopic, with the
 * facts its slides render) until `ahead_seconds` of airtime are queued or
 * spooled: the league voice first, at most `ai_calls_per_fill` (none while
 * `tv.commentary.ai` is off), the rest as pattern scripts (PatternScripts),
 * so airtime never depends on the model; live moments and chat reactions
 * jump the queue; a script expires unused after `max_age_minutes` or when
 * the facts of its topic changed. The workstation fetcher claims the next
 * script, voices it and uploads the clip (ClipSpool), or releases it.
 *
 * The long segments (news, "Markus erzählt vom Bauernkrieg", a followed
 * profile's Nostr note) are written here too, each when it is due
 * (NewsScripts, HistoryScripts, FollowedScripts), and a "Rückblick-Spitze"
 * on the world memory (fillCallback(), a short scene): normal
 * priority, so live moments and reactions still jump ahead of them, and
 * ClipSpool plays them on calm slots only. They do not count as airtime
 * ahead: a long Bauernkrieg segment waiting for a calm slot must not starve
 * the scene scripts of a live match.
 *
 * The length mix (LengthMix, `tv.commentary.length`): a share of each kind
 * is a long variant, a long pattern scene, a long news comment, a long
 * Bauernkrieg episode.
 *
 * Long scripts in parts (live 2026-10-11: "er wollte was über Bauernkriege
 * sagen und hat dann einfach aufgehört" — the reply played, then 2.5 minutes
 * of silence while the workstation voiced a 27-line episode as one clip): a
 * scene, a segment or a wished segment of more than `parts.sentences`
 * sentences is queued as consecutive parts of at most that many sentences,
 * split between lines only (one sentence per line), sharing `part_group`.
 * claim() hands out a later part only once the parts before it left the
 * queue, so the first part is voiced and spooled within seconds and the rest
 * follow in order; the parts share one expiry, and expire() expires the rest
 * of a group once one part expired. SceneDirector plays them back to back.
 *
 * The news bulletins (plan "TWENTY ONE Radio", P1/P2; fillBulletin()): the
 * broadcast clock names the next bulletin slot (:00, :30; at night only :00),
 * and from `bulletin.write_minutes_ahead` before it the bulletin is written
 * (NewsBulletin) and queued at `bulletin.priority`, so the workstation voices
 * it before the scenes and it waits in the spool for its slot (`airs_at`);
 * also in the quiet hours. It expires `bulletin.late_seconds` after its slot.
 *
 * The rubrics and teasers (P5; fillRubric(), fillTeaser()): the rubric
 * planned for the next rubric slot (Rubrics) is written the same way from
 * `rubrics.write_minutes_ahead` before it, in parts, and waits for its slot;
 * a teaser for the next bulletin or rubric (Announcements) is written
 * `teasers.write_minutes_ahead` before it and plays within
 * `teasers.before_minutes` of it (outside the quiet hours).
 *
 * Bounded: expired and voiced rows older than a day are deleted, so the table
 * holds at most a day of scripts.
 */
class ScriptQueue
{
    /** Rows that left the queue are kept this long (for the counters of `caster:script`), then deleted. */
    private const KEEP_HOURS = 24;

    /** The fewest sentences a script keeps before its first repeat or sign-off (enqueue()); a chat reply or live line needs one. */
    public const MIN_COHERENT = 2;

    private PatternScripts $patterns;

    private NewsScripts $news;

    private HistoryScripts $history;

    private FollowedScripts $followed;

    private DuetDirector $duets;

    private DuetScripts $duetScripts;

    private ?SpokenMemory $memory = null;

    private NewsBulletin $bulletin;

    private RubricScripts $rubricScripts;

    public function __construct(
        private CasterVoice $voice,
        private SceneFacts $facts,
        ?PatternScripts $patterns = null,
        ?NewsScripts $news = null,
        ?HistoryScripts $history = null,
        ?FollowedScripts $followed = null,
        ?DuetDirector $duets = null,
        ?DuetScripts $duetScripts = null,
        ?NewsBulletin $bulletin = null,
        ?RubricScripts $rubricScripts = null,
    ) {
        $this->patterns = $patterns ?? app(PatternScripts::class);
        $this->news = $news ?? app(NewsScripts::class);
        $this->history = $history ?? app(HistoryScripts::class);
        $this->followed = $followed ?? app(FollowedScripts::class);
        $this->duets = $duets ?? app(DuetDirector::class);
        $this->duetScripts = $duetScripts ?? app(DuetScripts::class);
        $this->bulletin = $bulletin ?? app(NewsBulletin::class);
        $this->rubricScripts = $rubricScripts ?? app(RubricScripts::class);
    }

    /** Whether the league voice writes scene scripts (TV_COMMENTARY_AI); off, the patterns fill the airtime alone. */
    public static function aiEnabled(): bool
    {
        return config('tv.commentary.ai') === true;
    }

    /** Airtime (seconds) queued, claimed or spooled and not yet played, fillers, segments and bulletins left out. */
    public function airtimeAhead(): float
    {
        $scripts = (float) CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->whereNotIn('kind', [CasterScript::FILLER, ...CasterScript::PROGRAMME, ...CasterScript::SEGMENTS])->where(self::notStock(...))->sum('estimated_seconds');

        return $scripts + app(ClipSpool::class)->readySeconds();
    }

    /**
     * Write scene scripts until the airtime ahead reaches its target, each
     * for the topic with data that has the least airtime ready: by the league
     * voice first (user 2026-10-12: "FREIE KI-generierte Rede!!! Komplette
     * Varianz!"), at most `ai_calls_per_fill` scripts per run, a long one when
     * the length mix asks for it; a pattern script only where the voice gave
     * nothing (off, capped, both tries failed), at most `patterns.per_run`. A
     * topic without data of its own gets no script, so the model is never
     * asked about an empty scene.
     *
     * @return int scripts written
     */
    public function fill(CarbonImmutable $now): int
    {
        if (! CasterVoice::enabled()) {
            return 0;
        }

        $this->expire($now);
        $this->seedFillers($now);
        $quiet = QuietHours::active($now);
        // While a podcast replay airs (P12) only the bulletins and sport and weather are written: no talk, rubric,
        // teaser, segment or slide scene would get on air before it ends.
        $podcast = PodcastReplay::active($now);
        // The voicing budget (VoiceBudget): a tier the workstation cannot voice in time is not written, highest tier first.
        $budget = app(VoiceBudget::class);
        // Live only (LiveOnly): the news, one comment after it (Sport und Wetter), the shows and the reactions, nothing generic.
        $liveOnly = LiveOnly::active($now);
        $written = $this->fillBulletin($now);
        $written += $budget->opens(VoiceBudget::NEWS, $now) ? $this->fillSportWeather($now) : 0;
        $written += $quiet || $podcast || $liveOnly || ! $budget->opens(VoiceBudget::NEWS, $now) ? 0 : $this->fillNewsTalk($now);
        // The Programmvorschau after the news block (P14), also at 08:5x for 09:03 while the quiet hours still run.
        $written += $podcast || $liveOnly || ! $budget->opens(VoiceBudget::RUBRIC, $now) ? 0 : $this->fillPreview($now);
        $written += $podcast ? 0 : $this->fillRubric($now, $liveOnly);
        $written += $quiet || $podcast || $liveOnly ? 0 : ($budget->opens(VoiceBudget::RUBRIC, $now) ? $this->fillTeaser($now) : 0) + $this->fillSegments($now);
        // Live comments on a running organizer tournament (TournamentLive), also in the live-only mode: real data only.
        $written += $podcast ? 0 : app(TournamentLive::class)->fill($this, $now);
        // The stock is written here, in the scheduler (CLI, no web timeout), not in the claim (live 2026-10-16:
        // the claim wrote its stock text synchronously over the tunnel to the workstation's opencode gateway,
        // 40-70 s, and nginx killed every such claim with a 502 - the GPU stayed idle all night). fillStock's own
        // gates (window, report, library target) decide; the claim only hands out what is queued.
        $written += $this->fillStock($now);
        $written += $this->fillPodcast($now);

        $settings = (array) config('tv.commentary.scripts', []);
        $sentences = (array) ($settings['sentences'] ?? []);
        $night = ScenePhase::isNight($now);
        $factsByTopic = [];

        foreach (SceneTopic::ALL as $topic) {
            $facts = $this->facts->forTopic($topic, $night);
            $this->supersedeTopic($topic, SceneFacts::hash($facts));

            if (! $podcast && ! $liveOnly && (! $night || in_array($topic, SceneTopic::NIGHT, true)) && SceneFacts::hasData($topic, $facts) && ! $this->scenesSuppressed($topic, $quiet)) {
                $factsByTopic[$topic] = $facts;
            }
        }

        $aiLeft = self::aiEnabled() ? max(0, (int) ($settings['ai_calls_per_fill'] ?? 6)) : 0;
        $patternsLeft = max(0, (int) config('tv.commentary.patterns.per_run', 40));
        $exhausted = [];

        while ($this->airtimeAhead() < ($quiet ? QuietHours::aheadSeconds() : (float) ($settings['ahead_seconds'] ?? 300)) && ($candidates = array_diff(array_keys($factsByTopic), $exhausted)) !== []
            && $budget->opens(VoiceBudget::SCENE, $now, deadline: $now->addMinutes((int) ($settings['max_age_minutes'] ?? 10)))) {
            $topic = $this->thinnestTopic(array_values($candidates));
            $phase = SceneTopic::phase($topic, $now);
            $facts = $factsByTopic[$topic] ?? [];

            if ($aiLeft > 0) {
                $aiLeft--;

                // About `duet.share` of the scenes are an exchange of Markus and Sirius (DuetDirector); when the duet
                // gives nothing, Markus speaks alone as before.
                $sequence = $this->duets->next($now);
                $duet = $sequence === null ? null : $this->duetScripts->write($sequence, $phase, $facts, $this->topics($phase, $now), $this->duets->lastTurns($now));

                if ($duet !== null && $this->enqueue(CasterScript::SCENE, $phase, $duet['text'], SceneFacts::hash($facts), 0, $now, source: CasterScript::SOURCE_AI, topic: $topic, speakers: $duet['speakers']) !== null) {
                    $written++;

                    continue;
                }

                $long = LengthMix::isDue(LengthMix::SCENE);
                $range = $long ? (array) config('tv.commentary.length.scene_long', []) : $sentences;
                $script = $this->voice->write(CasterScript::SCENE, $phase, $facts, (int) ($range['min'] ?? 2), (int) ($range['max'] ?? 8), [], $this->topics($phase, $now), $long);

                if ($script !== null && $this->enqueue(CasterScript::SCENE, $phase, $script['text'], SceneFacts::hash($facts), 0, $now, source: CasterScript::SOURCE_AI, topic: $topic) !== null) {
                    LengthMix::written(LengthMix::SCENE, $long);
                    $written++;

                    continue;
                }
            }

            if ($patternsLeft <= 0 || ! PatternScripts::enabled()) {
                $exhausted[] = $topic;

                continue;
            }

            $patternsLeft--;
            // Every so often a long one (LengthMix); when the topic cannot make one, the short script stands in.
            $long = LengthMix::isDue(LengthMix::SCENE);
            $script = $long ? $this->patterns->write($topic, $phase, $facts, $now, long: true) : null;
            $long = $script !== null;
            $script ??= $this->patterns->write($topic, $phase, $facts, $now);

            if ($script === null || $this->enqueue(CasterScript::SCENE, $phase, $script['text'], SceneFacts::hash($facts), 0, $now, source: CasterScript::SOURCE_PATTERN, topic: $topic) === null) {
                $exhausted[] = $topic;

                continue;
            }

            LengthMix::written(LengthMix::SCENE, $long);
            $written++;
        }

        return $written;
    }

    /**
     * Whether the slide scene scripts of `$topic` are left out now (user
     * 2026-10-12: "zwischen dem vorgerenderten Programm kommen so generische
     * Sprüche und die sind total langweilig"): by day, with
     * `stock.suppress_scenes` on, while the workstation reports at least
     * `suppress_min_seconds` of day stock or at least `scripts.ahead_seconds`
     * of fresh talk (segments, rubrics) waits; never for `keep_scene_topics`
     * (a running match).
     */
    public function scenesSuppressed(string $topic, bool $quiet): bool
    {
        $settings = (array) config('tv.commentary.blocks.stock', []);

        if ($quiet || ! DayStock::enabled() || ($settings['suppress_scenes'] ?? false) !== true || in_array($topic, (array) ($settings['keep_scene_topics'] ?? []), true)) {
            return false;
        }

        $report = DayStock::report();
        $freshTalk = (float) CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->whereIn('kind', [CasterScript::RUBRIC, ...CasterScript::SEGMENTS])->sum('estimated_seconds');

        return ($report !== null && $report['seconds'] >= (float) ($settings['suppress_min_seconds'] ?? 600))
            || $freshTalk >= (float) config('tv.commentary.scripts.ahead_seconds', 300);
    }

    /**
     * Evergreen talks for the day stock (StockScripts), written ahead in the
     * stock window while the workstation reports room in its stock: at most
     * one per run, until two wait unclaimed. Queued as scenes of scene
     * StockScripts::SCENE (in parts like any long scene), which only a stock
     * claim (claimStock()) hands out.
     *
     * @return int talks written (0 or 1)
     */
    public function fillStock(CarbonImmutable $now): int
    {
        if (! DayPlanner::writeStockAhead($now, DayStock::report())
            // A long format's write window is open: the writer belongs to the show, the stock waits (live
            // 2026-10-16: one whole-programme stock write holds the writer for minutes, and the day's shows
            // expired unwritten behind them).
            || self::showWriteOpen($now)
            || CasterScript::query()->where('scene', StockScripts::SCENE)->where('status', CasterScript::QUEUED)->where('expires_at', '>', $now)
                ->where(fn ($query) => $query->whereNull('part_index')->orWhere('part_index', 1))->count() >= 2
            // At most `writes_per_hour` programmes an hour (live 2026-10-16): a whole-programme write holds the
            // writer and the gateway for minutes, and the fresh programme must not queue behind it.
            || CasterScript::query()->where('scene', StockScripts::SCENE)->where('created_at', '>=', $now->subHour())
                ->where(fn ($query) => $query->whereNull('part_index')->orWhere('part_index', 1))->count()
                >= max(1, (int) config('tv.commentary.blocks.stock.writes_per_hour', 2))) {
            return 0;
        }

        return $this->writeStock($now) !== null ? 1 : 0;
    }

    /**
     * Whether a long format's write window is open: a show slot the schedule owns, within its `write_minutes_ahead`
     * and not written yet (the same slot fillRubric() would write). While one is, the stock writer waits, so a
     * show's segments are never queued behind a whole-programme stock write.
     */
    private static function showWriteOpen(CarbonImmutable $now): bool
    {
        if (config('tv.commentary.programme.shows.enabled') !== true) {
            return false;
        }

        $furthest = max(1, ...array_values(array_map(
            fn (mixed $format): int => is_array($format) ? (int) ($format['write_minutes_ahead'] ?? 0) : 0,
            (array) config('tv.commentary.programme.shows.formats', []),
        )));

        foreach (BroadcastClock::fromConfig()->upcoming($now, $furthest) as $slot) {
            if ($slot['type'] !== BroadcastClock::RUBRIC) {
                continue;
            }

            $show = app(Shows::class)->scheduledFor($slot);
            $ahead = $slot['key'] - $now->getTimestamp();

            if ($show !== null && $ahead <= 60 * (int) (Shows::settings($show)['write_minutes_ahead'] ?? 0) && ! Rubrics::written($slot['key'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names written the way the voices must say them (`say_as`, user 2026-10-14: "Noerd muss immer als Nörd umgeschrieben
     * und damit als Nörd gelesen werden"): whole words, any case, in every script before it is queued.
     */
    public static function sayAs(string $text): string
    {
        // Clock times: the voice reads a colon as "Uhr", so "22:37 Uhr" came out as "22 Uhr 37 Uhr" (live
        // 2026-10-15). Normalise to one spoken "Uhr" whatever the model wrote: "22:37 Uhr" and "22:37" both
        // become "22 Uhr 37", bare minutes keep their place ("14:05" -> "14 Uhr 5").
        $text = (string) preg_replace_callback('/(?<![\p{L}\p{N}])([01]?\d|2[0-3]):([0-5]\d)(\s*[uU]hr)?(?![\p{L}\p{N}])/u',
            fn (array $m): string => $m[1].' Uhr '.(ltrim($m[2], '0') ?: '0'), $text);

        foreach ((array) config('tv.commentary.say_as', []) as $written => $spoken) {
            if (is_string($written) && is_string($spoken) && $written !== '') {
                $text = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($written, '/').'(?![\p{L}\p{N}])/iu', $spoken, $text);
            }
        }

        return $text;
    }

    /**
     * The next stock script for the workstation (`?only=stock`): a queued
     * one (a later part of a talk only after the part before it), else one
     * written now. null when the writer gives nothing.
     */
    public function claimStock(CarbonImmutable $now): ?CasterScript
    {
        // Writing on demand is gone from the claim (live 2026-10-16: the synchronous stock text over the tunnel
        // took 40-70 s and the web server killed the request with a 502). The scheduler's fill() writes the stock
        // (ScriptQueue::fill calls fillStock); the claim only hands out what is queued.
        return $this->claim($now, stock: true);
    }

    private function writeStock(CarbonImmutable $now): ?CasterScript
    {
        $talk = app(StockScripts::class)->write();

        // The facts hash carries the subject (P18): the claim hands it to the workstation for the library's topic memory.
        return $talk === null ? null : $this->enqueue(CasterScript::SCENE, ScenePhase::BREAK, $talk['text'], $talk['subject'], 0, $now,
            scene: StockScripts::SCENE, topic: StockScripts::TOPIC_PREFIX.$talk['format'], maxAgeMinutes: (int) config('tv.commentary.blocks.stock.max_age_minutes', 180), speakers: $talk['speakers']);
    }

    /**
     * The podcast replay's promos, announcement and back-announce (P12, PodcastPromos), AI only.
     *
     * @return int scripts written
     */
    public function fillPodcast(CarbonImmutable $now): int
    {
        return self::aiEnabled() && PodcastLibrary::enabled() ? app(PodcastPromos::class)->fill($this, $now) : 0;
    }

    /**
     * A query constraint: not a script written for the day stock.
     *
     * @param  Builder<CasterScript>  $query
     */
    public static function notStock(Builder $query): void
    {
        $query->whereNull('scene')->orWhere('scene', '!=', StockScripts::SCENE);
    }

    /**
     * The next news bulletin (NewsBulletin), once per slot: written when the
     * broadcast clock's next bulletin slot is at most `write_minutes_ahead`
     * and at least `last_write_minutes` away, AI only; a call that gave
     * nothing is tried again on the next run within that window. Queued at
     * `bulletin.priority` with its slot as `airs_at`, expiring
     * `bulletin.late_seconds` after it.
     *
     * @return int bulletins written (0 or 1)
     */
    public function fillBulletin(CarbonImmutable $now): int
    {
        $clock = BroadcastClock::fromConfig();
        $slot = self::aiEnabled() && config('tv.commentary.news.enabled') === true ? $clock->nextBulletin($now) : null;

        if ($slot === null) {
            return 0;
        }

        $settings = (array) config('tv.commentary.programme.bulletin', []);
        $ahead = $slot['key'] - $now->getTimestamp();
        $hash = 'bulletin:'.$slot['key'];

        if ($ahead > 60 * (float) ($settings['write_minutes_ahead'] ?? 7) || $ahead < 60 * (float) ($settings['last_write_minutes'] ?? 2)
            || CasterScript::query()->where('kind', CasterScript::BULLETIN)->where('facts_hash', $hash)->exists()) {
            return 0;
        }

        // Two lanes write at once (caster:script and caster:bulletin, live 2026-10-16): the hash check above
        // sees only what already landed, so a slot being written right now is claimed here first. The lock
        // covers a slow write and frees itself for the retry after a failed one.
        if (! Cache::add('caster:bulletin:writing:'.$slot['key'], true, $now->addMinutes(3))) {
            return 0;
        }

        $bulletin = $this->bulletin->write($slot, $now);

        return $bulletin !== null && $this->enqueue(CasterScript::BULLETIN, ScenePhase::BREAK, $bulletin['text'], $hash, (int) ($settings['priority'] ?? 75), $now,
            source: CasterScript::SOURCE_AI, topic: $slot['type'], speakers: $bulletin['speakers'], airsAt: $slot['starts_at'],
            expiresAt: $slot['starts_at']->addSeconds((int) ($settings['late_seconds'] ?? 120))) !== null ? 1 : 0;
    }

    /**
     * The rubric of the next rubric slot (Rubrics::plannedFor(), RubricScripts),
     * once per slot: written when the slot is at most
     * `rubrics.write_minutes_ahead` and at least `last_write_minutes` away, AI
     * only (a call that gave nothing is tried again on the next run within
     * that window). Queued in parts at `rubrics.priority` with its slot as
     * `airs_at`; SceneDirector starts it at most `late_seconds` after the slot.
     * The quiet hours have no rubric slots (BroadcastClock). `$liveOnly`
     * (LiveOnly): only a show, never the rotation's rubric.
     *
     * @return int rubrics written (0 or 1)
     */
    public function fillRubric(CarbonImmutable $now, bool $liveOnly = false): int
    {
        if (! self::aiEnabled() || ! Rubrics::enabled()) {
            return 0;
        }

        $settings = (array) config('tv.commentary.programme.rubrics', []);
        $write = max(1, (int) ($settings['write_minutes_ahead'] ?? 8));
        // A long format may start writing earlier (the hotline looks its questions up first).
        $furthest = max($write, ...array_values(array_map(fn (mixed $format): int => is_array($format) ? (int) ($format['write_minutes_ahead'] ?? 0) : 0, (array) config('tv.commentary.programme.shows.formats', []))));
        $slots = array_values(array_filter(BroadcastClock::fromConfig()->upcoming($now, $furthest), fn (array $slot): bool => $slot['type'] === BroadcastClock::RUBRIC));
        $slot = null;

        // The first slot due for writing: a long show two hours ahead is not held up by the rubrics written before it (P21:
        // voicing thirty minutes takes about as long, so it cannot wait until the slot before it is gone).
        foreach ($slots as $candidate) {
            $ahead = $candidate['key'] - $now->getTimestamp();
            $show = app(Shows::class)->scheduledFor($candidate);
            $window = $show === null ? $write : max($write, (int) (Shows::settings($show)['write_minutes_ahead'] ?? 0));

            if ($ahead <= 60 * $window && $ahead >= 60 * (float) ($settings['last_write_minutes'] ?? 2) && ! Rubrics::written($candidate['key'])) {
                $slot = $candidate;

                break;
            }
        }

        if ($slot === null) {
            return 0;
        }

        $ahead = $slot['key'] - $now->getTimestamp();

        $rubrics = app(Rubrics::class);
        $id = $rubrics->plannedFor($slot, $now);

        // Planned early for a long format's window, the rotation's rubric still waits for its own; neither is written beyond
        // the voicing budget of its tier (VoiceBudget: a show above the chat, a rubric below the news).
        if ($id === null || (! Shows::isShow($id) && ($liveOnly || $ahead > 60 * $write)) || ! app(VoiceBudget::class)->opens(VoiceBudget::tierOf(CasterScript::RUBRIC, $id), $now)) {
            return 0;
        }

        if (Shows::isShow($id)) {
            $material = app(Shows::class)->material($id, $now);
            $rubric = $material === null ? null : ($id === Shows::STAMMTISCH
                ? app(TalkShowScripts::class)->write($slot, $material, $now)
                : app(ShowScripts::class)->write($slot, $id, $material, $now));
        } else {
            $material = $rubrics->material($id, $now);
            $rubric = $material === null ? null
                : (Roasts::owns($id) ? app(RoastScripts::class)->write($slot, $id, $material, $now) : $this->rubricScripts->write($slot, $id, $material, $now));
        }

        if ($material === null || $rubric === null
            || $this->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $rubric['text'], Rubrics::hashFor($id, $material, $slot['key']), (int) ($settings['priority'] ?? 70), $now,
                source: CasterScript::SOURCE_AI, topic: $id, speakers: $rubric['speakers'], airsAt: $slot['starts_at'],
                expiresAt: $slot['starts_at']->addSeconds((int) ($settings['late_seconds'] ?? 180) + 600)) === null) {
            return 0;
        }

        $rubrics->aired($id, $material, $now);

        return 1;
    }

    /** When a bulletin of `$text` on air from `$slotStart` ends: its spoken length plus the stinger in front of it. */
    public static function bulletinEnd(CarbonImmutable $slotStart, string $text): CarbonImmutable
    {
        return $slotStart->addSeconds((int) ceil(self::estimateSeconds($text) + 4));
    }

    /**
     * Sport und Wetter after the next :00 bulletin (SportWeather), once per
     * slot: written once that bulletin is queued, before the news talk is
     * (a talk already written keeps its place, the segment is then left out)
     * and while the slot is at least a minute away, AI only; also in the
     * quiet hours (`sport_weather.quiet`). Queued in parts as a rubric (topic
     * SportWeather::TOPIC) airing when the bulletin ends; the news talk airs
     * after it (fillNewsTalk()). A source that is down leaves its half out,
     * both down leave the segment out.
     *
     * @return int segments written (0 or 1)
     */
    public function fillSportWeather(CarbonImmutable $now): int
    {
        $slot = self::aiEnabled() && SportWeather::enabled() && config('tv.commentary.news.enabled') === true ? BroadcastClock::fromConfig()->nextBulletin($now) : null;

        if ($slot === null || $slot['type'] !== BroadcastClock::NEWS || $slot['key'] - $now->getTimestamp() < 60) {
            return 0;
        }

        $hash = 'sport_weather:'.$slot['key'];
        $bulletin = CasterScript::query()->where('kind', CasterScript::BULLETIN)->where('facts_hash', 'bulletin:'.$slot['key'])->orderBy('id')->first();

        if ($bulletin === null || CasterScript::query()->where('kind', CasterScript::RUBRIC)->whereIn('facts_hash', [$hash, 'news_talk:'.$slot['key']])->exists()) {
            return 0;
        }

        $settings = (array) config('tv.commentary.programme.sport_weather', []);
        $segment = app(SportWeather::class)->write($slot, $now);
        $airsAt = self::bulletinEnd($slot['starts_at'], $bulletin->text);

        return $segment !== null && $this->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $segment['text'], $hash, (int) ($settings['priority'] ?? 70), $now,
            source: CasterScript::SOURCE_AI, topic: SportWeather::TOPIC, speakers: $segment['speakers'], airsAt: $airsAt,
            expiresAt: $airsAt->addSeconds((int) ($settings['late_seconds'] ?? 240) + 600)) !== null ? 1 : 0;
    }

    /**
     * The news talk after the next :00 bulletin (NewsTalk), once per slot:
     * written once that bulletin is queued (its items, NewsBulletin) and the
     * slot is at least a minute away, AI only; queued in parts as a rubric
     * (topic NewsTalk::TOPIC) airing when the bulletin ends (its estimated
     * length after the slot) and Sport und Wetter after it (its estimated
     * length, when one was written), at most `news_talk.late_seconds` late.
     *
     * @return int talks written (0 or 1)
     */
    public function fillNewsTalk(CarbonImmutable $now): int
    {
        $slot = self::aiEnabled() && NewsTalk::enabled() && config('tv.commentary.news.enabled') === true ? BroadcastClock::fromConfig()->nextBulletin($now) : null;

        if ($slot === null || $slot['type'] !== BroadcastClock::NEWS || $slot['key'] - $now->getTimestamp() < 60) {
            return 0;
        }

        $hash = 'news_talk:'.$slot['key'];
        $bulletin = CasterScript::query()->where('kind', CasterScript::BULLETIN)->where('facts_hash', 'bulletin:'.$slot['key'])->orderBy('id')->first();
        $items = NewsTalk::itemsFor($slot['key']);

        if ($bulletin === null || $items === [] || CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('facts_hash', $hash)->exists()) {
            return 0;
        }

        $settings = (array) config('tv.commentary.programme.news_talk', []);
        // When the bulletin ends (its spoken length plus the stinger in front of it), and Sport und Wetter after it.
        $sportWeather = (float) CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('facts_hash', 'sport_weather:'.$slot['key'])
            ->where('status', '!=', CasterScript::EXPIRED)->sum('estimated_seconds');
        $airsAt = self::bulletinEnd($slot['starts_at'], $bulletin->text)->addSeconds((int) ceil($sportWeather));
        $talk = app(NewsTalk::class)->write($slot, $items, $airsAt, $now);

        return $talk !== null && $this->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $talk['text'], $hash, (int) ($settings['priority'] ?? 70), $now,
            source: CasterScript::SOURCE_AI, topic: NewsTalk::TOPIC, speakers: $talk['speakers'], airsAt: $airsAt,
            expiresAt: $airsAt->addSeconds((int) ($settings['late_seconds'] ?? 240) + 900)) !== null ? 1 : 0;
    }

    /**
     * The Programmvorschau (P14, ProgrammePreview) at the slot of
     * `programme.preview.slots` whose writing window holds `$now`, once per
     * slot, AI only, never while a podcast replay is on (fill()). It airs
     * after the news block of its hour: the bulletin before its time, Sport und
     * Wetter and the news talk of that bulletin (their estimated lengths), not
     * before its own time. It waits while that bulletin or news talk can
     * still be written (up to a minute before the bulletin slot), so it never
     * gets between them; queued in parts as a rubric (topic
     * ProgrammePreview::TOPIC). At most `preview.tries` writes per slot.
     *
     * @return int previews written (0 or 1)
     */
    public function fillPreview(CarbonImmutable $now): int
    {
        $target = self::aiEnabled() && ProgrammePreview::enabled() ? ProgrammePreview::target($now) : null;

        // While a podcast replay is on (or comes within its lead) the preview is left out, not postponed.
        if ($target === null || PodcastReplay::active($now)) {
            return 0;
        }

        $hash = 'programmvorschau:'.$target['key'];

        if (CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('facts_hash', $hash)->exists()) {
            return 0;
        }

        // The bulletin slot of the preview's hour at or before its time, and what airs after it.
        $bulletins = array_values(array_filter(BroadcastClock::fromConfig()->upcoming($target['at']->subMinutes(59), 59),
            fn (array $slot): bool => BroadcastClock::isBulletin($slot['type']) && $slot['key'] <= $target['key']));
        $slot = $bulletins === [] ? null : $bulletins[count($bulletins) - 1];
        $airsAt = $target['at'];

        if ($slot !== null && config('tv.commentary.news.enabled') === true) {
            $open = $slot['key'] - $now->getTimestamp() >= 60;
            $bulletin = CasterScript::query()->where('kind', CasterScript::BULLETIN)->where('facts_hash', 'bulletin:'.$slot['key'])->orderBy('id')->first();
            $talkWritten = CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('facts_hash', 'news_talk:'.$slot['key'])->exists();
            $talkDue = $slot['type'] === BroadcastClock::NEWS && NewsTalk::enabled() && ! QuietHours::active($now);

            // The news block is still being written: the preview comes after it, never between its parts.
            if ($open && ($bulletin === null || ($talkDue && ! $talkWritten))) {
                return 0;
            }

            if ($bulletin !== null) {
                $after = (float) CasterScript::query()->where('kind', CasterScript::RUBRIC)->whereIn('facts_hash', ['sport_weather:'.$slot['key'], 'news_talk:'.$slot['key']])
                    ->where('status', '!=', CasterScript::EXPIRED)->sum('estimated_seconds');
                $end = self::bulletinEnd($slot['starts_at'], $bulletin->text)->addSeconds((int) ceil($after));
                $airsAt = $end->greaterThan($airsAt) ? $end : $airsAt;
            }
        }

        // Written late, it airs from now on.
        $airsAt = $airsAt->lessThan($now) ? $now : $airsAt;
        $preview = app(ProgrammePreview::class);
        $material = $preview->material($airsAt, $now, $target['night']);

        if ($material === null || ! ProgrammePreview::tryWrite($target['key'])) {
            return 0;
        }

        $settings = ProgrammePreview::settings();
        $segment = $preview->write($material, $target['key']);

        return $segment !== null && $this->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $segment['text'], $hash, (int) ($settings['priority'] ?? 68), $now,
            source: CasterScript::SOURCE_AI, topic: ProgrammePreview::TOPIC, speakers: $segment['speakers'], airsAt: $airsAt,
            expiresAt: $airsAt->addSeconds((int) ($settings['late_seconds'] ?? 240) + 900)) !== null ? 1 : 0;
    }

    /**
     * The teaser for the next bulletin or planned rubric (Announcements),
     * once per slot: written from `teasers.write_minutes_ahead` before it
     * until a minute before it, played from `teasers.before_minutes` before
     * it (SceneDirector, at a clip boundary) and expiring fifteen seconds
     * before it. AI only; nothing true written, no teaser.
     *
     * @return int teasers written (0 or 1)
     */
    public function fillTeaser(CarbonImmutable $now): int
    {
        if (! self::aiEnabled() || ! Announcements::enabled()) {
            return 0;
        }

        $settings = (array) config('tv.commentary.programme.teasers', []);
        $announcements = app(Announcements::class);
        $target = $announcements->entries($now, max(1, (int) ($settings['write_minutes_ahead'] ?? 7)))[0] ?? null;

        if ($target === null) {
            return 0;
        }

        $hash = 'teaser:'.$target['starts_at']->getTimestamp();

        if ($target['starts_at']->getTimestamp() - $now->getTimestamp() < 60 || CasterScript::query()->where('kind', CasterScript::TEASER)->where('facts_hash', $hash)->exists()) {
            return 0;
        }

        $airsAt = $target['starts_at']->subMinutes(max(1, (int) ($settings['before_minutes'] ?? 5)));
        $airsAt = $airsAt->lessThan($now) ? $now : $airsAt;
        $text = $announcements->write($target, $airsAt);

        return $text !== null && $this->enqueue(CasterScript::TEASER, ScenePhase::BREAK, $text, $hash, (int) ($settings['priority'] ?? 72), $now,
            source: CasterScript::SOURCE_AI, topic: $target['type'], speakers: array_fill(0, count(ScriptGuard::lines($text)), DuetDirector::MARKUS),
            airsAt: $airsAt, expiresAt: $target['starts_at']->subSeconds(15)) !== null ? 1 : 0;
    }

    /**
     * A music announcement (MusicTeasers): what the music timeline really plays next, by the artist's collected
     * data (user 2026-10-17). It rides the handover to the music: written while the voice ahead runs low
     * (`ahead_below_seconds` of airtime, the spool aiming for `ahead_seconds` of it) or has run out altogether,
     * with no slot the clock places or stock block within `guard_minutes` — and not every track: one at most per
     * `min_gap_minutes`, at `chance` per opportunity, capped per day. Expires after `max_age_minutes`, so a late
     * one announces nothing that played. The live-only mode holds no generic talk back here (user 2026-10-17: the
     * announcement belongs to the music, like the imaging that rides its track changes; the bulletin, show and
     * reaction gates stay theirs). Called from its own lane (caster:music-teaser), so a digest-slow caster:script
     * run never keeps the window from being rolled.
     */
    public function fillMusicTeaser(CarbonImmutable $now): int
    {
        $settings = (array) config('tv.commentary.programme.music_teasers', []);

        if (! self::aiEnabled() || ! MusicTeasers::enabled() || QuietHours::active($now)
            || PodcastReplay::active($now)
            || $this->programmeNear($now, (int) ($settings['guard_minutes'] ?? 4))) {
            return 0;
        }

        // The handover to the music: the voice ahead (queued, claimed, ready) below this much airtime, or gone.
        // A single ready clip is not a programme — the announcement may follow it straight into the music.
        $ahead = $this->airtimeAhead();

        if ($ahead > (float) ($settings['ahead_below_seconds'] ?? 240)) {
            return 0;
        }

        if (CasterScript::query()->where('kind', CasterScript::MUSIC_TEASER)->where('created_at', '>', $now->subMinutes((int) ($settings['min_gap_minutes'] ?? 25)))->exists()
            || mt_rand(0, 999) >= 1000 * (float) ($settings['chance'] ?? 0.35)) {
            return 0;
        }

        // The track to name: the one that starts once the voice left, the announcement with it has aired and a
        // breath has passed. The lead must cover the voicing latency (live 2026-10-17: a 45 s lead named a track
        // that had been playing for minutes when the announcement finally aired).
        $lead = max((int) ($settings['lead_seconds'] ?? 200), (int) ceil($ahead) + (int) ($settings['render_seconds'] ?? 150));
        $next = MusicTeasers::nextTrack($now, $lead);

        if ($next === null
            || CasterScript::query()->where('kind', CasterScript::MUSIC_TEASER)->where('facts_hash', $next['hash'])->exists()) {
            return 0;
        }

        $text = app(MusicTeasers::class)->write($next, $now);

        // `airs_at` set like a teaser's (fillTeaser): with the blocks on, storeBlock() keeps a lead's own kind only
        // when it airs at a slot (live 2026-10-17: three announcements ended voiced without a clip, part of no
        // block, never on air); without blocks nothing changes - the clip is due at once either way.
        return $text !== null && $this->enqueue(CasterScript::MUSIC_TEASER, ScenePhase::BREAK, $text, $next['hash'], (int) ($settings['priority'] ?? 70), $now,
            source: CasterScript::SOURCE_AI, topic: 'music', maxAgeMinutes: (int) ($settings['max_age_minutes'] ?? 8),
            speakers: array_fill(0, count(ScriptGuard::lines($text)), DuetDirector::MARKUS), airsAt: $now) !== null ? 1 : 0;
    }

    /**
     * Whether programme starts within `$minutes`: a script the clock places (`airs_at`, a bulletin, a teaser, a
     * rubric) or the next bulletin slot of the broadcast clock. A stock block that waits is a clip and keeps
     * ClipSpool::hasFresh() true; a podcast replay is a gate of its own.
     */
    private function programmeNear(CarbonImmutable $now, int $minutes): bool
    {
        if ($minutes <= 0) {
            return false;
        }

        if (CasterScript::query()->where('airs_at', '>', $now->utc())->where('airs_at', '<=', $now->addMinutes($minutes)->utc())->exists()) {
            return true;
        }

        $bulletin = BroadcastClock::fromConfig()->nextBulletin($now);

        return $bulletin !== null && 60 * $minutes >= $bulletin['key'] - $now->getTimestamp();
    }

    /**
     * The long segments, each when it is due: a history segment every
     * `history.every_minutes`, a news segment at most every
     * `news.every_minutes` (the headline's comment by the league voice while
     * `tv.commentary.ai` is on, else a pattern reaction), a followed-profile
     * segment at most every `followed.every_minutes` (the same, for a note).
     *
     * @return int segments written
     */
    public function fillSegments(CarbonImmutable $now): int
    {
        $written = 0;

        if (config('tv.commentary.history.enabled') === true && $this->history->due($now)) {
            $long = LengthMix::isDue(LengthMix::HISTORY);
            $segment = $this->history->write($now, $long);

            if ($segment !== null && $this->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, $segment['text'], HistoryScripts::HASH_PREFIX.$segment['episode'], 0, $now,
                source: $segment['source'], maxAgeMinutes: (int) config('tv.commentary.history.max_age_minutes', 120)) !== null) {
                LengthMix::written(LengthMix::HISTORY, $long);
                $written++;
            }
        }

        $since = $now->subMinutes((int) config('tv.commentary.news.every_minutes', 10));

        if (config('tv.commentary.news.enabled') === true && ! CasterScript::query()->where('kind', CasterScript::NEWS)->where('created_at', '>', $since)->exists()) {
            $item = $this->news->next($now);
            $segment = $item === null ? null : $this->news->write($item, $now, self::aiEnabled(), LengthMix::isDue(LengthMix::NEWS));

            if ($item !== null && $segment !== null && $this->enqueue(CasterScript::NEWS, ScenePhase::BREAK, $segment['text'], 'news:'.$item->id, 0, $now,
                source: $segment['source'], topic: $item->section, maxAgeMinutes: (int) config('tv.commentary.news.max_age_minutes', 30)) !== null) {
                LengthMix::written(LengthMix::NEWS, $segment['long']);
                $written++;
            }
        }

        $written += $this->fillCallback($now);

        $since = $now->subMinutes((int) config('tv.commentary.followed.every_minutes', 10));

        if (config('tv.commentary.followed.enabled') === true && ! CasterScript::query()->where('kind', CasterScript::FOLLOWED)->where('created_at', '>', $since)->exists()) {
            $note = $this->followed->next($now);
            $segment = $note === null ? null : $this->followed->write($note, $now, self::aiEnabled());

            if ($note !== null && $segment !== null && $this->enqueue(CasterScript::FOLLOWED, ScenePhase::BREAK, $segment['text'], 'followed:'.$note->id, 0, $now,
                source: $segment['source'], maxAgeMinutes: (int) config('tv.commentary.followed.max_age_minutes', 30)) !== null) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * A "Rückblick-Spitze" (user 2026-10-12): every `world.callback_every_minutes` one or two real items of the world
     * memory (WorldMemory::pickForCallback()) called back with a sarcastic remark by the league voice; AI only. A
     * scene of topic WorldMemory::CALLBACK_TOPIC; its items are marked once it is queued, so none comes back within
     * `callback_repeat_hours`. fillSegments() runs outside the quiet hours only.
     *
     * @return int callbacks written (0 or 1)
     */
    public function fillCallback(CarbonImmutable $now): int
    {
        $every = (int) config('tv.commentary.world.callback_every_minutes', 12);

        if (! self::aiEnabled() || $every <= 0
            || CasterScript::query()->where('topic', WorldMemory::CALLBACK_TOPIC)->where('created_at', '>', $now->subMinutes($every))->exists()) {
            return 0;
        }

        $world = app(WorldMemory::class);
        $items = $world->pickForCallback($now, random_int(1, 2));
        $text = $items === [] ? null : $this->voice->callback($items);

        if ($text === null || $this->enqueue(CasterScript::SCENE, ScenePhase::BREAK, $text, 'callback:'.implode(',', array_column($items, 'key')), 0, $now,
            source: CasterScript::SOURCE_AI, topic: WorldMemory::CALLBACK_TOPIC) === null) {
            return 0;
        }

        $world->markCalledBack(array_column($items, 'key'), $now);

        return 1;
    }

    /**
     * A live moment (a result, a champion, a chat reaction): written now and
     * put at the front of the queue.
     *
     * @param  array<array-key, mixed>  $facts
     * @param  array<string, string>  $hints
     */
    public function pushLive(string $kind, string $phase, array $facts, int $priority, CarbonImmutable $now, array $hints = [], ?string $scene = null): ?CasterScript
    {
        if (! CasterVoice::enabled()) {
            return null;
        }

        $range = (array) config('tv.commentary.scripts.live_sentences', []);
        $script = $this->voice->write($kind, $phase, $facts, (int) ($range['min'] ?? 1), (int) ($range['max'] ?? 3), $hints);

        return $script === null ? null : $this->enqueue($kind, $phase, $script['text'], SceneFacts::hash($facts), $priority, $now, $scene);
    }

    /**
     * Queues a script; a long scene, segment or wished segment as its parts
     * (parts()), all with the same expiry. Returns the script, or its first
     * part; null for template text or a filler while the pattern fallback is
     * off (PatternScripts::enabled()), and when too little of it is left
     * before its first sentence said within `repeat_hours` (SpokenMemory: from
     * any source, chat replies and fallbacks included) or its first sign-off.
     * The script ends before such a sentence instead of losing it from the
     * middle (plan "TWENTY ONE Radio" P8: the hole left the next sentence
     * without its setup); a chat reply or a live line needs one sentence
     * before it, every other kind MIN_COHERENT. A pattern script is a row of
     * template lines that stand on their own: a repeated line just leaves it.
     *
     * `$speakers` (a duet, DuetDirector): the speaker of each line of `$text`;
     * the duet likewise ends before its first sign-off or repeated line, each
     * line keeping its speaker, and a scene duet needs both speakers in what
     * is left; every part stores the speakers of its own lines.
     *
     * A bulletin (`$airsAt`, `$expiresAt`; fillBulletin()) is queued as it
     * was written: its sentences are news and its ritual lines come back on
     * purpose, so neither the repeat filter nor the sign-off filter touches
     * it (NewsBulletin keeps goodbyes out of its commentary); its lines are
     * remembered all the same.
     *
     * @param  list<string>|null  $speakers
     */
    public function enqueue(string $kind, string $phase, string $text, string $factsHash, int $priority, CarbonImmutable $now, ?string $scene = null, string $source = CasterScript::SOURCE_AI, ?string $topic = null, ?int $maxAgeMinutes = null, ?array $speakers = null, ?CarbonImmutable $airsAt = null, ?CarbonImmutable $expiresAt = null): ?CasterScript
    {
        // AI only unless the pattern fallback is switched on (PatternScripts::enabled()): no template text, no filler.
        if (! PatternScripts::enabled() && ($source === CasterScript::SOURCE_PATTERN || $kind === CasterScript::FILLER)) {
            return null;
        }

        $text = self::sayAs($text);
        $maxAge = $maxAgeMinutes ?? (int) config('tv.commentary.scripts.max_age_minutes', 10);

        // A reaction may wait `chat.max_hold_seconds` on air before it cuts in, after its voicing: it never expires before that.
        if ($kind === CasterScript::CHAT || $scene === ChatWishes::SCENE || ($kind === CasterScript::LIVE && $topic === ChatReactions::ZAP_TOPIC)) {
            $maxAge = max($maxAge, (int) ceil((int) config('tv.commentary.chat.max_hold_seconds', 600) / 60) + (int) config('tv.commentary.chat.reaction_voicing_minutes', 20));
        }

        // Beyond the voicing budget, or voiced only after it expired, a script is not queued at all (VoiceBudget), before
        // any of its lines is remembered as said. A bulletin always is.
        if ($kind !== CasterScript::BULLETIN && VoiceBudget::enabled()
            && ! app(VoiceBudget::class)->admits($kind, $topic, $scene, self::estimateSeconds($text), $expiresAt ?? $now->addMinutes($maxAge), $now)) {
            return null;
        }

        if ($kind === CasterScript::BULLETIN) {
            $this->memory ??= new SpokenMemory;
            $this->memory->remember($text, $now);
        } elseif ($topic === SportWeather::TOPIC) {
            // Data read out every hour repeats by nature: the same temperature or score at 20:00 and 21:00 cut the weather
            // off the end of "Sport und Wetter" (user 2026-10-12: "Was ist mit dem Wetter passiert?"), and a repeated first
            // line after the opener dropped the whole segment (live 22:00). Only a goodbye goes, each line with its speaker.
            [$text, $speakers] = self::linesBeforeSignOff($text, $speakers);
            $this->memory ??= new SpokenMemory;
            $this->memory->remember($text, $now);
        } elseif ($kind === CasterScript::RUBRIC && $speakers !== null) {
            // The rubric's opening line (time and name) comes back on purpose; its content leaves repeats like a duet.
            $lines = ScriptGuard::lines($text);
            [$rest, $restSpeakers] = $this->duetLines(implode("\n", array_slice($lines, 1)), array_slice($speakers, 1), $now);

            if ($lines === [] || $rest === '') {
                return null;
            }

            [$text, $speakers] = [$lines[0]."\n".$rest, [$speakers[0] ?? DuetDirector::MARKUS, ...$restSpeakers]];
        } elseif ($speakers !== null) {
            [$text, $speakers] = $this->duetLines($text, $speakers, $now);

            if ($text === '' || ($kind === CasterScript::SCENE && count(array_unique($speakers)) < 2)) {
                return null;
            }
        } elseif ($kind === CasterScript::FILLER) {
            $text = self::beforeSignOff($text);
        } elseif ($source === CasterScript::SOURCE_PATTERN) {
            // Template lines stand on their own (an intro, a carrier with a canon joke): a repeated one leaves, the rest stays.
            $this->memory ??= new SpokenMemory;
            $text = $this->memory->withoutRepeats(self::withoutSignOffs($text), $now);

            if (trim(ScriptGuard::withoutMarkers($text)) === '') {
                return null;
            }

            $this->memory->remember($text, $now);
        } else {
            $this->memory ??= new SpokenMemory;
            $min = in_array($kind, [CasterScript::CHAT, CasterScript::LIVE, CasterScript::TEASER], true) ? 1 : self::MIN_COHERENT;
            $whole = self::beforeSignOff($text, $signedOff);
            $text = $this->memory->coherent($whole, $now, $min);

            // A sign-off that cut the script counts like a repeat: a single setup sentence is no bit.
            if (trim(ScriptGuard::withoutMarkers($text)) === '' || ($signedOff && count(VoiceHygiene::sentenceList(ScriptGuard::withoutMarkers($text))) < $min)) {
                return null;
            }

            $this->memory->remember($text, $now);
        }

        if (trim(ScriptGuard::withoutMarkers($text)) === '') {
            return null;
        }

        $inParts = in_array($kind, [CasterScript::SCENE, CasterScript::RUBRIC, ...CasterScript::SEGMENTS], true) || $scene === ChatWishes::SCENE;
        $parts = $inParts ? self::parts($text, (int) config('tv.commentary.parts.sentences', 5)) : [$text];
        $group = count($parts) > 1 ? (string) Str::ulid() : null;

        return DB::transaction(function () use ($kind, $phase, $scene, $source, $topic, $priority, $factsHash, $now, $maxAgeMinutes, $parts, $group, $speakers, $airsAt, $expiresAt): CasterScript {
            $first = null;
            $offset = 0;

            foreach ($parts as $index => $part) {
                $lineCount = count(ScriptGuard::lines($part));
                $partSpeakers = $speakers === null ? null : array_slice($speakers, $offset, $lineCount);
                $offset += $lineCount;

                $script = CasterScript::query()->create([
                    'kind' => $kind,
                    'phase' => $phase,
                    'scene' => $scene,
                    'source' => $source,
                    'topic' => $topic,
                    'priority' => $priority,
                    'text' => $part,
                    'facts_hash' => $factsHash,
                    'status' => CasterScript::QUEUED,
                    'estimated_seconds' => self::estimateSeconds($part),
                    // Nothing expires unused by default (user 2026-10-15: "verfallen soll nichts mehr, wir liefern
                    // das aus, was generiert wurde") — but a kind that carries its own explicit lifetime
                    // (`$maxAgeMinutes`, a live tournament comment, a stock talk) stays time-critical: its news is
                    // only news for that long, and a backlog of it plays en bloc instead of in its interval
                    // (live 2026-10-15). The config default does not count as one.
                    'expires_at' => $expiresAt?->utc() ?? ($maxAgeMinutes !== null ? $now->addMinutes($maxAgeMinutes) : $now->addYears(10)),
                    'airs_at' => $airsAt?->utc(),
                    'part_group' => $group,
                    'part_index' => $group === null ? null : $index + 1,
                    'speakers' => $partSpeakers,
                ]);
                $first ??= $script;
            }

            /** @var CasterScript $first */
            return $first;
        });
    }

    /**
     * A duet's lines without sign-offs and without the sentences said within
     * `repeat_hours` (SpokenMemory), each kept line with its speaker; the
     * kept lines are remembered. An empty text when nothing is left.
     *
     * @param  list<string>  $speakers  one per line of `$text`
     * @return array{0: string, 1: list<string>}
     */
    private function duetLines(string $text, array $speakers, CarbonImmutable $now): array
    {
        $this->memory ??= new SpokenMemory;
        [$lines, $kept] = [[], []];

        foreach (ScriptGuard::lines($text) as $index => $line) {
            $line = self::beforeSignOff(trim($line), $signedOff);
            $repeated = false;
            $line = $line === '' ? '' : trim($this->memory->coherent($line, $now, 1, $repeated));

            if (trim(ScriptGuard::withoutMarkers($line)) !== '') {
                $this->memory->remember($line, $now);
                $lines[] = str_replace("\n", ' ', $line);
                $kept[] = $speakers[$index] ?? DuetDirector::MARKUS;
            }

            // A sign-off or a repeat ends the duet here: the next speaker would answer a line nobody heard (P8).
            if ($signedOff || $repeated) {
                break;
            }
        }

        return [implode("\n", $lines), $kept];
    }

    /**
     * A script of more than `$perPart` sentences (lines) as parts of at most
     * that many, split between lines only and as even as possible (27 lines
     * at 5: 5, 5, 5, 4, 4, 4); a shorter one, or `$perPart` below 1, in one piece.
     *
     * @return list<string>
     */
    public static function parts(string $text, int $perPart): array
    {
        $lines = ScriptGuard::lines($text);

        if ($perPart < 1 || count($lines) <= $perPart) {
            return [$text];
        }

        $count = (int) ceil(count($lines) / $perPart);
        $base = intdiv(count($lines), $count);
        $extra = count($lines) % $count;
        $parts = [];
        $offset = 0;

        for ($index = 0; $index < $count; $index++) {
            $size = $base + ($index < $extra ? 1 : 0);
            $parts[] = implode("\n", array_slice($lines, $offset, $size));
            $offset += $size;
        }

        return $parts;
    }

    /**
     * The next script for the fetcher: the highest priority first, then the
     * oldest; claims that never came back are free again. `$kinds` limits it
     * to those kinds (the live moments and reactions while the spool is full);
     * a wished segment (ChatWishes::SCENE) travels with them, and so does a
     * later part of a long script. A later part is handed out only once every
     * part before it left the queue, so the parts are voiced in order.
     *
     * A stock script (StockScripts::SCENE) only for `$stock` (claimStock()), and only then.
     *
     * @param  list<string>|null  $kinds
     */
    public function claim(CarbonImmutable $now, ?array $kinds = null, bool $stock = false): ?CasterScript
    {
        $this->expire($now);

        CasterScript::query()->where('status', CasterScript::CLAIMED)
            ->where(fn (Builder $query) => self::lapsed($query, $now->subSeconds((int) config('tv.commentary.fetch.claim_seconds', 180))))
            ->update(['status' => CasterScript::QUEUED, 'claimed_at' => null, 'renewed_at' => null]);

        // A script the clock places is free again much sooner: a fetcher killed while voicing it (a restart, prod
        // 2026-10-13: bulletins 2644 and 2706) would otherwise hold it past its slot. A second voicing is harmless,
        // the later upload is refused (not_claimed). A fetcher still voicing it renews its claim (the claim heartbeat), so
        // a block that takes longer than this is never taken away mid-voicing.
        CasterScript::query()->where('status', CasterScript::CLAIMED)->whereNotNull('airs_at')
            ->where(fn (Builder $query) => self::lapsed($query, $now->subSeconds((int) config('tv.commentary.fetch.slot_claim_seconds', 120))))
            ->update(['status' => CasterScript::QUEUED, 'claimed_at' => null, 'renewed_at' => null]);

        return DB::transaction(function () use ($now, $kinds, $stock): ?CasterScript {
            $script = CasterScript::query()->where('status', CasterScript::QUEUED)->where('expires_at', '>', $now)
                ->where(fn ($query) => $stock ? $query->where('scene', StockScripts::SCENE) : self::notStock($query))
                ->when($kinds !== null, fn ($query) => $query->where(fn ($query) => $query->whereIn('kind', (array) $kinds)->orWhere('scene', ChatWishes::SCENE)->orWhere('part_index', '>', 1)))
                ->where(fn ($query) => $query->whereNull('part_group')->orWhereNotExists(fn ($earlier) => $earlier->from('caster_scripts as earlier')
                    ->whereColumn('earlier.part_group', 'caster_scripts.part_group')->whereColumn('earlier.part_index', '<', 'caster_scripts.part_index')
                    ->where('earlier.status', CasterScript::QUEUED)))
                // A bulletin first, whatever else waits: it airs at its slot or not at all. With the voicing budget on, the
                // tiers in strict order after it (VoiceBudget::TIERS: shows, replies, the news talk, rubrics, segments, scenes).
                ->orderByRaw(...(VoiceBudget::enabled() ? VoiceBudget::rankSql() : ['case when kind = ? then 0 else 1 end', [CasterScript::BULLETIN]]))
                ->orderByDesc('priority')->orderBy('id')->lockForUpdate()->first();

            $script?->update(['status' => CasterScript::CLAIMED, 'claimed_at' => $now, 'renewed_at' => null]);

            return $script;
        });
    }

    public function release(CasterScript $script): void
    {
        if ($script->status === CasterScript::CLAIMED) {
            $script->update(['status' => CasterScript::QUEUED, 'claimed_at' => null, 'renewed_at' => null]);
        }
    }

    /**
     * The claim heartbeat: the claims of `$ids` the workstation still holds are renewed (`renewed_at`), so they do not
     * lapse after `claim_seconds` / `slot_claim_seconds` while it voices them. `claimed_at` stays.
     *
     * @param  list<int>  $ids
     * @return int claims renewed
     */
    public function renew(array $ids, CarbonImmutable $now): int
    {
        return $ids === [] ? 0 : CasterScript::query()->whereKey($ids)->where('status', CasterScript::CLAIMED)->update(['renewed_at' => $now->utc()]);
    }

    /**
     * A query constraint: the claim was last claimed or renewed before `$cutoff`.
     *
     * @param  Builder<CasterScript>  $query
     */
    private static function lapsed(Builder $query, CarbonImmutable $cutoff): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('renewed_at')->where('claimed_at', '<', $cutoff->utc()))
            ->orWhere('renewed_at', '<', $cutoff->utc());
    }

    /**
     * Scripts whose time is up expire unused, with their clips (ClipSpool
     * deletes the files), and with them the unvoiced rest of their part
     * group; rows that left the queue a day ago are deleted.
     *
     * @return int scripts expired now
     */
    public function expire(CarbonImmutable $now): int
    {
        $expired = $this->skipUnreadyBulletins($now);

        $expired += CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->where('expires_at', '<=', $now)->update(['status' => CasterScript::EXPIRED]);

        // The parts of a script expire together: without the part before it, a part would start mid-story.
        $expired += CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->whereIn('part_group', CasterScript::query()->from('caster_scripts as dead')->where('dead.status', CasterScript::EXPIRED)->whereNotNull('dead.part_group')->select('dead.part_group'))
            ->update(['status' => CasterScript::EXPIRED]);

        CasterScript::query()->whereIn('status', [CasterScript::EXPIRED, CasterScript::VOICED])
            ->where('kind', '!=', CasterScript::FILLER)
            ->where('updated_at', '<', $now->subHours(self::KEEP_HOURS))
            ->whereDoesntHave('clips')
            ->delete();

        return $expired;
    }

    /**
     * A bulletin not voiced `bulletin.ready_seconds` before its slot is skipped cleanly: it expires unvoiced, and with
     * it the follow-ups of that slot (Sport und Wetter, the news talk), voiced or not, so nothing on air says "nach den
     * Nachrichten" about news that never aired (prod 2026-10-13, 00:00 Berlin).
     *
     * @return int bulletins skipped now
     */
    private function skipUnreadyBulletins(CarbonImmutable $now): int
    {
        $bulletins = CasterScript::query()->where('kind', CasterScript::BULLETIN)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->whereNotNull('airs_at')->where('airs_at', '<=', $now->utc()->addSeconds((int) config('tv.commentary.programme.bulletin.ready_seconds', 60)))->get();

        foreach ($bulletins as $bulletin) {
            $bulletin->update(['status' => CasterScript::EXPIRED]);
            $key = Str::after((string) $bulletin->facts_hash, 'bulletin:');
            app(ClipSpool::class)->dropScripts(CasterScript::query()->where('kind', CasterScript::RUBRIC)
                ->whereIn('facts_hash', ['sport_weather:'.$key, 'news_talk:'.$key])->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all());
        }

        return $bulletins->count();
    }

    /** Scene scripts of a topic written for other facts than `$hash` expire: the slide they talk about changed. */
    public function supersedeTopic(string $topic, string $hash): int
    {
        // Off by default: scene scripts draw on world memory, canon and news, not only on the slide's facts.
        if (! (bool) config('tv.commentary.scripts.supersede', false)) {
            return 0;
        }

        return CasterScript::query()->where('topic', $topic)->where('kind', CasterScript::SCENE)
            ->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->where('facts_hash', '!=', $hash)->update(['status' => CasterScript::EXPIRED]);
    }

    /** The fillers (resources/data/voice/fillers.json) wait in the queue until each is voiced once. */
    public function seedFillers(CarbonImmutable $now): int
    {
        if (! PatternScripts::enabled()) {
            return 0;
        }

        $data = json_decode((string) file_get_contents(resource_path('data/voice/fillers.json')), true);
        $texts = is_array($data) ? array_values(array_filter((array) ($data['fillers'] ?? []), 'is_string')) : [];
        $known = CasterScript::query()->where('kind', CasterScript::FILLER)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED, CasterScript::VOICED])->pluck('text')->all();
        $seeded = 0;

        foreach ($texts as $text) {
            if (! in_array(self::sayAs($text), $known, true)) {
                $this->enqueue(CasterScript::FILLER, ScenePhase::BREAK, $text, 'filler', 0, $now);
                $seeded++;
            }
        }

        return $seeded;
    }

    /** Spoken seconds of a script at `words_per_second`, with the pauses between its sentences. */
    public static function estimateSeconds(string $text): float
    {
        $lines = ScriptGuard::lines($text);
        $words = count(preg_split('/\s+/u', ScriptGuard::withoutMarkers(implode(' ', $lines)), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return round($words / max(0.5, (float) config('tv.commentary.scripts.words_per_second', 2.4)) + 0.4 * max(0, count($lines) - 1), 1);
    }

    /**
     * The topic with the least airtime queued or spooled (the first of equals, in SceneTopic::ALL order).
     *
     * @param  list<string>  $topics
     */
    private function thinnestTopic(array $topics): string
    {
        $ready = [];

        foreach ($topics as $topic) {
            $scripts = CasterScript::query()->where('topic', $topic)->where('kind', CasterScript::SCENE);
            $ready[$topic] = (float) (clone $scripts)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])->sum('estimated_seconds')
                + (float) CasterClip::query()->whereIn('caster_script_id', (clone $scripts)->select('id'))->where('status', CasterClip::READY)->sum('duration_seconds');
        }

        asort($ready);

        return (string) array_key_first($ready);
    }

    /**
     * Three topics of the phase from the cast research, rotating with the clock
     * (a topic comes back after the others of its phase had their turn).
     *
     * @return list<string>
     */
    public function topics(string $phase, CarbonImmutable $now): array
    {
        $when = match ($phase) {
            ScenePhase::LIVE_MATCH => ['live_match'],
            ScenePhase::AFTER_RESULT => ['after_result'],
            ScenePhase::PRE_SHOW => ['pre_show', 'between_matches'],
            ScenePhase::NIGHT => ['night'],
            default => ['break', 'between_matches'],
        };
        $topics = array_values(array_filter(CasterVoice::castData()['topics'], fn (array $topic): bool => array_intersect($when, $topic['when']) !== []));

        if ($topics === []) {
            return [];
        }

        $start = intdiv($now->getTimestamp(), 60) % count($topics);
        $picked = [];

        for ($i = 0; $i < min(3, count($topics)); $i++) {
            $topic = $topics[($start + $i * 7) % count($topics)];
            $picked[$topic['id']] = "{$topic['name']}: {$topic['what']} (etwa: „{$topic['example_de']}“)";
        }

        return array_values($picked);
    }

    /**
     * The script up to its first sentence with a goodbye (SIGN_OFF), without sentences that carry no word: a
     * goodbye after the first kept sentence ends the script there instead of leaving a hole in it (plan
     * "TWENTY ONE Radio" P8); one before it only leaves, as nothing builds on it yet. `$cut`: whether a goodbye ended it.
     *
     * @param-out bool $cut
     */
    /**
     * The lines of `$text` up to a sign-off, each kept line with its speaker (`$speakers`, one per line; null stays null).
     *
     * @param  list<string>|null  $speakers
     * @return array{0: string, 1: list<string>|null}
     */
    public static function linesBeforeSignOff(string $text, ?array $speakers): array
    {
        if ($speakers === null) {
            return [self::beforeSignOff($text), null];
        }

        [$lines, $kept] = [[], []];

        foreach (ScriptGuard::lines($text) as $index => $line) {
            $line = self::beforeSignOff(trim($line), $signedOff);

            if (trim(ScriptGuard::withoutMarkers($line)) !== '') {
                $lines[] = str_replace("\n", ' ', $line);
                $kept[] = $speakers[$index] ?? DuetDirector::MARKUS;
            }

            if ($signedOff) {
                break;
            }
        }

        return [implode("\n", $lines), $kept];
    }

    public static function beforeSignOff(string $text, ?bool &$cut = null): string
    {
        $lines = [];
        $cut = false;

        foreach (ScriptGuard::lines($text) as $line) {
            $kept = [];

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                if (preg_match(self::SIGN_OFF, $sentence) === 1) {
                    if ($lines === [] && $kept === []) {
                        continue;
                    }

                    if ($kept !== []) {
                        $lines[] = implode(' ', $kept);
                    }
                    $cut = true;

                    return implode("\n", $lines);
                }

                if (preg_match('/\p{L}/u', (string) preg_replace('/\[[^\]]*\]/u', '', $sentence)) === 1) {
                    $kept[] = $sentence;
                }
            }

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        return implode("\n", $lines);
    }

    /** A goodbye anywhere in a sentence: "ciao", "peace out", "bis zum nächsten Mal", "macht's gut", "gute Nacht" … */
    public const SIGN_OFF = '/\b(?:tsch(?:ü|ue)ss|tschau|ciao|adios|peace out|servus und|bis (?:gleich|später|morgen|bald|dann|demnächst|nächste woche|zum nächsten mal)|macht?\S{0,2} gut|auf wiedersehen|auf wiederhören|gute nacht|schönen abend noch|wir (?:sehen|hören|lesen) uns|haut rein|gehabt euch wohl|over and out|das war\S{0,2} für heute|meine finalen worte)\b/iu';

    /**
     * The TV voice never says goodbye (user 2026-10-11: "er soll sich nie wieder verabschieden!"): every sentence
     * with a farewell leaves the script, whichever source it came from (the canon has dozens of podcast outros).
     */
    public static function withoutSignOffs(string $text): string
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $sentences = preg_split('/(?<=[.!?])\\s+/u', trim($line)) ?: [];
            $kept = array_filter($sentences, fn (string $sentence): bool => $sentence !== '' && preg_match(self::SIGN_OFF, $sentence) !== 1);
            $kept = array_filter($kept, fn (string $sentence): bool => preg_match('/[\\p{L}]/u', preg_replace('/\\[[^\\]]*\\]/u', '', $sentence) ?? '') === 1);

            if ($kept !== []) {
                $lines[] = implode(' ', $kept);
            }
        }

        return $lines === [] ? $text : implode("\n", $lines);
    }
}
