<?php

namespace App\Support\Caster;

use App\Support\Caster\Blocks\BlockFetcher;
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
 * Turm's machine): claims the next script from the server, voices it in
 * chunks of two or three sentences (chunks()) with the local VoiceStudio
 * (`voicestudio.profiles`, a tempo per speaker), trims each chunk to its
 * speech with at least `pad_ms` of quiet left at both ends (Pcm::trimEdges()),
 * puts real pauses between the chunks (≈0.2 s, ≈0.35 s after ! ? : or a
 * marker, 0.45 to 0.7 s on a speaker change, slightly random),
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
        $claim = $this->claim();

        if ($claim === null) {
            return self::OFFLINE;
        }

        $script = $claim->json('script');

        if (! is_array($script) || ! is_int($script['id'] ?? null)) {
            return $claim->json('full') === true ? self::FULL : self::IDLE;
        }

        /** @var array<string, string> $terms */
        $terms = array_filter((array) $claim->json('pronunciation', []), 'is_string');

        // Programme blocks (plan "TWENTY ONE Radio", P6): everything but a live moment or a chat reaction is rendered
        // into a block of several scripts with jingles; those two are still voiced alone, to play next.
        if (config('tv.commentary.blocks.enabled') === true && ! in_array($script['kind'] ?? null, ClipSpool::LIVE_KINDS, true)) {
            return app(BlockFetcher::class)->run($this, $script, $terms, (float) $claim->json('spool_seconds', 0));
        }

        return $this->voiceAndUpload($script, $terms);
    }

    /**
     * One claim call: the server's answer, or null when it is unreachable or refuses. `$urgent`: only a live moment,
     * a reaction, a wish or a bulletin (asked while a block is being rendered).
     */
    public function claim(bool $urgent = false): ?Response
    {
        try {
            $claim = $this->server()->post($this->settings()['server'].'/api/caster/claim'.($urgent ? '?only=urgent' : ''));
        } catch (ConnectionException) {
            return null;
        }

        return $claim->successful() ? $claim : null;
    }

    /**
     * The sentences and their speakers of a claimed script (the claim's `script`), Markus for a line without one;
     * `duet` when the server named speakers.
     *
     * @param  array<array-key, mixed>  $script
     * @return array{sentences: list<string>, speakers: list<string>, duet: bool}
     */
    public static function lines(array $script): array
    {
        $given = is_array($script['speakers'] ?? null) ? array_values($script['speakers']) : [];
        [$sentences, $speakers] = [[], []];

        foreach (array_values((array) ($script['sentences'] ?? [])) as $index => $line) {
            if (is_string($line) && trim($line) !== '') {
                $sentences[] = $line;
                $speakers[] = is_string($given[$index] ?? null) ? $given[$index] : DuetDirector::MARKUS;
            }
        }

        return ['sentences' => $sentences, 'speakers' => $speakers, 'duet' => $given !== []];
    }

    /**
     * Voices one claimed script alone and uploads its clip (a live moment, a
     * chat reaction, or every script while blocks are off).
     *
     * @param  array<array-key, mixed>  $script
     * @param  array<string, string>  $terms
     */
    public function voiceAndUpload(array $script, array $terms): string
    {
        $settings = $this->settings();
        $id = (int) $script['id'];
        ['sentences' => $sentences, 'speakers' => $speakers, 'duet' => $duet] = self::lines($script);

        $voiced = $sentences === [] ? null : $this->voice($sentences, $terms, $duet ? $speakers : null);
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
     * The sentences in chunks of one thought for one /generate call each: at
     * most `chunk.max_sentences` sentences and `chunk.max_words` spoken words.
     * A sentence led by a marker ("[laughter] …") starts a chunk, and one that
     * ends on a marker ("… [laughter]") ends it, so the sound sits at the
     * chunk's edge. One sentence per call made the voice choppy (user
     * 2026-10-11: "Und er spricht zu abgehackt"): every call trimmed its tail
     * and got a pause, the model's own prosody never carried a sentence into
     * the next.
     *
     * @param  list<string>  $sentences
     * @return list<list<string>>
     */
    public static function chunks(array $sentences, int $maxSentences, int $maxWords): array
    {
        $chunks = [];
        $current = [];
        $words = 0;
        $markerLed = '/^\s*\[[a-z-]+\]/u';

        foreach ($sentences as $sentence) {
            $count = count(preg_split('/\s+/u', ScriptGuard::withoutMarkers($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($current !== [] && (preg_match($markerLed, $sentence) === 1 || count($current) >= $maxSentences || $words + $count > $maxWords)) {
                $chunks[] = $current;
                [$current, $words] = [[], 0];
            }

            $current[] = $sentence;
            $words += $count;

            if (preg_match('/\[[a-z-]+\]\s*$/u', $sentence) === 1) {
                $chunks[] = $current;
                [$current, $words] = [[], 0];
            }
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * The sentences voiced chunk by chunk (chunks()), joined with pauses
     * between the chunks only, in memory. `ends` are the chunk ends.
     *
     * A duet (`$speakers`, one per sentence, DuetDirector): a chunk never
     * spans two speakers, each is voiced with its speaker's profile
     * (`voicestudio.profiles`, Markus' `profile_id` for a speaker without
     * one), and a speaker change gets a breath of `speaker_pause` seconds.
     *
     * @param  list<string>  $sentences
     * @param  array<string, string>  $terms  the pronunciation dictionary
     * @param  list<string>|null  $speakers  the speaker of each sentence; null: Markus alone
     * @return array{pcm: string, rate: int, ends: list<float>}|null null when VoiceStudio failed past its retries
     */
    public function voice(array $sentences, array $terms = [], ?array $speakers = null): ?array
    {
        $settings = $this->settings();
        $pause = (array) $settings['pause'];
        $speakerPause = (array) ($settings['speaker_pause'] ?? []);
        $chunks = $this->plannedChunks($sentences, $speakers);
        $pcm = '';
        $rate = null;
        $ends = [];

        foreach ($chunks as $index => [$speaker, $lines]) {
            $sentence = (string) end($lines);
            $voiced = $this->voiceChunk($lines, $speaker, $terms);

            if ($voiced === null || ($rate !== null && $voiced['rate'] !== $rate)) {
                return null;
            }

            $rate = $voiced['rate'];
            $pcm .= $voiced['pcm'];
            $ends[] = round(Pcm::seconds($pcm, $rate), 3);

            if ($index < count($chunks) - 1 && $chunks[$index + 1][0] !== $speaker) {
                $min = (float) ($speakerPause['min'] ?? 0.45);
                $pcm .= Pcm::silence(max(0.1, $min + max(0.0, (float) ($speakerPause['max'] ?? 0.7) - $min) * (mt_rand(0, 1000) / 1000)), $rate);
            } elseif ($index < count($chunks) - 1) {
                $base = ScriptGuard::accented($sentence) ? (float) ($pause['accent'] ?? 0.35) : (float) ($pause['sentence'] ?? 0.2);
                $jitter = (float) ($pause['jitter'] ?? 0.0);
                $pcm .= Pcm::silence(max(0.1, $base + $jitter * (mt_rand(-1000, 1000) / 1000)), $rate);
            }
        }

        return $rate === null || $pcm === '' ? null : ['pcm' => $pcm, 'rate' => $rate, 'ends' => $ends];
    }

    /**
     * One chunk (the lines of one speaker for one /generate call) in the
     * speaker's voice and tempo, at the common speech level (Sirius' clone
     * was much louder than Markus', user 2026-10-12; `levels` fine-tunes a
     * speaker in dB) and trimmed to its speech. null when VoiceStudio failed
     * past its retries or answered no WAV.
     *
     * @param  list<string>  $lines
     * @param  array<string, string>  $terms
     * @return array{pcm: string, rate: int}|null
     */
    public function voiceChunk(array $lines, string $speaker, array $terms = [], ?string $profileId = null, ?float $speed = null): ?array
    {
        $settings = $this->settings();
        $wav = $this->generate(Pronunciation::spoken(implode(' ', $lines), $terms), $profileId ?? $this->profile($speaker), $speed ?? $this->speed($speaker));

        if ($wav === null) {
            return null;
        }

        try {
            ['pcm' => $part, 'rate' => $rate] = Pcm::fromWav($wav);
        } catch (InvalidArgumentException) {
            return null;
        }

        $level = (float) ($settings['speech_dbfs'] ?? -20.0) + (float) ($settings['levels'][$speaker] ?? 0.0);

        return ['pcm' => Pcm::trimEdges(Pcm::levelled($part, $level), $rate, (int) ($settings['silence_threshold'] ?? 150), (int) ($settings['pad_ms'] ?? 140), (int) ($settings['fade_ms'] ?? 100)), 'rate' => $rate];
    }

    /**
     * The chunks of a script in speaking order: consecutive sentences of one
     * speaker form a run, and the chunks (chunks()) are cut within a run only.
     *
     * @param  list<string>  $sentences
     * @param  list<string>|null  $speakers  null: Markus alone
     * @return list<array{0: string, 1: list<string>}> speaker and lines of each chunk
     */
    public function plannedChunks(array $sentences, ?array $speakers = null): array
    {
        $chunk = (array) ($this->settings()['chunk'] ?? []);
        $runs = [];

        foreach ($sentences as $index => $sentence) {
            $speaker = $speakers[$index] ?? DuetDirector::MARKUS;
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][0] === $speaker) {
                $runs[$last][1][] = $sentence;
            } else {
                $runs[] = [$speaker, [$sentence]];
            }
        }

        $chunks = [];

        foreach ($runs as [$speaker, $lines]) {
            foreach (self::chunks($lines, max(1, (int) ($chunk['max_sentences'] ?? 3)), max(1, (int) ($chunk['max_words'] ?? 25))) as $part) {
                $chunks[] = [$speaker, $part];
            }
        }

        return $chunks;
    }

    /**
     * Loudness to -16 LUFS and Opus at speech bitrate, ffmpeg from stdin to
     * stdout (no file). null when ffmpeg fails.
     */
    public function encode(string $pcm, int $rate): ?string
    {
        $settings = $this->settings();

        try {
            // A programme block of six minutes takes a few seconds; a single clip well under one.
            $result = Process::timeout(240)->env(ChildEnvironment::withoutSecrets())->input($pcm)->run([
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
     * One sentence from VoiceStudio as WAV bytes in the voice of
     * `$profileId` at `$speed`, retried on connection errors and 5xx; its
     * history entry deleted right after.
     */
    public function generate(string $text, string $profileId, float $speed, ?string $language = null): ?string
    {
        $settings = $this->settings();
        $attempts = max(1, (int) $settings['retries'] + 1);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::asMultipart()->timeout((int) $settings['timeout_seconds'])->post(rtrim((string) $settings['url'], '/').'/generate', [
                    'text' => $text,
                    'profile_id' => $profileId,
                    'speed' => (string) $speed,
                    'language' => $language ?? (string) $settings['language'],
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

    /** The VoiceStudio profile of a speaker (`voicestudio.profiles`); Markus' `profile_id` for one without a profile. */
    private function profile(string $speaker): string
    {
        $settings = $this->settings();
        $profile = ((array) ($settings['profiles'] ?? []))[$speaker] ?? null;

        return is_string($profile) && trim($profile) !== '' ? trim($profile) : (string) $settings['profile_id'];
    }

    /** The tempo of a speaker (`voicestudio.speeds`), Markus' `speed` for one without its own. */
    private function speed(string $speaker): float
    {
        $settings = $this->settings();
        $speed = ((array) ($settings['speeds'] ?? []))[$speaker] ?? null;

        return is_numeric($speed) ? (float) $speed : (float) ($settings['speed'] ?? 1.0);
    }

    public function release(int $id): void
    {
        try {
            $this->server()->post($this->settings()['server'].'/api/caster/scripts/'.$id.'/release');
        } catch (ConnectionException) {
            // The server frees an unanswered claim after `claim_seconds` anyway.
        }
    }

    public function server(): PendingRequest
    {
        return Http::withToken((string) config('tv.commentary.fetch.token'))->acceptJson()->timeout(30);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = (array) config('tv.commentary.voicestudio', []);
        $settings['server'] = rtrim((string) ($settings['server'] ?? ''), '/');

        return $settings;
    }
}
