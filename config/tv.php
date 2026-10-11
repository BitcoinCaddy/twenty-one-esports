<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TWENTY ONE TV: the spoken commentary (plan "KI-Stimme der Liga", P6)
    |--------------------------------------------------------------------------
    |
    | Markus Turm's voice on the 24/7 stream, generated on his own workstation
    | with VoiceStudio. Every switch here is off by default; go-live waits for
    | the user (P7 tone approval).
    |
    | scripts (App\Support\Caster\ScriptQueue, `caster:script` every minute):
    | scene scripts are written ahead per slide topic, `ahead_seconds` of
    | airtime (estimated at `words_per_second`): at most `per_run_ai` by the
    | league voice (`ai`, TV_COMMENTARY_AI; never for a topic without data),
    | the rest as `patterns` (resources/data/voice/caster.json, filled only
    | with the topic's facts), so airtime never depends on the model. A
    | script expires unused after `max_age_minutes` or when the facts of its
    | topic changed. Live moments (`live_priority`) and chat reactions
    | (`chat_priority`) jump the queue.
    |
    | fetch: the workstation fetcher (`php artisan caster:fetch`) pulls scripts
    | with `token` as a bearer token (`.env` only, never logged). A fetcher
    | seen within `online_seconds` counts as online; otherwise the stream shows
    | the script as a text lower third instead of sound.
    |
    | spool (App\Support\Caster\ClipSpool, server only): Opus clips on the
    | `disk`, hard caps `max_bytes` and `max_seconds` of unplayed audio (an
    | upload over a cap is refused with 507, the fetcher backs off), a clip is
    | deleted right after it played, unplayed clips expire with their script;
    | `caster:prune` removes expired clips and orphan files as a safety net.
    | The fetcher works towards `target_seconds` of ready audio; below
    | `filler_below_seconds` the stream plays fillers (idents, `filler: true`
    | scripts) instead of eating the last clips.
    |
    | voicestudio (workstation): the local VoiceStudio API, the voice profile
    | (`ee7a2a18`, cut by the user), tempo 1.0, pauses between sentences
    | (seconds, slightly random), the quiet tail trimmed after the last word
    | with a `fade_ms` fade out. Every generation's history entry is deleted
    | right after the audio was read (the fetcher leaves no files behind).
    |
    | mix (App\Support\TwentyOne\Stream\FfmpegCommands): TV levels, voice at
    | -16 LUFS (normalised on the workstation), the music bed `music_gain_db`
    | down (to about -28 LUFS, measure with `caster:music-loudness`), ducked
    | under the voice by a sidechain compressor, limiter at -1 dBFS.
    |
    | scenes (App\Support\Caster\SceneDirector): with commentary on, a scene
    | stands at least `min_scene_seconds` and never ends before its clip ended
    | plus `tail_seconds` (never mid-sentence), at most `max_hold_seconds`.
    |
    | chat (App\Support\Caster\ChatReactions): spoken reactions to the stream
    | chat (kind 1311), only for trusted pubkeys (TrustRank >= the season's
    | minimum, or web-of-trust level 1/2 of the league's trust anchors).
    | `profiles` adds the persona card from public kind 0 + notes (cached in
    | the database, capped, pruned). Both stay off until the association has
    | decided the data protection question (profiling, GDPR).
    |
    */

    'commentary' => [
        'enabled' => (bool) env('TV_COMMENTARY', false),

        // Whether the league voice (Claude) writes scene scripts at all; off, the pattern scripts fill the airtime alone.
        'ai' => (bool) env('TV_COMMENTARY_AI', true),

        'scripts' => [
            'ahead_seconds' => 300,
            'words_per_second' => 2.4,
            'sentences' => ['min' => 2, 'max' => 8],
            'live_sentences' => ['min' => 1, 'max' => 3],
            'max_words_per_sentence' => 16,
            'max_commas' => 2,
            'max_chars' => 900,
            // At most this many league-voice scene scripts per fill() run (each with its one retry); patterns fill the rest.
            'per_run_ai' => 1,
            'max_age_minutes' => 10,
            'live_priority' => 100,
            'chat_priority' => 50,
            'recent' => 10,
            'daily_cap' => (int) env('TV_COMMENTARY_DAILY_CAP', 400),
        ],

        // The deterministic scene scripts (App\Support\Caster\PatternScripts): at most `per_run` per fill() run,
        // `sentences` each, no line pattern again within the last `recent` pattern scripts.
        'patterns' => [
            'per_run' => 40,
            'sentences' => ['min' => 3, 'max' => 6],
            'recent' => 4,
        ],

        'fetch' => [
            'token' => env('TV_COMMENTARY_TOKEN'),
            'online_seconds' => 120,
            // A claimed script that came back neither as a clip nor released is free again after this long.
            'claim_seconds' => 180,
        ],

        'spool' => [
            'disk' => 'local',
            'dir' => 'caster/spool',
            'max_bytes' => (int) env('TV_COMMENTARY_SPOOL_MAX_BYTES', 50 * 1024 * 1024),
            'max_seconds' => 600,
            'max_clip_bytes' => 2 * 1024 * 1024,
            'max_clip_seconds' => 90,
            'target_seconds' => 180,
            'filler_below_seconds' => 60,
        ],

        'voicestudio' => [
            'url' => env('TV_VOICESTUDIO_URL', 'http://127.0.0.1:3900'),
            'profile_id' => env('TV_VOICESTUDIO_PROFILE', 'ee7a2a18'),
            'speed' => 1.0,
            'language' => 'de',
            'timeout_seconds' => 60,
            'retries' => 3,
            'retry_seconds' => 5,
            'pause' => ['sentence' => 0.3, 'accent' => 0.5, 'scene' => [1.0, 1.5], 'jitter' => 0.08],
            'fade_ms' => 70,
            // Below this amplitude (of 32767) a 10 ms window counts as quiet when the tail is trimmed.
            'silence_threshold' => 400,
            'loudness_lufs' => -16,
            'opus_kbps' => 48,
            'ffmpeg' => env('TV_COMMENTARY_FFMPEG', 'ffmpeg'),
            'server' => env('TV_COMMENTARY_SERVER', 'https://esports.einundzwanzig.space'),
            'idle_seconds' => 10,
        ],

        'mix' => [
            'music_gain_db' => (float) env('TV_COMMENTARY_MUSIC_GAIN_DB', -14),
            'duck' => ['threshold' => 0.02, 'ratio' => 12, 'attack_ms' => 20, 'release_ms' => 600],
            'limit' => 0.891,
            'audio_kbps' => 128,
            'sample_rate' => 24000,
            // The voice is written into the encoder this far ahead of real time, so a slow tick of the daemon never starves it.
            'lead_seconds' => 1.0,
        ],

        'scenes' => [
            'min_scene_seconds' => 20,
            'silent_min_scene_seconds' => 15,
            'tail_seconds' => 1.5,
            'lead_in_seconds' => 0.5,
            'max_hold_seconds' => 100,
        ],

        'chat' => [
            'reactions' => (bool) env('TV_COMMENTARY_CHAT', false),
            'profiles' => (bool) env('TV_COMMENTARY_CHAT_PROFILES', false),
            'per_person_minutes' => 15,
            'per_hour' => 6,
            'notes_per_person' => 50,
            'notes_days' => 30,
            'notes_total' => 20_000,
            'profiles_total' => 2_000,
            'persona_chars' => 400,
            'persona_refresh_hours' => 24,
            'exchanges_per_person' => 5,
            'inactive_days' => 30,
            'wot_level_two_min_followers' => 2,
        ],
    ],

];
