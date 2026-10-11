<?php

use App\Models\CasterScript;
use App\Support\Caster\ScenePhase;
use Carbon\CarbonImmutable;

/**
 * Bytes that pass the TV commentary spool's Opus check (an Ogg page with an
 * OpusHead), `$bytes` long. Not playable audio: tests that decode it fake
 * ffmpeg (Process::fake).
 */
function casterOpus(int $bytes = 4000): string
{
    $head = 'OggS'.str_repeat("\0", 24).'OpusHead';

    return $head.str_repeat('x', max(0, $bytes - strlen($head)));
}

/**
 * A claimed commentary script of `$kind` and `$phase`, ready for ClipSpool::store().
 */
function casterScript(string $kind = CasterScript::SCENE, string $phase = ScenePhase::BREAK, ?CarbonImmutable $now = null): CasterScript
{
    $now ??= CarbonImmutable::now();

    return CasterScript::query()->create([
        'kind' => $kind, 'phase' => $phase, 'priority' => 0, 'text' => "Eins.\nZwei.", 'facts_hash' => 'h',
        'status' => CasterScript::CLAIMED, 'estimated_seconds' => 3, 'claimed_at' => $now,
        'expires_at' => $kind === CasterScript::FILLER ? $now->addYears(10) : $now->addMinutes(10),
    ]);
}

/**
 * The sentences of a whole script: of all its parts in order when it was
 * queued in parts (ScriptQueue::parts()), else its own.
 *
 * @return list<string>
 */
function casterWholeSentences(CasterScript $script): array
{
    if ($script->part_group === null) {
        return $script->sentences();
    }

    return array_merge(...CasterScript::query()->where('part_group', $script->part_group)->orderBy('part_index')->get()
        ->map(fn (CasterScript $part): array => $part->sentences())->all());
}
