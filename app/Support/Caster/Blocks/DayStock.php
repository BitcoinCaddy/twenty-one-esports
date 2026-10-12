<?php

namespace App\Support\Caster\Blocks;

use App\Support\Caster\AtomicFile;
use App\Support\Caster\QuietHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The day stock on the workstation (user 2026-10-12: "Wir brauchen für den
 * Tag über im Buffer vorgerendertes Programm durchweg"): evergreen talk
 * blocks rendered in the stock window (the quiet hours, when the GPU idles)
 * and kept as `<stamp>-<lead>.opus` with a JSON twin (the claim payloads of
 * its scripts, length, sentence ends, boundaries, steps) in `stock.dir`, at
 * most `target_hours` of audio and `max_bytes`. By day the oldest goes up
 * first (take()); once the server took it, it leaves the stock (remove())
 * and moves to the replay archive, so each stock block airs once as new.
 *
 * Fails closed: a block that is not evergreen (ReplayArchive::evergreen(),
 * a line that names the time or a time-of-day jingle) is never stocked, and
 * an unreadable twin or a missing file is never uploaded.
 */
final class DayStock
{
    /** What the workstation last told the server about its stock (server cache): seconds, full, when. */
    public const REPORT = 'caster:stock_report';

    /** The header every claim carries while the stock runs: "<seconds>;<full 0|1>". */
    public const HEADER = 'X-Caster-Stock';

    public function __construct(
        private readonly string $dir,
        private readonly float $targetSeconds = 6 * 3600,
        private readonly int $maxBytes = 2 * 1024 * 1024 * 1024,
    ) {}

    public static function fromConfig(): self
    {
        $settings = (array) config('tv.commentary.blocks.stock', []);

        return new self((string) ($settings['dir'] ?? storage_path('app/day-stock')), 3600 * (float) ($settings['target_hours'] ?? 6), (int) ($settings['max_bytes'] ?? 2 * 1024 ** 3));
    }

    /** Whether the day stock runs at all (blocks and `blocks.stock.enabled` on). */
    public static function enabled(): bool
    {
        return config('tv.commentary.blocks.enabled') === true && config('tv.commentary.blocks.stock.enabled') === true;
    }

    /** The header value of the workstation's stock: its seconds and whether it is full. */
    public function header(): string
    {
        return sprintf('%.1F;%d', $this->seconds(), $this->isFull() ? 1 : 0);
    }

    /** The server keeps a reported stock for ten minutes; a value that is not "<seconds>;<0|1>" is ignored. */
    public static function rememberReport(?string $header, CarbonImmutable $now): void
    {
        if (! is_string($header) || preg_match('/^(\d+(?:\.\d+)?);([01])$/', trim($header), $match) !== 1) {
            return;
        }

        Cache::put(self::REPORT, ['seconds' => (float) $match[1], 'full' => $match[2] === '1', 'at' => $now->getTimestamp()], 600);
    }

    /**
     * The stock the workstation reported within ten minutes, or null.
     *
     * @return array{seconds: float, full: bool, at: int}|null
     */
    public static function report(): ?array
    {
        $report = Cache::get(self::REPORT);

        return is_array($report) && is_numeric($report['seconds'] ?? null) ? ['seconds' => (float) $report['seconds'], 'full' => ($report['full'] ?? true) === true, 'at' => (int) ($report['at'] ?? 0)] : null;
    }

    /** Whether `$now` lies in the stock window (`stock.window`, Berlin), when the workstation renders stock. */
    public static function windowActive(CarbonImmutable $now): bool
    {
        $window = (array) config('tv.commentary.blocks.stock.window', []);

        return QuietHours::within($now, (string) ($window['from'] ?? ''), (string) ($window['to'] ?? ''), (string) config('tv.commentary.spool.quiet_hours.timezone', 'Europe/Berlin'));
    }

    /** Whether the stock holds its target of audio or its byte cap: no more stock rendering. */
    public function isFull(): bool
    {
        return $this->seconds() >= $this->targetSeconds || $this->bytes() >= $this->maxBytes;
    }

    /**
     * Keeps a rendered block; null when it is not evergreen (nothing written) or the directory cannot be made.
     *
     * @param  list<array<array-key, mixed>>  $scripts  the claim payloads, the first the lead
     * @param  array{ends: list<float>, boundaries: list<float>, steps: list<string>, seconds: float}  $block
     */
    public function store(string $opus, array $scripts, array $block, CarbonImmutable $now): ?string
    {
        if (! ReplayArchive::evergreen($scripts, $block['steps']) || (! is_dir($this->dir) && ! @mkdir($this->dir, 0755, true) && ! is_dir($this->dir))) {
            return null;
        }

        $name = $now->format('Ymd-His').'-'.(int) ($scripts[0]['id'] ?? 0);
        // Atomic, the audio first: the JSON twin is the commit mark entries() reads, so a kill mid-way leaves no half pair (P18).
        if (! AtomicFile::put($this->dir.'/'.$name.'.opus', $opus)) {
            return null;
        }

        AtomicFile::put($this->dir.'/'.$name.'.json', (string) json_encode([
            'scripts' => $scripts,
            'rendered_at' => $now->getTimestamp(),
            'seconds' => $block['seconds'],
            'ends' => $block['ends'],
            'boundaries' => $block['boundaries'],
            'steps' => $block['steps'],
        ], JSON_UNESCAPED_UNICODE));

        return $name;
    }

    /**
     * The oldest stocked block with its audio and metadata, or null.
     *
     * @return array{name: string, opus: string, meta: array<string, mixed>}|null
     */
    public function take(): ?array
    {
        foreach ($this->entries() as $name => $meta) {
            $opus = is_file($this->dir.'/'.$name.'.opus') ? (string) file_get_contents($this->dir.'/'.$name.'.opus') : '';

            if (str_starts_with($opus, 'OggS') && is_numeric($meta['seconds'] ?? null) && (float) $meta['seconds'] > 0) {
                return ['name' => $name, 'opus' => $opus, 'meta' => $meta];
            }

            // A half pair or a broken file never goes up.
            $this->remove($name);
        }

        return null;
    }

    public function remove(string $name): void
    {
        @unlink($this->dir.'/'.$name.'.opus');
        @unlink($this->dir.'/'.$name.'.json');
    }

    /** Seconds of audio in the stock. */
    public function seconds(): float
    {
        return (float) array_sum(array_map(fn (array $meta): float => (float) ($meta['seconds'] ?? 0), $this->entries()));
    }

    public function bytes(): int
    {
        return (int) array_sum(array_map(fn (string $file): int => (int) filesize($file), glob($this->dir.'/*.opus') ?: []));
    }

    /**
     * The stock's metadata by block name, oldest first.
     *
     * @return array<string, array<string, mixed>>
     */
    public function entries(): array
    {
        $entries = [];

        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            $meta = json_decode((string) file_get_contents($file), true);

            if (is_array($meta)) {
                $entries[basename($file, '.json')] = $meta;
            }
        }

        ksort($entries);

        return $entries;
    }
}
