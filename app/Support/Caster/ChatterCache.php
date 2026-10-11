<?php

namespace App\Support\Caster;

use App\Models\ChatterExchange;
use App\Models\ChatterNote;
use App\Models\ChatterProfile;
use App\Models\TrustRank;
use App\Models\WotLevel;
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\RelayReader;
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
 * public kind 0 (name, a trimmed about), their newest public kind 1 notes
 * (fetched incrementally after the newest one known, NIP-09 deletions
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
     * Read the chatter's kind 0 and the notes after the newest one known
     * from the relays into the cache, then trim it.
     */
    public function refresh(ChatterProfile $profile, CarbonImmutable $now): void
    {
        $settings = (array) config('tv.commentary.chat', []);
        $since = max((int) ($profile->newest_note_at ?? 0) + 1, $now->subDays((int) ($settings['notes_days'] ?? 30))->getTimestamp());
        $perPerson = (int) ($settings['notes_per_person'] ?? 50);

        $events = $this->reader->fetch([
            ['kinds' => [0], 'authors' => [$profile->pubkey], 'limit' => 1],
            ['kinds' => [1], 'authors' => [$profile->pubkey], 'since' => $since, 'limit' => $perPerson],
            ['kinds' => [5], 'authors' => [$profile->pubkey], 'since' => $now->subDays((int) ($settings['notes_days'] ?? 30))->getTimestamp(), 'limit' => 50],
        ], perAuthor: $perPerson + 51);

        foreach ($events as $event) {
            if ($event->pubkey !== $profile->pubkey) {
                continue;
            }

            if ($event->kind === 0 && $event->createdAt > (int) ($profile->profile_created_at ?? 0)) {
                $meta = json_decode($event->content, true);
                $name = is_array($meta) ? ($meta['display_name'] ?? $meta['name'] ?? null) : null;
                $about = is_array($meta) && is_string($meta['about'] ?? null) ? $meta['about'] : null;
                $profile->name = is_string($name) && trim($name) !== '' ? mb_substr(self::plain($name), 0, 80) : null;
                $profile->about = $about === null ? null : mb_substr(self::plain($about), 0, 300);
                $profile->profile_created_at = $event->createdAt;
            } elseif ($event->kind === 1) {
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

    /** One line of plain text: no control characters, no links. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('~(?:https?://|nostr:|www\.)\S+~iu', ' ', $text);

        return trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $text));
    }
}
