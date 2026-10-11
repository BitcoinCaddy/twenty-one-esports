<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The server's queue of commentary scripts (plan "Puffer und Länge", stage 1):
 * the league voice writes scene scripts ahead until `ahead_seconds` of
 * airtime are queued or spooled; live moments and chat reactions jump the
 * queue; a script expires unused after `max_age_minutes` or when the facts of
 * its phase changed. The workstation fetcher claims the next script, voices
 * it and uploads the clip (ClipSpool), or releases it.
 *
 * Bounded: expired and voiced rows older than a day are deleted, so the table
 * holds at most a day of scripts.
 */
class ScriptQueue
{
    /** Rows that left the queue are kept this long (for the counters of `caster:script`), then deleted. */
    private const KEEP_HOURS = 24;

    public function __construct(
        private CasterVoice $voice,
        private SceneFacts $facts,
    ) {}

    /** Airtime (seconds) queued, claimed or spooled and not yet played, fillers left out. */
    public function airtimeAhead(): float
    {
        $scripts = (float) CasterScript::query()->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->where('kind', '!=', CasterScript::FILLER)->sum('estimated_seconds');
        $clips = (float) CasterClip::query()->where('status', CasterClip::READY)->where('kind', '!=', CasterScript::FILLER)->sum('duration_seconds');

        return $scripts + $clips;
    }

    /**
     * Write scene scripts until the airtime ahead reaches its target, at most
     * `per_run`; each for the likely phase with the least airtime ready.
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

        $settings = (array) config('tv.commentary.scripts', []);
        $written = 0;
        $attempts = 0;
        $phases = $this->facts->likelyPhases($now);
        $factsByPhase = [];

        foreach ($phases as $phase) {
            $factsByPhase[$phase] = $this->facts->for($phase);
            $this->supersede($phase, SceneFacts::hash($factsByPhase[$phase]));
        }

        while ($this->airtimeAhead() < (float) ($settings['ahead_seconds'] ?? 300) && $attempts < (int) ($settings['per_run'] ?? 4)) {
            $attempts++;
            $phase = $this->thinnestPhase($phases);
            $sentences = (array) ($settings['sentences'] ?? []);
            $script = $this->voice->write(CasterScript::SCENE, $phase, $factsByPhase[$phase], (int) ($sentences['min'] ?? 3), (int) ($sentences['max'] ?? 8), [], $this->topics($phase, $now));

            if ($script !== null) {
                $this->enqueue(CasterScript::SCENE, $phase, $script['text'], SceneFacts::hash($factsByPhase[$phase]), 0, $now);
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

    public function enqueue(string $kind, string $phase, string $text, string $factsHash, int $priority, CarbonImmutable $now, ?string $scene = null): CasterScript
    {
        $maxAge = (int) config('tv.commentary.scripts.max_age_minutes', 10);

        return CasterScript::query()->create([
            'kind' => $kind,
            'phase' => $phase,
            'scene' => $scene,
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
     * oldest; claims that never came back are free again.
     */
    public function claim(CarbonImmutable $now): ?CasterScript
    {
        $this->expire($now);

        CasterScript::query()->where('status', CasterScript::CLAIMED)
            ->where('claimed_at', '<', $now->subSeconds((int) config('tv.commentary.fetch.claim_seconds', 180)))
            ->update(['status' => CasterScript::QUEUED, 'claimed_at' => null]);

        return DB::transaction(function () use ($now): ?CasterScript {
            $script = CasterScript::query()->where('status', CasterScript::QUEUED)->where('expires_at', '>', $now)
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

    /** Scene scripts of a phase written for other facts than `$hash` expire: the scene they talk about is gone. */
    public function supersede(string $phase, string $hash): int
    {
        return CasterScript::query()->where('phase', $phase)->where('kind', CasterScript::SCENE)
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
     * @param  list<string>  $phases
     */
    private function thinnestPhase(array $phases): string
    {
        $ready = [];

        foreach ($phases as $phase) {
            $ready[$phase] = (float) CasterScript::query()->where('phase', $phase)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])->sum('estimated_seconds')
                + (float) CasterClip::query()->where('phase', $phase)->where('status', CasterClip::READY)->where('kind', '!=', CasterScript::FILLER)->sum('duration_seconds');
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
    private function topics(string $phase, CarbonImmutable $now): array
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
