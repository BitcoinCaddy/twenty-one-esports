<?php

namespace App\Support\Caster\Blocks;

use App\Support\Caster\AtomicFile;
use App\Support\Caster\InvalidClip;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\SpoolFull;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The server's stock library (plan "TWENTY ONE Radio", P18; user 2026-10-12:
 * "Audiomaterial für 3 Tage vorhalten oder sogar schon hochgeladen haben"):
 * the evergreen blocks the workstation rendered, each as `<sha1>.opus` in
 * `stock.library.dir` with its metadata in `index.json` (format, topics,
 * subjects, length, sentence ends, boundaries, rendered_at, aired_count,
 * last_aired_at, evergreen), plus when each subject last aired.
 *
 * Bounded: store() takes a block only when the library stays within
 * `max_bytes` after evicting aired blocks, the retired ones (aired
 * `retire_after_airings` times) first, then the oldest-aired; a block that
 * never aired is never evicted (507 instead, the workstation keeps it).
 * Idempotent: the same hash again is "exists", nothing written. Fails
 * closed: a body whose sha1 is not its name, not Opus, empty or too long, or
 * a block not marked evergreen is refused (InvalidClip).
 *
 * A show longer than a block (the 30-minute talk show, P20) reaches the
 * library as consecutive blocks of one `episode` (its story's part group,
 * the first and last part each block covers, whether the story goes on):
 * such an episode airs only whole and in order (pick() returns its first
 * block with the rest in `episode`, StockFeeder queues them back to back);
 * a later block is never picked on its own, and an episode with a block
 * missing (not uploaded yet, evicted) not at all.
 *
 * pick() is the programme's choice (StockFeeder): never a block within
 * `repeat_hours` of its last airing; a block whose subjects aired within
 * `subject_spacing_hours` only when nothing else is left; never-aired
 * blocks first (another format than the last one first, the oldest
 * rendered first), then (repeats allowed) the least recently aired.
 *
 * Every read-modify-write runs under an exclusive lock on `index.lock`, the
 * index itself is written atomically (AtomicFile): the upload endpoint and the
 * feeder never lose each other's changes.
 */
final class StockLibrary
{
    public const STORED = 'stored';

    public const EXISTS = 'exists';

    public function __construct(
        private readonly string $dir,
        private readonly int $maxBytes = 4 * 1024 * 1024 * 1024,
        private readonly float $targetSeconds = 60 * 3600,
        private readonly int $retireAfter = 4,
        private readonly float $repeatHours = 24,
        private readonly float $subjectSpacingHours = 6,
        private readonly float $maxBlockSeconds = 900,
    ) {}

    public static function fromConfig(): self
    {
        $settings = (array) config('tv.commentary.blocks.stock.library', []);

        return new self(
            rtrim((string) ($settings['dir'] ?? storage_path('app/stream/stock')), '/'),
            (int) ($settings['max_bytes'] ?? 4 * 1024 ** 3),
            3600 * (float) ($settings['target_hours'] ?? 60),
            max(1, (int) ($settings['retire_after_airings'] ?? 4)),
            (float) ($settings['repeat_hours'] ?? 24),
            (float) ($settings['subject_spacing_hours'] ?? 6),
            // A stock block, or a whole long show with its own slot (P20: the thirty-minute talk) and five minutes to spare.
            max(1.5 * max(60.0, (float) config('tv.commentary.blocks.stock.seconds.max', 360)), 60.0 * max([0, ...array_values(Shows::windows())]) + 300.0),
        );
    }

    /** Whether the library runs: the day stock on (DayStock::enabled()) and `stock.library.enabled`. */
    public static function enabled(): bool
    {
        return DayStock::enabled() && config('tv.commentary.blocks.stock.library.enabled') === true;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function file(string $hash): string
    {
        return $this->dir.'/'.$hash.'.opus';
    }

    /**
     * Takes a block under its sha1: STORED, or EXISTS when it is there already.
     *
     * @param  array{format?: string, topics?: list<string>, subjects?: list<string>, seconds: float, ends?: list<float>, boundaries?: list<float>, rendered_at?: int, evergreen?: bool, episode?: mixed}  $meta
     *
     * @throws InvalidClip when the body is not the block it claims to be
     * @throws SpoolFull when even after eviction the library would pass `max_bytes`
     */
    public function store(string $hash, string $opus, array $meta, CarbonImmutable $now): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $hash) !== 1 || sha1($opus) !== $hash) {
            throw new InvalidClip('hash');
        }

        if (! str_starts_with($opus, 'OggS') || ! str_contains(substr($opus, 0, 200), 'OpusHead')) {
            throw new InvalidClip('format');
        }

        $seconds = (float) $meta['seconds'];

        if ($seconds <= 0 || $seconds > $this->maxBlockSeconds) {
            throw new InvalidClip('size');
        }

        if (($meta['evergreen'] ?? false) !== true) {
            throw new InvalidClip('not_evergreen');
        }

        return $this->locked(function (array $index) use ($hash, $opus, $meta, $seconds, $now): array {
            if (isset($index['blocks'][$hash]) && is_file($this->file($hash))) {
                return [$index, self::EXISTS];
            }

            $index = $this->evict($index, strlen($opus));

            if (! AtomicFile::put($this->file($hash), $opus)) {
                throw new SpoolFull;
            }

            $clean = fn (mixed $list): array => array_values(array_unique(array_filter((array) $list, fn (mixed $value): bool => is_string($value) && $value !== '' && strlen($value) <= 200)));
            $times = fn (mixed $list): array => array_values(array_map(fn ($time): float => round(max(0.0, min($seconds, (float) $time)), 3), array_filter((array) $list, 'is_numeric')));

            $index['blocks'][$hash] = [
                'format' => is_string($meta['format'] ?? null) ? mb_substr($meta['format'], 0, 40) : 'talk',
                'topics' => $clean($meta['topics'] ?? []),
                'subjects' => $clean($meta['subjects'] ?? []),
                'seconds' => round($seconds, 3),
                'bytes' => strlen($opus),
                'ends' => $times($meta['ends'] ?? []),
                'boundaries' => $times($meta['boundaries'] ?? []),
                'rendered_at' => (int) ($meta['rendered_at'] ?? $now->getTimestamp()),
                'uploaded_at' => $now->getTimestamp(),
                'aired_count' => 0,
                'last_aired_at' => null,
                'evergreen' => true,
                'episode' => self::cleanEpisode($meta['episode'] ?? null),
            ];

            return [$index, self::STORED];
        });
    }

    /**
     * The blocks by hash (only those whose file is there).
     *
     * @return array<string, array<string, mixed>>
     */
    public function entries(): array
    {
        return array_filter($this->read()['blocks'], fn (string $hash): bool => is_file($this->file($hash)), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The episode a stock block belongs to (workstation, for `X-Caster-Stock-Meta`): its story's part group, the first
     * and last part it covers and whether the story goes on after it; null for a block of whole scripts.
     *
     * @param  list<array<array-key, mixed>>  $scripts  the block's scripts (claim payloads, in order)
     * @return array{group: string, first: int, last: int, continues: bool}|null
     */
    public static function episodeOf(array $scripts): ?array
    {
        $first = $scripts[0]['part'] ?? null;
        $last = $scripts === [] ? null : ($scripts[count($scripts) - 1]['part'] ?? null);

        if (! is_array($first) || ! is_array($last) || ! is_string($first['group'] ?? null) || ($last['group'] ?? null) !== $first['group']) {
            return null;
        }

        $episode = ['group' => $first['group'], 'first' => (int) ($first['index'] ?? 1), 'last' => (int) ($last['index'] ?? 1), 'continues' => ($last['continues'] ?? false) === true];

        // One block with the whole story is no episode of several.
        return $episode['first'] <= 1 && ! $episode['continues'] ? null : $episode;
    }

    /**
     * An uploaded episode, or null when it is none or malformed.
     *
     * @return array{group: string, first: int, last: int, continues: bool}|null
     */
    private static function cleanEpisode(mixed $episode): ?array
    {
        if (! is_array($episode) || ! is_string($episode['group'] ?? null) || preg_match('/^[A-Za-z0-9:_-]{1,64}$/', $episode['group']) !== 1
            || ! is_int($episode['first'] ?? null) || ! is_int($episode['last'] ?? null) || $episode['first'] < 1 || $episode['last'] < $episode['first']) {
            return null;
        }

        return ['group' => $episode['group'], 'first' => $episode['first'], 'last' => $episode['last'], 'continues' => ($episode['continues'] ?? false) === true];
    }

    /**
     * The blocks of a whole episode in order, starting with `$hash` (its first block): each next block starts with the
     * part after the last of the one before, the last does not go on; null when one is missing.
     *
     * @param  array<string, array<string, mixed>>  $blocks
     * @return list<string>|null
     */
    private function chain(array $blocks, string $hash): ?array
    {
        $episode = self::cleanEpisode($blocks[$hash]['episode'] ?? null);

        if ($episode === null) {
            return [$hash];
        }

        $chain = [$hash];

        while ($episode['continues']) {
            $group = $episode['group'];
            $wanted = $episode['last'] + 1;
            $next = collect($blocks)->search(fn (array $meta, string $other): bool => ($meta['episode']['group'] ?? null) === $group
                && ($meta['episode']['first'] ?? null) === $wanted && is_file($this->file($other)));

            if (! is_string($next) || count($chain) > 50) {
                return null;
            }

            $chain[] = $next;
            $episode = self::cleanEpisode($blocks[$next]['episode']);

            if ($episode === null) {
                return null;
            }
        }

        return $chain;
    }

    /**
     * Picks the next block to air at `$now`, or null. `$repeats`: a block that aired already may come (the autopilot).
     * The first block of an episode comes with the rest of it (`episode`, in order).
     *
     * @return array{hash: string, meta: array<string, mixed>, repeat: bool, episode: list<array{hash: string, meta: array<string, mixed>}>}|null
     */
    public function pick(CarbonImmutable $now, bool $repeats, ?string $previousFormat = null): ?array
    {
        $index = $this->read();
        $at = $now->getTimestamp();
        $spacing = 3600 * $this->subjectSpacingHours;
        $candidates = [];

        foreach ($index['blocks'] as $hash => $meta) {
            $aired = (int) ($meta['aired_count'] ?? 0);
            $last = is_int($meta['last_aired_at'] ?? null) ? $meta['last_aired_at'] : null;
            $episode = self::cleanEpisode($meta['episode'] ?? null);

            if (($meta['evergreen'] ?? false) !== true || ! is_file($this->file($hash)) || ($aired > 0 && ! $repeats)
                || ($last !== null && $at - $last < 3600 * $this->repeatHours)
                // A later block of an episode only behind the one before it; an episode only whole.
                || ($episode !== null && ($episode['first'] > 1 || $this->chain($index['blocks'], (string) $hash) === null))) {
                continue;
            }

            $crowded = collect((array) ($meta['subjects'] ?? []))->contains(fn (mixed $subject): bool => is_string($subject)
                && is_int($index['subjects'][$subject] ?? null) && $at - $index['subjects'][$subject] < $spacing);

            $candidates[] = [
                'hash' => (string) $hash,
                'meta' => $meta,
                'repeat' => $aired > 0,
                // Ascending: spaced subjects first, unaired first, another format first, then the oldest rendered or least recently aired.
                'rank' => [$crowded ? 1 : 0, $aired > 0 ? 1 : 0, $previousFormat !== null && ($meta['format'] ?? null) === $previousFormat ? 1 : 0,
                    $aired > 0 ? (int) $last : (int) ($meta['rendered_at'] ?? 0), (string) $hash],
            ];
        }

        usort($candidates, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        if (! isset($candidates[0])) {
            return null;
        }

        $rest = array_slice((array) $this->chain($index['blocks'], $candidates[0]['hash']), 1);

        return ['hash' => $candidates[0]['hash'], 'meta' => $candidates[0]['meta'], 'repeat' => $candidates[0]['repeat'],
            'episode' => array_map(fn (string $hash): array => ['hash' => $hash, 'meta' => $index['blocks'][$hash]], $rest)];
    }

    /**
     * Marks a block as aired at `$at` (it went into the spool) and its subjects with it; returns what it was before,
     * for restore(). null when the block is not there.
     *
     * @return array{aired_count: int, last_aired_at: int|null}|null
     */
    public function markAired(string $hash, CarbonImmutable $at): ?array
    {
        return $this->locked(function (array $index) use ($hash, $at): array {
            if (! isset($index['blocks'][$hash])) {
                return [$index, null];
            }

            $before = ['aired_count' => (int) ($index['blocks'][$hash]['aired_count'] ?? 0), 'last_aired_at' => $index['blocks'][$hash]['last_aired_at'] ?? null];
            $index['blocks'][$hash]['aired_count'] = $before['aired_count'] + 1;
            $index['blocks'][$hash]['last_aired_at'] = $at->getTimestamp();

            foreach ((array) ($index['blocks'][$hash]['subjects'] ?? []) as $subject) {
                if (is_string($subject)) {
                    $index['subjects'][$subject] = $at->getTimestamp();
                }
            }

            return [$index, $before];
        });
    }

    /** Removes a block, its file and its index entry (FormatPurge); nothing when it is not there. */
    public function remove(string $hash): void
    {
        $this->locked(function (array $index) use ($hash): array {
            unset($index['blocks'][$hash]);
            @unlink($this->file($hash));

            return [$index, null];
        });
    }

    /**
     * Undoes markAired() for a block that never got on air (a repeat dropped when the workstation came back).
     *
     * @param  array{aired_count: int, last_aired_at: int|null}  $before
     */
    public function restore(string $hash, array $before): void
    {
        $this->locked(function (array $index) use ($hash, $before): array {
            if (isset($index['blocks'][$hash])) {
                $index['blocks'][$hash]['aired_count'] = max(0, $before['aired_count']);
                $index['blocks'][$hash]['last_aired_at'] = $before['last_aired_at'];
            }

            return [$index, null];
        });
    }

    /** Seconds of audio in the library. */
    public function seconds(): float
    {
        return $this->totals($this->entries())['seconds'];
    }

    /** Seconds that count toward the target: the blocks not yet retired. */
    public function countingSeconds(): float
    {
        return $this->totals($this->entries())['counting_seconds'];
    }

    public function bytes(): int
    {
        return $this->totals($this->entries())['bytes'];
    }

    /** Whether the library holds its target of unique audio (or is at its byte cap): the workstation pauses stock rendering. */
    public function isFull(): bool
    {
        return $this->totals($this->entries())['full'];
    }

    /**
     * One pass over the blocks: seconds, seconds toward the target, never-aired seconds, bytes, full.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @return array{seconds: float, counting_seconds: float, unaired_seconds: float, bytes: int, full: bool}
     */
    private function totals(array $entries): array
    {
        $totals = ['seconds' => 0.0, 'counting_seconds' => 0.0, 'unaired_seconds' => 0.0, 'bytes' => 0, 'full' => false];

        foreach ($entries as $meta) {
            $seconds = (float) ($meta['seconds'] ?? 0);
            $aired = (int) ($meta['aired_count'] ?? 0);
            $totals['seconds'] += $seconds;
            $totals['counting_seconds'] += $aired < $this->retireAfter ? $seconds : 0.0;
            $totals['unaired_seconds'] += $aired === 0 ? $seconds : 0.0;
            $totals['bytes'] += (int) ($meta['bytes'] ?? 0);
        }

        $totals['full'] = $totals['counting_seconds'] >= $this->targetSeconds || $totals['bytes'] >= $this->maxBytes;

        return $totals;
    }

    /**
     * How many blocks carry each subject (StockScripts' memory across the whole library) and each format.
     *
     * @param  array<string, array<string, mixed>>|null  $entries
     * @return array{subjects: array<string, int>, formats: array<string, int>}
     */
    public function census(?array $entries = null): array
    {
        $subjects = [];
        $formats = [];

        foreach ($entries ?? $this->entries() as $meta) {
            $format = (string) ($meta['format'] ?? '');
            $formats[$format] = ($formats[$format] ?? 0) + 1;

            foreach ((array) ($meta['subjects'] ?? []) as $subject) {
                if (is_string($subject)) {
                    $subjects[$subject] = ($subjects[$subject] ?? 0) + 1;
                }
            }
        }

        return ['subjects' => $subjects, 'formats' => $formats];
    }

    /**
     * The library in numbers (caster:stock-library, the claim's `stock_library`).
     *
     * @return array{blocks: int, seconds: float, counting_seconds: float, unaired_seconds: float, target_seconds: float, full: bool, bytes: int, max_bytes: int, formats: array<string, int>}
     */
    public function summary(): array
    {
        $entries = $this->entries();
        $totals = $this->totals($entries);

        return [
            'blocks' => count($entries),
            'seconds' => round($totals['seconds'], 1),
            'counting_seconds' => round($totals['counting_seconds'], 1),
            'unaired_seconds' => round($totals['unaired_seconds'], 1),
            'target_seconds' => $this->targetSeconds,
            'full' => $totals['full'],
            'bytes' => $totals['bytes'],
            'max_bytes' => $this->maxBytes,
            'formats' => $this->census($entries)['formats'],
        ];
    }

    /**
     * Makes room for `$incoming` bytes: retired blocks first, then the other aired ones, the oldest-aired first.
     *
     * @param  array{blocks: array<string, array<string, mixed>>, subjects: array<string, int>}  $index
     * @return array{blocks: array<string, array<string, mixed>>, subjects: array<string, int>}
     *
     * @throws SpoolFull when only never-aired blocks would be left to evict
     */
    private function evict(array $index, int $incoming): array
    {
        $bytes = (int) array_sum(array_map(fn (array $meta): int => (int) ($meta['bytes'] ?? 0), $index['blocks']));
        $victims = array_filter($index['blocks'], fn (array $meta): bool => (int) ($meta['aired_count'] ?? 0) > 0);
        uksort($victims, fn (string $a, string $b): int => [(int) ($victims[$a]['aired_count'] ?? 0) < $this->retireAfter ? 1 : 0, (int) ($victims[$a]['last_aired_at'] ?? 0)]
            <=> [(int) ($victims[$b]['aired_count'] ?? 0) < $this->retireAfter ? 1 : 0, (int) ($victims[$b]['last_aired_at'] ?? 0)]);

        $evicted = [];

        foreach (array_keys($victims) as $hash) {
            if ($bytes + $incoming <= $this->maxBytes) {
                break;
            }

            $bytes -= (int) ($index['blocks'][$hash]['bytes'] ?? 0);
            $evicted[] = (string) $hash;
        }

        // Nothing is deleted unless the block then fits.
        if ($bytes + $incoming > $this->maxBytes) {
            throw new SpoolFull;
        }

        foreach ($evicted as $hash) {
            @unlink($this->file($hash));
            unset($index['blocks'][$hash]);
        }

        return $index;
    }

    /**
     * @return array{blocks: array<string, array<string, mixed>>, subjects: array<string, int>}
     */
    private function read(): array
    {
        $file = $this->dir.'/index.json';
        $index = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return [
            'blocks' => is_array($index) && is_array($index['blocks'] ?? null) ? array_filter($index['blocks'], 'is_array') : [],
            'subjects' => is_array($index) && is_array($index['subjects'] ?? null) ? array_filter($index['subjects'], 'is_int') : [],
        ];
    }

    /**
     * Runs `$change` on the index under the library's lock and writes what it returns.
     *
     * @template T
     *
     * @param  Closure(array{blocks: array<string, array<string, mixed>>, subjects: array<string, int>}): array{0: array{blocks: array<string, array<string, mixed>>, subjects: array<string, int>}, 1: T}  $change
     * @return T
     */
    private function locked(Closure $change): mixed
    {
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0755, true) && ! is_dir($this->dir)) {
            throw new SpoolFull;
        }

        $lock = fopen($this->dir.'/index.lock', 'c');

        if ($lock === false) {
            throw new SpoolFull;
        }

        try {
            flock($lock, LOCK_EX);
            [$index, $result] = $change($this->read());
            AtomicFile::put($this->dir.'/index.json', (string) json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
