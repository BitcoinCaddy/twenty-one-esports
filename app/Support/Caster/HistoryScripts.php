<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use Carbon\CarbonImmutable;

/**
 * "Markus erzählt vom Bauernkrieg" (user 2026-10-11: "Ein Gag wäre es, wenn
 * er ab und an mal etwas Längeres über die Bauernkriege erzählen würde"):
 * one episode of resources/data/voice/bauernkrieg.json per segment, built
 * from the file alone, no model: an intro, the episode's facts in order (as
 * many as fit `history.sentences.max`, at least three), one twist (a joke,
 * announced as one) and an outro. Every fact carries its source URL in the
 * file; nothing else in the segment claims a fact.
 *
 * Due every `history.every_minutes` (a random length within the range, drawn
 * from the last segment's id, so it is stable between runs); the episode
 * told least recently first, none again within `repeat_hours`.
 */
class HistoryScripts
{
    /** facts_hash of a history script: this prefix and the episode id. */
    public const HASH_PREFIX = 'history:';

    /** Whether the next history segment is due. */
    public function due(CarbonImmutable $now): bool
    {
        $last = CasterScript::query()->where('kind', CasterScript::HISTORY)->orderByDesc('id')->first();

        if ($last === null) {
            return true;
        }

        $range = (array) config('tv.commentary.history.every_minutes', []);
        $min = max(1, (int) ($range['min'] ?? 45));
        $max = max($min, (int) ($range['max'] ?? 90));
        $minutes = $min + crc32((string) $last->id) % ($max - $min + 1);

        return $last->created_at === null || CarbonImmutable::parse($last->created_at)->addMinutes($minutes)->lessThanOrEqualTo($now);
    }

    /**
     * The next segment, or null when every episode was told within `repeat_hours`.
     *
     * @return array{text: string, episode: string}|null
     */
    public function write(CarbonImmutable $now): ?array
    {
        $data = self::data();
        $told = CasterScript::query()->where('kind', CasterScript::HISTORY)->where('facts_hash', 'like', self::HASH_PREFIX.'%')
            ->selectRaw('facts_hash, max(created_at) as told_at')->groupBy('facts_hash')->pluck('told_at', 'facts_hash')->all();
        $since = $now->subHours((int) config('tv.commentary.history.repeat_hours', 24));

        $candidates = [];
        foreach ($data['episodes'] as $index => $episode) {
            $toldAt = $told[self::HASH_PREFIX.$episode['id']] ?? null;

            if ($toldAt !== null && CarbonImmutable::parse((string) $toldAt)->greaterThan($since)) {
                continue;
            }

            // Never told first (in file order), then the one told longest ago.
            $candidates[] = ['episode' => $episode, 'order' => $toldAt === null ? sprintf('0-%04d', $index) : '1-'.CarbonImmutable::parse((string) $toldAt)->format('YmdHis')];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => strcmp($a['order'], $b['order']));
        $episode = $candidates[0]['episode'];
        $turn = count($told) + intdiv($now->getTimestamp(), 86_400);

        return ['text' => self::build($episode, $turn, $data), 'episode' => $episode['id']];
    }

    /**
     * An episode as a script, the intro, twist and outro picked by `$turn`.
     *
     * @param  array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}  $episode
     * @param  array{intros: list<string>, outros: list<string>, episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>}  $data
     */
    public static function build(array $episode, int $turn, array $data): string
    {
        $intro = ScriptGuard::lines($data['intros'][$turn % count($data['intros'])]);
        $twist = ScriptGuard::lines($episode['twists'][$turn % count($episode['twists'])]);
        $outro = ScriptGuard::lines($data['outros'][$turn % count($data['outros'])]);
        $budget = (int) config('tv.commentary.history.sentences.max', 12) - count($intro) - count($twist) - count($outro);

        $facts = [];
        foreach ($episode['facts'] as $index => $fact) {
            $lines = ScriptGuard::lines($fact['text']);

            // At least three facts; beyond them only what fits the sentence budget.
            if ($index >= 3 && count($facts) + count($lines) > $budget) {
                break;
            }

            array_push($facts, ...$lines);
        }

        return implode("\n", [...$intro, ...$facts, ...$twist, ...$outro]);
    }

    /**
     * @return array{intros: list<string>, outros: list<string>, episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>}
     */
    public static function data(): array
    {
        /** @var array{intros: list<string>, outros: list<string>, episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>} $data */
        $data = json_decode((string) file_get_contents(resource_path('data/voice/bauernkrieg.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
