<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use App\Models\ChatterProfile;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Spoken reactions to the stream chat (user 2026-10-11: "wenn Chat Messages
 * reinkommen im Nostr Chat, dann kann Markus ja darauf reagieren"): new kind
 * 1311 messages of the stream (`a` tag) since the last poll; only a
 * reactable author (ChatterCache::isReactable(): trust rank or web of trust,
 * not muted) gets one, at most once per `per_person_minutes` and
 * `per_hour` in all, as a live script at the front of the queue.
 *
 * The message itself is data: the model gets its keywords (letters only, no
 * names in capitals, no digits, no links) and the persona card as hints that
 * never count as facts, so nothing from the chat can reach the spoken text
 * through the guard. Skips are counted per reason in VoiceUsage
 * (`chat_reaction`), never the text.
 */
class ChatReactions
{
    public const SKIPS = 'chat_reaction';

    private const CURSOR = 'caster:chat_cursor';

    /** Chat messages read per poll at most. */
    private const LIMIT = 50;

    public function __construct(
        private RelayReader $reader,
        private ChatterCache $cache,
        private PersonaCards $personas,
        private ScriptQueue $queue,
        private VoiceUsage $usage,
    ) {}

    public static function enabled(): bool
    {
        return config('tv.commentary.enabled') === true && config('tv.commentary.chat.reactions') === true;
    }

    /**
     * @return int reactions queued
     */
    public function poll(CarbonImmutable $now): int
    {
        $stream = StreamCoordinates::fromConfig();
        $relays = array_values(array_filter((array) config('esports.stream_bot.chat_relays', []), 'is_string'));

        if (! self::enabled() || $stream === null || $relays === []) {
            return 0;
        }

        $since = (int) Cache::get(self::CURSOR, $now->subMinutes(2)->getTimestamp());
        $events = $this->reader->fetch([['kinds' => [1311], '#a' => [$stream->address()], 'since' => $since, 'limit' => self::LIMIT]], $relays, [], self::LIMIT);
        usort($events, fn (SignedEvent $a, SignedEvent $b): int => $a->createdAt <=> $b->createdAt);

        $queued = 0;
        $newest = $since;

        foreach ($events as $event) {
            $newest = max($newest, $event->createdAt + 1);

            if ($event->kind !== 1311 || $event->createdAt < $since || $event->pubkey === $stream->pubkey) {
                continue;
            }

            $reason = $this->skipReason($event, $now);

            if ($reason !== null) {
                $this->usage->recordRejection(self::SKIPS, [$reason]);

                continue;
            }

            if ($this->react($event, $now)) {
                $queued++;
            }
        }

        Cache::put(self::CURSOR, $newest, 86_400);

        return $queued;
    }

    /** Why a message gets no reaction, or null. */
    public function skipReason(SignedEvent $event, CarbonImmutable $now): ?string
    {
        if (! $this->cache->isReactable($event->pubkey)) {
            return 'untrusted';
        }

        $settings = (array) config('tv.commentary.chat', []);
        $profile = ChatterProfile::query()->where('pubkey', $event->pubkey)->first();

        if ($profile?->last_reaction_at !== null && $profile->last_reaction_at->greaterThan($now->subMinutes((int) ($settings['per_person_minutes'] ?? 15)))) {
            return 'person_cooldown';
        }

        if (CasterScript::query()->where('kind', CasterScript::CHAT)->where('created_at', '>', $now->subHour())->count() >= (int) ($settings['per_hour'] ?? 6)) {
            return 'hourly_cap';
        }

        return self::keywords($event->content) === [] ? 'no_content' : null;
    }

    /**
     * Up to five lowercase keywords of a chat message: letters only, at least
     * four of them, no capitalised word (a name), no link, no number.
     *
     * @return list<string>
     */
    public static function keywords(string $message): array
    {
        $message = (string) preg_replace('~(?:https?://|nostr:|www\.)\S+~iu', ' ', $message);
        preg_match_all('/(?<![\p{L}\p{N}])\p{Ll}{4,}(?![\p{L}\p{N}])/u', $message, $words);

        return array_slice(array_values(array_unique($words[0])), 0, 5);
    }

    private function react(SignedEvent $event, CarbonImmutable $now): bool
    {
        $profile = $this->cache->touchChat($event->pubkey, $now);
        $persona = $this->personas->refreshIfDue($profile, $now);

        if ($persona === null) {
            $this->cache->refreshName($profile, $now);
        }
        $keywords = self::keywords($event->content);
        // A name is spoken only when it looks like one: at most three plain words (no slogan, no link, no handle soup).
        $name = is_string($profile->name) && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\'-]{1,29}$/u', $profile->name) === 1 && substr_count($profile->name, ' ') <= 2 ? $profile->name : null;

        $hints = ['chat_stichworte' => implode(', ', $keywords)];
        if ($persona !== null) {
            $hints['persona'] = $persona;
        }
        if (($earlier = $this->cache->exchanges($event->pubkey)) !== []) {
            $hints['frühere_wortwechsel'] = implode(' | ', $earlier);
        }

        // The greeting's name is the one fact from the chatter: their own public kind 0 name (never a gamer tag).
        $facts = $name === null ? ['chat' => 'eine Nachricht im Stream-Chat'] : ['chatter_name' => $name];
        $script = $this->queue->pushLive(CasterScript::CHAT, ScenePhase::BREAK, $facts, (int) config('tv.commentary.scripts.chat_priority', 50), $now, $hints);

        if ($script === null) {
            $this->usage->recordRejection(self::SKIPS, ['voice_rejected']);

            return false;
        }

        $profile->last_reaction_at = Carbon::instance($now);
        $profile->save();
        $this->cache->rememberExchange($event->pubkey, implode(', ', $keywords));

        return true;
    }
}
