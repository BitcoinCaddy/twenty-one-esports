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
 * kind 1 notes, from the nprofile's relay hints and the stream's relays,
 * checked like any input from outside by RelayReader. With `fresh_hours` 0
 * (the default; user 2026-10-12: "Die Kommentare für die Nostr-Posts des
 * npubs dürfen bis an den Anfang aller je geposteten kind-1-Events gehen.
 * Also ALL!") that is the whole history, paged back with `until` a bounded
 * stretch per run; otherwise only the last `fresh_hours`. The display name
 * (kind 0) is cached for `followed.name_hours`.
 *
 * Every note is stored once by its id; one this class turns down (a reply,
 * too short, only links or media, explicit, about suffering, too old) is
 * stored as reacted and skipped, so it never comes up again. With a
 * `fresh_hours` limit, rows older than `keep_days` are deleted and beyond
 * `max_rows` the oldest go; without one nothing is deleted, since the
 * backfill would read a deleted note again and it would be spoken twice.
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

    /** Set once the backfill of a pubkey found nothing older; asked again after BACKFILL_DONE_DAYS (a new relay hint may hold more). */
    private const BACKFILL_DONE = 'caster:followed:backfill-done:';

    private const BACKFILL_DONE_DAYS = 7;

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

    /**
     * Reads each profile's notes: the head (everything after the newest note
     * stored; on the first run from now back to the floor), then, while
     * `fresh_hours` is 0, the tail (back from the oldest note stored until
     * the relays have nothing older). At most `per_run` notes per profile and
     * `run_seconds` per run: the tail resumes at the oldest stored note.
     *
     * @return array{profiles: int, stored: int, filtered: int, pruned: int, read: int, exhausted: int}
     */
    public function fetch(CarbonImmutable $now): array
    {
        $settings = (array) config('tv.commentary.followed', []);
        $counts = ['profiles' => 0, 'stored' => 0, 'filtered' => 0, 'pruned' => 0, 'read' => 0, 'exhausted' => 0];
        $fresh = (int) ($settings['fresh_hours'] ?? 0);
        $deadline = microtime(true) + max(0.01, (float) ($settings['run_seconds'] ?? 120));

        foreach (self::profiles() as $profile) {
            $counts['profiles']++;
            $pubkey = $profile['pubkey'];
            $relays = self::relaysFor($profile['relays']);
            $budget = max(1, (int) ($settings['per_run'] ?? 1000));

            if (! Cache::has(self::NAME_CACHE.$pubkey)) {
                $this->refreshName($pubkey, $relays, $settings);
            }

            $newest = CasterFollowedNote::query()->where('pubkey', $pubkey)->max('note_created_at');
            $oldest = CasterFollowedNote::query()->where('pubkey', $pubkey)->min('note_created_at');
            $floor = $fresh > 0 ? $now->subHours($fresh)->getTimestamp() : 0;

            $since = max($newest === null ? 0 : CarbonImmutable::parse($newest)->getTimestamp() + 1, $floor);
            $head = $this->page($pubkey, $relays, $since, null, $budget, $deadline, $now, $settings, $counts);
            // On the first run the head is the whole walk back; after that only the tail can reach the first note.
            $exhausted = $newest === null && $head['exhausted'];

            if ($fresh === 0 && $oldest !== null && $budget > $head['read'] && ! Cache::has(self::BACKFILL_DONE.$pubkey)) {
                $exhausted = $this->page($pubkey, $relays, 0, CarbonImmutable::parse($oldest)->getTimestamp(), $budget - $head['read'], $deadline, $now, $settings, $counts)['exhausted'];
            }

            if ($fresh === 0 && $exhausted) {
                Cache::put(self::BACKFILL_DONE.$pubkey, true, now()->addDays(self::BACKFILL_DONE_DAYS));
                $counts['exhausted']++;
            }
        }

        $counts['pruned'] = $this->prune($now);

        return $counts;
    }

    /**
     * Pages back through a profile's kind 1 notes (NIP-01 `until`, inclusive,
     * `page_limit` per REQ) from `$until` (null: now) down to `$since`, and
     * stores each note once. Stops when a page brings no note this walk has
     * not seen (exhausted), when `$budget` notes are read, or at `$deadline`.
     * The deadline is checked between pages, never inside one: a page cut
     * short after some relays would move the cursor past the notes of the
     * others, and they would never be read (each relay keeps its own
     * `relay_fetch_budget_seconds`). A page no relay answered is no proof of
     * the end: the walk stops without calling the history exhausted, and the
     * next run asks again.
     *
     * @param  list<string>|null  $relays
     * @param  array<array-key, mixed>  $settings
     * @param  array{profiles: int, stored: int, filtered: int, pruned: int, read: int, exhausted: int}  $counts
     * @return array{read: int, exhausted: bool}
     */
    private function page(string $pubkey, ?array $relays, int $since, ?int $until, int $budget, float $deadline, CarbonImmutable $now, array $settings, array &$counts): array
    {
        $limit = max(1, (int) ($settings['page_limit'] ?? 200));
        $seen = [];
        $read = 0;

        while ($read < $budget && microtime(true) < $deadline) {
            $filter = ['kinds' => [1], 'authors' => [$pubkey], 'limit' => min($limit, $budget - $read)];
            $filter += $since > 0 ? ['since' => $since] : [];
            $filter += $until !== null ? ['until' => $until] : [];
            $result = $this->reader->fetchCounted([$filter], $relays, $seen, $filter['limit']);

            if ($result['answered'] === 0) {
                return ['read' => $read, 'exhausted' => false];
            }

            $oldest = null;

            foreach ($result['events'] as $event) {
                // A relay may ignore `until`: a newer note would keep the walk from going back. One that ignores `since`
                // hands over older notes, stored like any other (skipReason() turns down one past `fresh_hours`).
                if ($event->pubkey !== $pubkey || $event->kind !== 1 || isset($seen[$event->id]) || ($until !== null && $event->createdAt > $until)) {
                    continue;
                }

                $seen[$event->id] = true;
                $read++;
                $counts['read']++;
                $oldest = min($oldest ?? $event->createdAt, $event->createdAt);
                $stored = $this->store($event, $now, $settings);
                $counts['stored'] += $stored === 'stored' ? 1 : 0;
                $counts['filtered'] += $stored === 'filtered' ? 1 : 0;
            }

            if ($oldest === null) {
                return ['read' => $read, 'exhausted' => true];
            }

            // Inclusive: notes sharing the oldest second come again and are skipped as seen.
            $until = $oldest;
        }

        return ['read' => $read, 'exhausted' => false];
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
        $fresh = (int) ($settings['fresh_hours'] ?? 0);

        if ($fresh > 0 && $event->createdAt < $now->subHours($fresh)->getTimestamp()) {
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

    /**
     * Rows older than `keep_days` (never less than `fresh_hours`, so no note
     * can be read again), then the oldest beyond `max_rows`. Without a
     * `fresh_hours` limit nothing: the backfill would read a deleted note
     * again, and it would get a second reaction.
     */
    public function prune(CarbonImmutable $now): int
    {
        $settings = (array) config('tv.commentary.followed', []);
        $fresh = (int) ($settings['fresh_hours'] ?? 0);

        if ($fresh <= 0) {
            return 0;
        }

        $hours = max(24 * (int) ($settings['keep_days'] ?? 7), $fresh + 1);
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
