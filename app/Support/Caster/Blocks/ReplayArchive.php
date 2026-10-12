<?php

namespace App\Support\Caster\Blocks;

use App\Models\CasterScript;
use App\Support\Caster\ChatReactions;
use App\Support\Caster\ChatWishes;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\SceneTopic;
use Carbon\CarbonImmutable;

/**
 * The day's rendered blocks on the workstation (plan "TWENTY ONE Radio",
 * P6, replay; user 2026-10-12: a "Best of des Tages" in the quiet hours):
 * every block that went up is kept as `<stamp>-<lead>.opus` with a JSON
 * twin (scripts, kinds, topics, when, length, sentence ends, boundaries,
 * evergreen, replayed_at) in `blocks.replay.dir`, bounded to `keep_hours`
 * and `max_bytes` (oldest first).
 *
 * evergreen() decides what may ever come again: only talk, history and the
 * rubrics without a date in them; never a bulletin, a teaser, a chat reply,
 * a wish, a zap thank-you, a greeting, a live moment, news, a followed
 * note, the news talk, Sport und Wetter, the press review, the league or chat rubric, a scene
 * about running games or results, a block that opens with a time-of-day
 * jingle or a "coming up" jingle, and no block with a line that names the
 * time ("Es ist vierzehn Uhr", "heute Abend", "gerade eben").
 *
 * Fails closed: an unreadable twin or a missing file is never replayed.
 */
final class ReplayArchive
{
    /** The kinds that may come again (talk, history, rubrics), everything else never. */
    public const EVERGREEN_KINDS = [CasterScript::SCENE, CasterScript::HISTORY, 'rubric'];

    /** Topics that are bound to their day, whatever their kind. */
    public const NEVER_TOPICS = [
        'chat', 'nachrichten_talk', 'presseschau', 'liga', SportWeather::TOPIC,
        SceneTopic::LIVE_GAMES, SceneTopic::LIVE_TOURNAMENT, SceneTopic::RESULTS, SceneTopic::TOURNAMENTS, SceneTopic::MEMPOOL,
        ChatReactions::ZAP_TOPIC, ChatReactions::GREETING_TOPIC,
    ];

    /** A line that names the time of day or a moment ("Es ist vierzehn Uhr", "heute Abend", "gerade eben"). */
    public const TIMESTAMPED = '/\b(?:uhr|heute|gestern|morgen früh|heute abend|heute nacht|gerade eben|soeben|vorhin|in diesem moment|zur vollen stunde|um halb|guten morgen|guten abend|gute nacht|mittag)\b/iu';

    public function __construct(
        private readonly string $dir,
        private readonly float $keepHours = 48,
        private readonly int $maxBytes = 3 * 1024 * 1024 * 1024,
    ) {}

    public static function fromConfig(): self
    {
        $settings = (array) config('tv.commentary.blocks.replay', []);

        return new self((string) ($settings['dir'] ?? storage_path('app/replay')), (float) ($settings['keep_hours'] ?? 48), (int) ($settings['max_bytes'] ?? 3 * 1024 ** 3));
    }

    public static function enabled(): bool
    {
        return config('tv.commentary.blocks.replay.enabled') === true;
    }

    /**
     * Whether a block of `$scripts` (the claim payloads) with the running order `$steps` may ever be replayed.
     *
     * @param  list<array<array-key, mixed>>  $scripts
     * @param  list<string>  $steps  BlockRenderer's steps (`jingle:<id>`, `script:<id>`)
     */
    public static function evergreen(array $scripts, array $steps): bool
    {
        if ($scripts === []) {
            return false;
        }

        foreach ($scripts as $script) {
            if (! in_array($script['kind'] ?? null, self::EVERGREEN_KINDS, true) || ($script['scene'] ?? null) === ChatWishes::SCENE || in_array($script['topic'] ?? null, self::NEVER_TOPICS, true)) {
                return false;
            }

            foreach ((array) ($script['sentences'] ?? []) as $line) {
                if (is_string($line) && preg_match(self::TIMESTAMPED, $line) === 1) {
                    return false;
                }
            }
        }

        foreach ($steps as $step) {
            if (str_starts_with($step, 'jingle:time-') || str_starts_with($step, 'jingle:coming-up') || str_starts_with($step, 'jingle:news')
                || str_starts_with($step, 'jingle:headlines') || str_starts_with($step, 'jingle:back-announce') || str_starts_with($step, 'jingle:breaking')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keeps an uploaded block with its metadata, then prunes the archive to its bounds.
     *
     * @param  list<array<array-key, mixed>>  $scripts
     * @param  array{ends: list<float>, boundaries: list<float>, steps: list<string>, seconds: float}  $block
     */
    public function store(string $opus, array $scripts, array $block, CarbonImmutable $now): ?string
    {
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0755, true) && ! is_dir($this->dir)) {
            return null;
        }

        $name = $now->format('Ymd-His').'-'.(int) ($scripts[0]['id'] ?? 0);
        file_put_contents($this->dir.'/'.$name.'.opus', $opus);
        file_put_contents($this->dir.'/'.$name.'.json', (string) json_encode([
            'scripts' => array_map(fn (array $script): int => (int) ($script['id'] ?? 0), $scripts),
            'kinds' => array_values(array_unique(array_map(fn (array $script): string => (string) ($script['kind'] ?? ''), $scripts))),
            'topics' => array_values(array_unique(array_filter(array_map(fn (array $script): ?string => is_string($script['topic'] ?? null) ? $script['topic'] : null, $scripts)))),
            'rendered_at' => $now->getTimestamp(),
            'seconds' => $block['seconds'],
            'ends' => $block['ends'],
            'boundaries' => $block['boundaries'],
            'steps' => $block['steps'],
            'evergreen' => self::evergreen($scripts, $block['steps']),
            'replayed_at' => null,
        ], JSON_UNESCAPED_UNICODE));

        $this->prune($now);

        return $name;
    }

    /**
     * The evergreen block to replay now: rendered within `keep_hours` and
     * at least `$minAgeHours` ago (its first airing), not replayed within
     * `$repeatHours`, the one replayed longest ago (never replayed first,
     * then the oldest); null when there is none.
     *
     * @return array{name: string, file: string, meta: array<string, mixed>}|null
     */
    public function pick(CarbonImmutable $now, float $repeatHours, float $minAgeHours = 0.0): ?array
    {
        $candidates = [];

        foreach ($this->entries() as $name => $meta) {
            $replayed = is_int($meta['replayed_at'] ?? null) ? $meta['replayed_at'] : null;

            if (($meta['evergreen'] ?? false) !== true || ! is_file($this->dir.'/'.$name.'.opus')
                || ! is_int($meta['rendered_at'] ?? null) || $now->getTimestamp() - $meta['rendered_at'] < $minAgeHours * 3600
                || ($replayed !== null && $now->getTimestamp() - $replayed < $repeatHours * 3600)) {
                continue;
            }

            $candidates[] = ['name' => $name, 'file' => $this->dir.'/'.$name.'.opus', 'meta' => $meta, 'order' => [$replayed ?? 0, $name]];
        }

        usort($candidates, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $pick = $candidates[0] ?? null;

        return $pick === null ? null : ['name' => $pick['name'], 'file' => $pick['file'], 'meta' => $pick['meta']];
    }

    public function markReplayed(string $name, CarbonImmutable $now): void
    {
        $meta = $this->entries()[$name] ?? null;

        if ($meta !== null) {
            $meta['replayed_at'] = $now->getTimestamp();
            file_put_contents($this->dir.'/'.$name.'.json', (string) json_encode($meta, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Drops blocks older than `keep_hours`, then the oldest until the archive is under `max_bytes`; and any half pair.
     */
    public function prune(CarbonImmutable $now): void
    {
        $pairs = [];

        foreach (glob($this->dir.'/*.opus') ?: [] as $file) {
            $name = basename($file, '.opus');
            $meta = $this->entries()[$name] ?? null;

            if ($meta === null || $now->getTimestamp() - (int) ($meta['rendered_at'] ?? 0) > $this->keepHours * 3600) {
                $this->delete($name);

                continue;
            }

            $pairs[$name] = (int) filesize($file);
        }

        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            if (! is_file(substr($file, 0, -5).'.opus')) {
                @unlink($file);
            }
        }

        ksort($pairs);

        while (array_sum($pairs) > $this->maxBytes && $pairs !== []) {
            $oldest = (string) array_key_first($pairs);
            $this->delete($oldest);
            unset($pairs[$oldest]);
        }
    }

    /**
     * The archive's metadata by block name.
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

    private function delete(string $name): void
    {
        @unlink($this->dir.'/'.$name.'.opus');
        @unlink($this->dir.'/'.$name.'.json');
    }
}
