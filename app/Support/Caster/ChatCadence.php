<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Programme\BroadcastClock;
use Carbon\CarbonImmutable;

/**
 * At least one chat item on air every `chat.cadence.every_minutes` (user
 * 2026-10-13: "Die Reactions auf die Chats, auch die von YouTube … müssen
 * alle 10 Minuten kommen, außer es läuft gerade eine fixe Show/Programm").
 *
 * Measured on prod the same day: every chat item that was voiced also aired;
 * what was missing were items at all. Overnight the YouTube chat had seven
 * lines in ten hours, below the summary's `min_messages` per window, and
 * nobody wrote on Nostr, so for hours no chat item was written.
 *
 * `caster:chat` asks every minute: once no chat item (CasterScript::CHAT)
 * aired for `every_minutes` less `lead_minutes` (the time a script needs to
 * be voiced and reach the air, AirLog) and none is queued, voicing, ready or
 * on air, it queues one: a YouTube summary of whatever came in there (one
 * new line is enough, YouTubeChatSummary::summarise() forced), else a short
 * call-out to the quiet chat (ReactionLines::callOut(), no model). On air a
 * chat item takes the next clip or block boundary like every reaction
 * (SceneDirector), ahead of stock and autopilot blocks.
 *
 * Nothing is queued while the news (a bulletin slot), a show window or a
 * podcast replay is on air, now or at the expected airing; the first item after one of them
 * is queued as soon as it is over, so it never cuts into the show. Nor in
 * the autopilot: nobody could voice it. A broken air log reads as "no chat
 * aired": the call-out then comes at most once per `every_minutes` less
 * `lead_minutes`, never more often.
 */
final class ChatCadence
{
    /** The topic of the call-out to a quiet chat. */
    public const CALLOUT_TOPIC = 'chat_callout';

    public function __construct(
        private ScriptQueue $queue,
        private ReactionLines $lines,
        private YouTubeChatSummary $youtube,
    ) {}

    public static function enabled(): bool
    {
        return ChatReactions::enabled() && config('tv.commentary.chat.cadence.enabled') === true;
    }

    /** The seconds from one chat item on air to the next at most. */
    public static function everySeconds(): int
    {
        return 60 * max(1, (int) config('tv.commentary.chat.cadence.every_minutes', 10));
    }

    /** The seconds before that a chat item is queued, so it is voiced and on air in time. */
    public static function leadSeconds(): int
    {
        return min(self::everySeconds() - 60, 60 * max(0, (int) config('tv.commentary.chat.cadence.lead_minutes', 3)));
    }

    /**
     * One look at the chat cadence.
     *
     * @return string|null what was queued: `youtube`, `callout`, or null (nothing due, or nothing allowed now)
     */
    public function tick(CarbonImmutable $now): ?string
    {
        if (! self::enabled() || AutoPilot::active($now)) {
            return null;
        }

        $due = self::everySeconds() - self::leadSeconds();
        $aired = self::lastAired();

        if (($aired !== null && $now->getTimestamp() - $aired < $due) || self::onItsWay($now)
            || self::fixedProgramme($now) || self::fixedProgramme($now->addSeconds(self::leadSeconds()))) {
            return null;
        }

        if ($this->youtube->summarise($now, force: true) === 1) {
            return 'youtube';
        }

        $recent = CasterScript::query()->where('kind', CasterScript::CHAT)->where('topic', self::CALLOUT_TOPIC)
            ->where('created_at', '>', $now->subSeconds($due))->exists();

        if ($recent) {
            return null;
        }

        $script = $this->queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, $this->lines->callOut(), 'callout',
            (int) config('tv.commentary.scripts.chat_priority', 50), $now, source: CasterScript::SOURCE_PATTERN, topic: self::CALLOUT_TOPIC);

        return $script === null ? null : 'callout';
    }

    /** When the last chat item left the air (unix seconds, AirLog), or null when none is in the log. */
    public static function lastAired(): ?int
    {
        $ends = array_map(fn (array $piece): float => $piece['end'], array_filter(AirLog::entries(), fn (array $piece): bool => $piece['kind'] === CasterScript::CHAT));

        return $ends === [] ? null : (int) floor(max($ends));
    }

    /** Whether a chat item is queued, being voiced, ready or on air. */
    public static function onItsWay(CarbonImmutable $now): bool
    {
        $utc = $now->utc();

        return CasterScript::query()->where('kind', CasterScript::CHAT)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])->where('expires_at', '>', $utc)->exists()
            || CasterClip::query()->where('kind', CasterScript::CHAT)->whereIn('status', [CasterClip::READY, CasterClip::PLAYING])->where('expires_at', '>', $utc)->exists();
    }

    /**
     * Whether `$at` falls on the news (a bulletin slot) or a show window, or a podcast replay is on air. A rubric's slot
     * is no exclusion: its five minutes are the hour's programme, not a show (else four fixed slots an hour would stretch
     * the ten minutes to seventeen).
     */
    public static function fixedProgramme(CarbonImmutable $at): bool
    {
        $clock = BroadcastClock::fromConfig();

        return ($clock->enabled() && (BroadcastClock::isBulletin($clock->slotAt($at)['type']) || $clock->inShowWindow($at)))
            || CasterClip::query()->where('kind', CasterScript::PODCAST)->where('status', CasterClip::PLAYING)->exists();
    }
}
