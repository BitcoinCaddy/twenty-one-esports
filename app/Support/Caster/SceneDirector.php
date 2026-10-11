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
 */
final class SceneDirector
{
    private ?int $slotNumber = null;

    private ?CasterClip $clip = null;

    private ?string $caption = null;

    /**
     * @param  array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int}  $settings
     */
    public function __construct(
        private readonly ClipSpool $spool,
        public readonly CommentaryTimeline $timeline,
        private readonly array $settings = [],
    ) {}

    public static function fromConfig(ClipSpool $spool): self
    {
        /** @var array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int} $settings */
        $settings = (array) config('tv.commentary.scenes', []);

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
        }

        // A takeover waits for the end of the sentence on air, never longer.
        if ($this->clip !== null && ! $this->timeline->isCut() && $planner->urgentPending()) {
            $end = $this->timeline->nextSentenceEnd($now) ?? $now;
            $this->timeline->cutAt($end);
            $planner->hold($end + 0.3);
        }

        if ($planner->slotNumber() === $this->slotNumber) {
            return $this->caption;
        }

        $this->slotNumber = $planner->slotNumber();
        $this->caption = null;

        // Still speaking (a hold that ran out at max_hold_seconds): the new slot gets no clip of its own.
        if ($this->clip !== null) {
            return null;
        }

        $decision = $this->spool->next(ScenePhase::forSlot($slot, CarbonImmutable::createFromTimestamp($now)), CarbonImmutable::createFromTimestamp($now), SceneTopic::forSlot($slot));
        $pcm = $decision['clip'] === null ? null : $this->spool->pcm($decision['clip']);

        if ($decision['clip'] !== null && $pcm === null) {
            // A clip that cannot be decoded is dropped, never retried on every slot.
            $this->spool->played($decision['clip']);
        }

        if ($decision['clip'] === null || $pcm === null) {
            $planner->hold($now + (float) ($this->settings['silent_min_scene_seconds'] ?? 15));
            $this->caption = $decision['caption'];

            return $this->caption;
        }

        $start = $now + (float) ($this->settings['lead_in_seconds'] ?? 0.5);
        $this->timeline->start($pcm, $decision['clip']->sentence_ends, $start);
        $this->spool->playing($decision['clip']);
        $this->clip = $decision['clip'];

        $spoken = (float) $this->timeline->endsAt() + (float) ($this->settings['tail_seconds'] ?? 1.5);
        $planner->hold(min(
            max($spoken, $now + (float) ($this->settings['min_scene_seconds'] ?? 20)),
            $now + (float) ($this->settings['max_hold_seconds'] ?? 90),
        ));

        return null;
    }

    /** The clip on air (tests, logging). */
    public function clip(): ?CasterClip
    {
        return $this->clip;
    }
}
