<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use App\Models\ChatterProfile;
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\StreamChat\StreamChat;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Spoken reactions to the stream chat (user 2026-10-11: "wenn Chat Messages
 * reinkommen im Nostr Chat, dann kann Markus ja darauf reagieren"; later:
 * "Reaction auf alles was im Stream reinkommt, auch Reactions auf Zaps mit
 * witzigem Spruch und Danke für die Sats"), each poll over the stream's `a`
 * address since the last one:
 *
 * - Chat (kind 1311) of a reactable author (ChatterCache::isReactable():
 *   trust rank or web of trust, not muted): a personal reaction, at most
 *   once per `per_person_minutes` and `per_hour` in all.
 * - Chat of anybody else (not muted): no name, nothing read out, only a
 *   generic greeting from ReactionLines for all of them together, at most
 *   one per `everyone_minutes`.
 * - Zaps (kind 9735, the /live chat's rules: StreamZaps): every zap is
 *   thanked once (deduplicated by receipt id), zaps within
 *   `zap_bundle_seconds` in one thank-you, the amount spelled out, the
 *   zapper's name only when the zapper is reactable; from `zap_big_sats` on
 *   an extra-emotional line. A live script, ahead of the chat.
 *
 * Our own keys (the stream, its chat bot, the league) never get a reaction.
 * Every text comes from ReactionLines without the model; with
 * `tv.commentary.ai` on, the league voice may write a personal reaction or
 * a thank-you instead (its checks and fact check included), and the pattern
 * text stays the fallback.
 *
 * The message itself is data: a trusted chatter's message, sanitised
 * (sanitizeMessage(): no links, keys, markup, at most 200 characters), goes
 * to the model between delimiters, never as a fact or an instruction, and
 * Markus answers what was written (CasterVoice::chatReply(), user
 * 2026-10-11: "reagiert gar nicht auf den Inhalt der Chat-Nachricht! Das
 * will ich aber!"); without the model ReactionLines::reply() picks a line
 * for the message's intent. Banter passes, an invented league fact does
 * not (ChatFactCheck). A trusted chatter's wish (a Bauernkrieg episode,
 * news, jokes, the game; ChatWishes) is acknowledged by name and the segment
 * queued right behind the reply. An untrusted chatter's message is never read. Skips are counted per reason in VoiceUsage
 * (`chat_reaction`), never the text.
 */
class ChatReactions
{
    /** What the acknowledgement of a wish announces (ChatWishes types): the segment itself follows right behind it. */
    private const WISH_TEASERS = [
        ChatWishes::HISTORY => 'gleich danach erzählst du eine Geschichte aus dem Bauernkrieg: kündige sie an, erzähl sie noch nicht',
        ChatWishes::NEWS => 'gleich danach liest du eine Schlagzeile vor und kommentierst sie: kündige sie an, nenn sie noch nicht',
        ChatWishes::JOKE => 'gleich danach kommen ein paar Sprüche aus dem Podcast: kündige sie an',
        ChatWishes::CANON => 'gleich danach erzählst du etwas aus der EINUNDZWANZIG-Welt: kündige es an',
        ChatWishes::GAME => 'gleich danach kommentierst du das Spielgeschehen: kündige es an',
    ];

    public const SKIPS = 'chat_reaction';

    /** The topic of a zap thank-you (kind live) and of the greeting for everybody (kind chat). */
    public const ZAP_TOPIC = 'zap_thanks';

    public const GREETING_TOPIC = 'chat_hello';

    private const CURSOR = 'caster:chat_cursor';

    private const ZAP_CURSOR = 'caster:zap_cursor';

    /** Zap receipts are read again this far back (a relay may deliver late); the receipt id keeps each thanked once. */
    private const ZAP_OVERLAP_SECONDS = 300;

    private const ZAP_SEEN = 'caster:zap_seen:';

    /** The longest chat message the reply prompt sees (sanitizeMessage()). */
    public const MESSAGE_CHARS = 200;

    /** Chat messages and zap receipts read per poll at most. */
    private const LIMIT = 50;

    public function __construct(
        private RelayReader $reader,
        private ChatterCache $cache,
        private PersonaCards $personas,
        private ScriptQueue $queue,
        private VoiceUsage $usage,
        private CasterVoice $voice,
        private ReactionLines $lines,
        private ChatWishes $wishes,
    ) {}

    public static function enabled(): bool
    {
        return config('tv.commentary.enabled') === true && config('tv.commentary.chat.reactions') === true;
    }

    /**
     * @return int reactions queued (personal reactions, greetings and zap thank-yous)
     */
    public function poll(CarbonImmutable $now): int
    {
        $stream = StreamCoordinates::fromConfig();
        // The same relays the /live chat reads (stream relays + the bot's chat relays): with only
        // `stream_bot.chat_relays`, which prod leaves empty, no chat message was ever seen (2026-10-11).
        $relays = $stream === null ? [] : array_values(array_filter(RelayPublisher::relayUrls([...$stream->relays, ...(array) config('esports.stream_bot.chat_relays', [])]), fn (string $relay): bool => EventBuilder::isRelayUrl($relay)));

        if (! self::enabled() || $stream === null || $relays === []) {
            return 0;
        }

        $since = (int) Cache::get(self::CURSOR, $now->subMinutes(2)->getTimestamp());
        $zapCursor = (int) Cache::get(self::ZAP_CURSOR, $now->subMinutes(2)->getTimestamp());
        $filters = [['kinds' => [1311], '#a' => [$stream->address()], 'since' => $since, 'limit' => self::LIMIT]];
        if (config('tv.commentary.chat.zaps', true) === true) {
            $filters[] = ['kinds' => [9735], '#a' => [$stream->address()], 'since' => $zapCursor - self::ZAP_OVERLAP_SECONDS, 'limit' => self::LIMIT];
        }

        $events = $this->reader->fetch($filters, $relays, [], self::LIMIT);
        usort($events, fn (SignedEvent $a, SignedEvent $b): int => $a->createdAt <=> $b->createdAt);

        $own = self::ownPubkeys($stream);
        $queued = 0;
        $newest = $since;
        $newestZap = $zapCursor;
        $receipts = [];
        $strangers = 0;

        foreach ($events as $event) {
            if ($event->kind === 9735) {
                $newestZap = max($newestZap, $event->createdAt + 1);
                $receipts[] = $event;

                continue;
            }

            $newest = max($newest, $event->createdAt + 1);

            if ($event->kind !== 1311 || $event->createdAt < $since || in_array($event->pubkey, $own, true)) {
                continue;
            }

            $reason = $this->skipReason($event, $now);

            if ($reason === 'untrusted' && ! SiteModeration::isHidden($event->pubkey)) {
                $strangers++;
            }

            if ($reason !== null) {
                $this->usage->recordRejection(self::SKIPS, [$reason]);

                continue;
            }

            if ($this->react($event, $now)) {
                $queued++;
            }
        }

        $queued += $this->thankZaps($stream, $receipts, $own, $now);

        if ($strangers > 0 && $this->greet($strangers, $now)) {
            $queued++;
        }

        Cache::put(self::CURSOR, $newest, 86_400);
        Cache::put(self::ZAP_CURSOR, $newestZap, 86_400);

        return $queued;
    }

    /**
     * Our own keys: the stream's host, its chat bot (also the champion GG and
     * notes) and the league key. None of them ever gets a reaction.
     *
     * @return list<string>
     */
    public static function ownPubkeys(StreamCoordinates $stream): array
    {
        return array_values(array_unique(array_filter([$stream->pubkey, StreamChat::botPubkey(), LeagueKey::fromConfig()?->pubkey()], 'is_string')));
    }

    /** Why a message gets no reaction, or null. */
    public function skipReason(SignedEvent $event, CarbonImmutable $now): ?string
    {
        if (! $this->cache->isReactable($event->pubkey)) {
            return 'untrusted';
        }

        $settings = (array) config('tv.commentary.chat', []);
        $profile = ChatterProfile::query()->where('pubkey', $event->pubkey)->first();

        // 0 means no limit, for the cooldown and the hourly cap alike.
        $personMinutes = (int) ($settings['per_person_minutes'] ?? 15);
        if ($personMinutes > 0 && $profile?->last_reaction_at !== null && $profile->last_reaction_at->greaterThan($now->subMinutes($personMinutes))) {
            return 'person_cooldown';
        }

        // The greetings for everybody have their own limit (everyone_minutes) and do not count here.
        $perHour = (int) ($settings['per_hour'] ?? 6);
        if ($perHour > 0 && CasterScript::query()->where('kind', CasterScript::CHAT)->where(fn ($query) => $query->whereNull('topic')->orWhere('topic', '!=', self::GREETING_TOPIC))
            ->where('created_at', '>', $now->subHour())->count() >= $perHour) {
            return 'hourly_cap';
        }

        return preg_match('/\p{L}/u', self::sanitizeMessage($event->content)) === 1 ? null : 'no_content';
    }

    /**
     * A trusted chat message as the reply prompt and the pattern lines see
     * it: links, nostr references, bech32 keys and ids, markup and control
     * characters out, whitespace collapsed, at most `MESSAGE_CHARS`
     * characters. Still user data: the prompt puts it between delimiters
     * as data, never as a fact or an instruction.
     */
    public static function sanitizeMessage(string $message): string
    {
        $message = (string) preg_replace([
            '~(?:https?://|nostr:|www\.)\S+~iu',
            '/\b(?:npub|nsec|note|nevent|nprofile|naddr|nrelay|lnurl|lnbc)1[02-9ac-hj-np-z]{6,}\b/iu',
            '/<[^>]*>/u',
            '/[<>`*_~#\[\]{}|\\\\]+/u',
            '/[\p{Cc}\p{Cf}]+/u',
        ], ' ', $message);
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        return mb_strlen($message) > self::MESSAGE_CHARS ? rtrim(mb_substr($message, 0, self::MESSAGE_CHARS)).'…' : $message;
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

    /** A name is spoken only when it looks like one: at most three plain words (no slogan, no link, no handle soup). */
    public static function speakableName(?string $name): ?string
    {
        return is_string($name) && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\'-]{1,29}$/u', $name) === 1 && substr_count($name, ' ') <= 2 ? $name : null;
    }

    private function react(SignedEvent $event, CarbonImmutable $now): bool
    {
        $profile = $this->cache->touchChat($event->pubkey, $now);
        $persona = $this->personas->refreshIfDue($profile, $now);

        if ($persona === null) {
            $this->cache->refreshName($profile, $now);
        }
        $keywords = self::keywords($event->content);
        $name = self::speakableName($profile->name);

        $hints = [];
        if ($persona !== null) {
            $hints['persona'] = $persona;
        }
        if (($earlier = $this->cache->exchanges($event->pubkey)) !== []) {
            $hints['frühere_wortwechsel'] = implode(' | ', $earlier);
        }

        // The reply answers what was written (user 2026-10-11: "reagiert gar nicht auf den Inhalt der Chat-Nachricht!");
        // the name stays the one fact from the chatter: their own public kind 0 name (never a gamer tag).
        $message = self::sanitizeMessage($event->content);
        $facts = $name === null ? ['chat' => 'eine Nachricht im Stream-Chat'] : ['chatter_name' => $name];

        // A wish of a trusted chatter (ChatWishes): the acknowledgement, then the segment right behind it, both live.
        $wish = $this->wishes->grant($event->pubkey, $message, $now);
        if ($wish !== null) {
            $live = (int) config('tv.commentary.scripts.live_priority', 100);
            // The acknowledgement answers the message too (user 2026-10-11: "antwortet nur generisch!"); the template only
            // when the model is off or its draft broke a house rule.
            $ack = ScriptQueue::aiEnabled() ? $this->voice->chatReply($message, $name, [...$hints, 'gleich_danach' => self::WISH_TEASERS[$wish['type']] ?? 'du erfüllst den Wunsch']) : null;
            $this->queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, $ack ?? $this->lines->wish($wish['type'], $name), SceneFacts::hash($facts), $live, $now, source: $ack === null ? CasterScript::SOURCE_PATTERN : CasterScript::SOURCE_AI);
            $this->queue->enqueue($wish['kind'], ScenePhase::BREAK, $wish['text'], $wish['facts_hash'], $live, $now, scene: ChatWishes::SCENE, source: $wish['source'], topic: $wish['topic'],
                maxAgeMinutes: (int) config('tv.commentary.chat.wishes_max_age_minutes', 30));
            $profile->last_reaction_at = Carbon::instance($now);
            $profile->save();
            $this->cache->rememberExchange($event->pubkey, implode(', ', $keywords));

            return true;
        }

        $priority = (int) config('tv.commentary.scripts.chat_priority', 50);
        $text = ScriptQueue::aiEnabled() ? $this->voice->chatReply($message, $name, $hints) : null;

        if ($text === null && ScriptQueue::aiEnabled()) {
            $this->usage->recordRejection(self::SKIPS, ['voice_rejected']);
        }

        // The model is off or its draft did not pass: a pattern reply to the message's intent, never silence.
        $this->queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, $text ?? $this->lines->reply($message, $name), SceneFacts::hash($facts), $priority, $now, source: $text === null ? CasterScript::SOURCE_PATTERN : CasterScript::SOURCE_AI);

        $profile->last_reaction_at = Carbon::instance($now);
        $profile->save();
        $this->cache->rememberExchange($event->pubkey, implode(', ', $keywords));

        return true;
    }

    /**
     * Thank the new zaps of the stream: valid receipts not thanked before,
     * not by one of our own keys, bundled per `zap_bundle_seconds`.
     *
     * @param  list<SignedEvent>  $receipts
     * @param  list<string>  $own
     * @return int thank-yous queued
     */
    private function thankZaps(StreamCoordinates $stream, array $receipts, array $own, CarbonImmutable $now): int
    {
        if ($receipts === []) {
            return 0;
        }

        $recipient = (new StreamChat($stream, []))->zapRecipient();
        $lnurls = StreamChat::zapLnurls();
        $until = StreamChat::zapUntil();
        $zaps = [];

        foreach ($receipts as $receipt) {
            $zap = StreamZaps::parse($receipt, $stream->address(), $recipient, $lnurls, $until);

            if ($zap === null) {
                $this->usage->recordRejection(self::SKIPS, ['zap_invalid']);

                continue;
            }

            if (in_array($zap['zapper'], $own, true) || ! Cache::add(self::ZAP_SEEN.$zap['id'], true, 2 * 86_400)) {
                continue;
            }

            $zaps[] = $zap;
        }

        $settings = (array) config('tv.commentary.chat', []);
        $queued = 0;

        foreach (StreamZaps::bundles($zaps, max(1, (int) ($settings['zap_bundle_seconds'] ?? 60))) as $bundle) {
            $this->thank($bundle, (int) ($settings['zap_big_sats'] ?? 10_000), $now);
            $queued++;
        }

        return $queued;
    }

    /**
     * One thank-you for a bundle of zaps, at the front of the queue.
     *
     * @param  list<array{id: string, zapper: string, at: int, sats: int, comment: string}>  $bundle
     */
    private function thank(array $bundle, int $bigSats, CarbonImmutable $now): CasterScript
    {
        $sats = array_sum(array_column($bundle, 'sats'));
        $count = count($bundle);
        $big = $bigSats > 0 && $sats >= $bigSats;
        $name = null;

        // A name only for a single zap of a reactable zapper (their own public kind 0 name); a bundle is thanked as a whole.
        if ($count === 1 && $this->cache->isReactable($bundle[0]['zapper'])) {
            $profile = ChatterProfile::query()->firstOrNew(['pubkey' => $bundle[0]['zapper']]);
            $profile->last_chat_at ??= Carbon::instance($now);
            $this->cache->refreshName($profile, $now);
            $name = self::speakableName($profile->name);
        }

        $spoken = ReactionLines::spokenSats($sats);
        $facts = [
            'anlass' => $count === 1 ? 'ein Zap im Stream-Chat' : 'mehrere Zaps im Stream-Chat innerhalb einer Minute',
            'betrag' => $spoken,
            'anzahl_zaps' => ReactionLines::spokenNumber($count),
        ];
        if ($name !== null) {
            $facts['zapper_name'] = $name;
        }
        if ($big) {
            $facts['grosser_zap'] = 'ja';
        }

        $text = $this->aiThanks($bundle, $facts, $spoken);
        $source = $text === null ? CasterScript::SOURCE_PATTERN : CasterScript::SOURCE_AI;
        $text ??= $this->lines->zapThanks($count, $sats, $name, $big);

        return $this->queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, $text, SceneFacts::hash($facts), (int) config('tv.commentary.scripts.live_priority', 100), $now, source: $source, topic: self::ZAP_TOPIC);
    }

    /**
     * The league voice's thank-you (one write, with its checks and fact
     * check), or null: AI off, a rejected draft, or a text that does not
     * speak the amount. The zap comments go in as keywords only (hints, never
     * facts); a muted zapper's not at all.
     *
     * @param  list<array{id: string, zapper: string, at: int, sats: int, comment: string}>  $bundle
     * @param  array<string, string>  $facts
     */
    private function aiThanks(array $bundle, array $facts, string $spoken): ?string
    {
        if (! ScriptQueue::aiEnabled()) {
            return null;
        }

        $keywords = [];
        foreach ($bundle as $zap) {
            if (! SiteModeration::isHidden($zap['zapper'])) {
                $keywords = [...$keywords, ...self::keywords($zap['comment'])];
            }
        }

        $hints = ['auftrag' => 'Bedanke dich für die Sats, den Betrag ausgeschrieben sagen, dazu ein witziger Spruch.'];
        if ($keywords !== []) {
            $hints['zap_stichworte'] = implode(', ', array_slice(array_values(array_unique($keywords)), 0, 5));
        }

        $range = (array) config('tv.commentary.scripts.live_sentences', []);
        $script = $this->voice->write(CasterScript::LIVE, ScenePhase::BREAK, $facts, (int) ($range['min'] ?? 1), (int) ($range['max'] ?? 3), $hints);

        return $script !== null && str_contains(mb_strtolower($script['text']), mb_strtolower($spoken)) ? $script['text'] : null;
    }

    /**
     * One greeting for everybody whose messages are never read out, at most
     * one per `everyone_minutes`: within that time they are counted, not greeted.
     */
    private function greet(int $messages, CarbonImmutable $now): bool
    {
        $settings = (array) config('tv.commentary.chat', []);

        if (($settings['everyone'] ?? true) !== true) {
            return false;
        }

        $recent = CasterScript::query()->where('kind', CasterScript::CHAT)->where('topic', self::GREETING_TOPIC)
            ->where('created_at', '>', $now->subMinutes(max(1, (int) ($settings['everyone_minutes'] ?? 3))))->exists();

        if ($recent) {
            $this->usage->recordRejection(self::SKIPS, ['greeting_rate_limited']);

            return false;
        }

        $this->queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, $this->lines->greeting($messages), 'greeting', (int) config('tv.commentary.scripts.chat_priority', 50), $now, source: CasterScript::SOURCE_PATTERN, topic: self::GREETING_TOPIC);

        return true;
    }
}
