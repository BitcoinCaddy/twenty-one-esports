<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The station sound (plan "TWENTY ONE Radio", P4): stingers before a news
 * block or a segment and the news bed under a bulletin, all synthesized by
 * scripts/make-idents.sh (own work, CC0, resources/audio/idents/LICENSES.md).
 *
 * The stream has one voice input (VoiceFeed, mono PCM at
 * `tv.commentary.mix.sample_rate`), and the music is ducked under whatever
 * comes through it: so an ident is not a second track but part of the clip.
 * dress() puts the stinger of the clip's kind (`kinds`, a kind to a slot of
 * `stingers`) in front of its speech, `pause_seconds` apart, and lays the bed
 * under the speech of a kind in `bed_kinds`; the sentence ends move with the
 * speech, so a takeover still cuts at a sentence end.
 *
 * Fails open towards the speech: a missing or undecodable ident, or a bed mix
 * that fails, leaves the clip as it was (logged), the speech is never lost
 * for its decoration. In quiet hours the stingers stay (short, at the voice's
 * level) and the bed is left out unless `bed_at_night`.
 */
final class Idents
{
    /**
     * @param  array{enabled?: bool, dir?: string, stingers?: array<string, string>, kinds?: array<string, string>, bed?: string, bed_kinds?: list<string>, bed_gain_db?: float|int, bed_fade_seconds?: float|int, bed_at_night?: bool, pause_seconds?: float|int}  $settings
     */
    public function __construct(
        private readonly array $settings,
        public readonly int $rate = 24000,
    ) {}

    /** The idents of tv.commentary.idents, or null while they are switched off. */
    public static function fromConfig(): ?self
    {
        /** @var array{enabled?: bool, dir?: string, stingers?: array<string, string>, kinds?: array<string, string>, bed?: string, bed_kinds?: list<string>, bed_gain_db?: float|int, bed_fade_seconds?: float|int, bed_at_night?: bool, pause_seconds?: float|int} $settings */
        $settings = (array) config('tv.commentary.idents', []);

        return ($settings['enabled'] ?? false) === true ? new self($settings, (int) config('tv.commentary.mix.sample_rate', 24000)) : null;
    }

    /** The file of a stinger slot (news, ident, breaking …), or null when the slot is unknown or its file is missing. */
    public function stingerFor(string $slot): ?string
    {
        $file = $this->settings['stingers'][$slot] ?? null;

        return is_string($file) ? $this->existing($file) : null;
    }

    /** The stinger slot a clip of `$kind` opens with, or null (no stinger). */
    public function slotFor(string $kind): ?string
    {
        $slot = $this->settings['kinds'][$kind] ?? null;

        return is_string($slot) && $slot !== '' ? $slot : null;
    }

    /** The news bed file, or null when unset or missing. */
    public function bed(): ?string
    {
        $file = $this->settings['bed'] ?? null;

        return is_string($file) ? $this->existing($file) : null;
    }

    /** Whether a clip of `$kind` gets the bed under its speech at `$now`. */
    public function bedFor(string $kind, CarbonImmutable $now): bool
    {
        if (! in_array($kind, $this->settings['bed_kinds'] ?? [], true)) {
            return false;
        }

        return ($this->settings['bed_at_night'] ?? false) === true || ! QuietHours::active($now);
    }

    /**
     * The clip as it goes on air: the stinger of its kind in front, the bed
     * under its speech where due, and its sentence ends moved with the speech.
     *
     * @param  list<float>  $sentenceEnds  seconds into the clip
     * @param  bool  $withStinger  false: no stinger in front (an imaging jingle opens the clip instead)
     * @return array{pcm: string, ends: list<float>, stinger: string|null, bed: bool}
     */
    public function dress(CasterClip $clip, string $pcm, array $sentenceEnds, CarbonImmutable $now, bool $withStinger = true): array
    {
        $bed = false;

        if ($this->bedFor($clip->kind, $now)) {
            $under = $this->underBed($pcm);

            if ($under !== null) {
                $pcm = $under;
                $bed = true;
            }
        }

        // An imaging jingle in front of the clip (Imaging) takes the stinger's place.
        $slot = $withStinger ? $this->slotFor($clip->kind) : null;
        $stinger = $slot === null ? null : $this->stingerPcm($slot);

        if ($stinger === null) {
            return ['pcm' => $pcm, 'ends' => $sentenceEnds, 'stinger' => null, 'bed' => $bed];
        }

        $intro = $stinger.Pcm::silence(max(0.0, (float) ($this->settings['pause_seconds'] ?? 0.3)), $this->rate);
        $offset = Pcm::seconds($intro, $this->rate);

        return [
            'pcm' => $intro.$pcm,
            'ends' => array_map(fn (float $end): float => round($end + $offset, 3), $sentenceEnds),
            'stinger' => $slot,
            'bed' => $bed,
        ];
    }

    /** A stinger as PCM (s16le mono at the voice's rate), decoded by ffmpeg; null when it is missing or fails. */
    public function stingerPcm(string $slot): ?string
    {
        $file = $this->stingerFor($slot);

        if ($file === null) {
            if ($slot !== '') {
                Log::warning('Caster ident missing', ['slot' => $slot]);
            }

            return null;
        }

        $result = Process::timeout(10)->env(ChildEnvironment::withoutSecrets())->run([
            $this->ffmpeg(), '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $file, '-ac', '1', '-ar', (string) $this->rate, '-f', 's16le', 'pipe:1',
        ]);

        if (! $result->successful() || $result->output() === '') {
            Log::warning('Caster ident failed', ['slot' => $slot]);

            return null;
        }

        $pcm = $result->output();

        return substr($pcm, 0, strlen($pcm) - strlen($pcm) % Pcm::BYTES_PER_SAMPLE);
    }

    /**
     * The speech with the bed looped under it, `bed_gain_db` from the file's
     * own level (-28 LUFS against the voice's -16), faded in and out over
     * `bed_fade_seconds`, exactly as long as the speech; null when it fails.
     */
    public function underBed(string $pcm): ?string
    {
        $bed = $this->bed();
        $seconds = Pcm::seconds($pcm, $this->rate);

        if ($bed === null || $seconds <= 0) {
            return null;
        }

        $fade = min((float) ($this->settings['bed_fade_seconds'] ?? 1.5), $seconds / 2);
        $filter = sprintf(
            '[1:a]aresample=%d,aformat=sample_fmts=s16:channel_layouts=mono,volume=%.1FdB,atrim=duration=%.3F,afade=t=in:d=%.3F,afade=t=out:st=%.3F:d=%.3F[bed];'
            .'[0:a][bed]amix=inputs=2:duration=first:dropout_transition=0:normalize=0[out]',
            $this->rate, (float) ($this->settings['bed_gain_db'] ?? 0), $seconds, $fade, max(0.0, $seconds - $fade), $fade,
        );

        $result = Process::timeout(20)->env(ChildEnvironment::withoutSecrets())->input($pcm)->run([
            $this->ffmpeg(), '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 's16le', '-ar', (string) $this->rate, '-ac', '1', '-i', 'pipe:0',
            '-stream_loop', '-1', '-i', $bed,
            '-filter_complex', $filter, '-map', '[out]', '-ac', '1', '-ar', (string) $this->rate, '-f', 's16le', 'pipe:1',
        ]);

        $out = $result->successful() ? $result->output() : '';

        // Never longer or shorter than the speech: the sentence ends stay where they were.
        if (abs(strlen($out) - strlen($pcm)) > $this->rate * Pcm::BYTES_PER_SAMPLE / 10) {
            Log::warning('Caster news bed failed', ['bytes' => strlen($out), 'expected' => strlen($pcm)]);

            return null;
        }

        return substr(str_pad($out, strlen($pcm), "\0"), 0, strlen($pcm));
    }

    private function existing(string $file): ?string
    {
        $path = rtrim((string) ($this->settings['dir'] ?? resource_path('audio/idents')), '/').'/'.basename($file);

        return is_file($path) ? $path : null;
    }

    private function ffmpeg(): string
    {
        return (string) config('twentyone.stream.ffmpeg', 'ffmpeg');
    }
}
