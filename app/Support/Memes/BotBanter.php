<?php

namespace App\Support\Memes;

use App\Jobs\WriteBotBanter;
use App\Support\Voice\LeagueVoice;
use Illuminate\Support\Facades\Cache;

/**
 * What a bot opponent says in the game chat (plan "KI-Stimme der Liga", P5): per occasion a few lines of its
 * archetype (BotArchetypes), handed to the page with its config; the page shows one at a time and the player can
 * mute them (resources/js/pong/banter.js, kept in the browser).
 *
 * Lines come from two places: the archetype's templates, always, and a pool the league voice wrote ahead (channel
 * `bot_chat`, default off). A page never waits for the model: when a pool is short, a queued job (WriteBotBanter)
 * asks the voice for one more line of one occasion, at most once per archetype in ten minutes, and the page goes on
 * with what it has. The pool lives in the cache as lists of strings (`memes:banter:<archetype>:<occasion>`).
 *
 * Never against a person: a line of the voice goes into a pool only when the voice's own guard let it through AND
 * refusals() finds nothing: no name of a real person (`esports.voice.persons` plus the figures of the games), no
 * handle, mention, link or number, nothing from the blocklist, at most `esports.voice.channels.bot_chat.max_chars`.
 */
final class BotBanter
{
    /** Real people the games show as figures, on top of `esports.voice.persons`: never named by a bot. */
    public const FIGURES = ['Lagarde', 'Schiff', 'Peter Schiff', 'Schnabel', 'Saylor', 'Satoshi', 'Dorsey', 'Jack Dorsey', 'Gigi', 'Niko', 'Markus', 'Turm'];

    /** Lines per occasion the page gets. */
    public const LINES_PER_OCCASION = 4;

    private const POOL_TTL_SECONDS = 7 * 86_400;

    private const PENDING_TTL_SECONDS = 600;

    public const REFUSE_EMPTY = 'empty';

    public const REFUSE_TOO_LONG = 'too_long';

    public const REFUSE_PERSON = 'person';

    public const REFUSE_HANDLE = 'handle';

    public const REFUSE_NUMBER = 'number';

    public const REFUSE_BLOCKLIST = 'blocklist';

    /**
     * The banter of one of Proof of Pong's bots for its page, or null for a bot without an archetype.
     *
     * @return array{archetype: string, name: string, lines: array<string, list<string>>}|null
     */
    public function forPongBot(string $botId): ?array
    {
        $archetype = BotArchetypes::PONG_BOTS[$botId] ?? null;

        return $archetype === null ? null : $this->forArchetype($archetype);
    }

    /**
     * @return array{archetype: string, name: string, lines: array<string, list<string>>}|null
     */
    public function forArchetype(string $archetype): ?array
    {
        $character = BotArchetypes::ROSTER[$archetype] ?? null;

        if ($character === null) {
            return null;
        }

        $lines = [];
        $short = null;

        foreach (BotArchetypes::OCCASIONS as $occasion) {
            $pool = $this->pool($archetype, $occasion);
            $templates = array_map(fn (array $line): string => $line[0], $character['lines'][$occasion]);
            $mixed = array_values(array_unique([...$pool, ...$templates]));
            shuffle($mixed);
            $lines[$occasion] = array_slice($mixed, 0, self::LINES_PER_OCCASION);

            if ($short === null && count($pool) < (int) config('esports.voice.memes.banter_pool', 8)) {
                $short = $occasion;
            }
        }

        if ($short !== null && config('esports.voice.channels.bot_chat.enabled') === true && Cache::add("memes:banter-pending:{$archetype}", 1, self::PENDING_TTL_SECONDS)) {
            WriteBotBanter::dispatch($archetype, $short);
        }

        return ['archetype' => $archetype, 'name' => $character['name'], 'lines' => $lines];
    }

    /**
     * Asks the voice for one line of an archetype's occasion and keeps it in the pool when both guards let it
     * through (WriteBotBanter). Returns whether a line was added.
     */
    public function write(string $archetype, string $occasion, LeagueVoice $voice): bool
    {
        $character = BotArchetypes::ROSTER[$archetype] ?? null;

        if ($character === null || ! in_array($occasion, BotArchetypes::OCCASIONS, true)) {
            return false;
        }

        $line = $voice->say(self::moment($occasion), [
            'bot' => $character['name'],
            'character' => $character['about'],
            'situation' => self::situation($occasion),
            'speaker' => 'Der Bot spricht selbst im Spiel-Chat: den Spieler mit du, ohne Namen, frech und freundlich.',
        ], 'bot_chat');

        if ($line === null || $this->refusals($line->text) !== []) {
            return false;
        }

        $pool = $this->pool($archetype, $occasion);

        if (in_array($line->text, $pool, true)) {
            return false;
        }

        $keep = max(1, (int) config('esports.voice.memes.banter_pool', 8));
        Cache::put(self::poolKey($archetype, $occasion), array_slice([$line->text, ...$pool], 0, $keep), self::POOL_TTL_SECONDS);

        return true;
    }

    /**
     * What keeps a line out of the game chat (empty: it may be said), on top of the voice's own guard.
     *
     * @return list<string>
     */
    public function refusals(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [self::REFUSE_EMPTY];
        }

        $refusals = [];
        $lower = mb_strtolower($text);

        if (mb_strlen($text) > (int) config('esports.voice.channels.bot_chat.max_chars', 120)) {
            $refusals[] = self::REFUSE_TOO_LONG;
        }

        foreach ([...array_map('strval', (array) config('esports.voice.persons', [])), ...self::FIGURES] as $name) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($name), '/').'(?![\p{L}\p{N}])/u', $lower) === 1) {
                $refusals[] = self::REFUSE_PERSON;
                break;
            }
        }

        if (preg_match('~@|nostr:|npub1|https?://|www\.|#~iu', $text) === 1) {
            $refusals[] = self::REFUSE_HANDLE;
        }

        if (preg_match('/\d/u', $text) === 1) {
            $refusals[] = self::REFUSE_NUMBER;
        }

        foreach ((array) config('esports.voice.blocklist', []) as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower((string) $word), '/').'(?![\p{L}\p{N}])/u', $lower) === 1) {
                $refusals[] = self::REFUSE_BLOCKLIST;
                break;
            }
        }

        return $refusals;
    }

    /**
     * @return list<string>
     */
    public function pool(string $archetype, string $occasion): array
    {
        $stored = Cache::get(self::poolKey($archetype, $occasion));

        return is_array($stored) ? array_values(array_filter($stored, fn (mixed $line): bool => is_string($line) && $this->refusals($line) === [])) : [];
    }

    public static function poolKey(string $archetype, string $occasion): string
    {
        return "memes:banter:{$archetype}:{$occasion}";
    }

    /** The voice's moment for an occasion (VoiceCanon::MOMENTS). */
    private static function moment(string $occasion): string
    {
        return match ($occasion) {
            'bot_point', 'bot_win' => 'win',
            'player_point', 'player_win' => 'loss',
            'deuce' => 'pong_deuce',
            default => 'general',
        };
    }

    private static function situation(string $occasion): string
    {
        return match ($occasion) {
            'start' => 'Vor dem ersten Aufschlag einer Partie Proof of Pong gegen den Spieler.',
            'bot_point' => 'Der Bot hat gerade einen Punkt gemacht.',
            'player_point' => 'Der Spieler hat gerade einen Punkt gegen den Bot gemacht.',
            'deuce' => 'Beide stehen bei zwanzig oder mehr, es geht in die Verlängerung.',
            'bot_win' => 'Der Bot hat die Partie gewonnen.',
            default => 'Der Spieler hat die Partie gegen den Bot gewonnen.',
        };
    }
}
