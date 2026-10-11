<?php

namespace App\Support\Cards;

use App\Jobs\WritePrideLine;
use App\Support\Voice\LeagueVoice;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

/**
 * The model's line on a pride picture (plan "KI-Stimme der Liga", P4): the
 * title of a player card, the last page of the Season Wrapped story, the
 * line under a tale of the tape. Each function has its own switch,
 * `esports.voice.channels.card.functions.<function>`, off by default, on
 * top of the `card` channel's own switch, daily cap and the monthly budget
 * (LeagueVoice).
 *
 * Never in the request: line() returns the line once it is written, else
 * the template the caller passed, and queues the writing (WritePrideLine,
 * unique per line). The written text is cached as a string for
 * {@see self::TTL_SECONDS}; its key carries the facts, so a new number asks
 * for a new line. A card's fingerprint carries the title it draws, so the
 * file changes when the line arrives.
 *
 * The model writes in the channel's language only (German): any other
 * locale gets the template, translated as usual.
 */
final class PrideVoice
{
    /** The voice moment each function speaks to (LeagueVoice, VoiceCanon::MOMENTS). */
    public const FUNCTIONS = ['player' => 'general', 'wrapped' => 'season_end', 'tape' => 'tournament_final'];

    public const TTL_SECONDS = 30 * 86_400;

    public function __construct(private readonly LeagueVoice $voice) {}

    public static function enabled(string $function): bool
    {
        $channel = (array) config('esports.voice.channels.card', []);

        return isset(self::FUNCTIONS[$function])
            && ($channel['enabled'] ?? false) === true
            && (($channel['functions'] ?? [])[$function] ?? false) === true
            && App::getLocale() === ($channel['language'] ?? 'de');
    }

    /**
     * The written line, else `$template` (and the line is queued).
     *
     * @param  array<string, mixed>  $facts  scalars and lists, public only
     */
    public function line(string $function, string $key, array $facts, string $template): string
    {
        if (! self::enabled($function)) {
            return $template;
        }

        $stored = Cache::get(self::cacheKey($function, $key, $facts));

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        WritePrideLine::dispatch($function, $key, $facts);

        return $template;
    }

    /**
     * Ask the model once and keep a guarded answer. A refusal of any kind
     * (switch, cap, budget, failure, a check) keeps nothing: the template stays.
     *
     * @param  array<string, mixed>  $facts
     */
    public function write(string $function, string $key, array $facts): ?string
    {
        if (! self::enabled($function)) {
            return null;
        }

        $line = $this->voice->say(self::FUNCTIONS[$function], $facts, 'card');

        // A card draws plain text: a resolved mention (nostr:npub…) or a link has no place on a picture.
        if ($line === null || preg_match('/nostr:|https?:\/\//i', $line->text) === 1) {
            return null;
        }

        $text = trim($line->text);
        Cache::put(self::cacheKey($function, $key, $facts), $text, self::TTL_SECONDS);

        return $text;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function cacheKey(string $function, string $key, array $facts): string
    {
        return 'pride-voice:'.$function.':'.$key.':'.substr(hash('sha256', (string) json_encode($facts)), 0, 16);
    }

    /**
     * The facts the model gets for a person: the name behind a role token,
     * never mentioned (a picture carries no mention).
     *
     * @param  array<string, mixed>  $person  name, pubkey
     * @return array{role: string, name: string, pubkey: string, mentionable: false}
     */
    public static function person(string $role, array $person): array
    {
        return ['role' => $role, 'name' => (string) $person['name'], 'pubkey' => (string) $person['pubkey'], 'mentionable' => false];
    }
}
