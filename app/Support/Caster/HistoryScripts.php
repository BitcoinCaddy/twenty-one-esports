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
 * A long episode (LengthMix; user 2026-10-11: "Ich denke da an die
 * Bauernkriege-Geschichten … die länger sein dürfen"): the episode's facts in
 * narrative order as far as `length.history_long.max` sentences go, with an
 * aside (opinion, from the file's `asides`) after every second fact, then the
 * twist and the outro: 15 to 30 sentences, two to four minutes spoken.
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
    public function write(CarbonImmutable $now, bool $long = false): ?array
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

        return ['text' => $long ? self::buildLong($episode, $turn, $data) : self::build($episode, $turn, $data), 'episode' => $episode['id']];
    }

    /**
     * An episode as a script, the intro, twist and outro picked by `$turn`.
     *
     * @param  array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}  $episode
     * @param  array{intros: list<string>, outros: list<string>, asides: list<string>, episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>}  $data
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
     * A long episode: the intro, the facts in order with an aside after every
     * second one (the episode's other twist in the middle) while
     * `length.history_long.max` sentences allow, the twist and the outro.
     * Asides rotate with `$turn`, none twice. Every episode of
     * the file makes at least `length.history_long.min` sentences this way
     * (HistorySegmentTest holds the file to it).
     *
     * @param  array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}  $episode
     * @param  array{intros: list<string>, outros: list<string>, asides: list<string>, episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>}  $data
     */
    public static function buildLong(array $episode, int $turn, array $data): string
    {
        $intro = ScriptGuard::lines($data['intros'][$turn % count($data['intros'])]);
        $twist = ScriptGuard::lines($episode['twists'][$turn % count($episode['twists'])]);
        $outro = ScriptGuard::lines($data['outros'][$turn % count($data['outros'])]);
        $asides = $data['asides'];
        $otherTwist = count($episode['twists']) > 1 ? ScriptGuard::lines($episode['twists'][($turn + 1) % count($episode['twists'])]) : [];
        $middle = max(1, intdiv(count($episode['facts']), 2));
        $budget = (int) config('tv.commentary.length.history_long.max', 30) - count($intro) - count($twist) - count($outro);

        $body = [];
        $asidesUsed = 0;
        foreach ($episode['facts'] as $index => $fact) {
            $lines = ScriptGuard::lines($fact['text']);
            // An aside before every third, fifth … fact: after every second one told, never at the very end.
            $aside = $index > 0 && $index % 2 === 0 && $asidesUsed < count($asides) ? [$asides[($turn * 5 + $asidesUsed) % count($asides)]] : [];
            $between = $index === $middle && $otherTwist !== [] ? $otherTwist : $aside;

            if (count($body) + count($between) + count($lines) > $budget) {
                break;
            }

            array_push($body, ...$between, ...$lines);
            $asidesUsed += $between === $aside ? count($aside) : 0;
        }

        return implode("\n", [...$intro, ...$body, ...$twist, ...$outro]);
    }

    /**
     * @return array{intros: list<string>, outros: list<string>, asides: list<string>, episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>}
     */
    public static function data(): array
    {
        /** @var array{intros: list<string>, outros: list<string>, asides: list<string>, episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>} $data */
        $data = json_decode((string) file_get_contents(resource_path('data/voice/bauernkrieg.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
