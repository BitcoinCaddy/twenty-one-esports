<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The server's audio spool (plan "Puffer und Länge", stage 2; user
 * 2026-10-11: "Auf dem Server darf es natürlich auch nicht zu einem
 * Speicherproblem kommen"). Clips are Opus files on the spool disk, one row
 * per file, never audio in the database.
 *
 * Bounded three ways: store() refuses a clip over `max_clip_bytes` or
 * `max_clip_seconds` (a scene, news or history clip: `long_clip_seconds` of
 * its kind, the long variants of tv.commentary.length), and any clip that would take the spool over
 * `max_bytes` or (a scene clip) `max_seconds` of unplayed audio (SpoolFull, the endpoint
 * answers 507 and the fetcher backs off); played() deletes a clip right after
 * it played (fillers excepted, they are a fixed small set that plays in
 * turn); prune() deletes expired clips, clips whose script expired, clips
 * stuck as playing, and orphan files or rows. Worst case on disk: max_bytes.
 *
 * next() is the fallback chain of the stream (plan: "Fällt der Spool unter
 * 60 s, nimmt der Sender vorproduzierte Füller … ist der Rechner aus,
 * Text-Bauchbinde"): a live clip first, then a clip of the slot's phase while
 * at least `filler_below_seconds` are ready (the clip about the slide's
 * topic first, SceneTopic), else a filler, else nothing; with the
 * workstation offline and no clip, the next queued script of the phase as a
 * text lower third. A long segment (news, history) comes right after the
 * live clips, on a calm slot only (never over a live match or a result),
 * and never within `segment_spacing_minutes` of the last one.
 *
 * A later part of a long script (ScriptQueue::parts()) is never picked by
 * next(), nextLive() or nextSegment(): it plays only right after the part
 * before it (part(), SceneDirector), so it never starts a story mid-way.
 */
class ClipSpool
{
    /** A clip still marked as playing this long after its start was left behind by a crash. */
    /** Seconds a playing clip may run past its own length before it counts as cut off. */
    private const PLAYING_MARGIN_SECONDS = 30;

    public const FETCHER_SEEN = 'caster:fetcher_seen_at';

    /** When the last news or history segment started playing (a timestamp). */
    public const SEGMENT_PLAYED = 'caster:segment_played_at';

    /**
     * Live moments and reactions (a result, a chat reaction, a zap thank-you):
     * played at the next clip boundary, and never kept out by the airtime
     * caps (`target_seconds`, `max_seconds`), only by `max_bytes` (user
     * 2026-10-11: reactions are played next, as fast as possible).
     */
    public const LIVE_KINDS = [CasterScript::LIVE, CasterScript::CHAT];

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('tv.commentary.spool.disk', 'local'));
    }

    public function dir(): string
    {
        return trim((string) config('tv.commentary.spool.dir', 'caster/spool'), '/');
    }

    /**
     * @param  list<float>  $sentenceEnds
     *
     * @throws SpoolFull when a cap is reached
     * @throws InvalidClip when the upload is not an Opus clip within the limits
     */
    public function store(CasterScript $script, string $opus, float $durationSeconds, array $sentenceEnds, CarbonImmutable $now): CasterClip
    {
        $settings = (array) config('tv.commentary.spool', []);

        if (! str_starts_with($opus, 'OggS') || ! str_contains(substr($opus, 0, 200), 'OpusHead')) {
            throw new InvalidClip('format');
        }

        $maxSeconds = (float) ($settings['long_clip_seconds'][$script->kind] ?? $settings['max_clip_seconds'] ?? 0);

        if (strlen($opus) > (int) ($settings['max_clip_bytes'] ?? 0) || $durationSeconds <= 0 || $durationSeconds > $maxSeconds) {
            throw new InvalidClip('size');
        }

        // A later part is never refused for airtime: the part before it may already be on air.
        $airtimeCapped = $script->kind !== CasterScript::FILLER && ! in_array($script->kind, [...self::LIVE_KINDS, ...CasterScript::SEGMENTS], true)
            && ! $script->isContinuation();

        if ($this->bytes() + strlen($opus) > (int) ($settings['max_bytes'] ?? 0)
            || ($airtimeCapped && $this->readySeconds() + $durationSeconds > (float) ($settings['max_seconds'] ?? 0))) {
            throw new SpoolFull;
        }

        $path = $this->dir().'/'.$script->id.'-'.Str::lower(Str::random(10)).'.opus';
        $this->disk()->put($path, $opus);

        $ends = array_map(fn (float $end): float => round(max(0.0, min($durationSeconds, $end)), 3), $sentenceEnds);
        sort($ends);

        $clip = CasterClip::query()->create([
            'caster_script_id' => $script->id,
            'phase' => $script->phase,
            'kind' => $script->kind,
            'path' => $path,
            'duration_seconds' => round($durationSeconds, 3),
            'bytes' => strlen($opus),
            'sentence_ends' => $ends === [] ? [round($durationSeconds, 3)] : $ends,
            'status' => CasterClip::READY,
            'expires_at' => $script->expires_at,
        ]);

        $script->update(['status' => CasterScript::VOICED]);

        return $clip;
    }

    /** Bytes of every clip in the spool (fillers included). */
    public function bytes(): int
    {
        return (int) CasterClip::query()->sum('bytes');
    }

    /**
     * Seconds of unplayed commentary ready, fillers and segments left out: a
     * long news or Bauernkrieg segment waits for a calm slot, and counted it
     * would fill the spool's target, so a live match got no scene clip.
     */
    public function readySeconds(): float
    {
        return (float) CasterClip::query()->where('status', CasterClip::READY)
            ->whereNotIn('kind', [CasterScript::FILLER, ...CasterScript::SEGMENTS])->sum('duration_seconds');
    }

    public function fetcherOnline(CarbonImmutable $now): bool
    {
        $seen = Cache::get(self::FETCHER_SEEN);

        return is_int($seen) && $now->getTimestamp() - $seen <= (int) config('tv.commentary.fetch.online_seconds', 120);
    }

    /**
     * What the stream says over a new slot of `$phase` showing `$topic`.
     *
     * @return array{clip: CasterClip|null, caption: string|null, source: string}
     */
    public function next(string $phase, CarbonImmutable $now, ?string $topic = null): array
    {
        $this->releaseStale($now);

        $ready = CasterClip::query()->where('status', CasterClip::READY)->where('expires_at', '>', $now)
            ->whereNotIn('caster_script_id', self::continuations());

        $live = $this->nextLive($now);
        if ($live !== null) {
            return ['clip' => $live, 'caption' => null, 'source' => 'live'];
        }

        $segment = in_array($phase, ScenePhase::CALM, true) ? $this->nextSegment($now) : null;
        if ($segment !== null) {
            return ['clip' => $segment, 'caption' => null, 'source' => 'segment'];
        }

        if ($this->readySeconds() >= (float) config('tv.commentary.spool.filler_below_seconds', 60)) {
            $phases = in_array($phase, ScenePhase::CALM, true) ? [$phase, ...array_diff(ScenePhase::CALM, [$phase])] : [$phase];

            // The clip about the slide on air first, within the phases it may stand in for.
            if ($topic !== null) {
                $clip = (clone $ready)->where('kind', CasterScript::SCENE)->whereIn('phase', $phases)
                    ->whereIn('caster_script_id', CasterScript::query()->where('topic', $topic)->select('id'))->orderBy('id')->first();

                if ($clip !== null) {
                    return ['clip' => $clip, 'caption' => null, 'source' => 'scene'];
                }
            }

            foreach ($phases as $candidate) {
                $clip = (clone $ready)->where('kind', CasterScript::SCENE)->where('phase', $candidate)->orderBy('id')->first();

                if ($clip !== null) {
                    return ['clip' => $clip, 'caption' => null, 'source' => 'scene'];
                }
            }

            // Nothing for this phase: any ready commentary beats silence (user 2026-10-11: "große Lücken immer" — clips
            // of another phase waited while the stream played only music). No scene is held back for its slide any more
            // (user 2026-10-12: "Das mit den Slide-Fakten ist nicht stimmig"): a scene is no slide commentary, and a
            // comment on a running game names its match in its first sentence (CasterVoice::ANCHOR_RULE).
            $clip = (clone $ready)->where('kind', CasterScript::SCENE)->orderBy('id')->first();
            if ($clip !== null) {
                return ['clip' => $clip, 'caption' => null, 'source' => 'scene'];
            }
        } else {
            // The filler played longest ago (updated_at moves on every play).
            // No filler while the pattern fallback is off (user 2026-10-12: "KEINE SICH WIEDERHOLENDEN FÜLLSÄTZE MEHR"): music instead.
            $filler = PatternScripts::enabled() ? CasterClip::query()->where('kind', CasterScript::FILLER)->where('status', CasterClip::READY)->orderBy('updated_at')->orderBy('id')->first() : null;

            if ($filler !== null) {
                return ['clip' => $filler, 'caption' => null, 'source' => 'filler'];
            }
        }

        // No text lower third, ever (user 2026-10-11: "diese Banner wollten wir nicht"): the spoken commentary is never shown as text.
        return ['clip' => null, 'caption' => null, 'source' => 'none'];
    }

    /**
     * The oldest ready live moment, chat reaction or wished segment
     * (ChatWishes::SCENE, also a long news or Bauernkrieg one), which may cut
     * the gap between clips short. In the order the scripts were queued, so a
     * wish's segment follows its acknowledgement.
     */
    public function nextLive(CarbonImmutable $now): ?CasterClip
    {
        return CasterClip::query()->where('status', CasterClip::READY)->where('expires_at', '>', $now)
            ->whereNotIn('caster_script_id', self::continuations())
            ->where(fn ($query) => $query->whereIn('kind', self::LIVE_KINDS)
                ->orWhereIn('caster_script_id', CasterScript::query()->where('scene', ChatWishes::SCENE)->select('id')))
            ->orderBy('caster_script_id')->orderBy('id')->first();
    }

    /** The oldest ready news or history segment, unless one started within `segment_spacing_minutes`. */
    public function nextSegment(CarbonImmutable $now): ?CasterClip
    {
        $last = Cache::get(self::SEGMENT_PLAYED);

        if (is_int($last) && $now->getTimestamp() - $last < 60 * (int) config('tv.commentary.spool.segment_spacing_minutes', 5)) {
            return null;
        }

        return CasterClip::query()->where('status', CasterClip::READY)->where('expires_at', '>', $now)
            ->whereNotIn('caster_script_id', self::continuations())
            ->whereIn('kind', CasterScript::SEGMENTS)->orderBy('id')->first();
    }

    /**
     * The part group of a clip's script and the part's position, or null for a script in one piece.
     *
     * @return array{group: string, index: int}|null
     */
    public function partOf(CasterClip $clip): ?array
    {
        $script = CasterScript::query()->find($clip->caster_script_id);

        return $script?->part_group === null ? null : ['group' => $script->part_group, 'index' => (int) $script->part_index];
    }

    /** The ready clip of part `$index` of `$group`, or null (not voiced yet, expired or gone). */
    public function part(string $group, int $index, CarbonImmutable $now): ?CasterClip
    {
        return CasterClip::query()->where('status', CasterClip::READY)->where('expires_at', '>', $now)
            ->whereIn('caster_script_id', CasterScript::query()->where('part_group', $group)->where('part_index', $index)->select('id'))
            ->orderBy('id')->first();
    }

    /** Whether part `$index` of `$group` may still come: queued, claimed or voiced, and not expired. */
    public function partPending(string $group, int $index, CarbonImmutable $now): bool
    {
        return CasterScript::query()->where('part_group', $group)->where('part_index', $index)
            ->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED, CasterScript::VOICED])->where('expires_at', '>', $now)->exists();
    }

    /**
     * A group that ended early (a part missing or cut off): its parts from
     * `$fromIndex` on expire and their ready clips are deleted, so none of
     * them waits in the spool for a story that is over.
     */
    public function endGroup(string $group, int $fromIndex): void
    {
        $rest = CasterScript::query()->where('part_group', $group)->where('part_index', '>=', $fromIndex);

        (clone $rest)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED, CasterScript::VOICED])->update(['status' => CasterScript::EXPIRED]);

        foreach (CasterClip::query()->where('status', CasterClip::READY)->whereIn('caster_script_id', (clone $rest)->select('id'))->get() as $clip) {
            $this->delete($clip);
        }
    }

    /**
     * The ids of the later parts of long scripts (part 2 on), which play only right after the part before them.
     *
     * @return Builder<CasterScript>
     */
    private static function continuations(): Builder
    {
        return CasterScript::query()->where('part_index', '>', 1)->select('id');
    }

    public function playing(CasterClip $clip): void
    {
        $clip->update(['status' => CasterClip::PLAYING]);

        if (in_array($clip->kind, CasterScript::SEGMENTS, true)) {
            Cache::put(self::SEGMENT_PLAYED, now()->getTimestamp(), now()->addDay());
        }
    }

    /** A clip that played is gone at once; a filler goes back to the end of the fillers' turn. */
    public function played(CasterClip $clip): void
    {
        if ($clip->kind === CasterScript::FILLER) {
            $clip->status = CasterClip::READY;
            $clip->touch();

            return;
        }

        $this->delete($clip);
    }

    /**
     * The audio of a clip as raw PCM (s16le, mono, `tv.commentary.mix.sample_rate`),
     * decoded in memory by ffmpeg (stdin to stdout, no file). null when it fails.
     */
    public function pcm(CasterClip $clip): ?string
    {
        $opus = $this->disk()->get($clip->path);

        if (! is_string($opus) || $opus === '') {
            return null;
        }

        $result = Process::timeout(10)->env(ChildEnvironment::withoutSecrets())->input($opus)->run([
            (string) config('twentyone.stream.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'ogg', '-i', 'pipe:0', '-ac', '1', '-ar', (string) (int) config('tv.commentary.mix.sample_rate', 24000), '-f', 's16le', 'pipe:1',
        ]);

        return $result->successful() && $result->output() !== '' ? $result->output() : null;
    }

    /**
     * The safety net (`caster:prune`): expired clips, clips of expired scripts,
     * clips stuck as playing, files without a row and rows without a file.
     *
     * @return array{expired: int, stale: int, orphan_files: int, orphan_rows: int}
     */
    public function prune(CarbonImmutable $now): array
    {
        $counts = ['expired' => 0, 'stale' => 0, 'orphan_files' => 0, 'orphan_rows' => 0];

        $expired = CasterClip::query()->where('kind', '!=', CasterScript::FILLER)
            ->where(fn ($query) => $query->where('expires_at', '<=', $now)->orWhereHas('script', fn ($script) => $script->where('status', CasterScript::EXPIRED)))
            ->where('status', CasterClip::READY)->get();
        foreach ($expired as $clip) {
            $this->delete($clip);
            $counts['expired']++;
        }

        $counts['stale'] += $this->releaseStale($now);

        $known = array_flip(CasterClip::query()->pluck('path')->all());
        foreach ($this->disk()->files($this->dir()) as $file) {
            if (! isset($known[$file])) {
                $this->disk()->delete($file);
                $counts['orphan_files']++;
            }
        }

        foreach (CasterClip::query()->get() as $clip) {
            if (! $this->disk()->exists($clip->path)) {
                $this->deleteRow($clip);
                $counts['orphan_rows']++;
            }
        }

        return $counts;
    }

    private function delete(CasterClip $clip): void
    {
        $this->disk()->delete($clip->path);
        $this->deleteRow($clip);
    }

    /** A filler's script goes with its clip, so the queue voices it again. */
    private function deleteRow(CasterClip $clip): void
    {
        if ($clip->kind === CasterScript::FILLER) {
            CasterScript::query()->whereKey($clip->caster_script_id)->delete();
        }

        $clip->delete();
    }

    /**
     * A clip left "playing" longer than its own length plus a margin is gone: a stream restart (every deploy
     * restarts the daemon) cut it off and nothing else would ever play after it (2026-10-11: clip 330 held the
     * spool for minutes after a deploy).
     */
    public function releaseStale(CarbonImmutable $now): int
    {
        $released = 0;

        foreach (CasterClip::query()->where('status', CasterClip::PLAYING)->get() as $clip) {
            $ends = $clip->updated_at?->copy()->addSeconds((int) ceil((float) $clip->duration_seconds) + self::PLAYING_MARGIN_SECONDS);

            if ($ends === null || $ends->lessThan($now)) {
                $this->delete($clip);
                $released++;
            }
        }

        return $released;
    }
}
