<?php

namespace App\Support\Caster;

/**
 * The two voices of TWENTY ONE TV as they really are in the EINUNDZWANZIG
 * podcast (user 2026-10-12: "Sirius soll auch typische Themengebiete in
 * seinem Textprofil-Style haben, wie er sie in echt in den Podcasts hat.
 * Dasselbe bei Markus auch."): Markus Turm, the voice of every TV prompt,
 * and Sirius, his side commentator in the duets (DuetDirector; consent:
 * Sirius agreed, per Markus Turm 2026-10-12).
 *
 * resources/data/voice/personas.json holds per speaker his topic areas
 * (ranked), his speaking style, running gags, signature phrases and what the
 * other one teases him about, researched from the news episodes 333 to 335
 * the two host together (transcribed, every line told apart by voice pitch)
 * and the meme research of the plan "KI-Stimme der Liga"; every verified
 * item carries its source (episode and minute, or video and time).
 *
 * The blocks go into the cached system prompt: markus() into every TV prompt
 * (CasterVoice::fixedPart()), sirius() behind it for the duets
 * (DuetScripts::systemPrompt()). Phrases are inspiration, never quotes to
 * hand out as new: the rules forbid presenting an invented line as one the
 * person said. No "#" anywhere (a prompt line with one is dropped,
 * CasterVoice::withoutHashes()): episodes are written "Folge 335".
 *
 * @phpstan-type Sourced array{text: string, source: string}
 * @phpstan-type Phrase array{wording: string, context: string, source: string}
 * @phpstan-type Persona array{name: string, role: string, topics: list<array{name: string, what: string, source: string}>, style: array{rhythm: string, fillers: list<string>, humor: string, worked_up: string}, running_gags: list<Sourced>, phrases: list<Phrase>, teased_by: list<Sourced>}
 */
final class CasterPersonas
{
    public const MARKUS = DuetDirector::MARKUS;

    public const SIRIUS = DuetDirector::SIRIUS;

    /** Markus' persona: part of every TV prompt's cached prefix. */
    public static function markus(): string
    {
        return "Persona: Markus Turm (das bist du)\n\n".self::block(self::MARKUS);
    }

    /** Sirius' persona and how the two play together: the duets' cached prefix behind markus(). */
    public static function sirius(): string
    {
        $data = self::data();

        return "Zweite Stimme: Sirius (nur bei einem Wortwechsel)\n\n".self::block(self::SIRIUS)
            ."\n\nZusammenspiel der beiden\n\n".implode("\n", array_map(fn (string $line): string => '- '.$line, $data['interplay']));
    }

    /**
     * @return list<Phrase>
     */
    public static function phrases(string $speaker): array
    {
        return self::persona($speaker)['phrases'];
    }

    /**
     * @return list<array{name: string, what: string, source: string}>
     */
    public static function topics(string $speaker): array
    {
        return self::persona($speaker)['topics'];
    }

    /**
     * @return Persona
     */
    public static function persona(string $speaker): array
    {
        return self::data()['personas'][$speaker];
    }

    private static function block(string $speaker): string
    {
        $persona = self::persona($speaker);
        $rules = self::data()['rules'];
        $list = fn (array $rows, callable $line): string => implode("\n", array_map(fn (array $row): string => '- '.$line($row), $rows));

        return implode("\n\n", [
            $persona['role'],
            "Themengebiete (die ersten zuerst)\n".$list($persona['topics'], fn (array $topic): string => "{$topic['name']}: {$topic['what']} ({$topic['source']})"),
            "Sprechweise\n- Satzrhythmus: {$persona['style']['rhythm']}\n- Füllwörter, die er wirklich benutzt: ".implode(', ', array_map(fn (string $filler): string => "„{$filler}“", $persona['style']['fillers']))
                ."\n- Humor: {$persona['style']['humor']}\n- Da regt er sich auf: {$persona['style']['worked_up']}",
            "Running Gags\n".$list($persona['running_gags'], fn (array $gag): string => "{$gag['text']} ({$gag['source']})"),
            "Typische Wendungen (wirklich so gesagt, mit Quelle; nur zum Weiterspinnen, nie wörtlich vorlesen)\n".$list($persona['phrases'], fn (array $phrase): string => "„{$phrase['wording']}“ ({$phrase['context']}; {$phrase['source']})"),
            "Damit zieht ihn der andere auf\n".$list($persona['teased_by'], fn (array $tease): string => "{$tease['text']} ({$tease['source']})"),
            "Grenzen\n".implode("\n", array_map(fn (string $rule): string => '- '.$rule, $rules)),
        ]);
    }

    /**
     * @return array{rules: list<string>, interplay: list<string>, personas: array<string, Persona>}
     */
    private static function data(): array
    {
        /** @var array{rules: list<string>, interplay: list<string>, personas: array<string, Persona>} $data */
        $data = json_decode((string) file_get_contents(resource_path('data/voice/personas.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
