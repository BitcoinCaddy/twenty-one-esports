<?php

namespace App\Support\Caster;

/**
 * The league's pronunciation dictionary (resources/data/voice/pronunciation.json):
 * league terms respelled the way the German voice should say them ("Blockli"
 * was heard as "Block League", 2026-10-11). The fetcher applies it to each
 * sentence right before VoiceStudio; the script itself keeps the real word.
 */
final class Pronunciation
{
    /**
     * @return array<string, string>
     */
    public static function terms(): array
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/pronunciation.json')), true);
        $terms = is_array($data) && is_array($data['terms'] ?? null) ? $data['terms'] : [];

        return array_filter($terms, fn (mixed $spoken, mixed $term): bool => is_string($term) && is_string($spoken) && $term !== '', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The sentence as the voice should say it: every term as a whole word,
     * case-sensitive, longest term first (so "Zaps" is not read as "Zap" + s).
     *
     * @param  array<string, string>  $terms
     */
    public static function spoken(string $sentence, array $terms): string
    {
        $sentence = self::dates($sentence);
        uksort($terms, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($terms as $term => $spoken) {
            $sentence = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $spoken, $sentence);
        }

        return $sentence;
    }

    private const ORDINALS = [1 => 'erst', 'zweit', 'dritt', 'viert', 'fünft', 'sechst', 'siebt', 'acht', 'neunt', 'zehnt', 'elft', 'zwölft', 'dreizehnt', 'vierzehnt', 'fünfzehnt', 'sechzehnt', 'siebzehnt', 'achtzehnt', 'neunzehnt', 'zwanzigst', 'einundzwanzigst', 'zweiundzwanzigst', 'dreiundzwanzigst', 'vierundzwanzigst', 'fünfundzwanzigst', 'sechsundzwanzigst', 'siebenundzwanzigst', 'achtundzwanzigst', 'neunundzwanzigst', 'dreißigst', 'einunddreißigst'];

    private const MONTHS = ['jan' => 'Januar', 'feb' => 'Februar', 'mär' => 'März', 'mrz' => 'März', 'apr' => 'April', 'mai' => 'Mai', 'jun' => 'Juni', 'jul' => 'Juli', 'aug' => 'August', 'sep' => 'September', 'okt' => 'Oktober', 'nov' => 'November', 'dez' => 'Dezember'];

    private const WEEKDAYS = ['Mo' => 'Montag', 'Di' => 'Dienstag', 'Mi' => 'Mittwoch', 'Do' => 'Donnerstag', 'Fr' => 'Freitag', 'Sa' => 'Samstag', 'So' => 'Sonntag'];

    /**
     * Dates and times the way a German speaker says them (user 2026-10-11: "11. Oktober" was read
     * "elf Oktober"): "am 11. Okt" → "am elften Oktober", "Mi, 14. Okt 2026, 19:00 MESZ" →
     * "Mittwoch, der vierzehnte Oktober 2026, 19 Uhr". After a preposition the dative "-ten", else "der …-te".
     */
    public static function dates(string $sentence): string
    {
        $sentence = (string) preg_replace_callback('/\b(Mo|Di|Mi|Do|Fr|Sa|So),(?=\s*\d{1,2}\.)/u', fn (array $m): string => self::WEEKDAYS[$m[1]].',', $sentence);

        $sentence = (string) preg_replace_callback(
            '/(?:\b(am|vom|zum|bis zum|bis|ab|seit|nach dem|vor dem|der|den|dem)\s+)?\b(\d{1,2})\.\s*(Jan|Feb|Mär|Mrz|Apr|Mai|Jun|Jul|Aug|Sep|Okt|Nov|Dez)(?:[a-zä]+|\.(?=\s*\S))?(?=[\s,.!?]|$)/iu',
            function (array $m): string {
                $day = (int) $m[2];
                $month = self::MONTHS[mb_strtolower(mb_substr($m[3], 0, 3))] ?? $m[3];

                if (! isset(self::ORDINALS[$day])) {
                    return $m[0];
                }

                return match (mb_strtolower($m[1])) {
                    '' => 'der '.self::ORDINALS[$day].'e '.$month,
                    'der' => $m[1].' '.self::ORDINALS[$day].'e '.$month,
                    default => $m[1].' '.self::ORDINALS[$day].'en '.$month,
                };
            },
            $sentence,
        );

        $sentence = (string) preg_replace_callback('/\b(\d{1,2}):(\d{2})\b/u', fn (array $m): string => (int) $m[1].' Uhr'.((int) $m[2] === 0 ? '' : ' '.(int) $m[2]), $sentence);

        return trim((string) preg_replace('/\s*\b(MESZ|MEZ|CEST|CET|EDT|EST|UTC)\b/u', '', $sentence));
    }
}
