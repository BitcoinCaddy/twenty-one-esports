<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use Carbon\CarbonImmutable;

/**
 * Finds and removes what a format produced before a cut-off (plan "TWENTY ONE
 * Radio", P20; user 2026-10-13: "Die Altbestände der Talks dann entfernen"),
 * wherever this machine keeps it: on the server the scripts of the format
 * (topic `<format>` or `stock:<format>`, queued, claimed or voiced, written
 * before the cut-off) and every ready clip that carries one, and the stock
 * library's blocks of the format rendered before it; on the workstation the
 * day stock and the replay archive. find() only reads; purge() removes what
 * find() lists (a script expires, a file and its twin go).
 */
final class FormatPurge
{
    public function __construct(
        private readonly ClipSpool $spool,
        private readonly ?StockLibrary $library = null,
        private readonly ?DayStock $dayStock = null,
        private readonly ?ReplayArchive $replays = null,
    ) {}

    public static function fromConfig(ClipSpool $spool): self
    {
        return new self($spool, StockLibrary::fromConfig(), DayStock::fromConfig(), ReplayArchive::fromConfig());
    }

    /**
     * What a purge of `$format` before `$before` would remove.
     *
     * @return array{scripts: list<int>, clips: list<int>, library: list<string>, day_stock: list<string>, replays: list<string>}
     */
    public function find(string $format, CarbonImmutable $before): array
    {
        $topics = [$format, StockScripts::TOPIC_PREFIX.$format];
        $scripts = CasterScript::query()->whereIn('topic', $topics)->where('created_at', '<', $before)
            ->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED, CasterScript::VOICED])->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $wanted = array_flip($scripts);
        $clips = CasterClip::query()->where('status', CasterClip::READY)->orderBy('id')->get()
            ->filter(fn (CasterClip $clip): bool => isset($wanted[$clip->caster_script_id]) || array_intersect_key(array_flip(array_map('intval', (array) ($clip->block['scripts'] ?? []))), $wanted) !== [])
            ->map(fn (CasterClip $clip): int => $clip->id)->values()->all();

        $library = [];
        foreach ($this->library?->entries() ?? [] as $hash => $meta) {
            $ofFormat = ($meta['format'] ?? null) === $format || array_intersect($topics, (array) ($meta['topics'] ?? [])) !== [];

            if ($ofFormat && (int) ($meta['rendered_at'] ?? 0) < $before->getTimestamp()) {
                $library[] = (string) $hash;
            }
        }

        return [
            'scripts' => array_values($scripts),
            'clips' => array_values($clips),
            'library' => $library,
            'day_stock' => self::fileEntries($this->dayStock?->entries() ?? [], $topics, $before),
            'replays' => self::fileEntries($this->replays?->entries() ?? [], $topics, $before),
        ];
    }

    /**
     * Removes what find() lists and returns it.
     *
     * @return array{scripts: list<int>, clips: list<int>, library: list<string>, day_stock: list<string>, replays: list<string>}
     */
    public function purge(string $format, CarbonImmutable $before): array
    {
        $found = $this->find($format, $before);

        foreach (CasterClip::query()->whereKey($found['clips'])->get() as $clip) {
            $this->spool->discard($clip);
        }

        $this->spool->dropScripts($found['scripts']);

        foreach ($found['library'] as $hash) {
            $this->library?->remove($hash);
        }

        foreach ($found['day_stock'] as $name) {
            $this->dayStock?->remove($name);
        }

        foreach ($found['replays'] as $name) {
            $this->replays?->remove($name);
        }

        return $found;
    }

    /**
     * The names of day stock or replay entries one of whose scripts has a topic of `$topics`, rendered (or kept)
     * before `$before`.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @param  list<string>  $topics
     * @return list<string>
     */
    private static function fileEntries(array $entries, array $topics, CarbonImmutable $before): array
    {
        $names = [];

        foreach ($entries as $name => $meta) {
            // The day stock keeps its scripts' payloads, the replay archive their topics.
            $scriptTopics = [...array_map(fn (mixed $script): mixed => is_array($script) ? ($script['topic'] ?? null) : null, (array) ($meta['scripts'] ?? [])), ...(array) ($meta['topics'] ?? [])];
            $at = (int) ($meta['rendered_at'] ?? 0);

            if (array_intersect($topics, array_filter($scriptTopics, 'is_string')) !== [] && $at < $before->getTimestamp()) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
