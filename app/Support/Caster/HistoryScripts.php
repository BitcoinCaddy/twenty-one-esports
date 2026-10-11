<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use Carbon\CarbonImmutable;

/**
 * "Markus erzählt vom Bauernkrieg" (user 2026-10-11: "Ein Gag wäre es, wenn
 * er ab und an mal etwas Längeres über die Bauernkriege erzählen würde"):
 * one episode of resources/data/voice/bauernkrieg.json per segment. Markus
 * tells it freely (CasterVoice::history(), user 2026-10-12: "FREIE
 * KI-generierte Rede!!!"), the episode's facts (each with its source URL in
 * the file) as the model's data; only when the voice gives nothing, the
 * pattern episode stands in: the facts in order and a twist (build()).
 *
 * A long episode (LengthMix; user 2026-10-11: "Ich denke da an die
 * Bauernkriege-Geschichten … die länger sein dürfen"): `length.history_long`
 * sentences, two to four minutes spoken (buildLong() as the fallback).
 *
 * Due every `history.every_minutes` (a random length within the range, drawn
 * from the last segment's id, so it is stable between runs); the episode
 * told least recently first, none again within `repeat_hours`.
 */
class HistoryScripts
{
    public function __construct(private CasterVoice $voice) {}

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
     * The next segment, or null when every episode was told within `repeat_hours`
     * (`$repeatMinutes` instead: a chat wish, ChatWishes, may hear an episode
     * again after an hour).
     *
     * @return array{text: string, episode: string, source: string}|null
     */
    public function write(CarbonImmutable $now, bool $long = false, ?int $repeatMinutes = null): ?array
    {
        $data = self::data();
        $told = CasterScript::query()->where('kind', CasterScript::HISTORY)->where('facts_hash', 'like', self::HASH_PREFIX.'%')
            ->selectRaw('facts_hash, max(created_at) as told_at')->groupBy('facts_hash')->pluck('told_at', 'facts_hash')->all();
        // `$repeatMinutes` 0: no repeat window, the episode told longest ago comes next.
        $since = $repeatMinutes === null ? $now->subHours((int) config('tv.commentary.history.repeat_hours', 24)) : $now->subMinutes(max(0, $repeatMinutes));

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

        // Markus narrates the episode freely (CasterVoice::history()); the pattern episode only when the voice gives nothing.
        $told = ScriptQueue::aiEnabled() ? $this->voice->history($episode, $long) : null;

        if ($told === null && ! PatternScripts::enabled()) {
            return null;
        }

        return $told !== null
            ? ['text' => $told, 'episode' => $episode['id'], 'source' => CasterScript::SOURCE_AI]
            : ['text' => $long ? self::buildLong($episode, $turn) : self::build($episode, $turn), 'episode' => $episode['id'], 'source' => CasterScript::SOURCE_PATTERN];
    }

    /**
     * The pattern episode (the fallback when the voice gives nothing): the
     * episode's facts in order, as many as fit `history.sentences.max` (at
     * least three), and one twist picked by `$turn`. No fixed intro, aside or
     * outro any more (user 2026-10-12: "'Und gleich geht's weiter mit der
     * Liga' kommt auch ständig! ALLE WIEDERHOLUNGEN RAUS!!!").
     *
     * @param  array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}  $episode
     */
    public static function build(array $episode, int $turn): string
    {
        $twist = ScriptGuard::lines($episode['twists'][$turn % count($episode['twists'])]);
        $budget = (int) config('tv.commentary.history.sentences.max', 12) - count($twist);

        $facts = [];
        foreach ($episode['facts'] as $index => $fact) {
            $lines = ScriptGuard::lines($fact['text']);

            // At least three facts; beyond them only what fits the sentence budget.
            if ($index >= 3 && count($facts) + count($lines) > $budget) {
                break;
            }

            array_push($facts, ...$lines);
        }

        return implode("\n", [...$facts, ...$twist]);
    }

    /**
     * The long pattern episode: every fact in narrative order as far as
     * `length.history_long.max` sentences go, the episode's other twist in
     * the middle, its twist at the end.
     *
     * @param  array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}  $episode
     */
    public static function buildLong(array $episode, int $turn): string
    {
        $twist = ScriptGuard::lines($episode['twists'][$turn % count($episode['twists'])]);
        $otherTwist = count($episode['twists']) > 1 ? ScriptGuard::lines($episode['twists'][($turn + 1) % count($episode['twists'])]) : [];
        $middle = max(1, intdiv(count($episode['facts']), 2));
        $budget = (int) config('tv.commentary.length.history_long.max', 30) - count($twist);

        $body = [];
        foreach ($episode['facts'] as $index => $fact) {
            $lines = ScriptGuard::lines($fact['text']);
            $between = $index === $middle ? $otherTwist : [];

            if (count($body) + count($between) + count($lines) > $budget) {
                break;
            }

            array_push($body, ...$between, ...$lines);
        }

        return implode("\n", [...$body, ...$twist]);
    }

    /**
     * @return array{episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>}
     */
    public static function data(): array
    {
        /** @var array{episodes: list<array{id: string, title: string, facts: list<array{text: string, source: string}>, twists: list<string>}>} $data */
        $data = json_decode((string) file_get_contents(resource_path('data/voice/bauernkrieg.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
