<?php

namespace App\Console\Commands;

use App\Support\Caster\AtomicFile;
use App\Support\Caster\Blocks\JingleSynth;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\Pcm;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * The Anchor's jingle library for TWENTY ONE ESPORTS RADIO (plan "TWENTY
 * ONE Radio", P6; user 2026-10-12: "Benutze die Stimme 'The Anchor' aus dem
 * VoiceStudio, um … verschiedenste Jingles … vorzurendern … Hohe Varianz"),
 * run on the workstation next to the fetcher:
 *
 *  1. the VoiceStudio profile "The Anchor (TWENTY ONE)" from the personality
 *     `us_news_anchor` (a designed voice: male, middle-aged, American accent,
 *     no person's clone), made once and found by its name afterwards
 *     (`blocks.anchor`, TV_ANCHOR_PROFILE pins one);
 *  2. one VoiceStudio call per line of resources/data/voice/anchor-jingles.json
 *     whose voice is not on disk yet or was made for another spoken text,
 *     length or profile (voice/<id>.wav, the source, with its hash in
 *     voice/<id>.json), at most `--limit` calls, `--pause` seconds apart so
 *     the fetcher's voicing never starves; no ASR. English only, the line's
 *     `spoken` text ("E. Sports." for ESPORTS), slow and dramatic: the
 *     `duration` VoiceStudio is asked for is `anchor.seconds_per_word` per
 *     word plus `anchor.sentence_pause_seconds` per sentence (user
 *     2026-10-12: "viel viel viel langsamer und dramatischer"; `speed` had no
 *     audible effect, 2.58 s at 0.75);
 *  3. two variants of every line over the self-made music (JingleSynth:
 *     bed, key, voice treatment, intro from a hash of the id), ducked,
 *     -16 LUFS, limited, Ogg Opus (anchor/<id>-a.opus, -b.opus), a jingle
 *     already rendered from its voice kept (resumable; --remix renders anew);
 *  4. the speakers' blips ("hm", "ja", "stimmt", a laugh) with their own
 *     profiles, at the speech level, at most 1.6 s (blips/<speaker>/*.wav);
 *  5. manifest.json with every jingle's line, category, file, length and
 *     measured loudness; files of lines no longer in the list are deleted;
 *  6. the library goes to the server as its imaging (caster:imaging-upload,
 *     P11), unless --no-upload.
 *
 * Radio imaging (P11; user 2026-10-12: "aufregendere Jingles, die viel
 * länger sind vom ANCHOR, und mehr unterschiedliche Jingles und Stinger und
 * Betten"): the lines of resources/data/voice/anchor-imaging.json are voiced
 * the same way (their category's tempo on the slow default length,
 * JingleSynth::IMAGING) and produced once each (imaging/<id>.opus): an
 * opening stinger, the voice, a closing hit, a bed of the category or of the
 * format (`imaging.format_beds`) ducked under it, -16 LUFS, peaks at -1 dBFS.
 * The stingers and beds are files of resources/audio/idents (sfx-*.opus,
 * bed-*.opus), rendered by --sounds from JingleSynth (own work, CC0), and
 * so are the studio's applause and the talk show's theme (applause-*.opus,
 * theme-talk-*.opus, P20; BlockRenderer mixes them in on the workstation).
 * --lines renders only the named line ids (a probe), keeping the rest of the
 * library as it is.
 *
 * Everything stays in `blocks.jingles.dir` (storage/app/jingles): bounded by
 * the list (about 100 small files, a few MB), never in git.
 */
#[Signature('caster:jingles
    {--limit=260 : VoiceStudio calls at most in this run (the whole library: 74 block lines, 126 imaging lines, the blips)}
    {--pause=6 : Seconds between two VoiceStudio calls}
    {--no-blips : Leave the speakers\' blips out}
    {--mix-only : Render the jingles from the voices on disk, no VoiceStudio call}
    {--remix : Render every jingle again, also those already on disk}
    {--no-upload : Do not upload the library to the server as its imaging (caster:imaging-upload)}
    {--lines= : Voice and render only these line ids (comma separated), keep the rest as it is}
    {--sounds : Render the stingers and beds (JingleSynth) into resources/audio/idents and stop}')]
#[Description('Render the Anchor jingles and the speakers\' blips for the programme blocks (workstation)')]
class CasterJinglesCommand extends Command
{
    private int $calls = 0;

    public function handle(CasterFetcher $fetcher): int
    {
        $this->calls = 0;
        $settings = (array) config('tv.commentary.blocks', []);
        $dir = rtrim((string) ($settings['jingles']['dir'] ?? storage_path('app/jingles')), '/');
        $list = json_decode((string) file_get_contents(resource_path('data/voice/anchor-jingles.json')), true);
        $imaging = is_file(resource_path('data/voice/anchor-imaging.json')) ? json_decode((string) file_get_contents(resource_path('data/voice/anchor-imaging.json')), true) : null;
        $limit = max(0, (int) $this->option('limit'));
        $mixOnly = (bool) $this->option('mix-only');
        $only = is_string($this->option('lines')) && $this->option('lines') !== '' ? array_flip(array_map('trim', explode(',', $this->option('lines')))) : null;

        if ($this->option('sounds')) {
            return $this->renderSounds(resource_path('audio/idents'));
        }

        if (! is_array($list) || ! is_array($list['lines'] ?? null)) {
            $this->error('resources/data/voice/anchor-jingles.json is not readable.');

            return self::FAILURE;
        }

        $lines = [...$list['lines'], ...array_map(fn ($line): mixed => is_array($line) ? $line + ['imaging' => true] : $line, (array) (is_array($imaging) ? ($imaging['lines'] ?? []) : []))];

        foreach (['', '/voice', '/anchor', '/blips', '/imaging'] as $sub) {
            if (! is_dir($dir.$sub)) {
                mkdir($dir.$sub, 0755, true);
            }
        }

        $profile = $mixOnly ? null : $this->anchorProfile($fetcher, (array) ($settings['anchor'] ?? []));

        if (! $mixOnly && $profile === null) {
            $this->error('No Anchor profile in VoiceStudio and none could be made.');

            return self::FAILURE;
        }

        $items = [];
        $keep = [];

        foreach ($lines as $line) {
            if (! is_array($line) || ! is_string($line['id'] ?? null) || ! is_string($line['text'] ?? null) || ! is_string($line['category'] ?? null)) {
                continue;
            }

            $id = $line['id'];
            $voice = $dir.'/voice/'.$id.'.wav';
            $spoken = is_string($line['spoken'] ?? null) && trim($line['spoken']) !== '' ? $line['spoken'] : $line['text'];
            $isImaging = ($line['imaging'] ?? false) === true;
            $recipe = $isImaging ? JingleSynth::imagingRecipe($id, $line['category'], (array) config('tv.commentary.imaging.format_beds', [])) : null;
            $duration = $recipe === null ? self::duration($spoken) : round(self::duration($spoken) * $recipe['tempo'], 1);
            $hash = $profile === null ? null : self::voiceHash($spoken, $duration, $profile, (string) ($line['language'] ?? 'en'));
            $selected = $only === null || isset($only[$id]);
            array_push($keep, 'voice/'.$id.'.wav', 'voice/'.$id.'.json');

            if ($hash !== null && $selected && $this->calls < $limit && (! is_file($voice) || self::storedHash($dir, $id) !== $hash)) {
                $wav = $this->generate($fetcher, $spoken, $profile, (float) ($settings['anchor']['speed'] ?? 1.0), (string) ($line['language'] ?? 'en'), $duration);

                if ($wav !== null) {
                    AtomicFile::put($voice, $wav);
                    AtomicFile::put($dir.'/voice/'.$id.'.json', (string) json_encode(['hash' => $hash, 'spoken' => $spoken, 'duration' => $duration], JSON_UNESCAPED_UNICODE));
                }
            }

            if (! is_file($voice)) {
                $this->warn('no voice yet: '.$id);

                continue;
            }

            if ($recipe !== null) {
                $item = $this->produce($dir, $id, $recipe, $voice, $selected);

                if ($item !== null) {
                    $items[] = $item + ['line' => $id, 'category' => $line['category'], 'text' => $line['text'], 'spoken' => $spoken, 'voice_hash' => self::storedHash($dir, $id)];
                    $keep[] = $item['file'];
                }

                continue;
            }

            foreach (['a', 'b'] as $variant) {
                $item = $this->render($dir, $id, $variant, $line['category'], $voice);

                if ($item !== null) {
                    $items[] = $item + ['line' => $id, 'category' => $line['category'], 'text' => $line['text'], 'spoken' => $spoken, 'voice_hash' => self::storedHash($dir, $id)];
                    $keep[] = $item['file'];
                }
            }
        }

        $blips = [];

        if (! $this->option('no-blips')) {
            foreach ((array) ($list['blips'] ?? []) as $speaker => $texts) {
                foreach (array_values((array) $texts) as $number => $text) {
                    $file = 'blips/'.$speaker.'/'.sprintf('%02d', $number + 1).'.wav';
                    $keep[] = $file;

                    if (! is_file($dir.'/'.$file) && ! $mixOnly && $this->calls < $limit && is_string($text)) {
                        $this->blip($fetcher, (string) $speaker, $text, $dir.'/'.$file);
                    }

                    if (is_file($dir.'/'.$file)) {
                        $blips[] = ['speaker' => $speaker, 'text' => $text, 'file' => $file];
                    }
                }
            }
        }

        $this->prune($dir, $keep);

        AtomicFile::put($dir.'/manifest.json', (string) json_encode([
            'about' => 'TWENTY ONE ESPORTS RADIO jingles: voice "The Anchor" (VoiceStudio personality us_news_anchor, a designed voice, no person\'s clone), music own work CC0 (App\Support\Caster\Blocks\JingleSynth). Rendered by php artisan caster:jingles.',
            'generated_at' => now()->toIso8601String(),
            'profile_id' => $profile,
            'items' => $items,
            'blips' => $blips,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf('%d jingles of %d lines, %d blips, %d VoiceStudio calls; manifest in %s', count($items), count($lines), count($blips), $this->calls, $dir));

        // The server plays the same jingles as its imaging (P11): the nightly run hands them over itself.
        if (! $this->option('no-upload') && $items !== []) {
            $this->call('caster:imaging-upload', ['--dir' => $dir]);
        }

        return self::SUCCESS;
    }

    /**
     * The Anchor's profile id: pinned, found by name, or made from the personality.
     *
     * @param  array<string, mixed>  $anchor
     */
    private function anchorProfile(CasterFetcher $fetcher, array $anchor): ?string
    {
        if (is_string($anchor['profile_id'] ?? null) && $anchor['profile_id'] !== '') {
            return $anchor['profile_id'];
        }

        $url = rtrim((string) $fetcher->settings()['url'], '/');
        $name = (string) ($anchor['name'] ?? 'The Anchor (TWENTY ONE)');

        try {
            $profiles = Http::timeout(20)->get($url.'/profiles')->json();
            $profiles = is_array($profiles) && array_is_list($profiles) ? $profiles : (array) ($profiles['profiles'] ?? []);

            foreach ($profiles as $profile) {
                if (is_array($profile) && ($profile['name'] ?? null) === $name && is_string($profile['id'] ?? null)) {
                    return $profile['id'];
                }
            }

            $created = Http::asMultipart()->timeout(180)->post($url.'/profiles', [
                'name' => $name,
                'kind' => 'design',
                'instruct' => (string) ($anchor['instruct'] ?? 'male, middle-aged, moderate pitch, american accent'),
                'personality' => (string) ($anchor['personality'] ?? 'us_news_anchor'),
                'language' => 'Auto',
                'seed' => (string) (int) ($anchor['seed'] ?? 2121),
                'vd_states' => json_encode(['Age' => 'middle-aged', 'ChineseDialect' => 'Auto', 'EnglishAccent' => 'american accent', 'Gender' => 'male', 'Pitch' => 'moderate pitch', 'Style' => 'Auto']),
            ]);
            $this->calls++;
        } catch (ConnectionException) {
            return null;
        }

        $id = $created->json('id') ?? $created->json('profile.id');
        $this->line('made VoiceStudio profile '.$name.': '.(is_string($id) ? $id : 'failed ('.$created->status().')'));

        return is_string($id) ? $id : null;
    }

    /**
     * The length the Anchor is asked to take for `$spoken`: `anchor.seconds_per_word` per word plus
     * `anchor.sentence_pause_seconds` per sentence, to a tenth of a second ("You are listening to TWENTY ONE. Eee.
     * Sports. Radio.": 9 words, 4 sentences, 7.4 s at the defaults; the probe of 2026-10-12 took 7).
     */
    public static function duration(string $spoken): float
    {
        $anchor = (array) config('tv.commentary.blocks.anchor', []);
        $words = count(preg_split('/\s+/u', trim($spoken), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $sentences = max(1, preg_match_all('/[.!?]+(?=\s|$)/u', trim($spoken)));

        return round($words * (float) ($anchor['seconds_per_word'] ?? 0.55) + $sentences * (float) ($anchor['sentence_pause_seconds'] ?? 0.6), 1);
    }

    /** What a voice was made from: a changed spoken text, length, profile or language renders it again. */
    public static function voiceHash(string $spoken, float $duration, string $profile, string $language): string
    {
        return sha1((string) json_encode([$spoken, $duration, $profile, $language], JSON_UNESCAPED_UNICODE));
    }

    private static function storedHash(string $dir, string $id): ?string
    {
        $meta = is_file($dir.'/voice/'.$id.'.json') ? json_decode((string) file_get_contents($dir.'/voice/'.$id.'.json'), true) : null;

        return is_array($meta) && is_string($meta['hash'] ?? null) ? $meta['hash'] : null;
    }

    private function generate(CasterFetcher $fetcher, string $text, string $profile, float $speed, string $language, ?float $duration = null): ?string
    {
        if ($this->calls > 0) {
            sleep(max(0, (int) $this->option('pause')));
        }

        $this->calls++;
        $started = microtime(true);
        $wav = $fetcher->generate($text, $profile, $speed, $language, $duration);
        $this->line(sprintf('voiced %-60s %.1f s', mb_strimwidth($text, 0, 60), microtime(true) - $started));

        return $wav;
    }

    /**
     * One variant of a jingle: the voice over its bed (JingleSynth), measured.
     *
     * @return array{id: string, file: string, seconds: float, lufs: float|null, bed: string, key: float, effect: string}|null
     */
    private function render(string $dir, string $id, string $variant, string $category, string $voice): ?array
    {
        $recipe = JingleSynth::variant($id, $category, $variant === 'a' ? 0 : 1);
        $voiceSeconds = $this->seconds($voice);

        if ($voiceSeconds <= 0) {
            return null;
        }

        $file = 'anchor/'.$id.'-'.$variant.'.opus';

        // Resumable: a jingle already rendered from this voice stays as it is (--remix renders it again).
        if (! $this->option('remix') && is_file($dir.'/'.$file) && filemtime($dir.'/'.$file) >= filemtime($voice)) {
            return ['id' => $id.'-'.$variant, 'file' => $file, 'seconds' => round($this->seconds($dir.'/'.$file), 3), 'lufs' => $this->lufs($dir.'/'.$file)] + $recipe;
        }

        $duration = $recipe['intro'] + $voiceSeconds + JingleSynth::BUTTON_TAIL;
        $bed = JingleSynth::bed($recipe['bed'], $recipe['key'], $recipe['intro'], $voiceSeconds, $duration);
        $ffmpeg = (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg');
        $mix = $dir.'/anchor/.mix-'.$id.'-'.$variant.'.wav';
        $mastered = $dir.'/anchor/.master-'.$id.'-'.$variant.'.wav';
        $run = fn (array $arguments) => Process::timeout(120)->env(ChildEnvironment::withoutSecrets())->run($arguments);

        // Mix, then gain to -16 LUFS and limit, then one correction pass (the limiter costs loudness on a hit).
        $result = $run(JingleSynth::mixArguments($ffmpeg, $voice, $bed, $recipe['effect'], $recipe['intro'], $duration, $mix));
        $measured = $result->successful() ? $this->lufs($mix) : null;
        $result = $measured === null ? $result : $run(JingleSynth::masterArguments($ffmpeg, $mix, -16 - $measured, $mastered, false));
        $measured = $measured !== null && $result->successful() ? $this->lufs($mastered) : null;
        $result = $measured === null ? $result : $run(JingleSynth::masterArguments($ffmpeg, $mastered, -16 - $measured, $dir.'/'.$file, true));

        foreach ([$mix, $mastered] as $temporary) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        if ($measured === null || ! $result->successful() || ! is_file($dir.'/'.$file)) {
            $this->warn('mix failed: '.$id.'-'.$variant.' '.mb_strimwidth($result->errorOutput(), 0, 300));

            return null;
        }

        return ['id' => $id.'-'.$variant, 'file' => $file, 'seconds' => round($this->seconds($dir.'/'.$file), 3), 'lufs' => $this->lufs($dir.'/'.$file)] + $recipe;
    }

    /**
     * An imaging line produced (P11): its stinger, the voice, its hit and bed (JingleSynth::produceArguments()),
     * -16 LUFS, peaks limited at -1 dBFS, Ogg Opus. Kept when already rendered from this voice and recipe;
     * a line not `$selected` (--lines) is never rendered, only kept.
     *
     * @param  array{tempo: float, stinger: string, intro: float, hit: string, tail: float, bed: string|null, effect: string}  $recipe
     * @return array{id: string, file: string, seconds: float, lufs: float|null, stinger: string, hit: string, bed: string|null, effect: string}|null
     */
    private function produce(string $dir, string $id, array $recipe, string $voice, bool $selected): ?array
    {
        $file = 'imaging/'.$id.'.opus';
        $meta = ['stinger' => $recipe['stinger'], 'hit' => $recipe['hit'], 'bed' => $recipe['bed'], 'effect' => $recipe['effect']];
        $fresh = is_file($dir.'/'.$file) && filemtime($dir.'/'.$file) >= filemtime($voice);

        if ($fresh && ! $this->option('remix') || ! $selected) {
            return is_file($dir.'/'.$file) ? ['id' => $id, 'file' => $file, 'seconds' => round($this->seconds($dir.'/'.$file), 3), 'lufs' => $this->lufs($dir.'/'.$file)] + $meta : null;
        }

        $sounds = resource_path('audio/idents');
        $stinger = $sounds.'/sfx-'.$recipe['stinger'].'.opus';
        $hit = $sounds.'/sfx-'.$recipe['hit'].'.opus';
        $bed = $recipe['bed'] === null ? null : $sounds.'/bed-'.$recipe['bed'].'.opus';

        foreach (array_filter([$stinger, $hit, $bed]) as $sound) {
            if (! is_file($sound)) {
                $this->warn('missing sound '.basename($sound).' (php artisan caster:jingles --sounds)');

                return null;
            }
        }

        $ffmpeg = (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg');
        $mix = $dir.'/imaging/.mix-'.$id.'.wav';
        $mastered = $dir.'/imaging/.master-'.$id.'.wav';
        $run = fn (array $arguments) => Process::timeout(120)->env(ChildEnvironment::withoutSecrets())->run($arguments);

        $result = $run(JingleSynth::produceArguments($ffmpeg, $voice, $this->seconds($voice), $stinger, $hit, $bed, $recipe['effect'], $recipe['intro'], $recipe['tail'], $mix));
        $measured = $result->successful() ? $this->lufs($mix) : null;
        $result = $measured === null ? $result : $run(JingleSynth::masterArguments($ffmpeg, $mix, -16 - $measured, $mastered, false, JingleSynth::TRUE_PEAK_LIMIT));
        $measured = $measured !== null && $result->successful() ? $this->lufs($mastered) : null;
        $result = $measured === null ? $result : $run(JingleSynth::masterArguments($ffmpeg, $mastered, -16 - $measured, $dir.'/'.$file, true, JingleSynth::TRUE_PEAK_LIMIT));
        $result = $measured === null || ! $result->successful() ? $result : $this->underTruePeak($run, $ffmpeg, $mastered, -16 - $measured, $dir.'/'.$file) ?? $result;

        foreach ([$mix, $mastered] as $temporary) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        if ($measured === null || ! $result->successful() || ! is_file($dir.'/'.$file)) {
            $this->warn('imaging mix failed: '.$id.' '.mb_strimwidth($result->errorOutput(), 0, 300));

            return null;
        }

        $item = ['id' => $id, 'file' => $file, 'seconds' => round($this->seconds($dir.'/'.$file), 3), 'lufs' => $this->lufs($dir.'/'.$file)] + $meta;
        $this->line(sprintf('produced %-28s %5.1f s %6.1f LUFS  %s + %s%s', $id, $item['seconds'], (float) $item['lufs'], $recipe['stinger'], $recipe['hit'], $recipe['bed'] === null ? '' : ' over '.$recipe['bed']));

        return $item;
    }

    /**
     * The stingers and beds of JingleSynth as files (P11): sfx-<name>.opus and bed-<name>.opus in `$out`, each gained
     * to -16 LUFS and limited at -1 dBFS (two passes, like the jingles), Ogg Opus 48 kHz mono.
     */
    private function renderSounds(string $out): int
    {
        $ffmpeg = (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg');
        $run = fn (array $arguments) => Process::timeout(120)->env(ChildEnvironment::withoutSecrets())->run($arguments);
        $failed = 0;
        $names = [...array_map(fn (string $name): array => [$name, 'sfx-'.$name], JingleSynth::STINGERS), ...array_map(fn (string $name): array => ['bed:'.$name, 'bed-'.$name], JingleSynth::LOOP_BEDS),
            ...array_map(fn (string $name): array => [$name, $name], JingleSynth::STUDIO)];

        foreach ($names as [$name, $file]) {
            $raw = $out.'/.raw-'.$file.'.wav';
            $mastered = $out.'/.master-'.$file.'.wav';
            $result = $run(JingleSynth::soundArguments($ffmpeg, $name, $raw));
            $measured = $result->successful() ? $this->lufs($raw) : null;
            $result = $measured === null ? $result : $run(JingleSynth::masterArguments($ffmpeg, $raw, -16 - $measured, $mastered, false, JingleSynth::TRUE_PEAK_LIMIT));
            $measured = $measured !== null && $result->successful() ? $this->lufs($mastered) : null;
            $result = $measured === null ? $result : $run(JingleSynth::masterArguments($ffmpeg, $mastered, -16 - $measured, $out.'/'.$file.'.opus', true, JingleSynth::TRUE_PEAK_LIMIT));
            $result = $measured === null || ! $result->successful() ? $result : $this->underTruePeak($run, $ffmpeg, $mastered, -16 - $measured, $out.'/'.$file.'.opus') ?? $result;

            foreach ([$raw, $mastered] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            if ($measured === null || ! $result->successful()) {
                $failed++;
                $this->warn('sound failed: '.$name.' '.mb_strimwidth($result->errorOutput(), 0, 300));

                continue;
            }

            $this->line(sprintf('%-22s %5.2f s %6.1f LUFS', $file.'.opus', $this->seconds($out.'/'.$file.'.opus'), (float) $this->lufs($out.'/'.$file.'.opus')));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** A blip in the speaker's own voice and tempo at the speech level (CasterFetcher::voiceChunk()), at most 1.6 s. */
    private function blip(CasterFetcher $fetcher, string $speaker, string $text, string $file): void
    {
        if ($this->calls > 0) {
            sleep(max(0, (int) $this->option('pause')));
        }

        $this->calls++;
        $voiced = $fetcher->voiceChunk([$text], $speaker);

        if ($voiced === null || $voiced['pcm'] === '') {
            $this->warn('blip failed: '.$speaker.' '.$text);

            return;
        }

        $max = (int) round(1.6 * $voiced['rate']) * Pcm::BYTES_PER_SAMPLE;
        $pcm = strlen($voiced['pcm']) > $max ? Pcm::fadeOut(substr($voiced['pcm'], 0, $max), (int) round(0.25 * $voiced['rate'])) : $voiced['pcm'];

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, Pcm::toWav($pcm, $voiced['rate']));
        $this->line(sprintf('blip %s "%s" %.2f s', $speaker, $text, Pcm::seconds($pcm, $voiced['rate'])));
    }

    /**
     * Deletes files under the jingle directory that no line names any more (manifest and rotation stay).
     *
     * @param  list<string>  $keep
     */
    private function prune(string $dir, array $keep): void
    {
        $keep = array_flip($keep);

        foreach (['voice/*.wav', 'voice/*.json', 'anchor/*.opus', 'imaging/*.opus', 'blips/*/*.wav'] as $pattern) {
            foreach (glob($dir.'/'.$pattern) ?: [] as $file) {
                if (! isset($keep[substr($file, strlen($dir) + 1)])) {
                    unlink($file);
                }
            }
        }
    }

    private function seconds(string $file): float
    {
        if (str_ends_with($file, '.wav')) {
            try {
                ['pcm' => $pcm, 'rate' => $rate] = Pcm::fromWav((string) file_get_contents($file));

                return Pcm::seconds($pcm, $rate);
            } catch (InvalidArgumentException) {
                return 0.0;
            }
        }

        $result = Process::timeout(20)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]);

        return (float) trim($result->output());
    }

    /**
     * The encoded file's true peak above -1 dBTP (Opus overshoots on a noisy hit by up to 2 dB): encoded again from
     * `$mastered` with the limiter that much lower plus a fifth of a dB, at most three times; the loudness stays, only
     * the hit is limited harder. Null when nothing had to be done.
     *
     * @param  Closure(list<string>): ProcessResult  $run
     */
    private function underTruePeak(Closure $run, string $ffmpeg, string $mastered, float $gainDb, string $out): ?ProcessResult
    {
        $limit = JingleSynth::TRUE_PEAK_LIMIT;
        $result = null;

        for ($pass = 0; $pass < 3; $pass++) {
            $peak = $this->truePeak($out);

            if ($peak === null || $peak <= -1.0) {
                break;
            }

            $limit *= 10 ** (-($peak + 1.2) / 20);
            $result = $run(JingleSynth::masterArguments($ffmpeg, $mastered, $gainDb, $out, true, $limit));
        }

        return $result;
    }

    private function truePeak(string $file): ?float
    {
        $result = Process::timeout(30)->run(['nice', '-n', '19', (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-threads', '1', '-i', $file, '-af', 'ebur128=peak=true', '-f', 'null', '-']);

        return preg_match_all('/Peak:\s+(-?[\d.]+) dBFS/', $result->errorOutput(), $match) > 0 ? (float) end($match[1]) : null;
    }

    private function lufs(string $file): ?float
    {
        $result = Process::timeout(30)->run(['nice', '-n', '19', (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-threads', '1', '-i', $file, '-af', 'ebur128', '-f', 'null', '-']);

        return preg_match('/Integrated loudness:\s+I:\s+(-?[\d.]+) LUFS/', $result->errorOutput(), $match) === 1 ? (float) $match[1] : null;
    }
}
