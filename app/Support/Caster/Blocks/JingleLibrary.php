<?php

namespace App\Support\Caster\Blocks;

use App\Support\Caster\Pcm;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The Anchor's jingles and the speakers' agreeing blips on the workstation
 * (plan "TWENTY ONE Radio", P6), as `caster:jingles` rendered them into
 * `tv.commentary.blocks.jingles.dir` (storage/app/jingles): `manifest.json`
 * names every jingle with its line (the spoken words), category and file;
 * `blips/<speaker>/*.wav` are the short sounds ("hm", "ja", a laugh).
 *
 * pick() rotates within a category: never the line that played last, never
 * a line within `repeat_minutes` (both variants of a line count as the same
 * line, they say the same words); when every line of the category is that
 * recent, the one played longest ago that is not the last. What played is
 * remembered in `rotation.json` next to the manifest (the last 300 picks),
 * so a restart of the fetcher keeps the rotation.
 *
 * With `$strict` a category whose every line is that recent gives nothing.
 *
 * Fails open towards the programme: no manifest, an unknown category or an
 * undecodable file give no jingle (null), the block goes on without one.
 */
final class JingleLibrary
{
    private const KEEP_PLAYED = 300;

    /** @var list<array{id: string, line: string, category: string, file: string, seconds: float}>|null */
    private ?array $items = null;

    public function __construct(
        private readonly string $dir,
        private readonly float $repeatMinutes = 30.0,
        private readonly bool $strict = false,
    ) {}

    public static function fromConfig(): self
    {
        $settings = (array) config('tv.commentary.blocks.jingles', []);

        return new self((string) ($settings['dir'] ?? storage_path('app/jingles')), (float) ($settings['repeat_minutes'] ?? 30));
    }

    public function dir(): string
    {
        return rtrim($this->dir, '/');
    }

    /**
     * Every jingle of the manifest whose file exists.
     *
     * @return list<array{id: string, line: string, category: string, file: string, seconds: float}>
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $manifest = is_file($this->dir().'/manifest.json') ? json_decode((string) file_get_contents($this->dir().'/manifest.json'), true) : null;
        $items = [];

        foreach ((array) (is_array($manifest) ? ($manifest['items'] ?? []) : []) as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['category'] ?? null) || ! is_string($item['file'] ?? null)) {
                continue;
            }

            $file = $this->dir().'/'.ltrim($item['file'], '/');

            if (is_file($file)) {
                $items[] = ['id' => $item['id'], 'line' => is_string($item['line'] ?? null) ? $item['line'] : $item['id'], 'category' => $item['category'], 'file' => $file, 'seconds' => (float) ($item['seconds'] ?? 0)];
            }
        }

        return $this->items = $items;
    }

    /**
     * A jingle of the first of `$categories` that has any, rotated (see the
     * class), and remembered as played at `$now`; null when none of them has one.
     *
     * @param  list<string>  $categories
     * @return array{id: string, line: string, category: string, file: string, seconds: float}|null
     */
    public function pick(array $categories, CarbonImmutable $now): ?array
    {
        $played = $this->played();
        $last = $played === [] ? null : $played[count($played) - 1]['line'];
        $lastAt = [];

        foreach ($played as $entry) {
            $lastAt[$entry['line']] = $entry['at'];
        }

        foreach ($categories as $category) {
            $candidates = array_values(array_filter($this->items(), fn (array $item): bool => $item['category'] === $category));

            if ($candidates === []) {
                continue;
            }

            $notLast = array_values(array_filter($candidates, fn (array $item): bool => $item['line'] !== $last));
            $fresh = array_values(array_filter($notLast, fn (array $item): bool => ! isset($lastAt[$item['line']]) || $now->getTimestamp() - $lastAt[$item['line']] >= $this->repeatMinutes * 60));

            if ($fresh === []) {
                // Strict (the server's imaging, P11): no line within `repeat_minutes`, the next category is tried instead.
                if ($notLast === [] || $this->strict) {
                    continue;
                }

                usort($notLast, fn (array $a, array $b): int => ($lastAt[$a['line']] ?? 0) <=> ($lastAt[$b['line']] ?? 0));
                $fresh = [$notLast[0]];
            }

            $item = $fresh[mt_rand(0, count($fresh) - 1)];
            $this->remember($item['line'], $now);

            return $item;
        }

        return null;
    }

    /**
     * The blip files of a speaker (blips/<speaker>/*.wav).
     *
     * @return list<string>
     */
    public function blips(string $speaker): array
    {
        $files = glob($this->dir().'/blips/'.basename($speaker).'/*.wav') ?: [];
        sort($files);

        return $files;
    }

    /**
     * An audio file as PCM (s16le mono at `$rate`) at `$gainDb`, decoded by
     * ffmpeg; null when it fails.
     */
    public function pcm(string $file, int $rate, float $gainDb = 0.0): ?string
    {
        try {
            $result = Process::timeout(20)->env(ChildEnvironment::withoutSecrets())->run([
                (string) config('tv.commentary.voicestudio.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-i', $file, '-ac', '1', '-ar', (string) $rate, '-f', 's16le', 'pipe:1',
            ]);
        } catch (Throwable) {
            return null;
        }

        $pcm = $result->successful() ? $result->output() : '';
        $pcm = substr($pcm, 0, strlen($pcm) - strlen($pcm) % Pcm::BYTES_PER_SAMPLE);

        return $pcm === '' ? null : Pcm::gained($pcm, $gainDb);
    }

    /**
     * @return list<array{line: string, at: int}>
     */
    private function played(): array
    {
        $file = $this->dir().'/rotation.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return array_values(array_filter((array) (is_array($data) ? ($data['played'] ?? []) : []), fn ($entry): bool => is_array($entry) && is_string($entry['line'] ?? null) && is_int($entry['at'] ?? null)));
    }

    private function remember(string $line, CarbonImmutable $now): void
    {
        if (! is_dir($this->dir())) {
            return;
        }

        $played = $this->played();
        $played[] = ['line' => $line, 'at' => $now->getTimestamp()];
        @file_put_contents($this->dir().'/rotation.json', json_encode(['played' => array_slice($played, -self::KEEP_PLAYED)]), LOCK_EX);
    }
}
