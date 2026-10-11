<?php

namespace App\Support\Caster;

use Illuminate\Support\Facades\Cache;

/**
 * The pattern texts of the spoken reactions to the stream chat and to zaps
 * (resources/data/voice/chat-reactions.json): a zap thank-you, a greeting
 * for everybody who writes, a hello for a trusted chatter. Written without
 * the model, so the thanks never depend on it (TV_COMMENTARY_AI off, a failed
 * request). One sentence per line; a line used within the last `RECENT`
 * picks of its pool comes back only when the pool has nothing else.
 *
 * Nothing a chatter or zapper wrote reaches the text: only the amount, the
 * number of zaps, and a trusted person's own public name.
 */
class ReactionLines
{
    private const RECENT = 6;

    private const RECENT_KEY = 'caster:recent:reaction_lines';

    /** Spoken for an untrusted or nameless zapper (user 2026-10-11: "ein Zuschauer"/"jemand im Chat"). */
    public const ANONYMOUS = ['Jemand im Chat', 'Ein Zuschauer'];

    /** @var array<string, list<string>>|null */
    private ?array $pools = null;

    /**
     * The thank-you for one bundle of zaps: who and how much (a single zap)
     * or how many and how much together (a bundle), an extra-emotional line
     * when the bundle is big, then thanks and a witty line.
     */
    public function zapThanks(int $count, int $sats, ?string $name, bool $big): string
    {
        $values = ['{sats}' => self::spokenSats($sats), '{anzahl}' => self::spokenNumber($count), '{wer}' => $name ?? $this->pick('anonymous', self::ANONYMOUS)];
        $lines = [$this->fill($this->pick($count > 1 ? 'zap_bundle' : 'zap_single'), $values)];

        if ($big) {
            $lines[] = $this->pick('zap_big');
        }

        $lines[] = $this->pick('zap_thanks');

        if (! $big) {
            $lines[] = $this->pick('zap_witty');
        }

        return implode("\n", $lines);
    }

    /** A friendly greeting for people whose messages are never read out, with an invitation to play. */
    public function greeting(int $messages): string
    {
        return implode("\n", [$this->pick($messages > 1 ? 'greet_many' : 'greet_one'), $this->pick('invite')]);
    }

    /** Hello to a trusted chatter by their public name, or the plain greeting without one. */
    public function hello(?string $name): string
    {
        if ($name === null) {
            return $this->greeting(1);
        }

        return implode("\n", [$this->fill($this->pick('trusted_hello'), ['{name}' => $name]), $this->pick('invite')]);
    }

    /**
     * A reply to a trusted chatter that answers the message's intent without
     * the model (user 2026-10-11: "reagiert gar nicht auf den Inhalt der
     * Chat-Nachricht!"): a line of the intent's pool with the name, the plain
     * hello only when no intent matches. Nothing of the message is read out.
     */
    public function reply(string $message, ?string $name): string
    {
        $intent = self::intent($message);

        if ($intent === null) {
            return $this->hello($name);
        }

        return $this->fill(self::withName($this->pick('reply_'.$intent), $name), []);
    }

    /**
     * The intent of a chat message, first match wins: a request to react,
     * 24/7 or sleep, work, zaps, a compliment, a question, a greeting; null
     * when none matches.
     */
    public static function intent(string $message): ?string
    {
        $text = mb_strtolower($message);
        $patterns = [
            'react' => '/reagier|antworte|antwort mal|sag mal was|lies (?:mal )?(?:den )?chat/u',
            'sleep' => '/24\s*\/\s*7|rund um die uhr|schläfst|schlafen|schlaf\b|pause|müde|nachts|die ganze nacht|immer noch da|non-?stop/u',
            'work' => '/arbeit|\bjob\b|beruf|büro|\bchef|feierabend|schicht|urlaub/u',
            'sats' => '/\bzap|\bsats?\b|satoshi|gezappt/u',
            'thanks' => '/geil|super|\btoll|stark|\bcool|klasse|mega|nice|danke|liebe|gefällt|großartig|beste|lustig|witzig|grandios/u',
            'question' => '/\?|^\s*(?:kannst|wie|wann|was|wo|warum|wieso|weshalb|wer|bist|hast|gibt|machst|spielst|darf|willst|wirst)\b/u',
            'greeting' => '/\b(?:hallo|hi|hey|servus|moin|gm|guten (?:morgen|abend|tag)|grüß|griaß|grüezi|n\'?abend|tach)\b/u',
        ];

        foreach ($patterns as $intent => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $intent;
            }
        }

        return null;
    }

    /** A template's "{name}" filled, or taken out with its comma when there is no speakable name. */
    public static function withName(string $template, ?string $name): string
    {
        if ($name !== null) {
            return strtr($template, ['{name}' => $name]);
        }

        return trim((string) preg_replace(['/^((?:\[[a-z-]+\]\s*)*)\{name\},\s*/u', '/,\s*\{name\}(?=[,.!?])/u', '/\s*\{name\}/u'], ['$1', '', ''], $template));
    }

    /**
     * @return list<string>
     */
    public function pool(string $name): array
    {
        $this->pools ??= $this->load();

        return $this->pools[$name] ?? [];
    }

    /** "einen Sat", "zweitausendeinhundert Sats". */
    public static function spokenSats(int $sats): string
    {
        return $sats === 1 ? 'einen Sat' : self::spokenNumber($sats).' Sats';
    }

    /**
     * A whole number in German words ("einundzwanzig", "zweitausendeinhundert",
     * "eine Million zweihunderttausend"), without the intl extension.
     */
    public static function spokenNumber(int $number): string
    {
        if ($number < 0) {
            return 'minus '.self::spokenNumber(-$number);
        }

        if ($number === 0) {
            return 'null';
        }

        $parts = [];
        foreach ([1_000_000_000 => ['eine Milliarde', 'Milliarden'], 1_000_000 => ['eine Million', 'Millionen']] as $unit => [$one, $many]) {
            $count = intdiv($number, $unit);
            if ($count > 0) {
                $parts[] = $count === 1 ? $one : self::belowMillion($count).' '.$many;
                $number %= $unit;
            }
        }

        if ($number > 0) {
            $parts[] = self::belowMillion($number);
        }

        $spoken = implode(' ', $parts);

        // A lone one at the end is "eins": "hunderteins", "zweitausendeins"; "einundzwanzig" keeps its "ein".
        return str_ends_with($spoken, 'ein') ? $spoken.'s' : $spoken;
    }

    private static function belowMillion(int $number): string
    {
        $thousands = intdiv($number, 1000);
        $rest = $number % 1000;

        return ($thousands > 0 ? self::belowThousand($thousands).'tausend' : '').($rest > 0 ? self::belowThousand($rest) : '');
    }

    private static function belowThousand(int $number): string
    {
        $units = ['', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun'];
        $teens = ['zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn'];
        $tens = ['', '', 'zwanzig', 'dreißig', 'vierzig', 'fünfzig', 'sechzig', 'siebzig', 'achtzig', 'neunzig'];

        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $spoken = $hundreds > 0 ? $units[$hundreds].'hundert' : '';

        if ($rest >= 20) {
            $unit = $rest % 10;
            $spoken .= ($unit > 0 ? $units[$unit].'und' : '').$tens[intdiv($rest, 10)];
        } elseif ($rest >= 10) {
            $spoken .= $teens[$rest - 10];
        } elseif ($rest > 0) {
            $spoken .= $units[$rest];
        }

        return $spoken;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $template, array $values): string
    {
        $line = strtr($template, $values);

        // The sentence opens with a capital, also when a spelled number opens it.
        return (string) preg_replace_callback('/^((?:\[[a-z-]+\]\s*)*)(\p{Ll})/u', fn (array $m): string => $m[1].mb_strtoupper($m[2]), $line);
    }

    /**
     * A line of the pool not among the last picks (the oldest of them when every line was used lately).
     *
     * @param  list<string>|null  $lines  the pool, when it is not one of the file's
     */
    private function pick(string $pool, ?array $lines = null): string
    {
        $lines ??= $this->pool($pool);

        if ($lines === []) {
            return '';
        }

        $recent = Cache::get(self::RECENT_KEY.':'.$pool);
        $recent = is_array($recent) ? array_values(array_filter($recent, 'is_string')) : [];
        $fresh = array_values(array_diff($lines, $recent));
        $line = $fresh === [] ? (string) (array_values(array_intersect(array_reverse($recent), $lines))[0] ?? $lines[0]) : $fresh[array_rand($fresh)];

        Cache::put(self::RECENT_KEY.':'.$pool, array_slice([$line, ...array_diff($recent, [$line])], 0, min(self::RECENT, max(1, count($lines) - 1))), 86_400);

        return $line;
    }

    /**
     * @return array<string, list<string>>
     */
    private function load(): array
    {
        $data = json_decode((string) file_get_contents(resource_path('data/voice/chat-reactions.json')), true);
        $pools = [];

        foreach (is_array($data) ? $data : [] as $name => $lines) {
            if (is_array($lines)) {
                $pools[(string) $name] = array_values(array_filter($lines, 'is_string'));
            }
        }

        return $pools;
    }
}
