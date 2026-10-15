<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\TournamentLive;
use App\Support\TwentyOne\Stream\MusicTimeline;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Closure;

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
 * Radio imaging (P11, Imaging): the Anchor's uploaded jingles open a clip
 * (station ID, sweeper, format opener, news open) or close a bulletin (news
 * close), by the rules of Imaging; an opener takes the place of the stinger.
 * Never on a reaction or live moment, a later part of a story or a block.
 *
 * The news bulletin (plan "TWENTY ONE Radio", P1; ClipSpool::bulletin()):
 * from its slot on (:00, :30 on the broadcast clock, at most
 * `bulletin.late_seconds` late) it plays before anything else, over the
 * music gap and the quiet hours' ten-minute rhythm. A scene or segment on air
 * is cut at the end of its sentence for it; a live moment, a reaction or a
 * part of a story too, and its rest resumes from that sentence after the news
 * (the story goes on after the bulletin; it never waits for a clip's end). Nothing
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
 *
 * A podcast replay (P12; ClipSpool::podcast(), PodcastReplay) starts from its
 * slot at the next clip boundary and plays its chunks as one piece, each
 * joined to the one before without a breath, all of it in news mode (the
 * music fully ducked). Only a bulletin and an overdue reaction cut into it,
 * always at a pause the library found: for a bulletin the pause nearest its
 * slot within `podcast.pause_window_seconds` (the first one after the slot,
 * else the last one before it, and then nothing else plays until the
 * bulletin), and the episode resumes from there after bulletin, sport and
 * weather and the replies that waited for the break. No rubric, teaser or
 * waiting reaction cuts it, and a cut rest waits up to
 * `podcast.resume_within_seconds`. Markus' and Sirius' announcement
 * (CasterScript::PODCAST_INTRO) plays right before the first chunk, the first
 * chunk right after it (nothing comes between), and Markus' back-announce
 * (PODCAST_OUTRO) right after the last chunk, each when it is voiced.
 *
 * A music break (user 2026-10-13: "Ab und an soll der Radiosender auch mal ein
 * komplettes Musikstück spielen lassen"; `music_breaks`): `per_hour` times an
 * hour, counted from the end of the last break (the first one half an
 * interval after the start), the voice pauses for one complete vocal track
 * of the stream music. It is planned at the next clip boundary (or ahead of
 * it, while a single clip or block plays) for the first PlebRap track (else
 * vocal track) of the music timeline that starts within `wait_seconds` of it
 * and whose whole run
 * crosses no bulletin, rubric or show window slot, no podcast airing and no
 * quiet hour; never while a story told in parts, a held rest or a podcast is
 * on air. No block joins the one on air once a break is planned. Until the
 * track starts only a single clip that ends in time plays (no first part of
 * a story); from its first second to its last nothing plays but a bulletin
 * (the news never waits), and the voice resumes as the track ends. A clip
 * still on air when the track starts cancels the break (it would no longer be
 * complete); the next boundary plans again.
 */
final class SceneDirector
{
    /** Seconds around a music break's track the voice keeps clear (the encoder starts the music a moment late). */
    private const MUSIC_BREAK_MARGIN = 2.0;

    /** Seconds a stinger or jingle may add to a clip before a music break (Idents, Imaging). */
    private const MUSIC_BREAK_DRESSING_SECONDS = 8.0;

    private ?int $slotNumber = null;

    private ?CasterClip $clip = null;

    private ?string $caption = null;

    /** The stinger slot the clip on air opened with (Idents), or null. */
    private ?string $ident = null;

    /** @var list<string> the imaging categories around the clip on air (Imaging), opener first */
    private array $imagingOnAir = [];

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

    /** The announcement of a podcast is on air: its first chunk follows before anything else (P12). */
    private bool $podcastFollows = false;

    /** The last chunk of a podcast just ended: its back-announce follows before anything else (P12). */
    private ?CasterClip $podcastEnded = null;

    /** The slot of the bulletin a podcast stopped for before the slot began (P12): nothing else plays until that bulletin. */
    private ?int $podcastBreak = null;

    /** The second the spool was last asked for a due rubric (at most once a second). */
    private int $rubricAskedAt = 0;

    /**
     * The stinger the stream has to play (P4): the slot type, when it was asked for, and when the voice starts.
     *
     * @var array{slot: string, at: float, voice_at: float}|null
     */
    private ?array $stinger = null;

    private readonly BroadcastClock $clock;

    /**
     * The music break planned or on air: the vocal track's start and end on the wall clock, its file, and whether the
     * voice already stood aside for it.
     *
     * @var array{start: float, end: float, file: string, started: bool}|null
     */
    private ?array $musicBreak = null;

    /** When the next music break is due (null: not drawn yet). */
    private ?float $nextMusicBreakAt = null;

    /** When a music break was last looked for (at most every few seconds). */
    private float $musicBreakAskedAt = 0.0;

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
     * @param  array{live_anytime?: bool, min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, long_max_hold_seconds?: array<string, float|int>, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int, runs?: array{enabled?: bool, every_minutes?: array{min?: float|int, max?: float|int}, clips?: array{min?: int, max?: int}, breath_seconds?: array{min?: float|int, max?: float|int}}, parts?: array{breath_seconds?: float|int, wait_seconds?: float|int}, stinger_seconds?: float|int, bulletin_late_seconds?: float|int, rubric_late_seconds?: float|int, blocks?: array<string, mixed>, reaction_max_hold_seconds?: float|int, podcast?: array{pause_window_seconds?: float|int, resume_within_seconds?: float|int}, music_breaks?: array{enabled?: bool, per_hour?: float|int, wait_seconds?: float|int}, music_first?: array{enabled?: bool, max_wait_seconds?: float|int}}  $settings
     */
    public function __construct(
        private readonly ClipSpool $spool,
        public readonly CommentaryTimeline $timeline,
        private readonly array $settings = [],
        private readonly ?Idents $idents = null,
        ?BroadcastClock $clock = null,
        private readonly ?Imaging $imaging = null,
        private readonly ?Closure $musicTracks = null,
    ) {
        $this->clock = $clock ?? BroadcastClock::fromConfig();
    }

    public static function fromConfig(ClipSpool $spool): self
    {
        /** @var array{live_anytime?: bool, min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, long_max_hold_seconds?: array<string, float|int>, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int, runs?: array{enabled?: bool, every_minutes?: array{min?: float|int, max?: float|int}, clips?: array{min?: int, max?: int}, breath_seconds?: array{min?: float|int, max?: float|int}}, parts?: array{breath_seconds?: float|int, wait_seconds?: float|int}, stinger_seconds?: float|int, bulletin_late_seconds?: float|int, rubric_late_seconds?: float|int, blocks?: array<string, mixed>, reaction_max_hold_seconds?: float|int, podcast?: array{pause_window_seconds?: float|int, resume_within_seconds?: float|int}, music_breaks?: array{enabled?: bool, per_hour?: float|int, wait_seconds?: float|int}, music_first?: array{enabled?: bool, max_wait_seconds?: float|int}} $settings */
        $settings = [...(array) config('tv.commentary.scenes', []), 'gap_seconds' => (array) config('tv.commentary.spool.gap_seconds', []), 'feed_lead_seconds' => (float) config('tv.commentary.mix.lead_seconds', 1.0), 'runs' => (array) config('tv.commentary.runs', []), 'parts' => (array) config('tv.commentary.parts', []), 'stinger_seconds' => (float) config('tv.commentary.programme.stinger_seconds', 0), 'bulletin_late_seconds' => (float) config('tv.commentary.programme.bulletin.late_seconds', 120), 'rubric_late_seconds' => (float) config('tv.commentary.programme.rubrics.late_seconds', 180), 'blocks' => (array) config('tv.commentary.blocks', []), 'reaction_max_hold_seconds' => (float) config('tv.commentary.chat.max_hold_seconds', 600), 'live_anytime' => (bool) config('tv.commentary.chat.live_anytime', true),
            'podcast' => ['pause_window_seconds' => (float) config('tv.commentary.podcast_replay.pause_window_seconds', 20), 'resume_within_seconds' => (float) config('tv.commentary.podcast_replay.resume_within_seconds', 1800)],
            'music_breaks' => (array) config('tv.commentary.music_breaks', []), 'music_first' => (array) config('tv.commentary.music_first', [])];

        $timeline = new CommentaryTimeline(
            (int) config('tv.commentary.mix.sample_rate', 24000),
            (float) config('tv.commentary.mix.news_music_db', -60),
            (float) config('tv.commentary.mix.news_fade_down_seconds', 1.0),
            (float) config('tv.commentary.mix.news_fade_up_seconds', 2.0),
        );

        return new self($spool, $timeline, $settings, Idents::fromConfig(), null, Imaging::fromConfig(), self::musicTracksFromConfig());
    }

    /**
     * The stream music's tracks within a stretch of the wall clock, read from the timeline the stream writes
     * (MusicTimeline::tracks()). A sung track (vocal folder, PlebRap, or Wavlake) is `vocal`, so a music
     * break can cover it whole and the voice does not talk over it. Instrumentals are the beds. `priority`
     * stays PlebRap, so a break still prefers that folder when one is inside the wait.
     *
     * @return Closure(float, float): list<array{file: string, start: float, end: float, vocal: bool, priority: bool}>
     */
    public static function musicTracksFromConfig(): Closure
    {
        $path = (string) config('twentyone.stream.music.timeline_file');
        $vocals = rtrim((string) config('twentyone.stream.music.dir'), '/');
        $plebrap = rtrim((string) config('twentyone.stream.music.plebrap_dir'), '/');
        $wavlake = rtrim((string) config('twentyone.stream.music.wavlake_dir'), '/');

        return fn (float $from, float $to): array => array_map(function (array $track) use ($vocals, $plebrap, $wavlake): array {
            $dir = dirname($track['file']);
            $priority = $plebrap !== '' && $dir === $plebrap;
            $sung = $priority
                || ($vocals !== '' && $dir === $vocals)
                || ($wavlake !== '' && $dir === $wavlake);

            return [...$track, 'vocal' => $sung, 'priority' => $priority];
        }, $path === '' ? [] : MusicTimeline::tracks($path, $from, $to));
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

        // A music break is planned at the boundary ahead, or one already planned is no longer whole.
        $this->planMusicBreak($now);

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
        // A rubric never cuts a rubric on air, nor comes between its parts: the news talk waits for the end of Sport und
        // Wetter when that runs longer than estimated.
        if ($bulletin !== null && $bulletin->kind === CasterScript::RUBRIC && ($this->clip?->kind === CasterScript::RUBRIC
            || ($this->clip === null && $this->part !== null && CasterScript::query()->where('part_group', $this->part['group'])->value('kind') === CasterScript::RUBRIC))) {
            $bulletin = null;
        }
        // A podcast stops for a bulletin only, never for a rubric (P12).
        if ($bulletin !== null && $bulletin->kind !== CasterScript::BULLETIN && $this->isPodcast($this->clip)) {
            $bulletin = null;
        }
        if ($bulletin !== null) {
            if ($this->clip === null) {
                if ($this->play($planner, $bulletin, $now)) {
                    return null;
                }
            } elseif ($bulletin->kind === CasterScript::BULLETIN && ! $this->clip->isBlock() && ($this->clipIsPart || in_array($this->clip->kind, ClipSpool::LIVE_KINDS, true))) {
                // The news never waits for a clip's end: a part of a story, a reaction or a live moment is cut at its next
                // sentence end too and its rest resumes from there after the news (live 2026-10-12 22:00: a 34 s duet part
                // that started 9 s before the slot held the bulletin back to 22:00:28).
                $end = $this->timeline->nextSentenceEnd($this->earliestCut($now, resumable: true)) ?? $this->earliestCut($now, resumable: true);

                if (! $this->timeline->isCut() && $end < (float) $this->timeline->endsAt() - 1.0) {
                    $this->cut($end, $now, resume: true);
                    $planner->hold($end + 0.3);
                }
            } elseif (! $this->timeline->isCut() && ($this->clip->isBlock() || ! $this->clipIsPart) && ! in_array($this->clip->kind, ClipSpool::LIVE_KINDS, true)) {
                // A block is cut at the end of its sentence on air too, and resumes from there after the news.
                $end = $this->timeline->nextSentenceEnd($this->earliestCut($now)) ?? $now;

                // A podcast with no pause within the window (its music outro: 233 s without one in episode #335) stops at
                // once rather than let the news expire (P12).
                if ($this->isPodcast($this->clip) && $end > $this->earliestCut($now) + (float) ($this->settings['podcast']['pause_window_seconds'] ?? 20)) {
                    $end = $this->earliestCut($now);
                }
                $this->cut($end, $now);
                $planner->hold($end + 0.3);
            }
        }

        // The music break's track is playing: only the music, until its last second (a due bulletin went first above), unless
        // a live reaction waits (liveAnytime()).
        if ($this->clip === null && $this->inMusicBreak($now) && ! ($this->liveAnytime($now) && $this->spool->nextLive(CarbonImmutable::createFromTimestamp($now)) !== null)) {
            return null;
        }

        // A podcast on air stops at the pause nearest the next bulletin's slot; stopped before the slot, nothing else plays
        // until that bulletin (P12).
        $this->podcastBreak($now);
        if ($this->clip === null && $this->awaitingBulletin($now)) {
            return null;
        }

        // The announcement ended: the episode starts at once. The episode ended: the back-announce follows at once.
        if ($this->clip === null && ($this->podcastFollows || $this->podcastEnded !== null) && $this->podcastFrameNext($planner, $now)) {
            return null;
        }

        // The next part of the story on air comes before anything else, once its breath is over; an overdue reaction first.
        if ($this->clip === null && $this->part !== null && $now >= $this->part['at'] && ! $overdue) {
            $this->continuePart($planner, $now);
        }

        // A live moment or reaction plays at the next clip boundary: it cuts the gap short, also in the middle of
        // a quiet slot or a long silent one, and never waits for the next slot (user 2026-10-11: "as fast as possible").
        // It does not wait for the end of the gap's track either (liveAnytime()); without that, music first: it waits for
        // the track's end like everything else, then goes first (an overdue one not).
        if ($this->clip === null && $planner->slotNumber() === $this->slotNumber && ($overdue || $this->gapOver($now) || $this->liveAnytime($now))) {
            $live = $this->spool->nextLive(CarbonImmutable::createFromTimestamp($now));

            if ($live !== null && $this->play($planner, $live, $now)) {
                return null;
            }
        }

        // The rest of a block cut for a reaction, a takeover or the news comes back once they are over.
        if ($this->clip === null && $this->held !== [] && $this->resumeHeld($planner, $now)) {
            return null;
        }

        // A podcast replay whose slot has come starts at the next clip boundary, after a story on air and anything held;
        // after a cut its next chunk goes on the same way (P12).
        if ($this->clip === null && $this->part === null && $this->held === []) {
            $at = CarbonImmutable::createFromTimestamp($now);
            $podcast = $this->spool->podcast($at);
            // Its first chunk comes after Markus' and Sirius' announcement, when that is voiced.
            $intro = $podcast !== null && ($podcast->podcastChunk()['index'] ?? 0) === 1 ? $this->spool->podcastFrame($podcast, CasterScript::PODCAST_INTRO, $at) : null;

            if ($intro !== null && $this->play($planner, $intro, $now)) {
                $this->podcastFollows = true;

                return null;
            }

            if ($podcast !== null && $this->play($planner, $podcast, $now)) {
                return null;
            }
        }

        // A programme teaser that is due ends the segment on air: it plays at the next clip boundary, never inside a story
        // (music first: after the gap's track).
        if ($this->clip === null && $this->part === null && $planner->slotNumber() === $this->slotNumber && $this->gapOver($now)) {
            $teaser = $this->spool->teaser(CarbonImmutable::createFromTimestamp($now));

            if ($teaser !== null && $this->play($planner, $teaser, $now)) {
                return null;
            }
        }

        // An overdue reaction cuts into what is on air at its next sentence end, never into the news, the weather
        // and sport talk, a news segment or a rendered programme (user 2026-10-15: reactions go on air at once,
        // the programme runs on); what it cut resumes from there afterwards.
        if ($overdue && $this->clip !== null && ! $this->spool->isLive($this->clip) && ! $this->protectedFromReactions($this->clip)) {
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

        // A reaction no longer cuts into a block on air, not even at a segment boundary (user 2026-10-15: a rendered
        // programme runs undisturbed); it follows at the block's end. A due programme teaser waits with it.

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

        // Within the gap only a live moment speaks (music first: nothing); the slot holds until the gap is over.
        if ($now < $this->quietUntil && ($decision['source'] !== 'live' || $this->musicFirst())) {
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

        // The last chunk of a podcast played to its end: the back-announce comes next (P12).
        if ($this->isPodcast($clip) && ! $cut && $clip->podcastChunk() !== null && $clip->podcastChunk()['index'] === $clip->podcastChunk()['count']) {
            $this->podcastEnded = $clip;
        }
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
        // Never into or out of a live tournament comment: it keeps its minute of music on both sides (user 2026-10-14: "jetzt
        // fehlt der 1-Minuten-Abstand, alle kommen auf einmal hintereinander").
        $next = $clip->isBlock() ? $this->spool->nextBlock($at) : null;

        if ($clip->isBlock() && $this->blocksFlowOn($clip, $at, false) && $next !== null
            && ! (TournamentLive::enabled() && (self::isTournamentLive($clip) || self::isTournamentLive($next)))) {
            $this->quietUntil = $now;
            $this->runPosition = 0;

            return;
        }

        // Inside a run only a breath; after its last clip the music gap returns. In quiet hours one clip every
        // `quiet_hours.every_minutes`, counted from the start of the clip that just ended, and no runs.
        // The music gap opens only when the spool is empty (user 2026-10-15: "immer direkt live, wenn hochgeladen"):
        // with a clip waiting, the next one follows after a short breath, so the voice runs on while there is supply —
        // except with music first, where the track still has precedence.
        $quiet = QuietHours::active($at);
        $inRun = ! $quiet && $this->runPosition > 0 && $this->runLeft > 0;
        $this->quietUntil = $now + ($quiet ? max($this->gap(), QuietHours::everySeconds() - (float) $clip->duration_seconds)
            : ($inRun || (! $this->musicFirst() && $this->spool->hasFresh($at)) ? $this->between('breath_seconds') : $this->musicGap($now)));
        $this->runPosition = $inRun ? $this->runPosition : 0;

        // A live tournament comment (TournamentLive; user 2026-10-14: "alle 1 Minute, mit einer Minute Abstand") is
        // followed by its own short music gap, not by a whole track.
        if (TournamentLive::enabled() && self::isTournamentLive($clip)) {
            $this->quietUntil = $now + max(0.0, (float) config('tv.commentary.tournament_live.gap_seconds', 60));
        }

        // The music gap opens: a power intro may ride its first seconds (P11), the music ducks under it.
        if (! $quiet && ! $inRun && ! $cut && $this->held === []) {
            $this->powerIntro($clip, $now);
        }
    }

    /**
     * The power intro over the start of the music gap (P11, Imaging::powerIntro()): the Anchor alone on the timeline,
     * no clip, `lead_in_seconds` from now; a clip that starts meanwhile follows it (play()).
     */
    private function powerIntro(CasterClip $ended, float $now): void
    {
        if ($this->imaging === null) {
            return;
        }

        $intro = $this->imaging->powerIntro($ended, CarbonImmutable::createFromTimestamp($now));
        $pcm = $intro === null ? null : $this->imaging->pcm($intro['file']);

        if ($pcm === null) {
            return;
        }

        $start = $now + max((float) ($this->settings['lead_in_seconds'] ?? 0.5), (float) ($this->settings['feed_lead_seconds'] ?? 0.0) + 0.05);
        $this->timeline->start($pcm, [round(Pcm::seconds($pcm, $this->timeline->rate), 3)], $start);
        $this->imagingOnAir = ['power-intro'];
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
        $this->quietUntil = $now >= $until ? $now : $now + $this->musicGap($now);
    }

    /** Puts a clip on the timeline and holds the slot until it has been spoken; false when it cannot be decoded (then dropped). */
    private function play(RotationPlanner $planner, CasterClip $clip, float $now, bool $continuation = false): bool
    {
        // Before a planned music break only a single clip that ends before its track starts (it waits otherwise).
        if (! $this->fitsBeforeMusicBreak($clip, $now, $continuation)) {
            return false;
        }

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
        // Radio imaging (P11): a jingle of the Anchor in front of the clip takes the stinger's place, a news close follows a
        // bulletin; decoded before the stinger, so a jingle that fails still leaves the stinger.
        $dressing = microtime(true);
        $choice = $this->imaging?->choose($clip, $continuation, CarbonImmutable::createFromTimestamp($now)) ?? ['before' => null, 'after' => null];

        if ($this->idents !== null && ! $continuation && ! $clip->isDressed()) {
            $dressed = $this->idents->dress($clip, $pcm, $ends, CarbonImmutable::createFromTimestamp($now), withStinger: $choice['before'] === null);
            ['pcm' => $pcm, 'ends' => $ends] = $dressed;
            $this->ident = $dressed['stinger'];
        }

        if ($this->imaging !== null && ($choice['before'] !== null || $choice['after'] !== null)) {
            $wrapped = $this->imaging->wrap($choice, $pcm, $ends);
            ['pcm' => $pcm, 'ends' => $ends] = $wrapped;
            $this->imagingOnAir = array_values(array_filter([$wrapped['before'] ? $choice['before']['category'] ?? null : null, $wrapped['after'] ? $choice['after']['category'] ?? null : null]));
        } else {
            $this->imagingOnAir = [];
        }

        $start += microtime(true) - $dressing;

        // A power intro still on air (P11, no clip): the clip follows it a breath later on the same timeline, the intro's
        // rest unchanged (what the feed has already written stays as it is), as joinNextBlock() does.
        $offset = 0.0;
        if ($this->clip === null && $this->timeline->isPlaying($now)) {
            $rate = $this->timeline->rate;
            $from = (int) round($now * $rate);
            $rest = $this->timeline->samples($from, (int) round((float) $this->timeline->endsAt() * $rate));
            $offset = Pcm::seconds($rest, $rate) + 0.3;
            $pcm = $rest.Pcm::silence(0.3, $rate).$pcm;
            $ends = [round($offset - 0.3, 3), ...array_map(fn (float $end): float => round($end + $offset, 3), $ends)];
            $start = $from / $rate;
            unset($rest);
        }

        // A bulletin puts the music into news mode for as long as its PCM (stinger and bed included) plays; a podcast too.
        $this->timeline->start($pcm, $ends, $start, news: $bulletinSlot !== null || $this->isPodcast($clip));

        if ($bulletinSlot !== null) {
            $this->podcastBreak = null;
        }
        $this->spool->playing($clip);
        $this->clip = $clip;
        $this->clipIsPart = $continuation || ($clip->kind !== CasterScript::BULLETIN && $this->spool->partOf($clip) !== null);
        $this->bulletinSlot = $bulletinSlot;
        $this->quietUntil = 0.0;
        $this->onAir($clip, $start, $ends, $offset);

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
     * Whether the clip on air keeps a waiting reaction off (user 2026-10-15: reactions go on air at once, except
     * while the news, the weather and sport talk or a rendered programme runs): the news of a bulletin, a rubric
     * (the news talk, Sport und Wetter, a live tournament's commentary), a news segment, a teaser, a bulletin and
     * every rendered block (a show, a talk, a podcast replay, stock). A scene, a segment of history or followed
     * profiles, a filler and another reaction may be cut at the end of their sentence on air.
     */
    private function protectedFromReactions(CasterClip $clip): bool
    {
        return $this->bulletinSlot !== null
            || $clip->isBlock()
            || in_array($clip->kind, [CasterScript::BULLETIN, CasterScript::NEWS, CasterScript::RUBRIC, CasterScript::TEASER, ...CasterScript::PROGRAMME], true);
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
        $kept = [];

        foreach ($this->held as $rest) {
            $within = $this->isPodcast($rest['clip']) ? (float) ($this->settings['podcast']['resume_within_seconds'] ?? 1800) : (float) ($this->settings['blocks']['resume_within_seconds'] ?? 600);

            if ($now - $rest['since'] > $within) {
                $this->spool->played($rest['clip']);
            } else {
                $kept[] = $rest;
            }
        }

        $this->held = $kept;

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
        $teaser = ($held['clip']->isBlock() && ! $this->isPodcast($held['clip'])) || ! $held['part'] ? $this->spool->teaser($at) : null;
        if ($teaser !== null && $this->play($planner, $teaser, $now)) {
            return true;
        }

        array_pop($this->held);
        $start = $now + max((float) ($this->settings['lead_in_seconds'] ?? 0.5), (float) ($this->settings['feed_lead_seconds'] ?? 0.0) + 0.05);
        $this->timeline->start($held['pcm'], $held['ends'], $start, news: $this->isPodcast($held['clip']));
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

        // A planned music break takes the boundary: the block on air ends, the next one waits for the track's end.
        if ($this->clip === null || $end === null || $this->timeline->isCut() || $this->held !== [] || $end - $now > 2.5 || $end <= $now || $this->musicBreak !== null) {
            return;
        }

        $at = CarbonImmutable::createFromTimestamp($now);
        // A podcast's next chunk follows whatever waits, only the news and an overdue reaction take the boundary (P12).
        $podcast = $this->isPodcast($this->clip);
        $part = $this->clipIsPart ? $this->spool->partOf($this->clip) : null;
        $continues = $part !== null && $this->spool->partPending($part['group'], $part['index'] + 1, $at);

        // A programme teaser never comes between two blocks of one story (the talk show's next ten minutes, P20): it
        // waits for the story's end.
        if ($podcast ? ($this->dueBulletin($now) !== null || $this->awaitingBulletin($now))
            : (! $this->blocksFlowOn($this->clip, $at, $continues) || $this->dueBulletin($now) !== null || (! $continues && $this->spool->teaser($at) !== null))) {
            return;
        }

        $next = $podcast ? $this->spool->nextPodcastChunk($this->clip)
            : ($continues ? $this->spool->part($part['group'], $part['index'] + 1, $at) : $this->spool->nextBlock($at));

        if ($next === null || ! $next->isBlock()) {
            return;
        }

        $pcm = $this->spool->pcm($next);

        if ($pcm === null) {
            $this->spool->played($next);

            return;
        }

        $nextEnds = $next->sentence_ends;

        // The last chunk of a podcast closes with its back-announce (Imaging), the chunks in between carry nothing.
        if ($podcast) {
            $choice = $this->imaging?->choose($next, false, $at) ?? ['before' => null, 'after' => null];

            if ($this->imaging !== null && $choice['after'] !== null) {
                ['pcm' => $pcm, 'ends' => $nextEnds] = $this->imaging->wrap(['before' => null, 'after' => $choice['after']], $pcm, $nextEnds);
            }
        }

        $rate = $this->timeline->rate;
        $from = (int) round($now * $rate);
        $tail = $this->timeline->samples($from, (int) round($end * $rate));
        $tailSeconds = Pcm::seconds($tail, $rate);
        $range = (array) ($this->settings['blocks']['join_seconds'] ?? []);
        // The chunks of a podcast are one recording cut at its pauses: no breath between them.
        $breath = $podcast ? 0.0 : self::random($range === [] ? ['min' => 0.4, 'max' => 0.4] : $range);
        $joined = $tail.Pcm::silence($breath, $rate).$pcm;
        $offset = Pcm::seconds($joined, $rate) - Pcm::seconds($pcm, $rate);
        // A six-minute block is about 17 MB of PCM: never more copies of it alive than needed.
        unset($pcm, $tail);
        $ends = [round($tailSeconds, 3), ...array_map(fn (float $time): float => round($offset + $time, 3), $nextEnds)];

        $this->logPiece($this->clip, $end);
        $this->spool->played($this->clip);
        $this->part = null;

        $start = $from / $rate;
        $this->timeline->start($joined, $ends, $start, news: $podcast);
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
     * A podcast on air (P12) stops for the next bulletin once that bulletin is
     * in the spool and its slot is at most `podcast.pause_window_seconds`
     * away: at the first pause after the slot within the window, else at the
     * last pause before it within the window (then awaitingBulletin() keeps
     * everything else off air until the bulletin). Without a pause in the
     * window it plays on, and from the slot on the bulletin cuts it at its
     * next pause like any block. The rest resumes after the news (held).
     */
    private function podcastBreak(float $now): void
    {
        if (! $this->isPodcast($this->clip) || $this->timeline->isCut() || $this->podcastBreak !== null) {
            return;
        }

        $window = max(0.0, (float) ($this->settings['podcast']['pause_window_seconds'] ?? 20));
        $earliest = $this->earliestCut($now, resumable: true);
        $slot = $this->clock->nextBulletin(CarbonImmutable::createFromTimestamp((int) ceil($now)));

        if ($slot === null || $earliest < $slot['key'] - $window || $this->spool->bulletin($slot['starts_at']) === null) {
            return;
        }

        $key = (float) $slot['key'];
        $end = (float) $this->timeline->endsAt();
        $pauses = array_values(array_filter($this->airEnds, fn (float $time): bool => $time >= $earliest && $time >= $key - $window && $time <= $key + $window && $time <= $end));
        $after = array_values(array_filter($pauses, fn (float $time): bool => $time >= $key));
        $at = $after[0] ?? ($pauses === [] ? null : end($pauses));

        if ($at === null) {
            return;
        }

        $this->cut($at, $now, resume: true);
        $this->podcastBreak = $slot['key'];
    }

    /**
     * The first chunk right after the podcast's announcement, the back-announce right after its last chunk; false
     * when there is none to play (the director goes on as usual).
     */
    private function podcastFrameNext(RotationPlanner $planner, float $now): bool
    {
        $at = CarbonImmutable::createFromTimestamp($now);
        $next = $this->podcastFollows ? $this->spool->podcast($at) : ($this->podcastEnded === null ? null : $this->spool->podcastFrame($this->podcastEnded, CasterScript::PODCAST_OUTRO, $at));
        $this->podcastFollows = false;
        $this->podcastEnded = null;

        return $next !== null && $this->play($planner, $next, $now);
    }

    /** Whether a podcast stopped for a bulletin whose slot has not come yet, or that is still due (P12). */
    private function awaitingBulletin(float $now): bool
    {
        if ($this->podcastBreak === null) {
            return false;
        }

        if ($now - $this->podcastBreak > (float) ($this->settings['bulletin_late_seconds'] ?? 120)) {
            // The bulletin never came (expired, undecodable): the podcast goes on.
            $this->podcastBreak = null;

            return false;
        }

        return true;
    }

    private function isPodcast(?CasterClip $clip): bool
    {
        return $clip?->kind === CasterScript::PODCAST;
    }

    /**
     * Whether the next block may follow the block `$clip` without a pause:
     * always by day; in the quiet hours (one clip every
     * `quiet_hours.every_minutes`) only a replayed block when
     * `blocks.replay.continuous` asks for a best of in one piece.
     */
    private function blocksFlowOn(CasterClip $clip, CarbonImmutable $at, bool $continues): bool
    {
        if (QuietHours::active($at)) {
            return $clip->isReplay() && ($this->settings['blocks']['replay']['continuous'] ?? false) === true;
        }

        // Music first: only the next block of the same story (a show told in blocks) or a show window runs on without a track.
        return ! $this->musicFirst() || $continues || $this->clock->inShowWindow($at);
    }

    /** One stretch of voice that was on air, for the air log (caster:gaps). */
    private function logPiece(CasterClip $clip, float $end): void
    {
        AirLog::record(['clip' => $clip->id, 'kind' => $clip->kind, 'block' => $clip->isBlock(), 'start' => round($this->airStart, 3), 'end' => round($end, 3),
            'source' => $clip->isReplay() ? AirLog::REPLAY : ($clip->isStock() ? AirLog::STOCK : AirLog::FRESH)]);
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

    /** Seconds between two music breaks (`3600 / per_hour`), null when music breaks are off. */
    private function musicBreakInterval(): ?float
    {
        $settings = (array) ($this->settings['music_breaks'] ?? []);
        $perHour = (float) ($settings['per_hour'] ?? 0);

        return $this->musicTracks === null || ($settings['enabled'] ?? false) !== true || $perHour <= 0 ? null : 3600 / $perHour;
    }

    /**
     * Plans the due music break around the next clip boundary (the end of the clip on air, else now): the first vocal
     * track that starts within `wait_seconds` of it and may run whole (musicBreakFits()). Cancels a planned break whose
     * track started while a clip was still on air.
     */
    private function planMusicBreak(float $now): void
    {
        $margin = self::MUSIC_BREAK_MARGIN;

        if ($this->musicBreak !== null) {
            if (! $this->musicBreak['started'] && $now > $this->musicBreak['start'] + $margin) {
                // The track began under a clip that ran on: the break would not be complete any more.
                $this->musicBreak = null;
            }

            return;
        }

        $interval = $this->musicBreakInterval();

        if ($interval === null) {
            return;
        }

        $this->nextMusicBreakAt ??= $now + $interval / 2;

        if ($now < $this->nextMusicBreakAt || $now - $this->musicBreakAskedAt < 5.0) {
            return;
        }

        $this->musicBreakAskedAt = $now;

        // Never inside a story told in parts, before a held rest, around a podcast or a bulletin on air.
        if ($this->part !== null || $this->held !== [] || $this->podcastFollows || $this->podcastEnded !== null || $this->podcastBreak !== null
            || $this->bulletinSlot !== null || ($this->clip !== null && ($this->clipIsPart || $this->isPodcast($this->clip) || $this->timeline->isCut()))) {
            return;
        }

        $lead = max((float) ($this->settings['lead_in_seconds'] ?? 0.5), (float) ($this->settings['feed_lead_seconds'] ?? 0.0) + 0.05);
        $from = max($now, $this->clip === null ? $now : (float) ($this->timeline->endsAt() ?? $now)) + $lead;
        $wait = max(0.0, (float) ($this->settings['music_breaks']['wait_seconds'] ?? 300));

        // The first PlebRap track that fits within the wait, else the first other vocal track that does.
        $fitting = array_values(array_filter(($this->musicTracks)($from, $from + $wait + 1200), fn (array $track): bool => ($track['vocal'] ?? false) === true
            && $track['start'] >= $from && $track['start'] <= $from + $wait && $this->musicBreakFits($track['start'], $track['end'])));
        $track = collect($fitting)->first(fn (array $track): bool => ($track['priority'] ?? false) === true) ?? ($fitting[0] ?? null);

        if ($track !== null) {
            $this->musicBreak = ['start' => $track['start'], 'end' => $track['end'], 'file' => $track['file'], 'started' => false];
        }
    }

    /**
     * Whether a music break may run from `$start` to `$end`: no quiet hour, no bulletin or rubric slot (a show window
     * reads as its rubric slot) on air at either edge or starting in between, and no podcast airing due by its end.
     */
    private function musicBreakFits(float $start, float $end): bool
    {
        $margin = self::MUSIC_BREAK_MARGIN;
        $from = CarbonImmutable::createFromTimestamp((int) floor($start - $margin));
        $to = CarbonImmutable::createFromTimestamp((int) ceil($end + $margin));

        if (QuietHours::active($from) || QuietHours::active($to)) {
            return false;
        }

        if ($this->clock->enabled()) {
            $fixed = fn (string $type): bool => $type === BroadcastClock::RUBRIC || BroadcastClock::isBulletin($type);

            if ($fixed($this->clock->slotAt($from)['type']) || $fixed($this->clock->slotAt($to)['type'])) {
                return false;
            }

            foreach ($this->clock->upcoming($from, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 60) + 1) as $slot) {
                if ($slot['key'] <= $to->getTimestamp() && $fixed($slot['type'])) {
                    return false;
                }
            }
        }

        return $this->spool->podcast($to) === null;
    }

    /**
     * Whether the music break's track is playing (the voice stands aside): from just before its start to its end. At
     * its end the break is over, the next one due `3600 / per_hour` later, and the voice resumes at once.
     */
    private function inMusicBreak(float $now): bool
    {
        if ($this->musicBreak === null || $now < $this->musicBreak['start'] - self::MUSIC_BREAK_MARGIN) {
            return false;
        }

        if ($now >= $this->musicBreak['end']) {
            $this->nextMusicBreakAt = $this->musicBreak['end'] + (float) ($this->musicBreakInterval() ?? 1800.0);
            $this->musicBreak = null;
            $this->quietUntil = $now;

            return false;
        }

        $this->musicBreak['started'] = true;

        return true;
    }

    /**
     * Whether `$clip` may start now with a music break planned: always without one, for a bulletin or the next part of
     * a story; else only a single clip (no first part of a story) whose end, stingers and jingles included, comes
     * before the track starts.
     */
    private function fitsBeforeMusicBreak(CasterClip $clip, float $now, bool $continuation): bool
    {
        if ($this->musicBreak === null || $continuation || $clip->kind === CasterScript::BULLETIN) {
            return true;
        }

        $lead = max((float) ($this->settings['lead_in_seconds'] ?? 0.5), (float) ($this->settings['feed_lead_seconds'] ?? 0.0) + 0.05);

        return $this->spool->partOf($clip) === null
            && $now + $lead + (float) $clip->duration_seconds + self::MUSIC_BREAK_DRESSING_SECONDS <= $this->musicBreak['start'] - self::MUSIC_BREAK_MARGIN;
    }

    /**
     * The music break planned or on air (tests, logging): its track's start and end on the wall clock and its file.
     *
     * @return array{start: float, end: float, file: string, started: bool}|null
     */
    public function musicBreak(): ?array
    {
        return $this->musicBreak;
    }

    /**
     * Music first (`music_first`, user 2026-10-13: "Was ist mit den Pausen, damit Musikstücke durchlaufen können!"): on by
     * setting and with the music timeline known.
     */
    private function musicFirst(): bool
    {
        return ($this->settings['music_first']['enabled'] ?? false) === true && $this->musicTracks !== null;
    }

    /** Whether `$clip` carries a live tournament comment (TournamentLive::TOPIC), on its own or in a block. */
    private static function isTournamentLive(CasterClip $clip): bool
    {
        $ids = $clip->isBlock() ? array_values(array_filter((array) ($clip->block['scripts'] ?? []), 'is_int')) : [$clip->caster_script_id];

        return $ids !== [] && CasterScript::query()->whereIn('id', $ids)->where('topic', TournamentLive::TOPIC)->exists();
    }

    /**
     * Whether a live reaction or live moment may come now, in the middle of a track or a music break, instead of waiting
     * for the track's end (user 2026-10-14: "Für Live Reactions gilt die Ausnahme, dass die immer kommen dürfen, außer
     * bei Programm, wie News, Wetter, Sport oder anderen vorgerenderten Shows"). A programme on air is never cut for it
     * (it plays only when no clip is on air); between the parts of a story and during a podcast replay it waits too.
     */
    private function liveAnytime(float $now): bool
    {
        return ($this->settings['live_anytime'] ?? true) === true && $this->part === null
            && $this->spool->podcast(CarbonImmutable::createFromTimestamp($now)) === null;
    }

    /** Whether the music gap after the last clip is over (always, without music first). */
    private function gapOver(float $now): bool
    {
        return ! $this->musicFirst() || $now >= $this->quietUntil;
    }

    /**
     * The music gap after a clip: with music first, up to the end of the first track of the stream music that starts
     * after the voice stopped (one complete track between two voice items, the voice resumes at its end), at most
     * `music_first.max_wait_seconds`; without a timeline, or no whole track within that, the random `gap_seconds`.
     */
    private function musicGap(float $now): float
    {
        if (! $this->musicFirst() || $this->musicTracks === null) {
            return $this->gap();
        }

        $max = max(60.0, (float) ($this->settings['music_first']['max_wait_seconds'] ?? 540));
        $tracks = ($this->musicTracks)($now, $now + $max);
        usort($tracks, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        foreach ($tracks as $track) {
            if ($track['start'] >= $now - 1.0 && $track['end'] > $track['start'] && $track['end'] <= $now + $max) {
                return $track['end'] - $now;
            }
        }

        return $this->gap();
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

        // Music first: never several clips back to back (user 2026-10-13: "nicht ständig welche hintereinander").
        if (($runs['enabled'] ?? false) !== true || $this->musicFirst()) {
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

    /**
     * The imaging categories around the clip on air (opener first), empty when none (tests, logging).
     *
     * @return list<string>
     */
    public function imagingOnAir(): array
    {
        return $this->imagingOnAir;
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
