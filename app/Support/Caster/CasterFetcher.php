<?php

namespace App\Support\Caster;

use App\Support\TwentyOne\Stream\ChildEnvironment;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Throwable;

/**
 * The workstation side of TWENTY ONE TV (`php artisan caster:fetch` on Markus
 * Turm's machine): claims the next script from the server, voices it sentence
 * by sentence with the local VoiceStudio (profile `ee7a2a18`, tempo 1.0),
 * trims each sentence's quiet tail (+70 ms fade), puts real pauses between
 * the sentences (≈0.3 s, ≈0.5 s after ! ? : or a marker, slightly random),
 * normalises to -16 LUFS and encodes Opus with ffmpeg (stdin to stdout), and
 * uploads the clip.
 *
 * No audio ever lands on the workstation's disk (user 2026-10-11: "Mein PC
 * soll natürlich nicht zugemüllt werden mit lauter Audiodaten"): VoiceStudio's
 * answer, the trimmed PCM and the Opus clip stay in memory, and every
 * generation's VoiceStudio history entry (VoiceStudio keeps each /generate,
 * GET /history) is deleted by the id VoiceStudio named for it (`x-audio-id`),
 * never any other.
 *
 * VoiceStudio hiccups (it crashed once on 2026-10-11) are retried
 * `retries` times with a growing wait; after that the script goes back to the
 * server's queue (release) and the fetcher waits.
 */
class CasterFetcher
{
    public const VOICED = 'voiced';

    public const IDLE = 'idle';

    public const FULL = 'full';

    public const RELEASED = 'released';

    public const OFFLINE = 'offline';

    /** @var Closure(float): void */
    private Closure $sleep;

    /**
     * @param  (Closure(float): void)|null  $sleep
     */
    public function __construct(?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    public function runOnce(): string
    {
        $settings = $this->settings();

        try {
            $claim = $this->server()->post($settings['server'].'/api/caster/claim');
        } catch (ConnectionException) {
            return self::OFFLINE;
        }

        if (! $claim->successful()) {
            return self::OFFLINE;
        }

        $script = $claim->json('script');

        if (! is_array($script) || ! is_int($script['id'] ?? null)) {
            return $claim->json('full') === true ? self::FULL : self::IDLE;
        }

        $id = $script['id'];
        $sentences = array_values(array_filter((array) ($script['sentences'] ?? []), fn (mixed $line): bool => is_string($line) && trim($line) !== ''));
        $terms = array_filter((array) $claim->json('pronunciation', []), 'is_string');

        $voiced = $sentences === [] ? null : $this->voice($sentences, $terms);
        $opus = $voiced === null ? null : $this->encode($voiced['pcm'], $voiced['rate']);

        if ($voiced === null || $opus === null) {
            $this->release($id);

            return self::RELEASED;
        }

        try {
            $upload = $this->server()
                ->withHeaders([
                    'X-Caster-Duration' => sprintf('%.3F', Pcm::seconds($voiced['pcm'], $voiced['rate'])),
                    'X-Caster-Sentence-Ends' => implode(',', array_map(fn (float $end): string => sprintf('%.3F', $end), $voiced['ends'])),
                ])
                ->withBody($opus, 'audio/ogg')
                ->post($settings['server'].'/api/caster/scripts/'.$id.'/clip');
        } catch (ConnectionException) {
            $this->release($id);

            return self::OFFLINE;
        }

        if ($upload->status() === 201) {
            return self::VOICED;
        }

        $this->release($id);

        return $upload->status() === 507 ? self::FULL : self::RELEASED;
    }

    /**
     * The sentences voiced one by one, joined with pauses, in memory.
     *
     * @param  list<string>  $sentences
     * @param  array<string, string>  $terms  the pronunciation dictionary
     * @return array{pcm: string, rate: int, ends: list<float>}|null null when VoiceStudio failed past its retries
     */
    public function voice(array $sentences, array $terms = []): ?array
    {
        $settings = $this->settings();
        $pause = (array) $settings['pause'];
        $pcm = '';
        $rate = null;
        $ends = [];

        foreach ($sentences as $index => $sentence) {
            $wav = $this->generate(Pronunciation::spoken($sentence, $terms));

            if ($wav === null) {
                return null;
            }

            try {
                ['pcm' => $part, 'rate' => $partRate] = Pcm::fromWav($wav);
            } catch (InvalidArgumentException) {
                return null;
            }

            if ($rate !== null && $partRate !== $rate) {
                return null;
            }

            $rate = $partRate;
            $pcm .= Pcm::trimTail($part, $rate, (int) $settings['silence_threshold'], (int) $settings['fade_ms']);
            $ends[] = round(Pcm::seconds($pcm, $rate), 3);

            if ($index < count($sentences) - 1) {
                $base = ScriptGuard::accented($sentence) ? (float) ($pause['accent'] ?? 0.5) : (float) ($pause['sentence'] ?? 0.3);
                $jitter = (float) ($pause['jitter'] ?? 0.0);
                $pcm .= Pcm::silence(max(0.1, $base + $jitter * (mt_rand(-1000, 1000) / 1000)), $rate);
            }
        }

        return $rate === null || $pcm === '' ? null : ['pcm' => $pcm, 'rate' => $rate, 'ends' => $ends];
    }

    /**
     * Loudness to -16 LUFS and Opus at speech bitrate, ffmpeg from stdin to
     * stdout (no file). null when ffmpeg fails.
     */
    public function encode(string $pcm, int $rate): ?string
    {
        $settings = $this->settings();

        try {
            $result = Process::timeout(60)->env(ChildEnvironment::withoutSecrets())->input($pcm)->run([
                (string) $settings['ffmpeg'], '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-f', 's16le', '-ar', (string) $rate, '-ac', '1', '-i', 'pipe:0',
                '-af', 'loudnorm=I='.(int) $settings['loudness_lufs'].':TP=-1.5:LRA=11',
                '-ar', '48000', '-c:a', 'libopus', '-b:a', ((int) $settings['opus_kbps']).'k', '-application', 'voip',
                '-f', 'ogg', 'pipe:1',
            ]);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() && str_starts_with($result->output(), 'OggS') ? $result->output() : null;
    }

    /**
     * One sentence from VoiceStudio as WAV bytes, retried on connection
     * errors and 5xx; its history entry deleted right after.
     */
    private function generate(string $text): ?string
    {
        $settings = $this->settings();
        $attempts = max(1, (int) $settings['retries'] + 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::asMultipart()->timeout((int) $settings['timeout_seconds'])->post(rtrim((string) $settings['url'], '/').'/generate', [
                    'text' => $text,
                    'profile_id' => (string) $settings['profile_id'],
                    'speed' => (string) $settings['speed'],
                    'language' => (string) $settings['language'],
                ]);

                $this->forget($response);

                if ($response->successful() && str_starts_with($response->body(), 'RIFF')) {
                    return $response->body();
                }

                if ($response->clientError()) {
                    return null;
                }
            } catch (ConnectionException) {
                // VoiceStudio is restarting or busy: wait and try again.
            }

            if ($attempt < $attempts) {
                ($this->sleep)((float) $settings['retry_seconds'] * $attempt);
            }
        }

        return null;
    }

    /** Deletes the history entry VoiceStudio named for this generation, and only that one. */
    private function forget(Response $response): void
    {
        $id = (string) $response->header('x-audio-id');

        if (preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id) !== 1) {
            return;
        }

        try {
            Http::timeout(10)->delete(rtrim((string) $this->settings()['url'], '/').'/history/'.$id);
        } catch (ConnectionException) {
            // Left in VoiceStudio's history (its own retention cap still applies).
        }
    }

    private function release(int $id): void
    {
        try {
            $this->server()->post($this->settings()['server'].'/api/caster/scripts/'.$id.'/release');
        } catch (ConnectionException) {
            // The server frees an unanswered claim after `claim_seconds` anyway.
        }
    }

    private function server(): PendingRequest
    {
        return Http::withToken((string) config('tv.commentary.fetch.token'))->acceptJson()->timeout(30);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $settings = (array) config('tv.commentary.voicestudio', []);
        $settings['server'] = rtrim((string) ($settings['server'] ?? ''), '/');

        return $settings;
    }
}
