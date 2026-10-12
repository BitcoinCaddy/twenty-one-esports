<?php

namespace App\Support\Caster;

/**
 * The first gate of the news segment (user 2026-10-11: sarcasm about
 * politics, never about suffering): a headline about death, disaster,
 * crimes against people, war, terror, abuse or suicide is never spoken, and
 * neither is a live ticker (it changes under the headline), nor a headline
 * that reads as another language than German (the voice is German; it
 * mangled English headlines word for word). Deterministic
 * word lists, German and English; the league voice checks once more
 * (NewsScripts). It errs on the side of skipping: a skipped political
 * headline costs nothing, a joke about victims costs everything.
 */
final class NewsFilter
{
    public const TRAGEDY = 'tragedy';

    public const LIVE = 'live';

    public const FOREIGN = 'foreign';

    /** An advertisement in a feed ("Anzeige: Legos Mondrakete …", Golem, prod 2026-10-13; "heise-Angebot: …"). */
    public const AD = 'ad';

    private const AD_PATTERN = '/^\s*(?:anzeige|werbung|sponsored|heise-angebot|angebot|deal)\s*[:|]/iu';

    /** Function words that mark an English headline ("Saylor teases … after ending September with …"). */
    private const ENGLISH_MARKERS = [
        'the', 'and', 'of', 'to', 'for', 'with', 'after', 'on', 'at', 'by', 'from', 'is', 'are', 'was',
        'its', 'his', 'her', 'their', 'as', 'into', 'over', 'up', 'out', 'what', 'why', 'how', 'who', 'will', 'could',
        'should', 'says', 'said', 'amid', 'while', 'than', 'this', 'that', 'it', 'be', 'has', 'have', 'new', 'wants',
    ];

    /** Function words that mark a German one. */
    private const GERMAN_MARKERS = [
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'und', 'oder', 'mit', 'für', 'von', 'vom',
        'zum', 'zur', 'auf', 'aus', 'bei', 'nach', 'über', 'unter', 'ist', 'sind', 'wird', 'will', 'nicht', 'kein',
        'keine', 'was', 'wie', 'wer', 'warum', 'im', 'am', 'um', 'gegen', 'noch', 'jetzt', 'neue', 'neuer', 'neues',
    ];

    /** Whole German words. */
    private const WORDS = [
        'tot', 'tote', 'toten', 'tod', 'todes', 'stirbt', 'starb', 'sterben', 'gestorben', 'leiche', 'leichen',
        'brand', 'brände', 'flut', 'fluten', 'sturm', 'krieg', 'kriege', 'opfer', 'gewalt', 'bombe', 'bomben',
        'rakete', 'raketen', 'messer', 'hunger', 'seuche', 'pandemie', 'epidemie', 'vermisst', 'vermisste',
        'operiert', 'erkrankt', 'krebs', 'rassist', 'rassisten', 'rassistisch', 'rassistische', 'rassistischen',
    ];

    /** Whole English words, for an English headline the language check missed (not "war": German for "was"). */
    private const WORDS_EN = [
        'dead', 'death', 'deaths', 'died', 'kill', 'kills', 'killed', 'killing', 'wars',
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

    /** Why the headline (from a feed in `$language`, de or en) is not spoken (FOREIGN, AD, LIVE, TRAGEDY), or null when it may be. */
    public static function reason(string $headline, string $language = 'de'): ?string
    {
        if ($language !== 'de' || ! self::readsGerman($headline)) {
            return self::FOREIGN;
        }

        if (preg_match(self::AD_PATTERN, $headline) === 1) {
            return self::AD;
        }

        return self::unfit($headline);
    }

    /**
     * Why a text in any language is never spoken (LIVE, TRAGEDY), or null:
     * the word lists of reason() without its language check, for a text the
     * league voice retells in German (a followed profile's note, FollowedScripts).
     */
    public static function unfit(string $text): ?string
    {
        $lower = mb_strtolower($text);

        foreach (self::LIVE_PARTS as $part) {
            if (str_contains($lower, $part)) {
                return self::LIVE;
            }
        }

        foreach (preg_split('/[^\p{L}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array($word, self::WORDS, true) || in_array($word, self::WORDS_EN, true)) {
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

    /**
     * Whether a headline reads as German: an umlaut or ß, or not more English
     * than German function words (and fewer than two English ones). A headline
     * of names and nouns alone ("Bitcoin-ETF: Rekord") counts as German.
     */
    public static function readsGerman(string $headline): bool
    {
        if (preg_match('/[äöüß]/iu', $headline) === 1) {
            return true;
        }

        $english = 0;
        $german = 0;

        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($headline), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // "was", "will" are both: they count for neither.
            $isEnglish = in_array($word, self::ENGLISH_MARKERS, true);
            $isGerman = in_array($word, self::GERMAN_MARKERS, true);
            $english += $isEnglish && ! $isGerman ? 1 : 0;
            $german += $isGerman && ! $isEnglish ? 1 : 0;
        }

        return $english < 2 || $german > $english;
    }
}
