<?php

namespace App\Support\Caster;

use App\Models\CasterFollowedNote;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamChat\StreamChat;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads the notes of the followed-profile segment (`caster:followed`, every
 * five minutes; user 2026-10-11: "Für mehr Varianz kann er noch auf die
 * Nostr-Posts von nprofile1… reagieren, aber pro Post nur einmal"): for each
 * profile in `tv.commentary.followed_profiles` (npub, nprofile or hex) its
 * kind 1 notes since its cursor (the newest note stored, at most
 * `followed.fresh_hours` back), from the nprofile's relay hints and the
 * stream's relays, checked like any input from outside by RelayReader. The
 * display name (kind 0) is cached for `followed.name_hours`.
 *
 * Every note is stored once by its id; one this class turns down (a reply,
 * too short, only links or media, explicit, about suffering, too old) is
 * stored as reacted and skipped, so it never comes up again. Bounded: rows
 * older than `keep_days` are deleted, and beyond `max_rows` the oldest go.
 */
class FollowedNotes
{
    public const REPLY = 'reply';

    public const MEDIA = 'media';

    public const SHORT = 'short';

    public const NSFW = 'nsfw';

    public const OLD = 'old';

    /** Spoken when the profile has no speakable name (user 2026-10-11). */
    public const FALLBACK_NAME = 'ein Nostr-Kollege';

    private const NAME_CACHE = 'caster:followed:name:';

    /** Whole words that mark explicit content, German and English. */
    private const NSFW_WORDS = [
        'nsfw', 'porn', 'porno', 'pornos', 'nude', 'nudes', 'nackt', 'nackte', 'nacktbilder', 'sex', 'sexy', 'xxx',
        'onlyfans', 'hentai', 'erotik', 'fick', 'ficken', 'titten', 'boobs', 'pussy', 'nippel',
    ];

    public function __construct(private RelayReader $reader) {}

    /**
     * The followed profiles: pubkey and relay hints, one entry per pubkey
     * (the hints of every reference merged); an unreadable reference is left out.
     *
     * @return list<array{pubkey: string, relays: list<string>}>
     */
    public static function profiles(): array
    {
        $profiles = [];

        foreach ((array) config('tv.commentary.followed_profiles', []) as $reference) {
            $profile = is_string($reference) ? NostrKeys::profileReference($reference) : null;

            if ($profile !== null) {
                $profiles[$profile['pubkey']] = [
                    'pubkey' => $profile['pubkey'],
                    'relays' => array_values(array_unique([...($profiles[$profile['pubkey']]['relays'] ?? []), ...$profile['relays']])),
                ];
            }
        }

        return array_values($profiles);
    }

    /** @return list<string> */
    public static function pubkeys(): array
    {
        return array_column(self::profiles(), 'pubkey');
    }

    /** @return array{profiles: int, stored: int, filtered: int, pruned: int} */
    public function fetch(CarbonImmutable $now): array
    {
        $settings = (array) config('tv.commentary.followed', []);
        $counts = ['profiles' => 0, 'stored' => 0, 'filtered' => 0, 'pruned' => 0];
        $perProfile = max(1, (int) ($settings['per_profile'] ?? 20));

        foreach (self::profiles() as $profile) {
            $counts['profiles']++;
            $pubkey = $profile['pubkey'];
            $relays = self::relaysFor($profile['relays']);

            if (! Cache::has(self::NAME_CACHE.$pubkey)) {
                $this->refreshName($pubkey, $relays, $settings);
            }

            $cursor = CasterFollowedNote::query()->where('pubkey', $pubkey)->max('note_created_at');
            $since = max(
                $cursor === null ? 0 : CarbonImmutable::parse($cursor)->getTimestamp() + 1,
                $now->subHours((int) ($settings['fresh_hours'] ?? 24))->getTimestamp(),
            );

            $events = $this->reader->fetch([['kinds' => [1], 'authors' => [$pubkey], 'since' => $since, 'limit' => $perProfile]], $relays, perAuthor: $perProfile);

            foreach ($events as $event) {
                if ($event->pubkey !== $pubkey || $event->kind !== 1) {
                    continue;
                }

                $stored = $this->store($event, $now, $settings);
                $counts['stored'] += $stored === 'stored' ? 1 : 0;
                $counts['filtered'] += $stored === 'filtered' ? 1 : 0;
            }
        }

        $counts['pruned'] = $this->prune($now);

        return $counts;
    }

    /** The profile's name as spoken: its cached kind 0 display name when speakable, else FALLBACK_NAME. */
    public static function displayName(string $pubkey): string
    {
        $name = Cache::get(self::NAME_CACHE.$pubkey);

        return is_string($name) && $name !== '' ? $name : self::FALLBACK_NAME;
    }

    /**
     * A note as plain text: links, nostr references, bare bech32 strings,
     * markup, hashtag signs, emoji and control characters out, whitespace
     * collapsed, at most 1000 characters.
     */
    public static function plainText(string $content): string
    {
        $text = (string) preg_replace([
            '~(?:https?://|nostr:|www\.)\S+~iu',
            '/\b(?:npub|nsec|note|nevent|nprofile|naddr|nrelay|lnurl|lnbc)1[02-9ac-hj-np-z]{6,}\b/iu',
            '/<[^>]*>/u',
            '/[<>`*_~#\[\]{}|\\\\]+/u',
            '/[\p{So}\p{Cs}\p{Co}]+/u',
            '/[\p{Cc}\p{Cf}]+/u',
        ], ' ', $content);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 1000 ? rtrim(mb_substr($text, 0, 999)).'…' : $text;
    }

    /** Words of a plain text: runs of letters and digits with at least one letter. */
    public static function wordCount(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', $text, $words);

        return count(array_filter($words[0], fn (string $word): bool => preg_match('/\p{L}/u', $word) === 1));
    }

    /**
     * A reply in the sense of NIP-10: an `e` tag marked root or reply, or one
     * without a marker (the deprecated positional form). A mention (a quoted
     * note) is no reply, nor is a `q` tag.
     */
    public static function isReply(SignedEvent $event): bool
    {
        foreach ($event->tagsNamed('e') as $tag) {
            if (($tag[2] ?? '') !== 'mention') {
                return true;
            }
        }

        return false;
    }

    /** Explicit content: a NIP-36 content warning, or an explicit word in the text. */
    public static function isExplicit(string $text, ?SignedEvent $event = null): bool
    {
        if ($event !== null && $event->tagsNamed('content-warning') !== []) {
            return true;
        }

        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array($word, self::NSFW_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a note is never spoken (REPLY, MEDIA, SHORT, NSFW, OLD, or a
     * NewsFilter reason), or null when it may be.
     *
     * @param  array<array-key, mixed>  $settings
     */
    public static function skipReason(SignedEvent $event, string $text, CarbonImmutable $now, array $settings): ?string
    {
        if ($event->createdAt < $now->subHours((int) ($settings['fresh_hours'] ?? 24))->getTimestamp()) {
            return self::OLD;
        }

        if (($settings['replies'] ?? false) !== true && self::isReply($event)) {
            return self::REPLY;
        }

        if ($text === '') {
            return self::MEDIA;
        }

        if (self::wordCount($text) < (int) ($settings['min_words'] ?? 5)) {
            return self::SHORT;
        }

        if (self::isExplicit($text, $event)) {
            return self::NSFW;
        }

        return NewsFilter::unfit($text);
    }

    /** Rows older than `keep_days` (never less than `fresh_hours`, so no note can be read again), then the oldest beyond `max_rows`. */
    public function prune(CarbonImmutable $now): int
    {
        $settings = (array) config('tv.commentary.followed', []);
        $hours = max(24 * (int) ($settings['keep_days'] ?? 7), (int) ($settings['fresh_hours'] ?? 24) + 1);
        $deleted = CasterFollowedNote::query()->where('note_created_at', '<', $now->subHours($hours))->delete();

        $max = max(1, (int) ($settings['max_rows'] ?? 500));

        if (CasterFollowedNote::query()->count() > $max) {
            $keep = CasterFollowedNote::query()->orderByDesc('note_created_at')->orderByDesc('id')->limit($max)->pluck('id');
            $deleted += CasterFollowedNote::query()->whereNotIn('id', $keep)->delete();
        }

        return $deleted;
    }

    /**
     * The nprofile's relay hints and the stream's relays, well-formed and
     * once each; none at all: null (RelayReader's configured relays).
     *
     * @param  list<string>  $hints
     * @return list<string>|null
     */
    public static function relaysFor(array $hints): ?array
    {
        try {
            $stream = StreamChat::current()->relays ?? [];
        } catch (Throwable) {
            $stream = [];
        }

        $relays = array_values(array_filter(RelayPublisher::relayUrls([...$hints, ...$stream]), fn (string $relay): bool => EventBuilder::isRelayUrl($relay)));

        return $relays === [] ? null : $relays;
    }

    /**
     * @param  list<string>|null  $relays
     * @param  array<array-key, mixed>  $settings
     */
    private function refreshName(string $pubkey, ?array $relays, array $settings): void
    {
        $name = '';
        $newest = 0;

        foreach ($this->reader->fetch([['kinds' => [0], 'authors' => [$pubkey], 'limit' => 1]], $relays) as $event) {
            if ($event->pubkey !== $pubkey || $event->kind !== 0 || $event->createdAt <= $newest) {
                continue;
            }

            $meta = json_decode($event->content, true);
            $candidate = is_array($meta) ? ($meta['display_name'] ?? null) : null;
            $candidate = is_string($candidate) && trim($candidate) !== '' ? $candidate : (is_array($meta) ? ($meta['name'] ?? null) : null);
            $name = ChatReactions::speakableName(is_string($candidate) ? self::plainText($candidate) : null) ?? '';
            $newest = $event->createdAt;
        }

        // Cached when there is none too: a profile without a kind 0 is not asked again every five minutes.
        Cache::put(self::NAME_CACHE.$pubkey, $name, now()->addHours(max(1, (int) ($settings['name_hours'] ?? 24))));
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return string stored, filtered or known
     */
    private function store(SignedEvent $event, CarbonImmutable $now, array $settings): string
    {
        if (CasterFollowedNote::query()->where('event_id', $event->id)->exists()) {
            return 'known';
        }

        $text = self::plainText($event->content);
        $reason = self::skipReason($event, $text, $now, $settings);

        $note = CasterFollowedNote::query()->firstOrCreate(['event_id' => $event->id], [
            'pubkey' => $event->pubkey,
            'note_created_at' => CarbonImmutable::createFromTimestamp(min($event->createdAt, $now->getTimestamp())),
            'content' => $text,
            'reacted_at' => $reason === null ? null : $now,
            'skipped' => $reason,
        ]);

        if (! $note->wasRecentlyCreated) {
            return 'known';
        }

        return $reason === null ? 'stored' : 'filtered';
    }
}
