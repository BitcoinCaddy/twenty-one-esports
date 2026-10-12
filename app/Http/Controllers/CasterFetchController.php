<?php

namespace App\Http\Controllers;

use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\InvalidClip;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\Pronunciation;
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

        $ready = $spool->readySeconds();
        $target = (float) config('tv.commentary.spool.target_seconds', 180);
        $atByteCap = $spool->bytes() >= (int) config('tv.commentary.spool.max_bytes', 0);
        $full = $ready >= $target || $atByteCap;

        // A full spool still takes live moments and reactions (they play next); only the byte cap stops them too.
        $script = match (true) {
            config('tv.commentary.enabled') !== true, $atByteCap => null,
            $full => $queue->claim($now, ClipSpool::LIVE_KINDS),
            default => $queue->claim($now),
        };

        return response()->json([
            'script' => $script === null ? null : [
                'id' => $script->id,
                'kind' => $script->kind,
                'sentences' => $script->sentences(),
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
