<?php

namespace App\Http\Controllers;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\AirLog;
use App\Support\Caster\Blocks\DayPlanner;
use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\InvalidClip;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\Pronunciation;
use App\Support\Caster\QuietHours;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpoolFull;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The workstation fetcher's side of TWENTY ONE TV (plan "KI-Stimme der Liga",
 * P6): `POST api/caster/claim` hands out the next script (or none, with how
 * full the spool is; a full spool still hands out live moments and chat
 * reactions, only the byte cap stops those), `POST api/caster/scripts/{id}/clip` takes its Opus
 * clip as the raw body, `POST api/caster/scripts/{id}/release` gives a
 * script back when VoiceStudio failed. `POST api/caster/podcast-transcript/status`
 * names the podcast episode the server has (the workstation skips it),
 * `POST api/caster/podcast-transcript` takes the transcript of a newer one as JSON (at most
 * `tv.commentary.podcast.max_bytes`, 413 above; 409 for an episode older than
 * the stored one; PodcastTranscripts keeps the latest only).
 *
 * Bearer token `tv.commentary.fetch.token` (fail closed: no token set, no
 * access). A refused clip says why: 507 when the spool is at a cap (the
 * fetcher waits), 422 when the clip is not one.
 */
final class CasterFetchController
{
    public function claim(Request $request, ScriptQueue $queue, ClipSpool $spool): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $now = CarbonImmutable::now();
        Cache::put(ClipSpool::FETCHER_SEEN, $now->getTimestamp(), 3600);
        DayStock::rememberReport($request->header(DayStock::HEADER), $now);

        $ready = $spool->readySeconds();
        $target = (float) config('tv.commentary.spool.target_seconds', 180);
        $atByteCap = $spool->bytes() >= (int) config('tv.commentary.spool.max_bytes', 0);
        // Day stock and replays are the reserve (DayPlanner): they never make the spool full for fresh scripts.
        $full = $ready - $spool->reserveSeconds() >= $target || $atByteCap;

        // `?only=urgent` (P6): the workstation asks in the middle of rendering a block for what cannot wait for it.
        $urgent = $request->query('only') === 'urgent';
        // `?only=stock`: an evergreen talk for the day stock, in the stock window, or by day (`stock.day_share`) while
        // the workstation has nothing fresh to voice.
        $stock = $request->query('only') === 'stock';

        // A full spool still takes live moments and reactions (they play next) and what the clock places (a bulletin,
        // a rubric, a teaser: each airs at its slot, whatever else waits); only the byte cap stops them too.
        $script = match (true) {
            config('tv.commentary.enabled') !== true => null,
            $stock => DayStock::enabled() && (DayStock::windowActive($now) || (! QuietHours::active($now) && config('tv.commentary.blocks.stock.day_share') === true))
                ? $queue->claimStock($now) : null,
            $atByteCap => null,
            $full || $urgent => $queue->claim($now, [...ClipSpool::LIVE_KINDS, ...CasterScript::PROGRAMME]),
            default => $queue->claim($now),
        };

        // The kinds filter also lets later parts of a story through; one of those waits for the block in the making.
        if ($urgent && $script !== null && $script->isContinuation()) {
            $queue->release($script);
            $script = null;
        }

        return response()->json([
            'script' => $script === null ? null : [
                'id' => $script->id,
                'kind' => $script->kind,
                'sentences' => $script->sentences(),
                // A duet (DuetDirector): the speaker of each sentence; null when Markus speaks alone.
                'speakers' => $script->lineSpeakers(),
                // What the workstation's block renderer composes by (P6): the scene and topic (a rubric's), the
                // airtime, the slot it is anchored to, and where it stands in a story told in parts.
                'scene' => $script->scene,
                'topic' => $script->topic,
                'estimated_seconds' => $script->estimated_seconds,
                'airs_at' => $script->airs_at?->toIso8601String(),
                'part' => $script->part_group === null ? null : [
                    'group' => $script->part_group,
                    'index' => (int) $script->part_index,
                    'continues' => CasterScript::query()->where('part_group', $script->part_group)->where('part_index', '>', (int) $script->part_index)->exists(),
                ],
            ],
            'spool_seconds' => round($ready, 1),
            'target_seconds' => $target,
            'full' => $full,
            'pronunciation' => Pronunciation::terms(),
        ]);
    }

    public function clip(Request $request, string $id, ClipSpool $spool): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // Looked up here: the route runs without the web group, so without route model binding.
        $script = CasterScript::query()->find((int) $id);

        if ($script === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if ($script->status !== CasterScript::CLAIMED) {
            return response()->json(['error' => 'not_claimed'], 409);
        }

        $duration = (float) $request->header('X-Caster-Duration', '0');
        $ends = array_values(array_filter(array_map('trim', explode(',', (string) $request->header('X-Caster-Sentence-Ends', ''))), 'is_numeric'));

        try {
            $clip = $spool->store($script, (string) $request->getContent(), $duration, array_map('floatval', $ends), CarbonImmutable::now());
        } catch (SpoolFull) {
            return response()->json(['error' => 'spool_full'], 507);
        } catch (InvalidClip $exception) {
            return response()->json(['error' => 'invalid_clip', 'reason' => $exception->getMessage()], 422);
        }

        return response()->json(['clip' => $clip->id, 'spool_seconds' => round($spool->readySeconds(), 1)], 201);
    }

    /**
     * A programme block (P6, `POST api/caster/blocks`): the raw Opus body,
     * the covered script ids in order (`X-Caster-Scripts`, all claimed,
     * the first is the lead), the length, the sentence ends, the segment
     * boundaries (`X-Caster-Boundaries`) and the stretch at the start nothing
     * may cut into (`X-Caster-Protected`). A live moment or chat reaction is
     * never part of a block (they are voiced alone, to play next), and a
     * script the clock anchors (`airs_at`) only leads one.
     */
    public function block(Request $request, ClipSpool $spool): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $ids = array_map('intval', array_values(array_filter(array_map('trim', explode(',', (string) $request->header('X-Caster-Scripts', ''))), 'ctype_digit')));

        if ($ids === [] || count($ids) !== count(array_unique($ids)) || count($ids) > (int) config('tv.commentary.blocks.max_scripts', 40)) {
            return response()->json(['error' => 'invalid_scripts'], 422);
        }

        $found = CasterScript::query()->whereKey($ids)->get()->keyBy('id');
        $scripts = array_values(array_filter(array_map(fn (int $id): ?CasterScript => $found->get($id), $ids)));

        if (count($scripts) !== count($ids)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        foreach ($scripts as $index => $script) {
            if ($script->status !== CasterScript::CLAIMED) {
                return response()->json(['error' => 'not_claimed', 'script' => $script->id], 409);
            }

            // A slot-placed script joins only a block that is placed itself (the news talk after its bulletin).
            if (in_array($script->kind, ClipSpool::LIVE_KINDS, true) || ($index > 0 && $script->airs_at !== null && $scripts[0]->airs_at === null)) {
                return response()->json(['error' => 'invalid_scripts', 'script' => $script->id], 422);
            }
        }

        $times = fn (string $header): array => array_map('floatval', array_values(array_filter(array_map('trim', explode(',', (string) $request->header($header, ''))), 'is_numeric')));

        try {
            $clip = $spool->storeBlock($scripts, (string) $request->getContent(), (float) $request->header('X-Caster-Duration', '0'),
                $times('X-Caster-Sentence-Ends'), $times('X-Caster-Boundaries'), (float) $request->header('X-Caster-Protected', '0'), CarbonImmutable::now());
        } catch (SpoolFull) {
            return response()->json(['error' => 'spool_full'], 507);
        } catch (InvalidClip $exception) {
            return response()->json(['error' => 'invalid_clip', 'reason' => $exception->getMessage()], 422);
        }

        return response()->json(['clip' => $clip->id, 'kind' => $clip->kind, 'spool_seconds' => round($spool->readySeconds(), 1)], 201);
    }

    /**
     * A replayed block from the workstation's archive (P6 replay, `POST
     * api/caster/replays`): the raw Opus body with its length, sentence ends
     * and boundaries, already behind the Anchor's replay jingle. Taken only
     * while `blocks.replay.enabled` (409 otherwise, fail closed): in the
     * quiet hours it expires when they end, so a best of never runs into the
     * day programme; by day only as the reserve of the hourly airtime budget
     * (`replay.day_reserve`, the spool below `below_seconds`, within
     * DayPlanner::replayAllowed(); 409 `over_budget` beyond it: music
     * instead), expiring `day_expire_minutes` after its upload. It stands in
     * the spool under a stand-in script of kind replay.
     */
    public function replay(Request $request, ClipSpool $spool): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $now = CarbonImmutable::now();
        $settings = (array) config('tv.commentary.blocks.replay', []);
        $quiet = QuietHours::active($now);
        $duration = (float) $request->header('X-Caster-Duration', '0');

        if (config('tv.commentary.blocks.replay.enabled') !== true || (! $quiet && ($settings['day_reserve'] ?? false) !== true)) {
            return response()->json(['error' => 'not_now'], 409);
        }

        if (! $quiet) {
            // A replay waiting or on air is not in the air log yet: it counts all the same.
            $waiting = (float) CasterClip::query()->whereIn('status', [CasterClip::READY, CasterClip::PLAYING])->where('kind', CasterClip::BLOCK)->get()
                ->filter(fn (CasterClip $clip): bool => $clip->isReplay())->sum('duration_seconds');
            $usage = DayPlanner::replayUsage(AirLog::entries(), $waiting, $now);

            if ($spool->readySeconds() >= (float) ($settings['below_seconds'] ?? 120)) {
                return response()->json(['error' => 'not_dry'], 409);
            }

            if (! DayPlanner::replayAllowed($usage['hour_replay'], $usage['day_replay'], $usage['day_voice'], $duration)) {
                return response()->json(['error' => 'over_budget'] + $usage, 409);
            }
        }

        $times = fn (string $header): array => array_map('floatval', array_values(array_filter(array_map('trim', explode(',', (string) $request->header($header, ''))), 'is_numeric')));

        $script = CasterScript::query()->create([
            'kind' => CasterScript::REPLAY, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => '(replay)', 'facts_hash' => 'replay',
            'status' => CasterScript::CLAIMED, 'source' => CasterScript::SOURCE_AI, 'estimated_seconds' => max(0.0, $duration),
            'claimed_at' => $now, 'expires_at' => $quiet ? $this->quietHoursEnd($now) : $now->addMinutes((int) ($settings['day_expire_minutes'] ?? 20)),
        ]);

        try {
            $clip = $spool->storeBlock([$script], (string) $request->getContent(), $duration, $times('X-Caster-Sentence-Ends'), $times('X-Caster-Boundaries'), 0.0, $now, replay: true);
        } catch (SpoolFull) {
            $script->delete();

            return response()->json(['error' => 'spool_full'], 507);
        } catch (InvalidClip $exception) {
            $script->delete();

            return response()->json(['error' => 'invalid_clip', 'reason' => $exception->getMessage()], 422);
        }

        return response()->json(['clip' => $clip->id, 'expires_at' => $clip->expires_at->toIso8601String()], 201);
    }

    /**
     * The scripts the workstation rendered into its day stock (`POST
     * api/caster/stock/stored`, `X-Caster-Scripts`): claimed stock scripts
     * are voiced (they never come again); anything else is left alone.
     */
    public function stockStored(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $ids = array_map('intval', array_values(array_filter(array_map('trim', explode(',', (string) $request->header('X-Caster-Scripts', ''))), 'ctype_digit')));
        $stored = $ids === [] ? 0 : CasterScript::query()->whereKey($ids)->where('scene', StockScripts::SCENE)->where('status', CasterScript::CLAIMED)
            ->update(['status' => CasterScript::VOICED]);

        return response()->json(['stored' => $stored]);
    }

    /**
     * A block of the workstation's day stock (`POST api/caster/stock/blocks`):
     * the raw Opus body with its length, sentence ends and boundaries, under a
     * stand-in script; it plays after every fresh block (ClipSpool::nextBlock())
     * and keeps `stock.spool_hours`. Taken only while the day stock runs and
     * outside the quiet hours (409 otherwise: the night air keeps its rhythm).
     */
    public function stockBlock(Request $request, ClipSpool $spool): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $now = CarbonImmutable::now();

        if (! DayStock::enabled() || QuietHours::active($now)) {
            return response()->json(['error' => 'not_now'], 409);
        }

        $duration = (float) $request->header('X-Caster-Duration', '0');
        $times = fn (string $header): array => array_map('floatval', array_values(array_filter(array_map('trim', explode(',', (string) $request->header($header, ''))), 'is_numeric')));

        $script = CasterScript::query()->create([
            'kind' => CasterScript::SCENE, 'phase' => ScenePhase::BREAK, 'scene' => StockScripts::SCENE, 'priority' => 0, 'text' => '(day stock)', 'facts_hash' => 'stock',
            'status' => CasterScript::CLAIMED, 'source' => CasterScript::SOURCE_AI, 'estimated_seconds' => max(0.0, $duration),
            'claimed_at' => $now, 'expires_at' => $now->addHours(max(1, (int) config('tv.commentary.blocks.stock.spool_hours', 3))),
        ]);

        try {
            $clip = $spool->storeBlock([$script], (string) $request->getContent(), $duration, $times('X-Caster-Sentence-Ends'), $times('X-Caster-Boundaries'), 0.0, $now, stock: true);
        } catch (SpoolFull) {
            $script->delete();

            return response()->json(['error' => 'spool_full'], 507);
        } catch (InvalidClip $exception) {
            $script->delete();

            return response()->json(['error' => 'invalid_clip', 'reason' => $exception->getMessage()], 422);
        }

        return response()->json(['clip' => $clip->id, 'spool_seconds' => round($spool->readySeconds(), 1)], 201);
    }

    /** The next end of the quiet hours after `$now` (UTC). */
    private function quietHoursEnd(CarbonImmutable $now): CarbonImmutable
    {
        $settings = (array) config('tv.commentary.spool.quiet_hours', []);
        $timezone = (string) ($settings['timezone'] ?? 'Europe/Berlin');
        [$hour, $minute] = array_map('intval', explode(':', (string) ($settings['to'] ?? '09:00')) + [1 => 0]);
        $end = $now->setTimezone($timezone)->setTime($hour, $minute);

        return ($end->lessThanOrEqualTo($now) ? $end->addDay() : $end)->utc();
    }

    public function release(Request $request, string $id, ScriptQueue $queue): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $script = CasterScript::query()->find((int) $id);

        if ($script === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $queue->release($script);

        return response()->json(['released' => true]);
    }

    public function podcastStatus(Request $request, PodcastTranscripts $transcripts): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return response()->json(['guid' => $transcripts->latestGuid()]);
    }

    public function podcastTranscript(Request $request, PodcastTranscripts $transcripts): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // The cap on the raw body, before anything is parsed.
        if (strlen((string) $request->getContent()) > (int) config('tv.commentary.podcast.max_bytes', 400 * 1024)) {
            return response()->json(['error' => 'too_large'], 413);
        }

        $data = json_decode((string) $request->getContent(), true);
        $guid = is_array($data) ? ($data['guid'] ?? null) : null;
        $title = is_array($data) ? ($data['title'] ?? null) : null;
        $number = is_array($data) ? ($data['episode_number'] ?? null) : null;
        $published = is_array($data) ? ($data['published_at'] ?? null) : null;
        $transcript = is_array($data) ? ($data['transcript'] ?? null) : null;

        if (! is_string($guid) || trim($guid) === '' || strlen($guid) > 255 || ! is_string($title) || trim($title) === '' || mb_strlen($title) > 255
            || ($number !== null && (! is_int($number) || $number < 0)) || ! is_int($published) || $published <= 0
            || ! is_string($transcript) || trim($transcript) === '' || ! mb_check_encoding($transcript, 'UTF-8')) {
            return response()->json(['error' => 'invalid'], 422);
        }

        $row = $transcripts->store(['guid' => $guid, 'title' => trim($title), 'episode_number' => $number, 'published_at' => $published, 'transcript' => trim($transcript)]);

        return $row === null
            ? response()->json(['error' => 'older_than_stored'], 409)
            : response()->json(['stored' => $row->guid, 'bytes' => strlen($row->transcript)], 201);
    }

    private function authorized(Request $request): bool
    {
        $token = config('tv.commentary.fetch.token');
        $given = $request->bearerToken();

        return is_string($token) && strlen($token) >= 32 && is_string($given) && hash_equals($token, $given);
    }
}
