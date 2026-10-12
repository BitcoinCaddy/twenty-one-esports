<?php

namespace App\Support\Caster\Programme;

use App\Support\Caster\ReactionLines;

/**
 * The fact check of the roast formats (plan "TWENTY ONE Radio", P10;
 * RoastScripts): a roast may mock anything, but its facts are the
 * material's. The material carries every number as words (spell()), so:
 *
 * - NUMBER: a digit anywhere in the draft (the material has none);
 * - AMOUNT: a number word right before a unit ("dreiundachtzig Prozent",
 *   "zwölf Millionen Euro", "neun Züge") that the material does not have;
 * - QUOTE: a quotation of four words or more that is not in the material
 *   word for word (letters and digits, any case);
 * - NAME: a name-like word (an acronym, an inner capital, letters with
 *   digits) or a known person (`esports.voice.persons`) or a league player
 *   (`$players`) the material does not have; the speakers and the station
 *   are not names here.
 *
 * Opinion, comparison and insult of politics stay free (TONE in the cached
 * prefix draws the limits); an invented common noun still passes, as in
 * the other rubrics.
 */
final class RoastFacts
{
    public const NUMBER = 'roast_number';

    public const AMOUNT = 'roast_amount';

    public const QUOTE = 'roast_quote';

    public const NAME = 'roast_name';

    /** Words that may follow a number as its unit (lower case, a stem ending in `*` takes any ending). */
    private const UNITS = ['prozent', 'prozentpunkt*', 'basispunkt*', 'euro', 'cent', 'dollar', 'bitcoin', 'btc', 'million*', 'milliard*', 'tausend',
        'jahr', 'jahre', 'jahren', 'monat*', 'tag', 'tage', 'tagen', 'stunde*', 'minute*', 'sekunde*', 'zug', 'züge', 'zügen', 'partie*', 'niederlage*',
        'sieg', 'siege', 'siegen', 'punkt', 'punkte', 'punkten', 'meter', 'kilometer', 'kubikmeter', 'quadratmeter', 'mark', 'pfennig', 'bäume', 'firmen', 'unternehmen'];

    /** Amounts that are Bitcoin's own rules, true in every roast: the supply cap and the block interval. */
    private const CANON_AMOUNTS = ['einundzwanzig millionen', 'einundzwanzig millionen bitcoin', 'zehn minuten'];

    /** Words that look like names to the check but are the station's or the speakers'. */
    private const NOT_NAMES = ['TWENTY', 'ONE', 'TV', 'ESPORTS', 'RADIO', 'EINUNDZWANZIG', 'EZB', 'EU', 'USA', 'US', 'BTC', 'KI', 'DM', 'NOSTR', 'ETF', 'ETFS'];

    /**
     * What a draft breaks against its material.
     *
     * @param  list<string>  $names  the names the material carries (players, authors, companies)
     * @param  list<string>  $players  every league player name: one the material does not carry is invented
     * @param  list<string>  $speakers  the speakers' names (never a violation)
     * @return list<string>
     */
    public static function check(string $text, string $data, array $names = [], array $players = [], array $speakers = []): array
    {
        $violations = [];
        $lowerData = mb_strtolower($data.' '.implode(' ', $names));

        if (preg_match('/\d/u', $text) === 1) {
            $violations[] = self::NUMBER;
        }

        if (self::inventedAmount($text, $lowerData)) {
            $violations[] = self::AMOUNT;
        }

        $squash = fn (string $value): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
        $squashedData = $squash($data);
        foreach (self::quotes($text) as $quote) {
            if (count(preg_split('/\s+/u', trim($quote), -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 4 && ! str_contains($squashedData, $squash($quote))) {
                $violations[] = self::QUOTE;
                break;
            }
        }

        $known = array_flip(preg_split('/[^\p{L}\p{N}]+/u', $lowerData, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $own = array_map('mb_strtolower', $speakers);
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $nameLike = preg_match('/^\p{Lu}{2,}$/u', $word) === 1 || preg_match('/\p{Ll}\p{Lu}/u', $word) === 1;

            if ($nameLike && ! isset($known[mb_strtolower($word)]) && ! in_array(mb_strtoupper($word), self::NOT_NAMES, true) && ! in_array(mb_strtolower($word), $own, true)) {
                $violations[] = self::NAME;
                break;
            }
        }

        foreach ([...(array) config('esports.voice.persons', []), ...$players] as $person) {
            $person = trim((string) $person);

            if ($person !== '' && mb_strlen($person) >= 3 && ! in_array(mb_strtolower($person), $own, true) && ! str_contains($lowerData, mb_strtolower($person))
                && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($person, '/').'(?![\p{L}\p{N}])/iu', $text) === 1) {
                $violations[] = self::NAME;
                break;
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * The quotations of a text: what stands between „…“, "…", »…« or «…».
     *
     * @return list<string>
     */
    public static function quotes(string $text): array
    {
        preg_match_all('/„([^“”"]+)[“”"]|"([^"]+)"|»([^«]+)«|«([^»]+)»/u', $text, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(fn (array $match): string => trim(($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '') ?: ($match[4] ?? '')), $matches)));
    }

    /** Whether a number word before a unit is one the material does not have. */
    private static function inventedAmount(string $text, string $lowerData): bool
    {
        $units = implode('|', array_map(fn (string $unit): string => str_ends_with($unit, '*') ? preg_quote(rtrim($unit, '*'), '/').'\p{L}*' : preg_quote($unit, '/'), self::UNITS));
        preg_match_all('/(?<![\p{L}])([\p{L}]+(?:\s+komma(?:\s+[\p{L}]+){1,3}?)?)\s+(?:'.$units.')(?![\p{L}])/iu', $text, $matches, PREG_SET_ORDER);
        $known = self::numberPhrases($lowerData);

        foreach ($matches as $match) {
            $phrase = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($match[1])));
            $first = explode(' ', $phrase)[0];

            $whole = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $match[0])));
            if (self::isNumberWord($first) && ! in_array($phrase, ['ein', 'eine', 'einen', 'einem', 'einer', 'eins'], true) && ! isset($known[$phrase])
                && ! in_array($whole, self::CANON_AMOUNTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole numbers a text says ("drei komma sieben fünf", "zwölf"), lower case: a number word, then "komma" and its
     * digits; "sieben" inside "drei komma sieben fünf" is no number of its own.
     *
     * @return array<string, true>
     */
    public static function numberPhrases(string $text): array
    {
        $tokens = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $phrases = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (! self::isNumberWord($tokens[$i]) && ! in_array($tokens[$i], ['ein', 'eine', 'eins', 'null'], true)) {
                continue;
            }

            $phrase = $tokens[$i];
            if (($tokens[$i + 1] ?? '') === 'komma') {
                $j = $i + 2;
                $digits = [];
                while ($j < $n && count($digits) < 3 && (self::isNumberWord($tokens[$j]) || in_array($tokens[$j], ['null', 'eins'], true))) {
                    $digits[] = $tokens[$j];
                    $j++;
                }
                // Every shorter reading too: "drei komma sieben" of "drei komma sieben fünf" is the same figure, rounded.
                for ($k = 1; $k <= count($digits); $k++) {
                    $phrases[$phrase.' komma '.implode(' ', array_slice($digits, 0, $k))] = true;
                }
                $i = $j - 1;
            }
            $phrases[$phrase] = true;
        }

        return $phrases;
    }

    /** Whether a lower-case word is a German number word from zwei to neunhundertneunundneunzigtausend (or a round million word). */
    public static function isNumberWord(string $word): bool
    {
        static $words = null;

        if ($words === null) {
            $words = [];
            for ($n = 0; $n <= 999; $n++) {
                $words[mb_strtolower(ReactionLines::spokenNumber($n))] = true;
            }
        }

        if (isset($words[$word])) {
            return true;
        }

        // "zweihundertdreißigtausendfünfhundert": a thousands part and a rest, both number words.
        if (preg_match('/^(.*)tausend(.*)$/u', $word, $parts) === 1) {
            return ($parts[1] === '' || isset($words[$parts[1]]) || $parts[1] === 'ein') && ($parts[2] === '' || isset($words[$parts[2]]));
        }

        return false;
    }

    /**
     * A text with every number as German words, so the voice reads it right and the check above can compare words:
     * "2,15 %" -> "zwei Komma eins fünf Prozent", "100.000 Euro" -> "hunderttausend Euro". `$english`: the text writes
     * numbers the English way ("2.15", "1,234,567"); else the German way ("2,15", "1.234.567"). "Mio." and "Mrd." become
     * "Millionen" and "Milliarden".
     */
    public static function spell(string $text, bool $english = false): string
    {
        // "2MB" is "2 MB": a number glued to its unit would be read as one word.
        $text = (string) preg_replace('/(\d)(?=\p{L})/u', '$1 ', $text);
        $text = (string) preg_replace(['/\bMio\.(?=\s|$)/u', '/\bMrd\.(?=\s|$)/u', '/\s*%/u'], [' Millionen', ' Milliarden', ' Prozent'], $text);
        if ($english) {
            // "$32M", "$1.2B": the amount, then its unit and the currency (real Decrypt headline 2026-10-12: "Bitmine Adds $32M in Ethereum").
            $text = (string) preg_replace_callback('/\$(\d+(?:\.\d+)?)\s?([KMB])\b/u', fn (array $m): string => $m[1].' '.['K' => 'Tausend', 'M' => 'Millionen', 'B' => 'Milliarden'][$m[2]].' Dollar', $text);
            // "$2 trillion" (CoinDesk headline 2026-10-12): an English trillion is a German Billion.
            $text = (string) preg_replace_callback('/\$(\d+(?:\.\d+)?)\s+(trillion|billion|million)\b/iu', fn (array $m): string => $m[1].' '.['trillion' => 'Billionen', 'billion' => 'Milliarden', 'million' => 'Millionen'][mb_strtolower($m[2])].' Dollar', $text);
            $text = (string) preg_replace('/\$(\d[\d,.]*)/u', '$1 Dollar', $text);
            // The Fed's fractions: "3-3/4" is 3.75, "1/4" is 0.25.
            $fraction = fn (int $whole, int $over, int $under): string => $under === 0 ? "{$whole}" : rtrim(rtrim(number_format($whole + $over / $under, 2, '.', ''), '0'), '.');
            $text = (string) preg_replace_callback('/(?<![\d\/])(\d+)-(\d)\/(\d)(?![\d\/])/u', fn (array $m): string => $fraction((int) $m[1], (int) $m[2], (int) $m[3]), $text);
            $text = (string) preg_replace_callback('/(?<![\d\/.,])(\d)\/(\d)(?![\d\/])/u', fn (array $m): string => $fraction(0, (int) $m[1], (int) $m[2]), $text);
        }

        [$thousands, $decimal] = $english ? [',', '.'] : ['.', ','];
        $pattern = '/(?<![\p{L}\p{N}])([+\-−]?)(\d{1,3}(?:'.preg_quote($thousands, '/').'\d{3})+|\d+)(?:'.preg_quote($decimal, '/').'(\d+))?(?![\p{N}])/u';

        $text = (string) preg_replace_callback($pattern, function (array $match): string {
            $whole = (int) str_replace(['.', ','], '', $match[2]);
            $sign = match ($match[1]) {
                '+' => 'plus ',
                '-', '−' => 'minus ',
                default => '',
            };
            $spoken = ReactionLines::spokenNumber($whole);
            $fraction = isset($match[3]) && $match[3] !== '' ? ' Komma '.implode(' ', array_map(fn (string $digit): string => ReactionLines::spokenNumber((int) $digit), mb_str_split($match[3]))) : '';

            return $sign.$spoken.$fraction;
        }, $text);

        // Whatever digit is left (a stray digit glued to letters, "MSTR3") goes as single digits.
        return (string) preg_replace_callback('/\d/u', fn (array $digit): string => ' '.ReactionLines::spokenNumber((int) $digit[0]).' ', $text);
    }
}
