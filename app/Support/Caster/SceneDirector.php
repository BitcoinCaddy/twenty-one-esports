<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;

/**
 * Scene and commentary as one unit (user 2026-10-11: "genug Zeit für den
 * Slide, bis der Kommentar passend dazu auch komplett ausgesprochen wurde"):
 * at the start of every slot the director takes the spool's clip for the
 * slot's phase, the one about the slot's topic first (ClipSpool::next(),
 * with its fallbacks), puts it on the
 * timeline `lead_in_seconds` later and holds the slot until the clip has been
 * spoken plus `tail_seconds`, at least `min_scene_seconds`, at most
 * `max_hold_seconds`. Without a clip the slot stands at least
 * `silent_min_scene_seconds`, and while the workstation is offline the
 * slot's script shows as a text lower third (the caption).
 *
 * A takeover (a champion moment, a running tournament) never cuts a
 * sentence: the clip is cut at the end of the sentence on air and the hold
 * shortened to it. A clip is deleted from the spool right after it played.
 *
 * Between two clips only the music plays for a random `gap_seconds` (user
 * 2026-10-11: "Es darf durchaus zwischen längeren Sätzen eine Pause von 20
 * bis 30 Sekunden da sein."): a slot that starts within the gap gets no
 * scene clip and holds until the gap is over, so the next scene change
 * starts with the next clip. A live moment (a result, a champion, a chat
 * reaction, a zap thank-you) plays as soon as no clip is on air: it cuts the
 * gap short, also in the middle of such a quiet slot or a silent one.
 *
 * A long segment (news, "Markus erzählt vom Bauernkrieg") comes from the
 * spool like a scene clip, after the gap and on a calm slot only. A scene,
 * news or history clip holds its slot up to `long_max_hold_seconds` of its
 * kind (a long variant of tv.commentary.length runs up to four minutes, and
 * the scene stays on air through it); a takeover still cuts it at the end of
 * the sentence on air.
 *
 * Commentary runs (user 2026-10-11: "Durchaus auch öfter mal mehrere Audios
 * hintereinander, damit es an diesen Stellen zu Dauerkommentaren kommt"):
 * every `runs.every_minutes` (a random length in the range) the next clip
 * opens a run of `runs.clips` clips, each with its own scene, separated by
 * a breath of `runs.breath_seconds` instead of the music gap; after the run
 * the normal gaps return. A live moment or reaction still plays right after
 * the clip on air and does not count as a run clip; a slot without a clip
 * ends the run. The run is the director's: the queue writes ahead and the
 * spool picks by phase and topic, so only the director knows which clips
 * really play in a row (run() names the run and the position on air).
 *
 * The parts of a long script (ScriptQueue::parts(), `tv.commentary.parts`)
 * play as one story: when a part ended, the next part of its group follows
 * after `parts.breath_seconds` (plus the lead-in), before any other scene or
 * segment, whatever the phase and `segment_spacing_minutes`; the music gap
 * holds back everything else meanwhile, and a live moment or reaction may
 * still cut in between two parts. A part not ready `parts.wait_seconds`
 * after the one before it ended, an expired one, or a part cut off by a
 * takeover ends the group (ClipSpool::endGroup()). A group counts as one
 * run clip.
 *
 * With the station sound on (Idents, tv.commentary.idents), a clip opens
 * with the stinger of its kind and a bulletin carries the news bed: both
 * become part of the clip's PCM before it goes on the timeline, so the hold
 * and the sentence ends include them.
 *
 * The news bulletin (plan "TWENTY ONE Radio", P1; ClipSpool::bulletin()):
 * from its slot on (:00, :30 on the broadcast clock, at most
 * `bulletin.late_seconds` late) it plays before anything else, over the
 * music gap and the quiet hours' ten-minute rhythm. A scene or segment on air
 * is cut at the end of its sentence for it; a live moment, a reaction or a
 * part of a story ends first (the story goes on after the bulletin). Nothing
 * cuts into a bulletin: no takeover, no reaction, no wish; they follow it.
 * A bulletin ends a run and does not count as a run clip. Hook for the
 * stinger (P4): a slot with `stinger` leaves a cue (takeStinger()) and the
 * voice starts `programme.stinger_seconds` later; bulletinOnAir() names the
 * slot while the bulletin plays (the news bed). The bulletin starts on the
 * timeline as news: the stream music fades away under it
 * (CommentaryTimeline::musicDuck()).
 *
 * A rubric (P5; ClipSpool::rubric()) starts the same way at its rubric
 * slot, at most `rubric_late_seconds` late, and its parts follow as a story;
 * unlike a bulletin a takeover may still cut it. A programme teaser
 * (ClipSpool::teaser()) plays at the next clip boundary once it is due, never
 * between two parts of a story, so it ends the segment before its slot.
 *
 * No reaction waits longer than `reaction_max_hold_seconds` after it was ready
 * (user 2026-10-12: "Maximal 10 Minuten, dann dürfen wieder Reactions
 * reinkommen"; ClipSpool::overdueLive()): from then on it goes before a due
 * rubric, the news talk and the next part of a story, and cuts into whatever is
 * on air at its next sentence end (a talk, a rubric, a segment, a part, a
 * block), except a bulletin's news, which it follows. The programme it cut
 * resumes from that sentence afterwards (held), its story going on as before.
 */
final class SceneDirector
{
    private ?int $slotNumber = null;

    private ?CasterClip $clip = null;

    private ?string $caption = null;

    /** The stinger slot the clip on air opened with (Idents), or null. */
    private ?string $ident = null;

    /** Until when only the music plays (the gap after a clip). */
    private float $quietUntil = 0.0;

    /** The current run: its number, the position of the clip on air (0: no run), the run clips still to come. */
    private int $runId = 0;

    private int $runPosition = 0;

    private int $runLeft = 0;

    /** When the next run may start (null: not yet drawn). */
    private ?float $nextRunAt = null;

    /**
     * The part that comes next: its group and position, when it may start (`at`) and how long it is waited for (`until`).
     *
     * @var array{group: string, index: int, at: float, until: float}|null
     */
    private ?array $part = null;

    /** Whether the clip on air is a later or first part of a story (ScriptQueue::parts()). */
    private bool $clipIsPart = false;

    /** The slot type of the bulletin on air, or null. */
    private ?string $bulletinSlot = null;

    /** The second the spool was last asked for a due rubric (at most once a second). */
    private int $rubricAskedAt = 0;

    /**
     * The stinger the stream has to play (P4): the slot type, when it was asked for, and when the voice starts.
     *
     * @var array{slot: string, at: float, voice_at: float}|null
     */
    private ?array $stinger = null;

    private readonly BroadcastClock $clock;

    /** When the clip on air starts on the wall clock (the air log). */
    private float $airStart = 0.0;

    /** @var list<float> the sentence ends of the clip on air, wall clock */
    private array $airEnds = [];

    /** @var list<float> the segment boundaries of the block on air, wall clock */
    private array $airBoundaries = [];

    /** Until when nothing cuts into the block on air (the news of a news block), wall clock. */
    private float $protectedUntil = 0.0;

    /**
     * The rests of the blocks cut for a reaction, a takeover or a bulletin, and of any clip cut for an overdue
     * reaction: each resumes from its cut once they are over (P6), the one cut last first. Their PCM, sentence ends
     * and boundaries are relative to the cut.
     *
     * @var list<array{clip: CasterClip, pcm: string, ends: list<float>, boundaries: list<float>, part: bool, since: float}>
     */
    private array $held = [];

    /**
     * @param  array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, long_max_hold_seconds?: array<string, float|int>, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int, runs?: array{enabled?: bool, every_minutes?: array{min?: float|int, max?: float|int}, clips?: array{min?: int, max?: int}, breath_seconds?: array{min?: float|int, max?: float|int}}, parts?: array{breath_seconds?: float|int, wait_seconds?: float|int}, stinger_seconds?: float|int, bulletin_late_seconds?: float|int, rubric_late_seconds?: float|int, blocks?: array<string, mixed>, reaction_max_hold_seconds?: float|int}  $settings
     */
    public function __construct(
        private readonly ClipSpool $spool,
        public readonly CommentaryTimeline $timeline,
        private readonly array $settings = [],
        private readonly ?Idents $idents = null,
        ?BroadcastClock $clock = null,
    ) {
        $this->clock = $clock ?? BroadcastClock::fromConfig();
    }

    public static function fromConfig(ClipSpool $spool): self
    {
        /** @var array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, long_max_hold_seconds?: array<string, float|int>, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int, runs?: array{enabled?: bool, every_minutes?: array{min?: float|int, max?: float|int}, clips?: array{min?: int, max?: int}, breath_seconds?: array{min?: float|int, max?: float|int}}, parts?: array{breath_seconds?: float|int, wait_seconds?: float|int}, stinger_seconds?: float|int, bulletin_late_seconds?: float|int, rubric_late_seconds?: float|int, blocks?: array<string, mixed>, reaction_max_hold_seconds?: float|int} $settings */
        $settings = [...(array) config('tv.commentary.scenes', []), 'gap_seconds' => (array) config('tv.commentary.spool.gap_seconds', []), 'feed_lead_seconds' => (float) config('tv.commentary.mix.lead_seconds', 1.0), 'runs' => (array) config('tv.commentary.runs', []), 'parts' => (array) config('tv.commentary.parts', []), 'stinger_seconds' => (float) config('tv.commentary.programme.stinger_seconds', 0), 'bulletin_late_seconds' => (float) config('tv.commentary.programme.bulletin.late_seconds', 120), 'rubric_late_seconds' => (float) config('tv.commentary.programme.rubrics.late_seconds', 180), 'blocks' => (array) config('tv.commentary.blocks', []), 'reaction_max_hold_seconds' => (float) config('tv.commentary.chat.max_hold_seconds', 600)];

        $timeline = new CommentaryTimeline(
            (int) config('tv.commentary.mix.sample_rate', 24000),
            (float) config('tv.commentary.mix.news_music_db', -60),
            (float) config('tv.commentary.mix.news_fade_down_seconds', 1.0),
            (float) config('tv.commentary.mix.news_fade_up_seconds', 2.0),
        );

        return new self($spool, $timeline, $settings, Idents::fromConfig());
    }

    /**
     * Called once per poll with the slot the planner chose. Returns the text
     * lower third for the slot, or null (sound plays, or nothing to show).
     *
     * @param  array{kind: string, scene: string|null}  $slot
     */
    public function tick(RotationPlanner $planner, array $slot, float $now): ?string
    {
        if ($this->clip !== null && ! $this->timeline->isPlaying($now)) {
            if ($this->held !== [] && $this->held[array_key_last($this->held)]['clip']->is($this->clip)) {
                $this->park($now);
            } else {
                $this->clipEnded($this->clip, $now);
            }
        }

        // A programme block about to end: the next block joins it seamlessly, a breath after its last word (P6).
        if ($this->clip !== null && $this->clip->isBlock()) {
            $this->joinNextBlock($planner, $now);
        }

        // The bulletin's slot has come: it plays at the next clip boundary, before the next part, a live moment or the
        // gap's end; a scene or segment on air is cut at the end of its sentence for it. A rubric or the news talk lets
        // an overdue reaction go first.
        $overdue = $this->spool->overdueLive(CarbonImmutable::createFromTimestamp($now), (float) ($this->settings['reaction_max_hold_seconds'] ?? 600)) !== null;
        $bulletin = $this->bulletinSlot === null ? $this->dueBulletin($now) : null;
        if ($overdue && $bulletin !== null && $bulletin->kind !== CasterScript::BULLETIN) {
            $bulletin = null;
        }
        if ($bulletin !== null) {
            if ($this->clip === null) {
                if ($this->play($planner, $bulletin, $now)) {
                    return null;
                }
            } elseif (! $this->timeline->isCut() && ($this->clip->isBlock() || ! $this->clipIsPart) && ! in_array($this->clip->kind, ClipSpool::LIVE_KINDS, true)) {
                // A block is cut at the end of its sentence on air too, and resumes from there after the news.
                $end = $this->timeline->nextSentenceEnd($this->earliestCut($now)) ?? $now;
                $this->cut($end, $now);
                $planner->hold($end + 0.3);
            }
        }

        // The next part of the story on air comes before anything else, once its breath is over; an overdue reaction first.
        if ($this->clip === null && $this->part !== null && $now >= $this->part['at'] && ! $overdue) {
            $this->continuePart($planner, $now);
        }

        // A live moment or reaction plays at the next clip boundary: it cuts the gap short, also in the middle of
        // a quiet slot or a long silent one, and never waits for the next slot (user 2026-10-11: "as fast as possible").
        if ($this->clip === null && $planner->slotNumber() === $this->slotNumber) {
            $live = $this->spool->nextLive(CarbonImmutable::createFromTimestamp($now));

            if ($live !== null && $this->play($planner, $live, $now)) {
                return null;
            }
        }

        // The rest of a block cut for a reaction, a takeover or the news comes back once they are over.
        if ($this->clip === null && $this->held !== [] && $this->resumeHeld($planner, $now)) {
            return null;
        }

        // A programme teaser that is due ends the segment on air: it plays at the next clip boundary, never inside a story.
        if ($this->clip === null && $this->part === null && $planner->slotNumber() === $this->slotNumber) {
            $teaser = $this->spool->teaser(CarbonImmutable::createFromTimestamp($now));

            if ($teaser !== null && $this->play($planner, $teaser, $now)) {
                return null;
            }
        }

        // An overdue reaction cuts into what is on air at its next sentence end, never into a bulletin's news (a news
        // block's talk after its news it may); what it cut resumes from there afterwards.
        if ($overdue && $this->clip !== null && ! $this->spool->isLive($this->clip)
            && ($this->bulletinSlot === null || ($this->clip->isBlock() && $now >= $this->protectedUntil))) {
            $planned = $this->timeline->isCut() ? (float) $this->timeline->endsAt() : null;
            $at = $this->timeline->nextSentenceEnd($this->earliestCut($now, resumable: true));

            // A cut planned further on (a block waiting for its next boundary) comes forward to this sentence end.
            if ($at !== null && $planned !== null && $at < $planned - 1.0
                && $this->held !== [] && $this->held[array_key_last($this->held)]['clip']->is($this->clip)) {
                array_pop($this->held);
                $this->timeline->uncut();
                $planned = null;
            }

            if ($at !== null && $planned === null && $at < (float) $this->timeline->endsAt() - 1.0) {
                $this->cut($at, $now, resume: true);
            }
        }

        // A reaction or a due programme teaser while a block is on air: it waits for the block's next segment boundary
        // (P6), never cuts a sentence.
        if ($this->clip !== null && $this->clip->isBlock() && $this->held === [] && ! $this->timeline->isCut()
            && ($this->spool->nextLive(CarbonImmutable::createFromTimestamp($now)) !== null || $this->spool->teaser(CarbonImmutable::createFromTimestamp($now)) !== null)) {
            $at = $this->reactionCut($now);

            if ($at !== null) {
                $this->cut($at, $now);
            }
        }

        // A takeover waits for the end of the sentence on air, never longer; it never cuts into a bulletin (a news
        // block's talk after its news it may).
        if ($this->clip !== null && ($this->bulletinSlot === null || ($this->clip->isBlock() && $now >= $this->protectedUntil))
            && ! $this->timeline->isCut() && $planner->urgentPending()) {
            $end = $this->timeline->nextSentenceEnd($this->earliestCut($now)) ?? $now;
            $this->cut($end, $now);
            $planner->hold($end + 0.3);
        }

        if ($planner->slotNumber() === $this->slotNumber) {
            // The gap is over and the slide is still on air: the next clip starts now instead of waiting for the next
            // slide (user 2026-10-11: "große Lücken immer" — up to 49 s of music with 300 s of commentary ready).
            if ($this->clip === null && $now >= $this->quietUntil) {
                $decision = $this->spool->next(ScenePhase::forSlot($slot, CarbonImmutable::createFromTimestamp($now)), CarbonImmutable::createFromTimestamp($now), SceneTopic::forSlot($slot));

                if ($decision['clip'] !== null) {
                    $this->play($planner, $decision['clip'], $now);
                }
            }

            return $this->caption;
        }

        $this->slotNumber = $planner->slotNumber();
        $this->caption = null;

        // Still speaking (a hold that ran out at max_hold_seconds): the new slot gets no clip of its own.
        if ($this->clip !== null) {
            return null;
        }

        $decision = $this->spool->next(ScenePhase::forSlot($slot, CarbonImmutable::createFromTimestamp($now)), CarbonImmutable::createFromTimestamp($now), SceneTopic::forSlot($slot));

        // Within the gap only a live moment speaks; the slot holds until the gap is over.
        if ($now < $this->quietUntil && $decision['source'] !== 'live') {
            $planner->hold($this->quietUntil);

            return null;
        }

        if ($decision['clip'] === null || ! $this->play($planner, $decision['clip'], $now)) {
            // Nothing to say: a run ends here, it never picks up again after a silent slot.
            $this->runPosition = 0;
            $this->runLeft = 0;
            $planner->hold($now + (float) ($this->settings['silent_min_scene_seconds'] ?? 15));
            $this->caption = $decision['caption'];

            return $this->caption;
        }

        return null;
    }

    /**
     * The clip on air ended: it leaves the spool, and what follows is the
     * next part of its group after a breath, else (a live moment that cut in
     * between two parts) still the part that was due, else the music gap or,
     * inside a run, a breath.
     */
    private function clipEnded(CasterClip $clip, float $now): void
    {
        $at = CarbonImmutable::createFromTimestamp($now);
        $part = $this->clipIsPart ? $this->spool->partOf($clip) : null;
        $cut = $this->timeline->isCut();
        $this->logPiece($clip, (float) ($this->timeline->endsAt() ?? $now));
        $this->spool->played($clip);
        $this->clip = null;
        $this->clipIsPart = false;
        $this->bulletinSlot = null;
        $this->timeline->clear();

        $settings = (array) ($this->settings['parts'] ?? []);
        $breath = max(0.0, (float) ($settings['breath_seconds'] ?? 0.0));
        $wait = max(0.0, (float) ($settings['wait_seconds'] ?? 20));

        if ($part !== null) {
            // A live wished segment of another story cut in: the story that was due is over.
            if ($this->part !== null && $this->part['group'] !== $part['group']) {
                $this->spool->endGroup($this->part['group'], $this->part['index']);
            }

            $this->part = null;

            // Cut off by a takeover, the story lost its last sentences: it does not go on over the takeover.
            if ($cut) {
                $this->spool->endGroup($part['group'], $part['index'] + 1);
            } elseif ($this->spool->partPending($part['group'], $part['index'] + 1, $at)) {
                $this->part = ['group' => $part['group'], 'index' => $part['index'] + 1, 'at' => $now + $breath, 'until' => $now + $breath + $wait];
            } else {
                // The last part, or the next one cannot come any more: what is left of the group goes.
                $this->spool->endGroup($part['group'], $part['index'] + 1);
            }
        } elseif ($this->part !== null) {
            $this->part['at'] = $now + $breath;
            $this->part['until'] = max($this->part['until'], $now + $breath + $wait);
        }

        if ($this->part !== null) {
            // Only the next part may end this quiet (a live moment still cuts in).
            $this->quietUntil = $this->part['until'];

            return;
        }

        // A block that could not be joined seamlessly (a reaction or the news came between): the programme runs on
        // with the next block, without the music gap.
        if ($clip->isBlock() && $this->blocksFlowOn($clip, $at) && $this->spool->nextBlock($at) !== null) {
            $this->quietUntil = $now;
            $this->runPosition = 0;

            return;
        }

        // Inside a run only a breath; after its last clip the music gap returns. In quiet hours one clip every
        // `quiet_hours.every_minutes`, counted from the start of the clip that just ended, and no runs.
        $quiet = QuietHours::active($at);
        $inRun = ! $quiet && $this->runPosition > 0 && $this->runLeft > 0;
        $this->quietUntil = $now + ($quiet ? max($this->gap(), QuietHours::everySeconds() - (float) $clip->duration_seconds) : ($inRun ? $this->between('breath_seconds') : $this->gap()));
        $this->runPosition = $inRun ? $this->runPosition : 0;
    }

    /**
     * Plays the part that is due when its clip is ready; ends the group when
     * the part cannot come any more (expired, gone, undecodable) or was waited
     * for `parts.wait_seconds`, and the normal gaps return.
     */
    private function continuePart(RotationPlanner $planner, float $now): void
    {
        if ($this->part === null) {
            return;
        }

        ['group' => $group, 'index' => $index, 'until' => $until] = $this->part;
        $at = CarbonImmutable::createFromTimestamp($now);
        $clip = $this->spool->part($group, $index, $at);

        if ($clip !== null) {
            $this->part = null;

            if ($this->play($planner, $clip, $now, continuation: true)) {
                return;
            }
        } elseif ($this->spool->partPending($group, $index, $at) && $now < $until) {
            return;
        }

        $this->spool->endGroup($group, $index);
        $this->part = null;
        // Waited long enough already; a part that will not come gets the music gap a clip end would have had.
        $this->quietUntil = $now >= $until ? $now : $now + $this->gap();
    }

    /** Puts a clip on the timeline and holds the slot until it has been spoken; false when it cannot be decoded (then dropped). */
    private function play(RotationPlanner $planner, CasterClip $clip, float $now, bool $continuation = false): bool
    {
        $pcm = $this->spool->pcm($clip);

        if ($pcm === null) {
            // A clip that cannot be decoded is dropped, never retried on every slot.
            $this->spool->played($clip);

            return false;
        }

        // The voice feed has already written silence up to `feed_lead_seconds` ahead of the clock: a clip starting
        // earlier lost its first syllables to it (user 2026-10-11: "Der Start des Audios scheint beim Start immer
        // etwas abgeschnitten zu sein"; lead-in 0.5 s against a 1.0 s feed lead cut half a second).
        $start = $now + max((float) ($this->settings['lead_in_seconds'] ?? 0.5), (float) ($this->settings['feed_lead_seconds'] ?? 0.0) + 0.05);
        $ends = $clip->sentence_ends;
        $this->ident = null;
        $bulletinSlot = null;

        if ($clip->kind === CasterScript::BULLETIN) {
            $airsAt = $this->spool->airsAt($clip);
            $slot = $airsAt === null ? null : $this->clock->slotAt($airsAt);
            $bulletinSlot = $slot['type'] ?? BroadcastClock::NEWS;

            // Without the station sound only a pause stands in for the stinger; with Idents the stinger is part of the PCM
            // below; a rendered block carries its own.
            if ($this->idents === null && ! $clip->isDressed() && ($slot['stinger'] ?? false) === true) {
                $start += max(0.0, (float) ($this->settings['stinger_seconds'] ?? 0.0));
                $this->stinger = ['slot' => $bulletinSlot, 'at' => $now, 'voice_at' => $start];
            }
        }

        // The station sound (Idents): a segment or bulletin opens with its stinger, never a later part of its story.
        // The ffmpeg calls take real time while the feed keeps writing ahead: the start moves by it, so the stinger's
        // first beat is not lost to silence already written.
        // A block rendered on the workstation is dressed already (P6): never a second stinger or bed.
        if ($this->idents !== null && ! $continuation && ! $clip->isDressed()) {
            $dressing = microtime(true);
            $dressed = $this->idents->dress($clip, $pcm, $ends, CarbonImmutable::createFromTimestamp($now));
            ['pcm' => $pcm, 'ends' => $ends] = $dressed;
            $this->ident = $dressed['stinger'];
            $start += microtime(true) - $dressing;
        }

        // A bulletin puts the music into news mode for as long as its PCM (stinger and bed included) plays.
        $this->timeline->start($pcm, $ends, $start, news: $bulletinSlot !== null);
        $this->spool->playing($clip);
        $this->clip = $clip;
        $this->clipIsPart = $continuation || ($clip->kind !== CasterScript::BULLETIN && $this->spool->partOf($clip) !== null);
        $this->bulletinSlot = $bulletinSlot;
        $this->quietUntil = 0.0;
        $this->onAir($clip, $start, $ends);

        if ($clip->isBlock()) {
            // A block is a stretch of programme: it ends a run and holds the slide only briefly, the slides rotate under it.
            $this->runPosition = 0;
            $this->runLeft = 0;
            $planner->hold($now + (float) ($this->settings['blocks']['hold_seconds'] ?? 30));

            return true;
        }

        if ($bulletinSlot !== null) {
            // The bulletin ends a run on air and is no run clip.
            $this->runPosition = 0;
            $this->runLeft = 0;
        } elseif (! in_array($clip->kind, ClipSpool::LIVE_KINDS, true) && ! $continuation) {
            // A later part continues its story: the group counts as one run clip.
            $this->countRunClip($now);
        }

        $spoken = (float) $this->timeline->endsAt() + (float) ($this->settings['tail_seconds'] ?? 1.5);
        $planner->hold(min(
            max($spoken, $now + (float) ($this->settings['min_scene_seconds'] ?? 20)),
            $now + (float) ($this->settings['long_max_hold_seconds'][$clip->kind] ?? $this->settings['max_hold_seconds'] ?? 90),
        ));

        return true;
    }

    /**
     * Remembers where the clip on air starts and, on the wall clock, where its
     * sentences end, its segment boundaries lie and its protected opening ends.
     *
     * @param  list<float>  $ends  seconds into the PCM that starts at `$start`
     * @param  float  $offset  where the clip itself starts within that PCM (a joined block follows the tail of the one before)
     */
    private function onAir(CasterClip $clip, float $start, array $ends, float $offset = 0.0): void
    {
        $this->airStart = $start + $offset;
        $this->airEnds = array_map(fn (float $end): float => $start + $end, $ends);
        $this->airBoundaries = array_map(fn (float $boundary): float => $this->airStart + $boundary, $clip->boundaries());
        $this->protectedUntil = $this->airStart + $clip->protectedSeconds();
    }

    /**
     * The earliest moment a clip may be cut at: now for a single clip; for a
     * block or a clip whose rest resumes (`$resumable`) past what the voice
     * feed has already written (`feed_lead_seconds` and a poll) and past its
     * protected opening, so its rest resumes exactly where it stopped.
     */
    private function earliestCut(float $now, bool $resumable = false): float
    {
        if ($this->clip === null || (! $this->clip->isBlock() && ! $resumable)) {
            return $now;
        }

        return max($now + (float) ($this->settings['feed_lead_seconds'] ?? 0.0) + 0.35, $this->protectedUntil);
    }

    /**
     * Cuts the clip on air at `$at` (a sentence end or a segment boundary).
     * A block, or any clip cut with `$resume` (for an overdue reaction), keeps
     * its rest (held) and resumes from there; any other single clip is over at
     * the cut, as before.
     */
    private function cut(float $at, float $now, bool $resume = false): void
    {
        if ($this->clip !== null && ($this->clip->isBlock() || $resume)) {
            $end = (float) $this->timeline->endsAt();
            $rest = $end - $at > 1.0 ? $this->timeline->samples((int) round($at * $this->timeline->rate), (int) round($end * $this->timeline->rate)) : '';

            if ($rest !== '') {
                $this->held[] = [
                    'clip' => $this->clip,
                    'pcm' => $rest,
                    'ends' => array_values(array_map(fn (float $time): float => round($time - $at, 3), array_filter($this->airEnds, fn (float $time): bool => $time > $at + 0.05))),
                    'boundaries' => array_values(array_map(fn (float $time): float => round($time - $at, 3), array_filter($this->airBoundaries, fn (float $time): bool => $time > $at + 0.05))),
                    'part' => $this->clipIsPart,
                    'since' => $now,
                ];
            }
        }

        $this->timeline->cutAt($at);
    }

    /**
     * Where a block on air makes way for a waiting reaction: its next segment
     * boundary within `blocks.reaction_wait_seconds`, else the last sentence
     * end within that wait, else its next sentence end; never inside its
     * protected opening, never closer than the feed lead. Null when the block
     * ends before that anyway (the reaction follows it).
     */
    private function reactionCut(float $now): ?float
    {
        $earliest = $this->earliestCut($now);
        $latest = $now + (float) ($this->settings['blocks']['reaction_wait_seconds'] ?? 90);
        $end = (float) $this->timeline->endsAt();
        $within = fn (array $times): array => array_values(array_filter($times, fn (float $time): bool => $time >= $earliest && $time <= $latest));

        $at = $within($this->airBoundaries)[0] ?? null;

        if ($at === null) {
            $ends = $within($this->airEnds);
            $at = $ends === [] ? null : end($ends);
        }

        if ($at === null) {
            $later = array_values(array_filter($this->airEnds, fn (float $time): bool => $time >= $earliest));
            $at = $later[0] ?? null;
        }

        return $at === null || $at >= $end - 1.0 ? null : $at;
    }

    /** The cut block stopped: it waits off air (held) while the reaction, takeover or news plays. */
    private function park(float $now): void
    {
        if ($this->clip !== null) {
            $this->logPiece($this->clip, (float) ($this->timeline->endsAt() ?? $now));
        }

        $this->clip = null;
        $this->clipIsPart = false;
        $this->bulletinSlot = null;
        $this->timeline->clear();
        $this->quietUntil = $now;
    }

    /**
     * Resumes the held rest cut last once no bulletin, live moment or
     * reaction waits any more; drops a rest after `blocks.resume_within_seconds`.
     */
    private function resumeHeld(RotationPlanner $planner, float $now): bool
    {
        foreach ($this->held as $key => $rest) {
            if ($now - $rest['since'] > (float) ($this->settings['blocks']['resume_within_seconds'] ?? 600)) {
                unset($this->held[$key]);
                $this->spool->played($rest['clip']);
            }
        }

        $this->held = array_values($this->held);

        if ($this->held === []) {
            return false;
        }

        $held = $this->held[array_key_last($this->held)];
        $at = CarbonImmutable::createFromTimestamp($now);

        if ($this->dueBulletin($now) !== null || $this->spool->nextLive($at) !== null) {
            return false;
        }

        // A programme teaser that came due while the block was off air goes first, then the block resumes; never in
        // the middle of a story's part.
        $teaser = $held['clip']->isBlock() || ! $held['part'] ? $this->spool->teaser($at) : null;
        if ($teaser !== null && $this->play($planner, $teaser, $now)) {
            return true;
        }

        array_pop($this->held);
        $start = $now + max((float) ($this->settings['lead_in_seconds'] ?? 0.5), (float) ($this->settings['feed_lead_seconds'] ?? 0.0) + 0.05);
        $this->timeline->start($held['pcm'], $held['ends'], $start);
        // Its playing mark starts again, so the spool does not take it for one a crash left behind.
        $held['clip']->touch();
        $this->clip = $held['clip'];
        $this->clipIsPart = $held['part'];
        $this->quietUntil = 0.0;
        $this->airStart = $start;
        $this->airEnds = array_map(fn (float $end): float => $start + $end, $held['ends']);
        $this->airBoundaries = array_map(fn (float $boundary): float => $start + $boundary, $held['boundaries']);
        $this->protectedUntil = 0.0;
        // A block holds the slide briefly; a single clip until its rest has been spoken, as when it started.
        $planner->hold($held['clip']->isBlock()
            ? $now + (float) ($this->settings['blocks']['hold_seconds'] ?? 30)
            : (float) $this->timeline->endsAt() + (float) ($this->settings['tail_seconds'] ?? 1.5));

        return true;
    }

    /**
     * Joins the next block to the block on air, `blocks.join_seconds` after
     * its end, when it ends within the next two polls: the timeline restarts
     * now with the identical rest of the block on air, the breath and the
     * next block, so not a sample changes of what is already playing and no
     * poll or feed lead widens the gap. The next part of a story the block
     * ended in comes first; nothing joins while a bulletin, a reaction or a
     * held rest waits (they take the boundary).
     */
    private function joinNextBlock(RotationPlanner $planner, float $now): void
    {
        $end = $this->timeline->endsAt();

        if ($this->clip === null || $end === null || $this->timeline->isCut() || $this->held !== [] || $end - $now > 2.5 || $end <= $now) {
            return;
        }

        $at = CarbonImmutable::createFromTimestamp($now);

        if (! $this->blocksFlowOn($this->clip, $at) || $this->dueBulletin($now) !== null || $this->spool->nextLive($at) !== null || $this->spool->teaser($at) !== null) {
            return;
        }

        $part = $this->clipIsPart ? $this->spool->partOf($this->clip) : null;
        $continues = $part !== null && $this->spool->partPending($part['group'], $part['index'] + 1, $at);
        $next = $continues ? $this->spool->part($part['group'], $part['index'] + 1, $at) : $this->spool->nextBlock($at);

        if ($next === null || ! $next->isBlock()) {
            return;
        }

        $pcm = $this->spool->pcm($next);

        if ($pcm === null) {
            $this->spool->played($next);

            return;
        }

        $rate = $this->timeline->rate;
        $from = (int) round($now * $rate);
        $tail = $this->timeline->samples($from, (int) round($end * $rate));
        $tailSeconds = Pcm::seconds($tail, $rate);
        $range = (array) ($this->settings['blocks']['join_seconds'] ?? []);
        $breath = self::random($range === [] ? ['min' => 0.4, 'max' => 0.4] : $range);
        $joined = $tail.Pcm::silence($breath, $rate).$pcm;
        $offset = Pcm::seconds($joined, $rate) - Pcm::seconds($pcm, $rate);
        // A six-minute block is about 17 MB of PCM: never more copies of it alive than needed.
        unset($pcm, $tail);
        $ends = [round($tailSeconds, 3), ...array_map(fn (float $time): float => round($offset + $time, 3), $next->sentence_ends)];

        $this->logPiece($this->clip, $end);
        $this->spool->played($this->clip);
        $this->part = null;

        $start = $from / $rate;
        $this->timeline->start($joined, $ends, $start);
        $this->spool->playing($next);
        $this->clip = $next;
        $this->clipIsPart = $continues || ($next->kind !== CasterScript::BULLETIN && $this->spool->partOf($next) !== null);
        $this->bulletinSlot = null;
        $this->ident = null;
        $this->quietUntil = 0.0;
        $this->onAir($next, $start, $ends, $offset);
        $planner->hold($now + (float) ($this->settings['blocks']['hold_seconds'] ?? 30));
    }

    /**
     * Whether the next block may follow the block `$clip` without a pause:
     * always by day; in the quiet hours (one clip every
     * `quiet_hours.every_minutes`) only a replayed block when
     * `blocks.replay.continuous` asks for a best of in one piece.
     */
    private function blocksFlowOn(CasterClip $clip, CarbonImmutable $at): bool
    {
        return ! QuietHours::active($at) || ($clip->isReplay() && ($this->settings['blocks']['replay']['continuous'] ?? false) === true);
    }

    /** One stretch of voice that was on air, for the air log (caster:gaps). */
    private function logPiece(CasterClip $clip, float $end): void
    {
        AirLog::record(['clip' => $clip->id, 'kind' => $clip->kind, 'block' => $clip->isBlock(), 'start' => round($this->airStart, 3), 'end' => round($end, 3)]);
    }

    /**
     * The bulletin or rubric clip to play now: a bulletin only within
     * `bulletin_late_seconds` of a bulletin slot's start on the clock, a
     * rubric (or the news talk) within `rubric_late_seconds` of its own
     * `airs_at`; only one whose time has come.
     */
    private function dueBulletin(float $now): ?CasterClip
    {
        if (! $this->clock->enabled()) {
            return null;
        }

        $at = CarbonImmutable::createFromTimestamp((int) floor($now));
        $slot = $this->clock->slotAt($at);

        $bulletin = BroadcastClock::isBulletin($slot['type']) && $now - $slot['key'] <= (float) ($this->settings['bulletin_late_seconds'] ?? 120) ? $this->spool->bulletin($at) : null;

        // A rubric from its own `airs_at` on (its slot, or the end of the bulletin for the news talk), at most `rubric_late_seconds` late.
        if ($bulletin !== null || $this->rubricAskedAt === $at->getTimestamp()) {
            return $bulletin;
        }

        $this->rubricAskedAt = $at->getTimestamp();

        return $this->spool->rubric($at, (int) ($this->settings['rubric_late_seconds'] ?? 180));
    }

    /**
     * The stinger cue of the bulletin that just started, once (P4 plays it): its slot type, when it was asked for
     * and when the voice starts; null when there is none.
     *
     * @return array{slot: string, at: float, voice_at: float}|null
     */
    public function takeStinger(): ?array
    {
        $stinger = $this->stinger;
        $this->stinger = null;

        return $stinger;
    }

    /** The slot type of the bulletin on air (news, headlines), null when none plays (the news bed, P4). */
    public function bulletinOnAir(): ?string
    {
        return $this->bulletinSlot;
    }

    /** A random gap within `gap_seconds` (none when unset). */
    private function gap(): float
    {
        return self::random((array) ($this->settings['gap_seconds'] ?? []));
    }

    /** A random length within `runs.breath_seconds` or `runs.every_minutes` (none when unset). */
    private function between(string $key): float
    {
        $runs = $this->settings['runs'] ?? [];

        return self::random(($key === 'breath_seconds' ? $runs['breath_seconds'] ?? null : $runs['every_minutes'] ?? null) ?? []);
    }

    /**
     * A clip that is not a live moment started: it continues the run on air,
     * or opens a new one when the run is due (the first one is drawn
     * `runs.every_minutes` after the first clip).
     */
    private function countRunClip(float $now): void
    {
        $runs = (array) ($this->settings['runs'] ?? []);

        if (($runs['enabled'] ?? false) !== true) {
            return;
        }

        if ($this->runPosition > 0 && $this->runLeft > 0) {
            $this->runPosition++;
            $this->runLeft--;

            return;
        }

        if ($this->nextRunAt === null) {
            $this->nextRunAt = $now + 60 * $this->between('every_minutes');

            return;
        }

        if ($now < $this->nextRunAt) {
            return;
        }

        $clips = (array) ($runs['clips'] ?? []);
        $min = max(2, (int) ($clips['min'] ?? 2));
        $size = mt_rand($min, max($min, (int) ($clips['max'] ?? $min)));

        $this->runId++;
        $this->runPosition = 1;
        $this->runLeft = $size - 1;
        $this->nextRunAt = $now + 60 * $this->between('every_minutes');
    }

    /**
     * A random value within a `min`/`max` range (0 when unset).
     *
     * @param  array<array-key, mixed>  $range
     */
    private static function random(array $range): float
    {
        $min = max(0.0, (float) ($range['min'] ?? 0));
        $max = max($min, (float) ($range['max'] ?? $min));

        return $min + ($max - $min) * mt_rand(0, 1000) / 1000;
    }

    /**
     * The run on air: its number, the position of the clip on air within it (0: no run) and the run clips still to come.
     *
     * @return array{id: int, position: int, left: int}
     */
    public function run(): array
    {
        return ['id' => $this->runId, 'position' => $this->runPosition, 'left' => $this->runLeft];
    }

    /** The stinger slot the clip on air opened with, or null (tests, logging). */
    public function ident(): ?string
    {
        return $this->ident;
    }

    /** The clip on air (tests, logging). */
    public function clip(): ?CasterClip
    {
        return $this->clip;
    }
}
