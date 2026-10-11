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
 * reaction) cuts the gap short, also in the middle of such a quiet slot.
 */
final class SceneDirector
{
    private ?int $slotNumber = null;

    private ?CasterClip $clip = null;

    private ?string $caption = null;

    /** Until when only the music plays (the gap after a clip). */
    private float $quietUntil = 0.0;

    /**
     * @param  array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int}  $settings
     */
    public function __construct(
        private readonly ClipSpool $spool,
        public readonly CommentaryTimeline $timeline,
        private readonly array $settings = [],
    ) {}

    public static function fromConfig(ClipSpool $spool): self
    {
        /** @var array{min_scene_seconds?: float|int, silent_min_scene_seconds?: float|int, tail_seconds?: float|int, lead_in_seconds?: float|int, max_hold_seconds?: float|int, gap_seconds?: array{min?: float|int, max?: float|int}, feed_lead_seconds?: float|int} $settings */
        $settings = [...(array) config('tv.commentary.scenes', []), 'gap_seconds' => (array) config('tv.commentary.spool.gap_seconds', []), 'feed_lead_seconds' => (float) config('tv.commentary.mix.lead_seconds', 1.0)];

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
            $this->quietUntil = $now + $this->gap();
        }

        // A live moment cuts the gap short, also in the middle of a quiet slot.
        if ($this->clip === null && $now < $this->quietUntil && $planner->slotNumber() === $this->slotNumber) {
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

        $spoken = (float) $this->timeline->endsAt() + (float) ($this->settings['tail_seconds'] ?? 1.5);
        $planner->hold(min(
            max($spoken, $now + (float) ($this->settings['min_scene_seconds'] ?? 20)),
            $now + (float) ($this->settings['max_hold_seconds'] ?? 90),
        ));

        return true;
    }

    /** A random gap within `gap_seconds` (none when unset). */
    private function gap(): float
    {
        $gap = (array) ($this->settings['gap_seconds'] ?? []);
        $min = max(0.0, (float) ($gap['min'] ?? 0));
        $max = max($min, (float) ($gap['max'] ?? $min));

        return $min + ($max - $min) * mt_rand(0, 1000) / 1000;
    }

    /** The clip on air (tests, logging). */
    public function clip(): ?CasterClip
    {
        return $this->clip;
    }
}
