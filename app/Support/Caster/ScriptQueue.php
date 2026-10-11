<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The server's queue of commentary scripts (plan "Puffer und Länge", stage 1):
 * scene scripts are written ahead per slide topic (SceneTopic, with the
 * facts its slides render) until `ahead_seconds` of airtime are queued or
 * spooled: at most `per_run_ai` by the league voice (and none while
 * `tv.commentary.ai` is off), the rest as pattern scripts (PatternScripts),
 * so airtime never depends on the model; live moments and chat reactions
 * jump the queue; a script expires unused after `max_age_minutes` or when
 * the facts of its topic changed. The workstation fetcher claims the next
 * script, voices it and uploads the clip (ClipSpool), or releases it.
 *
 * The long segments (news, "Markus erzählt vom Bauernkrieg", a followed
 * profile's Nostr note) are written here too, each when it is due
 * (NewsScripts, HistoryScripts, FollowedScripts): normal
 * priority, so live moments and reactions still jump ahead of them, and
 * ClipSpool plays them on calm slots only. They do not count as airtime
 * ahead: a long Bauernkrieg segment waiting for a calm slot must not starve
 * the scene scripts of a live match.
 *
 * The length mix (LengthMix, `tv.commentary.length`): a share of each kind
 * is a long variant, a long pattern scene, a long news comment, a long
 * Bauernkrieg episode.
 *
 * Bounded: expired and voiced rows older than a day are deleted, so the table
 * holds at most a day of scripts.
 */
class ScriptQueue
{
    /** Rows that left the queue are kept this long (for the counters of `caster:script`), then deleted. */
    private const KEEP_HOURS = 24;

    private PatternScripts $patterns;

    private NewsScripts $news;

    private HistoryScripts $history;

    private FollowedScripts $followed;

    public function __construct(
        private CasterVoice $voice,
        private SceneFacts $facts,
        ?PatternScripts $patterns = null,
        ?NewsScripts $news = null,
        ?HistoryScripts $history = null,
        ?FollowedScripts $followed = null,
    ) {
        $this->patterns = $patterns ?? app(PatternScripts::class);
        $this->news = $news ?? app(NewsScripts::class);
        $this->history = $history ?? app(HistoryScripts::class);
        $this->followed = $followed ?? app(FollowedScripts::class);
    }

    /** Whether the league voice writes scene scripts (TV_COMMENTARY_AI); off, the patterns fill the airtime alone. */
    public static function aiEnabled(): bool
    {
        return config('tv.commentary.ai') === true;
    }

    /** Airtime (seconds) queued, claimed or spooled and not yet played, fillers and segments left out. */
    public function airtimeAhead(): float
    {
        $scripts = (float) CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->whereNotIn('kind', [CasterScript::FILLER, ...CasterScript::SEGMENTS])->sum('estimated_seconds');

        return $scripts + app(ClipSpool::class)->readySeconds();
    }

    /**
     * Write scene scripts until the airtime ahead reaches its target, each
     * for the topic with data that has the least airtime ready: first at
     * most `per_run_ai` by the league voice, then pattern scripts, at most
     * `patterns.per_run`. A topic without data of its own gets no script, so
     * the model is never asked about an empty scene.
     *
     * @return int scripts written
     */
    public function fill(CarbonImmutable $now): int
    {
        if (! CasterVoice::enabled()) {
            return 0;
        }

        $this->expire($now);
        $this->seedFillers($now);
        $written = $this->fillSegments($now);

        $settings = (array) config('tv.commentary.scripts', []);
        $sentences = (array) ($settings['sentences'] ?? []);
        $night = ScenePhase::isNight($now);
        $factsByTopic = [];

        foreach (SceneTopic::ALL as $topic) {
            $facts = $this->facts->forTopic($topic, $night);
            $this->supersedeTopic($topic, SceneFacts::hash($facts));

            if ((! $night || in_array($topic, SceneTopic::NIGHT, true)) && SceneFacts::hasData($topic, $facts)) {
                $factsByTopic[$topic] = $facts;
            }
        }

        $aiLeft = self::aiEnabled() ? max(0, (int) ($settings['per_run_ai'] ?? 1)) : 0;
        $patternsLeft = max(0, (int) config('tv.commentary.patterns.per_run', 40));
        $exhausted = [];

        while ($this->airtimeAhead() < (float) ($settings['ahead_seconds'] ?? 300) && ($candidates = array_diff(array_keys($factsByTopic), $exhausted)) !== []) {
            $topic = $this->thinnestTopic(array_values($candidates));
            $phase = SceneTopic::phase($topic, $now);
            $facts = $factsByTopic[$topic] ?? [];

            if ($aiLeft > 0) {
                $aiLeft--;
                $script = $this->voice->write(CasterScript::SCENE, $phase, $facts, (int) ($sentences['min'] ?? 2), (int) ($sentences['max'] ?? 8), [], $this->topics($phase, $now));

                if ($script !== null) {
                    $this->enqueue(CasterScript::SCENE, $phase, $script['text'], SceneFacts::hash($facts), 0, $now, source: CasterScript::SOURCE_AI, topic: $topic);
                    $written++;
                }

                continue;
            }

            if ($patternsLeft <= 0) {
                break;
            }

            $patternsLeft--;
            // Every so often a long one (LengthMix); when the topic cannot make one, the short script stands in.
            $long = LengthMix::isDue(LengthMix::SCENE);
            $script = $long ? $this->patterns->write($topic, $phase, $facts, $now, long: true) : null;
            $long = $script !== null;
            $script ??= $this->patterns->write($topic, $phase, $facts, $now);

            if ($script === null) {
                $exhausted[] = $topic;

                continue;
            }

            $this->enqueue(CasterScript::SCENE, $phase, $script['text'], SceneFacts::hash($facts), 0, $now, source: CasterScript::SOURCE_PATTERN, topic: $topic);
            LengthMix::written(LengthMix::SCENE, $long);
            $written++;
        }

        return $written;
    }

    /**
     * The long segments, each when it is due: a history segment every
     * `history.every_minutes`, a news segment at most every
     * `news.every_minutes` (the headline's comment by the league voice while
     * `tv.commentary.ai` is on, else a pattern reaction), a followed-profile
     * segment at most every `followed.every_minutes` (the same, for a note).
     *
     * @return int segments written
     */
    public function fillSegments(CarbonImmutable $now): int
    {
        $written = 0;

        if (config('tv.commentary.history.enabled') === true && $this->history->due($now)) {
            $long = LengthMix::isDue(LengthMix::HISTORY);
            $segment = $this->history->write($now, $long);

            if ($segment !== null) {
                $this->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, $segment['text'], HistoryScripts::HASH_PREFIX.$segment['episode'], 0, $now,
                    source: CasterScript::SOURCE_PATTERN, maxAgeMinutes: (int) config('tv.commentary.history.max_age_minutes', 120));
                LengthMix::written(LengthMix::HISTORY, $long);
                $written++;
            }
        }

        $since = $now->subMinutes((int) config('tv.commentary.news.every_minutes', 10));

        if (config('tv.commentary.news.enabled') === true && ! CasterScript::query()->where('kind', CasterScript::NEWS)->where('created_at', '>', $since)->exists()) {
            $item = $this->news->next($now);
            $segment = $item === null ? null : $this->news->write($item, $now, self::aiEnabled(), LengthMix::isDue(LengthMix::NEWS));

            if ($item !== null && $segment !== null) {
                $this->enqueue(CasterScript::NEWS, ScenePhase::BREAK, $segment['text'], 'news:'.$item->id, 0, $now,
                    source: $segment['source'], topic: $item->section, maxAgeMinutes: (int) config('tv.commentary.news.max_age_minutes', 30));
                LengthMix::written(LengthMix::NEWS, $segment['long']);
                $written++;
            }
        }

        $since = $now->subMinutes((int) config('tv.commentary.followed.every_minutes', 10));

        if (config('tv.commentary.followed.enabled') === true && ! CasterScript::query()->where('kind', CasterScript::FOLLOWED)->where('created_at', '>', $since)->exists()) {
            $note = $this->followed->next($now);
            $segment = $note === null ? null : $this->followed->write($note, $now, self::aiEnabled());

            if ($note !== null && $segment !== null) {
                $this->enqueue(CasterScript::FOLLOWED, ScenePhase::BREAK, $segment['text'], 'followed:'.$note->id, 0, $now,
                    source: $segment['source'], maxAgeMinutes: (int) config('tv.commentary.followed.max_age_minutes', 30));
                $written++;
            }
        }

        return $written;
    }

    /**
     * A live moment (a result, a champion, a chat reaction): written now and
     * put at the front of the queue.
     *
     * @param  array<array-key, mixed>  $facts
     * @param  array<string, string>  $hints
     */
    public function pushLive(string $kind, string $phase, array $facts, int $priority, CarbonImmutable $now, array $hints = [], ?string $scene = null): ?CasterScript
    {
        if (! CasterVoice::enabled()) {
            return null;
        }

        $range = (array) config('tv.commentary.scripts.live_sentences', []);
        $script = $this->voice->write($kind, $phase, $facts, (int) ($range['min'] ?? 1), (int) ($range['max'] ?? 3), $hints);

        return $script === null ? null : $this->enqueue($kind, $phase, $script['text'], SceneFacts::hash($facts), $priority, $now, $scene);
    }

    public function enqueue(string $kind, string $phase, string $text, string $factsHash, int $priority, CarbonImmutable $now, ?string $scene = null, string $source = CasterScript::SOURCE_AI, ?string $topic = null, ?int $maxAgeMinutes = null): CasterScript
    {
        $maxAge = $maxAgeMinutes ?? (int) config('tv.commentary.scripts.max_age_minutes', 10);

        return CasterScript::query()->create([
            'kind' => $kind,
            'phase' => $phase,
            'scene' => $scene,
            'source' => $source,
            'topic' => $topic,
            'priority' => $priority,
            'text' => $text,
            'facts_hash' => $factsHash,
            'status' => CasterScript::QUEUED,
            'estimated_seconds' => self::estimateSeconds($text),
            // Fillers carry no facts: they never go stale.
            'expires_at' => $kind === CasterScript::FILLER ? $now->addYears(10) : $now->addMinutes($maxAge),
        ]);
    }

    /**
     * The next script for the fetcher: the highest priority first, then the
     * oldest; claims that never came back are free again. `$kinds` limits it
     * to those kinds (the live moments and reactions while the spool is full);
     * a wished segment (ChatWishes::SCENE) travels with them.
     *
     * @param  list<string>|null  $kinds
     */
    public function claim(CarbonImmutable $now, ?array $kinds = null): ?CasterScript
    {
        $this->expire($now);

        CasterScript::query()->where('status', CasterScript::CLAIMED)
            ->where('claimed_at', '<', $now->subSeconds((int) config('tv.commentary.fetch.claim_seconds', 180)))
            ->update(['status' => CasterScript::QUEUED, 'claimed_at' => null]);

        return DB::transaction(function () use ($now, $kinds): ?CasterScript {
            $script = CasterScript::query()->where('status', CasterScript::QUEUED)->where('expires_at', '>', $now)
                ->when($kinds !== null, fn ($query) => $query->where(fn ($query) => $query->whereIn('kind', (array) $kinds)->orWhere('scene', ChatWishes::SCENE)))
                ->orderByDesc('priority')->orderBy('id')->lockForUpdate()->first();

            $script?->update(['status' => CasterScript::CLAIMED, 'claimed_at' => $now]);

            return $script;
        });
    }

    public function release(CasterScript $script): void
    {
        if ($script->status === CasterScript::CLAIMED) {
            $script->update(['status' => CasterScript::QUEUED, 'claimed_at' => null]);
        }
    }

    /**
     * Scripts whose time is up expire unused, with their clips (ClipSpool
     * deletes the files); rows that left the queue a day ago are deleted.
     *
     * @return int scripts expired now
     */
    public function expire(CarbonImmutable $now): int
    {
        $expired = CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->where('expires_at', '<=', $now)->update(['status' => CasterScript::EXPIRED]);

        CasterScript::query()->whereIn('status', [CasterScript::EXPIRED, CasterScript::VOICED])
            ->where('kind', '!=', CasterScript::FILLER)
            ->where('updated_at', '<', $now->subHours(self::KEEP_HOURS))
            ->whereDoesntHave('clips')
            ->delete();

        return $expired;
    }

    /** Scene scripts of a topic written for other facts than `$hash` expire: the slide they talk about changed. */
    public function supersedeTopic(string $topic, string $hash): int
    {
        return CasterScript::query()->where('topic', $topic)->where('kind', CasterScript::SCENE)
            ->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->where('facts_hash', '!=', $hash)->update(['status' => CasterScript::EXPIRED]);
    }

    /** The fillers (resources/data/voice/fillers.json) wait in the queue until each is voiced once. */
    public function seedFillers(CarbonImmutable $now): int
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/fillers.json')), true);
        $texts = is_array($data) ? array_values(array_filter((array) ($data['fillers'] ?? []), 'is_string')) : [];
        $known = CasterScript::query()->where('kind', CasterScript::FILLER)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED, CasterScript::VOICED])->pluck('text')->all();
        $seeded = 0;

        foreach ($texts as $text) {
            if (! in_array($text, $known, true)) {
                $this->enqueue(CasterScript::FILLER, ScenePhase::BREAK, $text, 'filler', 0, $now);
                $seeded++;
            }
        }

        return $seeded;
    }

    /** Spoken seconds of a script at `words_per_second`, with the pauses between its sentences. */
    public static function estimateSeconds(string $text): float
    {
        $lines = ScriptGuard::lines($text);
        $words = count(preg_split('/\s+/u', ScriptGuard::withoutMarkers(implode(' ', $lines)), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return round($words / max(0.5, (float) config('tv.commentary.scripts.words_per_second', 2.4)) + 0.4 * max(0, count($lines) - 1), 1);
    }

    /**
     * The topic with the least airtime queued or spooled (the first of equals, in SceneTopic::ALL order).
     *
     * @param  list<string>  $topics
     */
    private function thinnestTopic(array $topics): string
    {
        $ready = [];

        foreach ($topics as $topic) {
            $scripts = CasterScript::query()->where('topic', $topic)->where('kind', CasterScript::SCENE);
            $ready[$topic] = (float) (clone $scripts)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])->sum('estimated_seconds')
                + (float) CasterClip::query()->whereIn('caster_script_id', (clone $scripts)->select('id'))->where('status', CasterClip::READY)->sum('duration_seconds');
        }

        asort($ready);

        return (string) array_key_first($ready);
    }

    /**
     * Three topics of the phase from the cast research, rotating with the clock
     * (a topic comes back after the others of its phase had their turn).
     *
     * @return list<string>
     */
    public function topics(string $phase, CarbonImmutable $now): array
    {
        $when = match ($phase) {
            ScenePhase::LIVE_MATCH => ['live_match'],
            ScenePhase::AFTER_RESULT => ['after_result'],
            ScenePhase::PRE_SHOW => ['pre_show', 'between_matches'],
            ScenePhase::NIGHT => ['night'],
            default => ['break', 'between_matches'],
        };
        $topics = array_values(array_filter(CasterVoice::castData()['topics'], fn (array $topic): bool => array_intersect($when, $topic['when']) !== []));

        if ($topics === []) {
            return [];
        }

        $start = intdiv($now->getTimestamp(), 60) % count($topics);
        $picked = [];

        for ($i = 0; $i < min(3, count($topics)); $i++) {
            $topic = $topics[($start + $i * 7) % count($topics)];
            $picked[$topic['id']] = "{$topic['name']}: {$topic['what']} (etwa: „{$topic['example_de']}“)";
        }

        return array_values($picked);
    }
}
