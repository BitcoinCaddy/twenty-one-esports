<?php

namespace App\Support\Voice;

use App\Support\Nostr\NostrKeys;

/**
 * Mentions in a voice post (NIP-27). The facts carry the people of the moment
 * (`people`: role, name, pubkey, mentionable); the model never sees a key and
 * never writes one. It writes a role token such as `{@winner}`, and the app
 * puts the person in: for the guard their plain name, for the post their
 * `nostr:npub1…` with a `p` tag once per person, the same pattern as
 * ChampionChat and PrideNotes.
 *
 * Rules (check()): only tokens of people in the facts, nobody twice (not as a
 * token and by name as well), at most
 * MAX per post, a person whose mentions are off only by name (no token at
 * all), in a team match only the captains, and never a raw npub, nprofile,
 * `nostr:` URI or hex key written by the model.
 *
 * @phpstan-type Person array{role: string, name: string, pubkey: ?string, mentionable: bool}
 */
final class VoiceMentions
{
    public const UNKNOWN = 'mention_unknown';

    public const RAW = 'mention_raw';

    public const NOT_ALLOWED = 'mention_not_allowed';

    public const TOO_MANY = 'mention_too_many';

    public const REPEATED = 'mention_repeated';

    /** Most people one post may mention. */
    public const MAX = 3;

    private const TOKEN = '/\{@([a-z][a-z0-9_]*)\}/';

    /**
     * The people of the facts, keyed by role. A pubkey that is not 64 hex
     * characters makes the person unmentionable.
     *
     * @param  array<array-key, mixed>  $facts
     * @return array<string, Person>
     */
    public static function people(array $facts): array
    {
        $people = [];

        foreach ((array) ($facts['people'] ?? []) as $person) {
            if (! is_array($person) || ! is_string($person['role'] ?? null) || preg_match('/^[a-z][a-z0-9_]*$/', $person['role']) !== 1) {
                continue;
            }

            $pubkey = is_string($person['pubkey'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $person['pubkey']) === 1 ? $person['pubkey'] : null;
            $people[$person['role']] = [
                'role' => $person['role'],
                'name' => trim((string) ($person['name'] ?? '')),
                'pubkey' => $pubkey,
                'mentionable' => ($person['mentionable'] ?? false) === true && $pubkey !== null,
            ];
        }

        return $people;
    }

    /**
     * The facts as the model sees them: every person with role, name and the
     * token to use (null when they may not be mentioned), no key.
     *
     * @param  array<array-key, mixed>  $facts
     * @return array<array-key, mixed>
     */
    public static function forModel(array $facts): array
    {
        if (! isset($facts['people'])) {
            return $facts;
        }

        $teamMatch = ($facts['team_match'] ?? false) === true;
        $facts['people'] = array_values(array_map(fn (array $person): array => [
            'role' => $person['role'],
            'name' => $person['name'],
            'mention' => self::allowed($person, $teamMatch) ? '{@'.$person['role'].'}' : null,
        ], self::people($facts)));

        return $facts;
    }

    /**
     * The mention rules the text breaks.
     *
     * @param  array<array-key, mixed>  $facts
     * @return list<string>
     */
    public function check(string $text, array $facts): array
    {
        $violations = [];

        if (preg_match('/\bnpub1[0-9a-z]{6,}|\bnprofile1[0-9a-z]{6,}|\bnostr:|(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/i', $text) === 1) {
            $violations[] = self::RAW;
        }

        $people = self::people($facts);
        $teamMatch = ($facts['team_match'] ?? false) === true;
        preg_match_all(self::TOKEN, $text, $matches);

        foreach ($matches[1] as $role) {
            if (! isset($people[$role])) {
                $violations[] = self::UNKNOWN;
            } elseif (! self::allowed($people[$role], $teamMatch)) {
                $violations[] = self::NOT_ALLOWED;
            }
        }

        if (count($matches[1]) !== count(array_unique($matches[1]))) {
            $violations[] = self::REPEATED;
        }

        // A mentioned person also named in plain text is named twice ("Ada … nostr:npub1… läuft bei dir", first P2 eval run).
        $withoutTokens = (string) preg_replace(self::TOKEN, ' ', $text);
        foreach (array_unique($matches[1]) as $role) {
            $name = $people[$role]['name'] ?? '';

            if ($name !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $withoutTokens) === 1) {
                $violations[] = self::REPEATED;
            }
        }

        if (count(array_unique($matches[1])) > self::MAX) {
            $violations[] = self::TOO_MANY;
        }

        // A brace token of another shape ({@Winner}, {@ winner}) is neither a mention nor a fact.
        if (preg_match('/\{@(?![a-z][a-z0-9_]*\})/', $text) === 1) {
            $violations[] = self::UNKNOWN;
        }

        return array_values(array_unique($violations));
    }

    /**
     * The text with every token replaced by the person's plain name (what
     * the fact guard and the hygiene pass read).
     *
     * @param  array<array-key, mixed>  $facts
     */
    public function withNames(string $text, array $facts): string
    {
        $people = self::people($facts);

        return (string) preg_replace_callback(self::TOKEN, fn (array $match): string => $people[$match[1]]['name'] ?? $match[0], $text);
    }

    /**
     * The text as it is posted: each token a `nostr:npub1…`, and one `p` tag
     * per mentioned person. Call only after check() found nothing.
     *
     * @param  array<array-key, mixed>  $facts
     * @return array{0: string, 1: list<list<string>>}
     */
    public function resolve(string $text, array $facts): array
    {
        $people = self::people($facts);
        $tags = [];

        $resolved = (string) preg_replace_callback(self::TOKEN, function (array $match) use ($people, &$tags): string {
            $pubkey = (string) ($people[$match[1]]['pubkey'] ?? '');

            if (! in_array(['p', $pubkey], $tags, true)) {
                $tags[] = ['p', $pubkey];
            }

            return 'nostr:'.NostrKeys::hexToNpub($pubkey);
        }, $text);

        return [$resolved, $tags];
    }

    /**
     * @param  Person  $person
     */
    private static function allowed(array $person, bool $teamMatch): bool
    {
        return $person['mentionable'] && (! $teamMatch || str_starts_with($person['role'], 'captain'));
    }
}
