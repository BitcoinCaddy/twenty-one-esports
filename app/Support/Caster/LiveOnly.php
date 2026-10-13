<?php

namespace App\Support\Caster;

use App\Support\Caster\Blocks\AutoPilot;
use Carbon\CarbonImmutable;

/**
 * Live only (`music_first.live_only`, user 2026-10-13: "Maximal noch ein Comment nach den News und danach kein Gelabere
 * mehr, nur noch bei Live-Reactions, wenn etwas Neues im Chat Nostr oder YT reinkommt … Ansonsten läuft halt
 * Programm-Show"): while the workstation is online, by day, the queue writes no generic voice item any more (no slide
 * scene, history, followed note, news segment, callback, teaser, programme preview, news talk or rotation rubric) and
 * the stream airs no stock or replay block. What stays: the bulletin and the one comment after it (Sport und Wetter),
 * the shows in their windows, the podcast replay, and the live reactions to new chat messages on Nostr and YouTube.
 * Offline or with VoiceStudio down (the autopilot) and in the quiet hours their own rhythm stay as they were.
 */
final class LiveOnly
{
    public static function active(CarbonImmutable $now): bool
    {
        return config('tv.commentary.music_first.enabled') === true && config('tv.commentary.music_first.live_only') === true
            && ! QuietHours::active($now) && app(ClipSpool::class)->fetcherOnline($now) && ! AutoPilot::voiceDown($now);
    }
}
