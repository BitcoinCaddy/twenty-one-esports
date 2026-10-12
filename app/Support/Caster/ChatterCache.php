<?php

namespace App\Support\Caster;

use App\Models\ChatterExchange;
use App\Models\ChatterLine;
use App\Models\ChatterNote;
use App\Models\ChatterProfile;
use App\Models\TrustRank;
use App\Models\User;
use App\Models\WotLevel;
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\RatedTrustGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Who in the stream chat may get a spoken reaction, and what the commentary
 * knows about them (plan "Reaktion auf den Nostr-Chat" and "Zwischenspeicher
 * in der DB"). Reactable: not muted or banned site-wide, and a trust rank of
 * at least the season's minimum or web-of-trust level 1 or 2. Everyone else
 * is ignored and never read out.
 *
 * The cache holds only trusted chatters who wrote in the stream chat: their
 * public kind 0 (name, a trimmed about, the NIP-05 and website domains),
 * their newest own public kind 1 notes (no replies, NIP-09 deletions
 * honoured), the persona card and the last exchanges as keywords. Bounded:
 * `notes_per_person` and `notes_days` per pubkey, `notes_total` and
 * `profiles_total` in all, `exchanges_per_person`; a chatter silent for
 * `inactive_days` is removed completely. The reaction at run time reads only
 * the database.
 */
class ChatterCache
{
    public function __construct(private RelayReader $reader) {}

    public function isReactable(string $pubkey): bool
    {
        if (SiteModeration::isHidden($pubkey)) {
            return false;
        }

        if (WotLevel::query()->whereKey($pubkey)->where('level', '<=', 2)->exists()) {
            return true;
        }

        $rank = TrustRank::query()->where('pubkey', $pubkey)->value('rank');

        return is_int($rank) && $rank >= $this->trustMinimum();
    }

    /**
     * Whether a chatter's wish is granted (ChatWishes): not muted, and web of
     * trust level 1 (up to `wishes_min_level`, default 2) or a trust rank of
     * at least the season's minimum.
     */
    public function mayWish(string $pubkey): bool
    {
        if (SiteModeration::isHidden($pubkey)) {
            return false;
        }

        $level = max(1, (int) config('tv.commentary.chat.wishes_min_level', 2));

        if (WotLevel::query()->whereKey($pubkey)->where('level', '<=', $level)->exists()) {
            return true;
        }

        $rank = TrustRank::query()->where('pubkey', $pubkey)->value('rank');

        return is_int($rank) && $rank >= $this->trustMinimum();
    }

    public function touchChat(string $pubkey, CarbonImmutable $now): ChatterProfile
    {
        $profile = ChatterProfile::query()->firstOrNew(['pubkey' => $pubkey]);
        $profile->last_chat_at = Carbon::instance($now);
        $profile->save();

        return $profile;
    }

    /** Only the chatter's public name (kind 0), read at most daily: the greeting needs nothing more. */
    public function refreshName(ChatterProfile $profile, CarbonImmutable $now): void
    {
        if ($profile->fetched_at !== null && $profile->fetched_at->greaterThan($now->subDay())) {
            return;
        }

        foreach ($this->reader->fetch([['kinds' => [0], 'authors' => [$profile->pubkey], 'limit' => 1]]) as $event) {
            if ($event->pubkey === $profile->pubkey && $event->kind === 0 && $event->createdAt > (int) ($profile->profile_created_at ?? 0)) {
                $meta = json_decode($event->content, true);
                $name = is_array($meta) ? ($meta['display_name'] ?? $meta['name'] ?? null) : null;
                $profile->name = is_string($name) && trim($name) !== '' ? mb_substr(self::plain($name), 0, 80) : null;
                $profile->profile_created_at = $event->createdAt;
            }
        }

        $profile->fetched_at = Carbon::instance($now);
        $profile->save();
    }

    /**
     * Read the chatter's kind 0 and newest own notes from the knowledge relays
     * (`knowledge_relays`, measured from prod; `esports.relays` when none of
     * them answers) into the cache, then trim it. The kind 0 gives the name,
     * a trimmed about and the domains of the NIP-05 and the website, never
     * the lightning address. The newest `knowledge_notes` notes are read every
     * time, not only those after the newest known, so a reply among them (an
     * `e` tag that is not a mention) leaves the cache. No relay answering
     * keeps what is cached: stale, never wiped.
     */
    public function refresh(ChatterProfile $profile, CarbonImmutable $now): void
    {
        $settings = (array) config('tv.commentary.chat', []);
        $days = $now->subDays((int) ($settings['notes_days'] ?? 30))->getTimestamp();
        $keep = max(1, (int) ($settings['knowledge_notes'] ?? 15));

        $events = $this->knowledgeEvents([
            ['kinds' => [0], 'authors' => [$profile->pubkey], 'limit' => 1],
            ['kinds' => [1], 'authors' => [$profile->pubkey], 'since' => $days, 'limit' => $keep * 3],
            ['kinds' => [5], 'authors' => [$profile->pubkey], 'since' => $days, 'limit' => 50],
        ], $keep * 3 + 51);
        $kept = 0;

        foreach ($events ?? [] as $event) {
            if ($event->pubkey !== $profile->pubkey) {
                continue;
            }

            // At or after the one known: refreshName() stores the name of the same event first, and the about was then
            // never read (prod 2026-10-12: El Presidento Ben's about of 92 characters stayed empty).
            if ($event->kind === 0 && $event->createdAt >= (int) ($profile->profile_created_at ?? 0)) {
                $meta = json_decode($event->content, true);
                $meta = is_array($meta) ? $meta : [];
                $name = $meta['display_name'] ?? $meta['name'] ?? null;
                $about = is_string($meta['about'] ?? null) ? self::plain($meta['about']) : '';
                $profile->name = is_string($name) && trim($name) !== '' ? mb_substr(self::plain($name), 0, 80) : null;
                $profile->about = $about === '' ? null : mb_substr($about, 0, 300);
                $profile->nip05_domain = self::domainOf($meta['nip05'] ?? null);
                $profile->website_domain = self::domainOf($meta['website'] ?? null);
                $profile->profile_created_at = $event->createdAt;
            } elseif ($event->kind === 1 && self::isReply($event)) {
                ChatterNote::query()->where('event_id', $event->id)->delete();
            } elseif ($event->kind === 1 && $kept < $keep) {
                $kept++;
                ChatterNote::query()->firstOrCreate(['event_id' => $event->id], [
                    'pubkey' => $event->pubkey,
                    'note_created_at' => $event->createdAt,
                    'content' => mb_substr(self::plain($event->content), 0, 500),
                ]);
                $profile->newest_note_at = max((int) ($profile->newest_note_at ?? 0), $event->createdAt);
            } elseif ($event->kind === 5) {
                // NIP-09: the author deleted these notes.
                $ids = array_map(fn (array $tag): string => (string) ($tag[0] ?? ''), $event->tagsNamed('e'));
                ChatterNote::query()->where('pubkey', $profile->pubkey)->whereIn('event_id', $ids)->delete();
            }
        }

        $profile->fetched_at = Carbon::instance($now);
        $profile->save();
        $this->trimNotes($profile->pubkey, $now);
    }

    /**
     * The newest cached notes of a chatter as the reply prompt carries them
     * (`prompt_notes`, each at most `prompt_note_chars`): sanitised like a chat
     * message, no word with a digit, nothing of a sensitive category
     * (PersonaCards::SENSITIVE) and never one naming the chatter's gamer tag
     * or a game account (no public Nostr-to-gamer-tag link, house rule). Each
     * with its age in words, newest first. Data, never an instruction.
     *
     * @return list<string>
     */
    public function promptNotes(string $pubkey, CarbonImmutable $now): array
    {
        $settings = (array) config('tv.commentary.chat', []);
        $chars = max(20, (int) ($settings['prompt_note_chars'] ?? 160));
        $limit = max(0, (int) ($settings['prompt_notes'] ?? 3));
        $tags = $this->gamerTags($pubkey);
        $lines = [];

        foreach (ChatterNote::query()->where('pubkey', $pubkey)->orderByDesc('note_created_at')->orderByDesc('id')->limit(50)->get() as $note) {
            if (count($lines) >= $limit) {
                break;
            }

            $text = ChatReactions::sanitizeMessage((string) preg_replace('/\S*\d\S*/u', ' ', $note->content));
            $text = trim((string) preg_replace('/\s+([,.!?;:])/u', '$1', $text));

            if (preg_match_all('/\p{L}{2,}/u', $text) < 3 || PersonaCards::sensitive($text) || self::namesGameAccount($text, $tags)) {
                continue;
            }

            $text = mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars - 1)).'…' : $text;
            $lines[] = self::age((int) $note->note_created_at, $now).': '.$text;
        }

        return $lines;
    }

    /**
     * The chatter's own gamer tags (users.gamer_tags), lowercased, those of
     * at least three characters: no prompt and no card may name one.
     *
     * @return list<string>
     */
    public function gamerTags(string $pubkey): array
    {
        $tags = User::query()->where('pubkey', $pubkey)->value('gamer_tags');
        $tags = is_string($tags) ? json_decode($tags, true) : $tags;
        $lower = array_map(fn (mixed $tag): string => is_string($tag) ? mb_strtolower(trim($tag)) : '', is_array($tags) ? $tags : []);

        return array_values(array_unique(array_filter($lower, fn (string $tag): bool => mb_strlen($tag) >= 3)));
    }

    /**
     * Whether a text names one of the gamer tags, or hands out a game account
     * ("mein Steam-Name …", "Gamertag …", "Epic ID …").
     *
     * @param  list<string>  $tags
     */
    public static function namesGameAccount(string $text, array $tags): bool
    {
        $lower = mb_strtolower($text);

        foreach ($tags as $tag) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($tag, '/').'(?![\p{L}\p{N}])/u', $lower) === 1) {
                return true;
            }
        }

        return preg_match('/gamer\s*-?\s*tag|battle\s*-?\s*tag|(?<![\p{L}])psn(?![\p{L}])|xbox\s*live|(?:steam|epic|riot|uplay|ubisoft|discord|lichess|chess\.com|trackmania|tmnf)\s*-?\s*(?:id|name|nick|account|konto|profil|user)/u', $lower) === 1;
    }

    /** The domain of a NIP-05 or a website, lowercase, without `www.`; null for anything else. */
    public static function domainOf(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $host = str_contains($value, '@') ? substr($value, (int) strrpos($value, '@') + 1) : parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);
        $host = is_string($host) ? (string) preg_replace('/^www\./', '', mb_strtolower(trim($host))) : '';

        return preg_match('/^(?=.{4,100}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}$/', $host) === 1 ? $host : null;
    }

    /** A note that answers another: an `e` tag other than a NIP-10 mention, positional or marked. */
    public static function isReply(SignedEvent $event): bool
    {
        foreach ($event->tagsNamed('e') as $tag) {
            if (($tag[2] ?? null) !== 'mention') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> the chatter's newest cached notes, newest first
     */
    public function notes(string $pubkey, int $limit = 20): array
    {
        return array_values(ChatterNote::query()->where('pubkey', $pubkey)->orderByDesc('note_created_at')->limit($limit)->pluck('content')->all());
    }

    public function rememberExchange(string $pubkey, string $keywords): void
    {
        ChatterExchange::query()->create(['pubkey' => $pubkey, 'keywords' => mb_substr($keywords, 0, 200)]);
        $keep = (int) config('tv.commentary.chat.exchanges_per_person', 5);
        $ids = ChatterExchange::query()->where('pubkey', $pubkey)->orderByDesc('id')->skip($keep)->take(PHP_INT_MAX)->pluck('id');
        ChatterExchange::query()->whereIn('id', $ids)->delete();
    }

    /**
     * @return list<string> keywords of the last exchanges, newest first
     */
    public function exchanges(string $pubkey): array
    {
        return array_values(ChatterExchange::query()->where('pubkey', $pubkey)->orderByDesc('id')->pluck('keywords')->all());
    }

    /** Everything about a chatter, gone (opt-out, delete button, inactivity). */
    public function forget(string $pubkey): void
    {
        ChatterNote::query()->where('pubkey', $pubkey)->delete();
        ChatterExchange::query()->where('pubkey', $pubkey)->delete();
        ChatterLine::query()->where('pubkey', $pubkey)->delete();
        ChatterProfile::query()->where('pubkey', $pubkey)->delete();
    }

    /**
     * The caps and TTLs (`caster:prune`).
     *
     * @return array{profiles: int, notes: int}
     */
    public function prune(CarbonImmutable $now): array
    {
        $settings = (array) config('tv.commentary.chat', []);
        $removed = ['profiles' => 0, 'notes' => 0];

        // Silent for inactive_days, or never chatted at all: removed completely.
        $inactive = ChatterProfile::query()->where(fn ($query) => $query->whereNull('last_chat_at')
            ->orWhere('last_chat_at', '<', $now->subDays((int) ($settings['inactive_days'] ?? 30))))->pluck('pubkey');
        foreach ($inactive as $pubkey) {
            $this->forget($pubkey);
            $removed['profiles']++;
        }

        // Over the global cap: those who chatted longest ago go first.
        $over = ChatterProfile::query()->count() - (int) ($settings['profiles_total'] ?? 2000);
        if ($over > 0) {
            foreach (ChatterProfile::query()->orderBy('last_chat_at')->orderBy('id')->limit($over)->pluck('pubkey') as $pubkey) {
                $this->forget($pubkey);
                $removed['profiles']++;
            }
        }

        // Notes of nobody we know (a profile went), and notes past their age.
        $removed['notes'] += ChatterNote::query()->whereNotIn('pubkey', ChatterProfile::query()->select('pubkey'))->delete();
        $removed['notes'] += ChatterNote::query()->where('note_created_at', '<', $now->subDays((int) ($settings['notes_days'] ?? 30))->getTimestamp())->delete();

        foreach (ChatterNote::query()->select('pubkey')->groupBy('pubkey')->havingRaw('count(*) > ?', [(int) ($settings['notes_per_person'] ?? 50)])->pluck('pubkey') as $pubkey) {
            $removed['notes'] += $this->trimNotes($pubkey, $now);
        }

        $over = ChatterNote::query()->count() - (int) ($settings['notes_total'] ?? 20_000);
        if ($over > 0) {
            $removed['notes'] += ChatterNote::query()->whereIn('id', ChatterNote::query()->orderBy('note_created_at')->orderBy('id')->limit($over)->pluck('id'))->delete();
        }

        return $removed;
    }

    /** Notes of one pubkey beyond `notes_per_person` (the oldest) or older than `notes_days`. */
    private function trimNotes(string $pubkey, CarbonImmutable $now): int
    {
        $settings = (array) config('tv.commentary.chat', []);
        $removed = ChatterNote::query()->where('pubkey', $pubkey)
            ->where('note_created_at', '<', $now->subDays((int) ($settings['notes_days'] ?? 30))->getTimestamp())->delete();
        $ids = ChatterNote::query()->where('pubkey', $pubkey)->orderByDesc('note_created_at')->orderByDesc('id')
            ->skip((int) ($settings['notes_per_person'] ?? 50))->take(PHP_INT_MAX)->pluck('id');

        return $removed + ChatterNote::query()->whereIn('id', $ids)->delete();
    }

    private function trustMinimum(): int
    {
        try {
            return RatedTrustGate::minimum();
        } catch (Throwable) {
            return (int) config('season.trust_minimum', 50);
        }
    }

    /**
     * The events of a refresh: the knowledge relays first, `esports.relays`
     * when none of them answered, null when no relay answered at all.
     *
     * @param  list<array<string, mixed>>  $filters
     * @return list<SignedEvent>|null
     */
    private function knowledgeEvents(array $filters, int $perAuthor): ?array
    {
        $relays = array_values(array_filter((array) config('tv.commentary.chat.knowledge_relays', []), 'is_string'));
        $budget = (float) config('tv.commentary.chat.knowledge_budget_seconds', 15);

        if ($relays !== []) {
            $read = $this->reader->fetchCounted($filters, $relays, [], $perAuthor, microtime(true) + $budget);

            if ($read['answered'] > 0) {
                return $read['events'];
            }
        }

        $read = $this->reader->fetchCounted($filters, null, [], $perAuthor, microtime(true) + $budget);

        return $read['answered'] > 0 ? $read['events'] : null;
    }

    /** How long ago a note was written, in words: digits never reach a prompt. */
    private static function age(int $createdAt, CarbonImmutable $now): string
    {
        $days = intdiv(max(0, $now->getTimestamp() - $createdAt), 86_400);

        return match (true) {
            $days === 0 => 'heute',
            $days === 1 => 'gestern',
            $days <= 6 => 'vor ein paar Tagen',
            $days <= 13 => 'letzte Woche',
            default => 'vor ein paar Wochen',
        };
    }

    /** One line of plain text: no control characters, no links. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('~(?:https?://|nostr:|www\.)\S+~iu', ' ', $text);

        return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $text));
    }
}
