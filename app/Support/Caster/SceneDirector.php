<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
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
 */
final class SceneDirector
{
    private ?int $slotNumber = null;

    private ?CasterClip $clip = null;

    private ?string $caption = null;

    /** Until when only the music plays (the gap after a clip). */
    private float $quietUntil = 0.0;

    /** The current run: its number, the position of the clip on air (0: no run), the run clips still to come. */
    private int $runId = 0;

    private int $runPosition = 0;

    private int $runLeft = 0;

    /** When the next run may start (null: not yet drawn). */
    private ?float $nextRunAt = null;

    /**
     * @param  array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, long_max_hold_seconds?: array<string, float|int>, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int, runs?: array{enabled?: bool, every_minutes?: array{min?: float|int, max?: float|int}, clips?: array{min?: int, max?: int}, breath_seconds?: array{min?: float|int, max?: float|int}}}  $settings
     */
    public function __construct(
        private readonly ClipSpool $spool,
        public readonly CommentaryTimeline $timeline,
        private readonly array $settings = [],
    ) {}

    public static function fromConfig(ClipSpool $spool): self
    {
        /** @var array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, long_max_hold_seconds?: array<string, float|int>, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int, runs?: array{enabled?: bool, every_minutes?: array{min?: float|int, max?: float|int}, clips?: array{min?: int, max?: int}, breath_seconds?: array{min?: float|int, max?: float|int}}} $settings */
        $settings = [...(array) config('tv.commentary.scenes', []), 'gap_seconds' => (array) config('tv.commentary.spool.gap_seconds', []), 'feed_lead_seconds' => (float) config('tv.commentary.mix.lead_seconds', 1.0), 'runs' => (array) config('tv.commentary.runs', [])];

        return new self($spool, new CommentaryTimeline((int) config('tv.commentary.mix.sample_rate', 24000)), $settings);
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
            $this->spool->played($this->clip);
            $this->clip = null;
            $this->timeline->clear();

            // Inside a run only a breath; after its last clip the music gap returns.
            $inRun = $this->runPosition > 0 && $this->runLeft > 0;
            $this->quietUntil = $now + ($inRun ? $this->between('breath_seconds') : $this->gap());
            $this->runPosition = $inRun ? $this->runPosition : 0;
        }

        // A live moment or reaction plays at the next clip boundary: it cuts the gap short, also in the middle of
        // a quiet slot or a long silent one, and never waits for the next slot (user 2026-10-11: "as fast as possible").
        if ($this->clip === null && $planner->slotNumber() === $this->slotNumber) {
            $live = $this->spool->nextLive(CarbonImmutable::createFromTimestamp($now));

            if ($live !== null && $this->play($planner, $live, $now)) {
                return null;
            }
        }

        // A takeover waits for the end of the sentence on air, never longer.
        if ($this->clip !== null && ! $this->timeline->isCut() && $planner->urgentPending()) {
            $end = $this->timeline->nextSentenceEnd($now) ?? $now;
            $this->timeline->cutAt($end);
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

    /** Puts a clip on the timeline and holds the slot until it has been spoken; false when it cannot be decoded (then dropped). */
    private function play(RotationPlanner $planner, CasterClip $clip, float $now): bool
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
        $this->timeline->start($pcm, $clip->sentence_ends, $start);
        $this->spool->playing($clip);
        $this->clip = $clip;
        $this->quietUntil = 0.0;

        if (! in_array($clip->kind, ClipSpool::LIVE_KINDS, true)) {
            $this->countRunClip($now);
        }

        $spoken = (float) $this->timeline->endsAt() + (float) ($this->settings['tail_seconds'] ?? 1.5);
        $planner->hold(min(
            max($spoken, $now + (float) ($this->settings['min_scene_seconds'] ?? 20)),
            $now + (float) ($this->settings['long_max_hold_seconds'][$clip->kind] ?? $this->settings['max_hold_seconds'] ?? 90),
        ));

        return true;
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

    /** The clip on air (tests, logging). */
    public function clip(): ?CasterClip
    {
        return $this->clip;
    }
}
