<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
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
 * `max_clip_seconds`, and any clip that would take the spool over
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
 * text lower third.
 */
class ClipSpool
{
    /** A clip still marked as playing this long after its start was left behind by a crash. */
    private const PLAYING_STALE_MINUTES = 10;

    public const FETCHER_SEEN = 'caster:fetcher_seen_at';

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

        if (strlen($opus) > (int) ($settings['max_clip_bytes'] ?? 0) || $durationSeconds <= 0 || $durationSeconds > (float) ($settings['max_clip_seconds'] ?? 0)) {
            throw new InvalidClip('size');
        }

        $airtimeCapped = $script->kind !== CasterScript::FILLER && ! in_array($script->kind, self::LIVE_KINDS, true);

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

    /** Seconds of unplayed commentary ready (fillers left out). */
    public function readySeconds(): float
    {
        return (float) CasterClip::query()->where('status', CasterClip::READY)->where('kind', '!=', CasterScript::FILLER)->sum('duration_seconds');
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
        $ready = CasterClip::query()->where('status', CasterClip::READY)->where('expires_at', '>', $now);

        $live = $this->nextLive($now);
        if ($live !== null) {
            return ['clip' => $live, 'caption' => null, 'source' => 'live'];
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
        } else {
            // The filler played longest ago (updated_at moves on every play).
            $filler = CasterClip::query()->where('kind', CasterScript::FILLER)->where('status', CasterClip::READY)->orderBy('updated_at')->orderBy('id')->first();

            if ($filler !== null) {
                return ['clip' => $filler, 'caption' => null, 'source' => 'filler'];
            }
        }

        // No text lower third, ever (user 2026-10-11: "diese Banner wollten wir nicht"): the spoken commentary is never shown as text.
        return ['clip' => null, 'caption' => null, 'source' => 'none'];
    }

    /** The oldest ready live moment or chat reaction, which may cut the gap between clips short. */
    public function nextLive(CarbonImmutable $now): ?CasterClip
    {
        return CasterClip::query()->where('status', CasterClip::READY)->where('expires_at', '>', $now)
            ->whereIn('kind', self::LIVE_KINDS)->orderBy('id')->first();
    }

    public function playing(CasterClip $clip): void
    {
        $clip->update(['status' => CasterClip::PLAYING]);
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

        $stale = CasterClip::query()->where('status', CasterClip::PLAYING)->where('updated_at', '<', $now->subMinutes(self::PLAYING_STALE_MINUTES))->get();
        foreach ($stale as $clip) {
            $this->delete($clip);
            $counts['stale']++;
        }

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
}
