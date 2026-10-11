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
        uksort($terms, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($terms as $term => $spoken) {
            $sentence = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $spoken, $sentence);
        }

        return $sentence;
    }
}
