<?php

namespace App\Support\Caster;

/**
 * Radio craft for every TV text (plan "TWENTY ONE Radio", P3; user 2026-10-12:
 * "Ein vollwertiger Talk- und Newsradiosender mit eSports-Bezug"): teasing,
 * back references, time orientation, addressing the listener, one thought per
 * sentence, transitions, the station ident, "what's in it for me", news apart
 * from opinion and the rest of resources/data/voice/radio-craft.json, every
 * rule with the broadcaster or handbook page it comes from and a verbatim
 * evidence line.
 *
 * block() goes into the cached system prompt (CasterVoice::fixedPart(), in
 * front of Markus' persona), so the scenes, live lines, chat replies, news,
 * followed notes, history and the duets (DuetScripts::systemPrompt(), which
 * starts with fixedPart()) all carry it, byte-identical on every call. Only
 * title and rule go in: the examples stay in the JSON, because the model
 * copied template sentences (user 2026-10-12, caster.json). The house rules
 * above it win where a craft rule would stretch them (sentence length, facts
 * only). No "#" anywhere: a prompt line with one is dropped
 * (CasterVoice::withoutHashes()).
 *
 * @phpstan-type Source array{url: string, title: string, read: string}
 * @phpstan-type Rule array{id: string, title: string, rule: string, example: string, evidence: string, sources: list<Source>}
 */
final class RadioCraft
{
    /** The compact prompt block: one line per rule, under the house rules. */
    public static function block(bool $gags = true): string
    {
        // Without the running gags (day stock and long shows, P21): their rule would ask for the gags the personas left out.
        $rules = array_filter(self::rules(), fn (array $rule): bool => $gags || $rule['id'] !== 'running_gags');

        return "Radio-Handwerk (für Markus und Sirius; wie bei einem echten Talk- und Newssender, die Hausregeln oben gehen vor)\n\n"
            .implode("\n", array_map(fn (array $rule): string => "- {$rule['title']}: {$rule['rule']}", $rules));
    }

    /**
     * @return list<Rule>
     */
    public static function rules(): array
    {
        /** @var array{rules: list<Rule>} $data */
        $data = json_decode((string) file_get_contents(resource_path('data/voice/radio-craft.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data['rules'];
    }
}
