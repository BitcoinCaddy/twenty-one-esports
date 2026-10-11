<?php

namespace App\Support\Caster;

/**
 * The first gate of the news segment (user 2026-10-11: sarcasm about
 * politics, never about suffering): a headline about death, disaster,
 * crimes against people, war, terror, abuse or suicide is never spoken, and
 * neither is a live ticker (it changes under the headline). Deterministic
 * word lists, German and English; the league voice checks once more
 * (NewsScripts). It errs on the side of skipping: a skipped political
 * headline costs nothing, a joke about victims costs everything.
 */
final class NewsFilter
{
    public const TRAGEDY = 'tragedy';

    public const LIVE = 'live';

    /** Whole German words. */
    private const WORDS = [
        'tot', 'tote', 'toten', 'tod', 'todes', 'stirbt', 'starb', 'sterben', 'gestorben', 'leiche', 'leichen',
        'brand', 'brände', 'flut', 'fluten', 'sturm', 'krieg', 'kriege', 'opfer', 'gewalt', 'bombe', 'bomben',
        'rakete', 'raketen', 'messer', 'hunger', 'seuche', 'pandemie', 'epidemie', 'vermisst', 'vermisste',
        'operiert', 'erkrankt', 'krebs', 'rassist', 'rassisten', 'rassistisch', 'rassistische', 'rassistischen',
    ];

    /** Whole English words, for English headlines only ("war" is also German for "was"). */
    private const WORDS_EN = [
        'dead', 'death', 'deaths', 'dies', 'died', 'kill', 'kills', 'killed', 'killing', 'war', 'wars',
        'attack', 'attacks', 'bomb', 'bombs', 'missile', 'missiles', 'shooting', 'shot', 'victim', 'victims',
        'fire', 'flood', 'floods', 'disaster', 'famine', 'stabbing', 'rape', 'body', 'bodies', 'ceasefire',
    ];

    /** Word parts (German compounds: "Ukrainekrieg", "Drohnenangriff", "Todesopfer"). */
    private const PARTS = [
        'krieg', 'angriff', 'anschlag', 'attentat', 'mord', 'mörder', 'terror', 'unglück', 'katastroph', 'getötet',
        'tötet', 'tötung', 'verletz', 'missbrauch', 'vergewalt', 'suizid', 'selbstmord', 'amok', 'erschoss',
        'erstoch', 'schüsse', 'schießerei', 'massaker', 'genozid', 'völkermord', 'geisel', 'explosion', 'absturz',
        'abgestürzt', 'verunglück', 'erdbeben', 'hochwasser', 'überschwemm', 'lawine', 'tsunami', 'hinricht',
        'todesopfer', 'großbrand', 'waldbrand', 'wohnungsbrand', 'misshandl', 'entführ', 'kinderporn',
        'murder', 'hostage', 'injur', 'abuse', 'suicide', 'massacre', 'genocide', 'earthquake',
        'wildfire', 'hurricane', 'casualt', 'fatal', 'kidnap', 'airstrike',
        // War, persecution and hatred: no topic for sarcasm, whatever the headline says about it.
        'waffenruhe', 'waffenstillstand', 'eskalation', 'antisemit', 'holocaust', 'rassismus', 'großdeutsch',
        'rechtsextrem', 'linksextrem', 'islamist', 'nazi',
    ];

    /** Live tickers and blogs. */
    private const LIVE_PARTS = ['liveblog', 'live-blog', 'liveticker', 'live-ticker', 'newsblog', '+++'];

    /** Why the headline (in `$language`, de or en) is not spoken (TRAGEDY, LIVE), or null when it may be. */
    public static function reason(string $headline, string $language = 'de'): ?string
    {
        $lower = mb_strtolower($headline);

        foreach (self::LIVE_PARTS as $part) {
            if (str_contains($lower, $part)) {
                return self::LIVE;
            }
        }

        foreach (preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array($word, self::WORDS, true) || ($language === 'en' && in_array($word, self::WORDS_EN, true))) {
                return self::TRAGEDY;
            }

            foreach (self::PARTS as $part) {
                if (str_contains($word, $part)) {
                    return self::TRAGEDY;
                }
            }
        }

        return null;
    }
}
