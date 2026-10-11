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
    | chat (kind 1311): a personal one only for trusted pubkeys (TrustRank >=
    | the season's minimum, or web-of-trust level 1/2 of the league's trust
    | anchors); everybody else (`everyone`) gets a generic greeting without
    | name or content, at most one per `everyone_minutes` for all of them.
    | `zaps`: every zap of the stream (kind 9735) is thanked once, with the
    | amount spelled out, the zapper's name only when trusted; zaps within
    | `zap_bundle_seconds` share one thank-you, from `zap_big_sats` on an
    | extra-emotional line. Pattern texts work with `ai` off.
    | `profiles` adds the persona card from public kind 0 + notes (cached in
    | the database, capped, pruned). Both stay off until the association has
    | decided the data protection question (profiling, GDPR).
    |
    | news (App\Support\Caster\NewsFeeds, NewsScripts; `caster:news` every 15
    | minutes): headlines from the public `feeds` (checked 2026-10-11), kept
    | `keep_hours`, at most `max_rows`; a headline about death, disaster,
    | war, terror, violence against people, abuse or suicide is never spoken
    | (keyword filter, then the model's own check). A news segment (intro,
    | the headline word for word with its source, one to three sentences of
    | opinion by the league voice, pattern reactions when it fails) at most
    | every `every_minutes`, one model call per headline.
    |
    | history (App\Support\Caster\HistoryScripts): "Markus erzählt vom
    | Bauernkrieg", a long segment built from resources/data/voice/bauernkrieg.json
    | (facts with sources, no model), every `every_minutes` (random within the
    | range), no episode again within `repeat_hours`. Segments play only on
    | calm slots, never two within `spool.segment_spacing_minutes`.
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
            'chat_sentences' => ['min' => 1, 'max' => 8, 'ask' => 4],
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
            // The canon entries a scene script's prompt offers (fitting the phase first): the system prompt (guides,
            // patterns, invitations) stays byte-identical and cached, only the facts and this pick vary.
            'canon' => ['min' => 12, 'max' => 15, 'token_budget' => 2_000],
        ],

        // The deterministic scene scripts (App\Support\Caster\PatternScripts): at most `per_run` per fill() run,
        // `sentences` each, no line pattern again within the last `recent` pattern scripts.
        // `fact_memory_minutes`: a fact atom is not said again by any script for this long; `name_memory_minutes` the same
        // for a person's name; `flavour_recent`: no flavour line (joke, invitation, pride, chat call, emotion) again
        // within this many pattern scripts (user 2026-10-11: fewer data, no repeats, more jokes and pride).
        'patterns' => [
            'per_run' => 40,
            'sentences' => ['min' => 3, 'max' => 6],
            'recent' => 4,
            'fact_memory_minutes' => 20,
            'name_memory_minutes' => 30,
            // A tournament, track, clan or ladder name: once per script, not again within this long (user 2026-10-11).
            'entity_memory_minutes' => 15,
            'flavour_recent' => 40,
            // No canon line again within this many canon lines of the pattern scripts (the full canon has over 1300).
            'canon_recent' => 200,
        ],

        'fetch' => [
            'token' => env('TV_COMMENTARY_TOKEN'),
            'online_seconds' => 120,
            // A claimed script that came back neither as a clip nor released is free again after this long (a long
            // Bauernkrieg segment is 15 to 30 sentences, about ten VoiceStudio calls).
            'claim_seconds' => 300,
        ],

        'spool' => [
            'disk' => 'local',
            'dir' => 'caster/spool',
            'max_bytes' => (int) env('TV_COMMENTARY_SPOOL_MAX_BYTES', 50 * 1024 * 1024),
            'max_seconds' => 600,
            // A long Bauernkrieg segment of four minutes is about 1.5 MB at `opus_kbps` 48.
            'max_clip_bytes' => 3 * 1024 * 1024,
            'max_clip_seconds' => 90,
            // A clip of these kinds may be this long (the long variants, tv.commentary.length): a long scene runs 60 to
            // 100 s spoken, a long news segment up to two minutes, a long Bauernkrieg segment two to four minutes.
            'long_clip_seconds' => ['scene' => 130, 'news' => 150, 'history' => 280],
            // At most one news or history segment on air within this many minutes.
            'segment_spacing_minutes' => 5,
            // Talk density with the gap (below): a clip of about 20 s plus 20 to 30 s of music, so the spool needs half the
            // airtime it did before the gap (180 and 60), and the queue writes half as much ahead (ahead_seconds).
            'target_seconds' => 240,
            'filler_below_seconds' => 30,
            // Music only between two clips, a random length in this range (user 2026-10-11); a live moment cuts it short.
            'gap_seconds' => ['min' => (float) env('TV_COMMENTARY_GAP_MIN', 6), 'max' => (float) env('TV_COMMENTARY_GAP_MAX', 12)],
        ],

        'voicestudio' => [
            'url' => env('TV_VOICESTUDIO_URL', 'http://127.0.0.1:3900'),
            'profile_id' => env('TV_VOICESTUDIO_PROFILE', 'ee7a2a18'),
            'speed' => (float) env('TV_VOICESTUDIO_SPEED', 1.05),
            'language' => 'de',
            'timeout_seconds' => 60,
            'retries' => 3,
            'retry_seconds' => 5,
            // Pauses between chunks only; a chunk is one /generate call of up to `max_sentences` and `max_words`
            // (user 2026-10-11: "Und er spricht zu abgehackt"; one call per sentence put a pause after each).
            'pause' => ['sentence' => 0.2, 'accent' => 0.35, 'scene' => [1.0, 1.5], 'jitter' => 0.08],
            'chunk' => ['max_sentences' => 3, 'max_words' => 25],
            'fade_ms' => 70,
            // Below this amplitude (of 32767) a 10 ms window counts as quiet when the tail is trimmed.
            'silence_threshold' => 400,
            'loudness_lufs' => -16,
            'opus_kbps' => 48,
            'ffmpeg' => env('TV_COMMENTARY_FFMPEG', 'ffmpeg'),
            'server' => env('TV_COMMENTARY_SERVER', 'https://esports.einundzwanzig.space'),
            'idle_seconds' => 10,
            // While the server's spool is full: ask again this soon, so a new chat reaction or zap thank-you is voiced within seconds.
            'full_seconds' => 5,
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
            // A clip of these kinds holds its slot until it has been spoken, up to this long (spool.long_clip_seconds
            // plus the lead-in and tail): the scene stays on air through a long scene or story.
            'long_max_hold_seconds' => ['scene' => 135, 'news' => 160, 'history' => 290],
        ],

        'news' => [
            'enabled' => (bool) env('TV_COMMENTARY_NEWS', true),
            'every_minutes' => 10,
            // A headline older than this is not spoken any more; rows older than `keep_hours` are deleted.
            'fresh_hours' => 12,
            'keep_hours' => 48,
            'max_rows' => 600,
            'per_feed' => 20,
            'max_title_chars' => 160,
            'max_age_minutes' => 30,
            'sentences' => ['min' => 1, 'max' => 3],
            'daily_cap' => (int) env('TV_COMMENTARY_NEWS_DAILY_CAP', 150),
            'timeout_seconds' => 8,
            'connect_timeout_seconds' => 4,
            'max_feed_bytes' => 2 * 1024 * 1024,
            // section bitcoin or germany; source as spoken ("laut tagesschau"). German feeds only: the voice is German,
            // and a headline that reads as another language is skipped all the same (NewsFilter::FOREIGN).
            'feeds' => [
                'btc-echo' => ['url' => 'https://www.btc-echo.de/feed/', 'source' => 'BTC-Echo', 'section' => 'bitcoin', 'language' => 'de'],
                'blocktrainer' => ['url' => 'https://www.blocktrainer.de/feed/', 'source' => 'Blocktrainer', 'section' => 'bitcoin', 'language' => 'de'],
                'tagesschau-inland' => ['url' => 'https://www.tagesschau.de/inland/index~rss2.xml', 'source' => 'tagesschau', 'section' => 'germany', 'language' => 'de'],
                'tagesschau-wirtschaft' => ['url' => 'https://www.tagesschau.de/wirtschaft/index~rss2.xml', 'source' => 'tagesschau', 'section' => 'germany', 'language' => 'de'],
                'spiegel-deutschland' => ['url' => 'https://www.spiegel.de/politik/deutschland/index.rss', 'source' => 'Spiegel', 'section' => 'germany', 'language' => 'de'],
                'spiegel-wirtschaft' => ['url' => 'https://www.spiegel.de/wirtschaft/index.rss', 'source' => 'Spiegel', 'section' => 'germany', 'language' => 'de'],
                'welt-deutschland' => ['url' => 'https://www.welt.de/feeds/section/politik/deutschland.rss', 'source' => 'Welt', 'section' => 'germany', 'language' => 'de'],
                'welt-wirtschaft' => ['url' => 'https://www.welt.de/feeds/section/wirtschaft.rss', 'source' => 'Welt', 'section' => 'germany', 'language' => 'de'],
                'faz-inland' => ['url' => 'https://www.faz.net/rss/aktuell/politik/inland/', 'source' => 'FAZ', 'section' => 'germany', 'language' => 'de'],
                'faz-wirtschaft' => ['url' => 'https://www.faz.net/rss/aktuell/wirtschaft/', 'source' => 'FAZ', 'section' => 'germany', 'language' => 'de'],
                'handelsblatt-politik' => ['url' => 'https://www.handelsblatt.com/contentexport/feed/politik', 'source' => 'Handelsblatt', 'section' => 'germany', 'language' => 'de'],
                'handelsblatt-finanzen' => ['url' => 'https://www.handelsblatt.com/contentexport/feed/finanzen', 'source' => 'Handelsblatt', 'section' => 'germany', 'language' => 'de'],
                'ntv-politik' => ['url' => 'https://www.n-tv.de/politik/rss', 'source' => 'n-tv', 'section' => 'germany', 'language' => 'de'],
                'ntv-wirtschaft' => ['url' => 'https://www.n-tv.de/wirtschaft/rss', 'source' => 'n-tv', 'section' => 'germany', 'language' => 'de'],
            ],
        ],

        'history' => [
            'enabled' => (bool) env('TV_COMMENTARY_HISTORY', true),
            'every_minutes' => ['min' => 45, 'max' => 90],
            'repeat_hours' => 24,
            'max_age_minutes' => 120,
            'sentences' => ['min' => 6, 'max' => 12],
        ],

        // The length mix (user 2026-10-11: "Die Audios dürfen ruhig auch mal ganz viel länger werden … dann öfters auch
        // mal viel längere Texte"): today's short scripts stay the base, and this share of each kind is a long variant
        // (App\Support\Caster\LengthMix; 0 turns it off, 1 makes every one long). Sentences per script: a long scene is a
        // pattern script of `scene_long` (a fact, canon lines, pride, an invitation, no model); a long news segment is the
        // intro, the headline and a comment of `news_long` by the league voice (one call per headline, as before), so at
        // most 10; a long Bauernkrieg segment chains `history_long` of the episode's facts, asides, twist and outro.
        'length' => [
            'shares' => [
                'scene' => (float) env('TV_COMMENTARY_LONG_SCENES', 0.25),
                'news' => (float) env('TV_COMMENTARY_LONG_NEWS', 0.5),
                'history' => (float) env('TV_COMMENTARY_LONG_HISTORY', 0.5),
            ],
            'scene_long' => ['min' => 8, 'max' => 14],
            'news_long' => ['min' => 4, 'max' => 8],
            'history_long' => ['min' => 15, 'max' => 30],
            // The fact guard's character cap for a whole long script (scripts.max_chars holds the short ones).
            'max_chars' => 1_800,
        ],

        // Commentary runs (user 2026-10-11: "Durchaus auch öfter mal mehrere Audios hintereinander, damit es an diesen Stellen
        // zu Dauerkommentaren kommt"): every `every_minutes` (random within the range) `clips` clips back to back, each with
        // its own scene and only a breath of `breath_seconds` between them instead of spool.gap_seconds
        // (App\Support\Caster\SceneDirector).
        'runs' => [
            'enabled' => (bool) env('TV_COMMENTARY_RUNS', true),
            'every_minutes' => ['min' => 10, 'max' => 15],
            'clips' => ['min' => 2, 'max' => 4],
            'breath_seconds' => ['min' => 1.0, 'max' => 3.0],
        ],

        'chat' => [
            'reactions' => (bool) env('TV_COMMENTARY_CHAT', false),
            'profiles' => (bool) env('TV_COMMENTARY_CHAT_PROFILES', false),
            'per_person_minutes' => (int) env('TV_COMMENTARY_CHAT_PERSON_MINUTES', 2),
            'per_hour' => (int) env('TV_COMMENTARY_CHAT_PER_HOUR', 20),
            'notes_per_person' => 50,
            'notes_days' => 30,
            'notes_total' => 20_000,
            'profiles_total' => 2_000,
            'persona_chars' => 400,
            'persona_refresh_hours' => 24,
            'exchanges_per_person' => 5,
            'inactive_days' => 30,
            'wot_level_two_min_followers' => 2,
            'everyone' => (bool) env('TV_COMMENTARY_CHAT_EVERYONE', true),
            'everyone_minutes' => (int) env('TV_COMMENTARY_CHAT_EVERYONE_MINUTES', 3),
            'zaps' => (bool) env('TV_COMMENTARY_CHAT_ZAPS', true),
            'zap_bundle_seconds' => 60,
            'zap_big_sats' => (int) env('TV_COMMENTARY_ZAP_BIG_SATS', 10_000),
            // Wishes of trusted chatters (App\Support\Caster\ChatWishes): a Bauernkrieg episode, news, jokes, the game,
            // EINUNDZWANZIG lore or "erzähl mehr", queued right behind the reply. Web of trust level 1 always, up to
            // `wishes_min_level`, or a trust rank of at least the season minimum. 0 lifts a limit (no cooldown, no
            // hourly cap, no Bauernkrieg repeat window).
            'wishes' => (bool) env('TV_COMMENTARY_CHAT_WISHES', true),
            'wishes_min_level' => (int) env('TV_COMMENTARY_WISHES_MIN_LEVEL', 2),
            'wishes_person_minutes' => (int) env('TV_COMMENTARY_WISH_PERSON_MINUTES', 5),
            'wishes_per_hour' => (int) env('TV_COMMENTARY_WISHES_PER_HOUR', 6),
            'wishes_history_repeat_minutes' => (int) env('TV_COMMENTARY_HISTORY_REPEAT_MINUTES', 60),
            'wishes_max_age_minutes' => 30,
            // `caster:chat` runs every minute and reads the chat and zaps this often within it (a reaction is written sooner).
            'poll_seconds' => 15,
        ],
    ],

];
