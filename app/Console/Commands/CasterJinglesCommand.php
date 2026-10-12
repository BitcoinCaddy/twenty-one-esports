<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\JingleSynth;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\Pcm;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
 *     whose voice is not on disk yet (voice/<id>.wav, the source), at most
 *     `--limit` calls, `--pause` seconds apart so the fetcher's voicing never
 *     starves; no ASR;
 *  3. two variants of every line over the self-made music (JingleSynth:
 *     bed, key, voice treatment, intro from a hash of the id), ducked,
 *     -16 LUFS, limited, Ogg Opus (anchor/<id>-a.opus, -b.opus), a jingle
 *     already rendered from its voice kept (resumable; --remix renders anew);
 *  4. the speakers' blips ("hm", "ja", "stimmt", a laugh) with their own
 *     profiles, at the speech level, at most 1.6 s (blips/<speaker>/*.wav);
 *  5. manifest.json with every jingle's line, category, file, length and
 *     measured loudness; files of lines no longer in the list are deleted.
 *
 * Everything stays in `blocks.jingles.dir` (storage/app/jingles): bounded by
 * the list (about 100 small files, a few MB), never in git.
 */
#[Signature('caster:jingles
    {--limit=60 : VoiceStudio calls at most in this run}
    {--pause=6 : Seconds between two VoiceStudio calls}
    {--no-blips : Leave the speakers\' blips out}
    {--mix-only : Render the jingles from the voices on disk, no VoiceStudio call}
    {--remix : Render every jingle again, also those already on disk}')]
#[Description('Render the Anchor jingles and the speakers\' blips for the programme blocks (workstation)')]
class CasterJinglesCommand extends Command
{
    private int $calls = 0;

    public function handle(CasterFetcher $fetcher): int
    {
        $settings = (array) config('tv.commentary.blocks', []);
        $dir = rtrim((string) ($settings['jingles']['dir'] ?? storage_path('app/jingles')), '/');
        $list = json_decode((string) file_get_contents(resource_path('data/voice/anchor-jingles.json')), true);
        $limit = max(0, (int) $this->option('limit'));
        $mixOnly = (bool) $this->option('mix-only');

        if (! is_array($list) || ! is_array($list['lines'] ?? null)) {
            $this->error('resources/data/voice/anchor-jingles.json is not readable.');

            return self::FAILURE;
        }

        foreach (['', '/voice', '/anchor', '/blips'] as $sub) {
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

        foreach ($list['lines'] as $line) {
            if (! is_array($line) || ! is_string($line['id'] ?? null) || ! is_string($line['text'] ?? null) || ! is_string($line['category'] ?? null)) {
                continue;
            }

            $id = $line['id'];
            $voice = $dir.'/voice/'.$id.'.wav';
            $keep[] = 'voice/'.$id.'.wav';

            if (! is_file($voice) && $profile !== null && $this->calls < $limit) {
                $wav = $this->generate($fetcher, $line['text'], $profile, (float) ($settings['anchor']['speed'] ?? 1.0), (string) ($line['language'] ?? 'de'));

                if ($wav !== null) {
                    file_put_contents($voice, $wav);
                }
            }

            if (! is_file($voice)) {
                $this->warn('no voice yet: '.$id);

                continue;
            }

            foreach (['a', 'b'] as $variant) {
                $item = $this->render($dir, $id, $variant, $line['category'], $voice);

                if ($item !== null) {
                    $items[] = $item + ['line' => $id, 'category' => $line['category'], 'text' => $line['text']];
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

        file_put_contents($dir.'/manifest.json', json_encode([
            'about' => 'TWENTY ONE ESPORTS RADIO jingles: voice "The Anchor" (VoiceStudio personality us_news_anchor, a designed voice, no person\'s clone), music own work CC0 (App\Support\Caster\Blocks\JingleSynth). Rendered by php artisan caster:jingles.',
            'generated_at' => now()->toIso8601String(),
            'profile_id' => $profile,
            'items' => $items,
            'blips' => $blips,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf('%d jingles of %d lines, %d blips, %d VoiceStudio calls; manifest in %s', count($items), count($list['lines']), count($blips), $this->calls, $dir));

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

    private function generate(CasterFetcher $fetcher, string $text, string $profile, float $speed, string $language): ?string
    {
        if ($this->calls > 0) {
            sleep(max(0, (int) $this->option('pause')));
        }

        $this->calls++;
        $started = microtime(true);
        $wav = $fetcher->generate($text, $profile, $speed, $language);
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

        $duration = $recipe['intro'] + $voiceSeconds + 1.6;
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

        foreach (['voice/*.wav', 'anchor/*.opus', 'blips/*/*.wav'] as $pattern) {
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

    private function lufs(string $file): ?float
    {
        $result = Process::timeout(30)->run(['nice', '-n', '19', (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-threads', '1', '-i', $file, '-af', 'ebur128', '-f', 'null', '-']);

        return preg_match('/Integrated loudness:\s+I:\s+(-?[\d.]+) LUFS/', $result->errorOutput(), $match) === 1 ? (float) $match[1] : null;
    }
}
