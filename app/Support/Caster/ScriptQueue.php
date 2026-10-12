<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Programme\Announcements;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsBulletin;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\RubricScripts;
use Carbon\CarbonImmutable;
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
            ->whereNotIn('kind', [CasterScript::FILLER, ...CasterScript::PROGRAMME, ...CasterScript::SEGMENTS])->sum('estimated_seconds');

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
        $written = $this->fillBulletin($now);
        $written += $quiet ? 0 : $this->fillNewsTalk($now);
        $written += $this->fillRubric($now);
        $written += $quiet ? 0 : $this->fillTeaser($now) + $this->fillSegments($now);

        $settings = (array) config('tv.commentary.scripts', []);
        $sentences = (array) ($settings['sentences'] ?? []);
        $night = ScenePhase::isNight($now);
        $factsByTopic = [];

        foreach (SceneTopic::ALL as $topic) {
            $facts = $this->facts->forTopic($topic, $night);
            $this->supersedeTopic($topic, SceneFacts::hash($facts));

            if ((! $night || in_array($topic, SceneTopic::NIGHT, true)) && SceneFacts::hasData($topic, $facts)) {
                $factsByTopic[$topic] = $facts;
            }
        }

        $aiLeft = self::aiEnabled() ? max(0, (int) ($settings['ai_calls_per_fill'] ?? 6)) : 0;
        $patternsLeft = max(0, (int) config('tv.commentary.patterns.per_run', 40));
        $exhausted = [];

        while ($this->airtimeAhead() < ($quiet ? QuietHours::aheadSeconds() : (float) ($settings['ahead_seconds'] ?? 300)) && ($candidates = array_diff(array_keys($factsByTopic), $exhausted)) !== []) {
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
     * The quiet hours have no rubric slots (BroadcastClock).
     *
     * @return int rubrics written (0 or 1)
     */
    public function fillRubric(CarbonImmutable $now): int
    {
        if (! self::aiEnabled() || ! Rubrics::enabled()) {
            return 0;
        }

        $settings = (array) config('tv.commentary.programme.rubrics', []);
        $write = max(1, (int) ($settings['write_minutes_ahead'] ?? 8));
        $slots = array_values(array_filter(BroadcastClock::fromConfig()->upcoming($now, $write), fn (array $slot): bool => $slot['type'] === BroadcastClock::RUBRIC));
        $slot = $slots[0] ?? null;

        if ($slot === null) {
            return 0;
        }

        $ahead = $slot['key'] - $now->getTimestamp();

        if ($ahead > 60 * $write || $ahead < 60 * (float) ($settings['last_write_minutes'] ?? 2) || Rubrics::written($slot['key'])) {
            return 0;
        }

        $rubrics = app(Rubrics::class);
        $id = $rubrics->plannedFor($slot, $now);
        $material = $id === null ? null : $rubrics->material($id, $now);
        $rubric = $id === null || $material === null ? null : $this->rubricScripts->write($slot, $id, $material, $now);

        if ($id === null || $material === null || $rubric === null
            || $this->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $rubric['text'], Rubrics::hashFor($id, $material, $slot['key']), (int) ($settings['priority'] ?? 70), $now,
                source: CasterScript::SOURCE_AI, topic: $id, speakers: $rubric['speakers'], airsAt: $slot['starts_at'],
                expiresAt: $slot['starts_at']->addSeconds((int) ($settings['late_seconds'] ?? 180) + 600)) === null) {
            return 0;
        }

        $rubrics->aired($id, $material, $now);

        return 1;
    }

    /**
     * The news talk after the next :00 bulletin (NewsTalk), once per slot:
     * written once that bulletin is queued (its items, NewsBulletin) and the
     * slot is at least a minute away, AI only; queued in parts as a rubric
     * (topic NewsTalk::TOPIC) airing when the bulletin ends (its estimated
     * length after the slot), at most `news_talk.late_seconds` late.
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
        // When the bulletin ends: its spoken length plus the stinger in front of it.
        $airsAt = $slot['starts_at']->addSeconds((int) ceil(self::estimateSeconds($bulletin->text) + 4));
        $talk = app(NewsTalk::class)->write($slot, $items, $airsAt, $now);

        return $talk !== null && $this->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $talk['text'], $hash, (int) ($settings['priority'] ?? 70), $now,
            source: CasterScript::SOURCE_AI, topic: NewsTalk::TOPIC, speakers: $talk['speakers'], airsAt: $airsAt,
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
     * off (PatternScripts::enabled()), and when every sentence of it was said within `repeat_hours`
     * (SpokenMemory: from any source, chat replies and fallbacks included;
     * the sentences said before leave a script, the rest is queued).
     *
     * `$speakers` (a duet, DuetDirector): the speaker of each line of `$text`;
     * sign-offs and repeats then leave it line by line, each line keeping its
     * speaker, and every part stores the speakers of its own lines.
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

        $maxAge = $maxAgeMinutes ?? (int) config('tv.commentary.scripts.max_age_minutes', 10);

        if ($kind === CasterScript::BULLETIN) {
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

            if ($text === '') {
                return null;
            }
        }

        $text = $kind === CasterScript::BULLETIN ? $text : self::withoutSignOffs($text);

        if ($kind !== CasterScript::FILLER && $kind !== CasterScript::BULLETIN && $speakers === null) {
            $this->memory ??= new SpokenMemory;
            $text = $this->memory->withoutRepeats($text, $now);

            if (trim(ScriptGuard::withoutMarkers($text)) === '') {
                return null;
            }

            $this->memory->remember($text, $now);
        }

        $inParts = in_array($kind, [CasterScript::SCENE, CasterScript::RUBRIC, ...CasterScript::SEGMENTS], true) || $scene === ChatWishes::SCENE;
        $parts = $inParts ? self::parts($text, (int) config('tv.commentary.parts.sentences', 5)) : [$text];
        $group = count($parts) > 1 ? (string) Str::ulid() : null;

        return DB::transaction(function () use ($kind, $phase, $scene, $source, $topic, $priority, $factsHash, $now, $maxAge, $parts, $group, $speakers, $airsAt, $expiresAt): CasterScript {
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
                    // Fillers carry no facts: they never go stale.
                    'expires_at' => $expiresAt?->utc() ?? ($kind === CasterScript::FILLER ? $now->addYears(10) : $now->addMinutes($maxAge)),
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
            $line = self::withoutSignOffs($line);

            if (preg_match(self::SIGN_OFF, $line) === 1) {
                continue;
            }

            $line = trim($this->memory->withoutRepeats($line, $now));

            if (trim(ScriptGuard::withoutMarkers($line)) === '') {
                continue;
            }

            $this->memory->remember($line, $now);
            $lines[] = str_replace("\n", ' ', $line);
            $kept[] = $speakers[$index] ?? DuetDirector::MARKUS;
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
     * @param  list<string>|null  $kinds
     */
    public function claim(CarbonImmutable $now, ?array $kinds = null): ?CasterScript
    {
        $this->expire($now);

        CasterScript::query()->where('status', CasterScript::CLAIMED)
            ->where('claimed_at', '<', $now->subSeconds((int) config('tv.commentary.fetch.claim_seconds', 180)))
            ->update(['status' => CasterScript::QUEUED, 'claimed_at' => null]);

        return DB::transaction(function () use ($now, $kinds): ?CasterScript {
            $script = CasterScript::query()->where('status', CasterScript::QUEUED)->where('expires_at', '>', $now)
                ->when($kinds !== null, fn ($query) => $query->where(fn ($query) => $query->whereIn('kind', (array) $kinds)->orWhere('scene', ChatWishes::SCENE)->orWhere('part_index', '>', 1)))
                ->where(fn ($query) => $query->whereNull('part_group')->orWhereNotExists(fn ($earlier) => $earlier->from('caster_scripts as earlier')
                    ->whereColumn('earlier.part_group', 'caster_scripts.part_group')->whereColumn('earlier.part_index', '<', 'caster_scripts.part_index')
                    ->where('earlier.status', CasterScript::QUEUED)))
                ->orderByDesc('priority')->orderBy('id')->lockForUpdate()->first();

            $script?->update(['status' => CasterScript::CLAIMED, 'claimed_at' => $now]);

            return $script;
        });
    }

    public function release(CasterScript $script): void
    {
        if ($script->status === CasterScript::CLAIMED) {
            $script->update(['status' => CasterScript::QUEUED, 'claimed_at' => null]);
        }
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
        $expired = CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
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
            if (! in_array($text, $known, true)) {
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
