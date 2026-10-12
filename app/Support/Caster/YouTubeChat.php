<?php

namespace App\Support\Caster;

use App\Models\YouTubeChatLine;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the live chat of the YouTube restream (user 2026-10-12: "Kannst du
 * den Chat auch aus https://www.youtube.com/live/TPNa5BhYfnE lesen und
 * kommentieren lassen? Aber nur zusammengefasst.") through the official
 * YouTube Data API v3 with an API key (public data, no OAuth):
 *
 * - videos.list (part liveStreamingDetails, 1 unit) gives the active live
 *   chat of `video_id`; without one, search.list (eventType live, its own
 *   bucket of 100 calls a day) finds the current live broadcast of
 *   `channel_id`, at most every `channel_lookup_minutes`.
 * - liveChat/messages (5 units a read) is read with the response's
 *   nextPageToken, never before `poll_seconds` and never before the
 *   response's pollingIntervalMillis are up (90 s: 4,800 of the daily
 *   10,000 units).
 * - No live chat (no broadcast, the chat ended or was not found, the
 *   response's offlineAt): no message read, a new look after `idle_minutes`.
 *   A spent quota waits an hour, any other failure one interval.
 *
 * Only text messages are stored (YouTubeChatLine): YouTube's message id for
 * deduplication, the speakable display name, the text sanitised like a Nostr
 * chat message (ChatReactions::sanitizeMessage()) and its time; never the
 * author's channel id, never the chat owner's own messages. The text is
 * data: it reaches a prompt only between delimiters (YouTubeChatSummary).
 * State lives in the cache as ints and strings.
 */
class YouTubeChat
{
    public const API = 'https://www.googleapis.com/youtube/v3';

    /** The chat state: chat id, page token and the earliest next read (unix seconds). */
    private const STATE = 'caster:youtube:chat';

    /** No live chat until then (unix seconds): no API call at all. */
    private const IDLE_UNTIL = 'caster:youtube:idle_until';

    /** The next search.list for the channel's live broadcast not before then (unix seconds). */
    private const SEARCH_AFTER = 'caster:youtube:search_after';

    public const SKIPS = 'youtube_chat';

    public function __construct(private VoiceUsage $usage) {}

    public static function enabled(): bool
    {
        $settings = self::settings();

        return ($settings['enabled'] ?? false) === true
            && trim((string) ($settings['api_key'] ?? '')) !== ''
            && (trim((string) ($settings['video_id'] ?? '')) !== '' || trim((string) ($settings['channel_id'] ?? '')) !== '');
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function settings(): array
    {
        return (array) config('twentyone.stream.youtube.chat', []);
    }

    /**
     * One read of the live chat when one is due.
     *
     * @return int messages stored
     */
    public function poll(CarbonImmutable $now): int
    {
        if (! self::enabled()) {
            return 0;
        }

        /** @var array{chat_id?: string, page_token?: string|null, next_at?: int} $state */
        $state = (array) Cache::get(self::STATE, []);

        if (($state['next_at'] ?? 0) > $now->getTimestamp()) {
            return 0;
        }

        $chatId = $state['chat_id'] ?? null;

        if (! is_string($chatId) || $chatId === '') {
            if ((int) Cache::get(self::IDLE_UNTIL, 0) > $now->getTimestamp()) {
                return 0;
            }

            $chatId = $this->activeChatId($now);

            if ($chatId === null) {
                $this->idle($now);

                return 0;
            }

            $state = ['chat_id' => $chatId, 'page_token' => null, 'next_at' => 0];
        }

        return $this->read($chatId, $state['page_token'] ?? null, $now);
    }

    /** Forget the chat and look again after `idle_minutes`. */
    private function idle(CarbonImmutable $now, ?int $minutes = null): void
    {
        Cache::forget(self::STATE);
        Cache::put(self::IDLE_UNTIL, $now->addMinutes($minutes ?? max(1, (int) (self::settings()['idle_minutes'] ?? 10)))->getTimestamp(), 86_400);
    }

    /** The interval between two reads, in seconds: never under a minute. */
    public static function interval(): int
    {
        return max(60, (int) (self::settings()['poll_seconds'] ?? 90));
    }

    /** The active live chat of the configured video, else of the channel's current live broadcast, or null. */
    private function activeChatId(CarbonImmutable $now): ?string
    {
        $settings = self::settings();
        $videoId = trim((string) ($settings['video_id'] ?? ''));

        if ($videoId !== '' && ($chatId = $this->chatIdOf($videoId)) !== null) {
            return $chatId;
        }

        $channelId = trim((string) ($settings['channel_id'] ?? ''));

        if ($channelId === '' || (int) Cache::get(self::SEARCH_AFTER, 0) > $now->getTimestamp()) {
            return null;
        }

        Cache::put(self::SEARCH_AFTER, $now->addMinutes(max(15, (int) ($settings['channel_lookup_minutes'] ?? 30)))->getTimestamp(), 86_400);
        $found = $this->get('search', ['part' => 'id', 'channelId' => $channelId, 'eventType' => 'live', 'type' => 'video', 'maxResults' => 1]);
        $liveId = $found?->json('items.0.id.videoId');

        return is_string($liveId) && $liveId !== '' && $liveId !== $videoId ? $this->chatIdOf($liveId) : null;
    }

    private function chatIdOf(string $videoId): ?string
    {
        $chatId = $this->get('videos', ['part' => 'liveStreamingDetails', 'id' => $videoId])?->json('items.0.liveStreamingDetails.activeLiveChatId');

        return is_string($chatId) && $chatId !== '' ? $chatId : null;
    }

    /** @return int messages stored */
    private function read(string $chatId, ?string $pageToken, CarbonImmutable $now): int
    {
        $query = array_filter(['liveChatId' => $chatId, 'part' => 'snippet,authorDetails', 'maxResults' => 200, 'pageToken' => $pageToken], fn (mixed $value): bool => $value !== null);
        $response = $this->get('liveChat/messages', $query, true);

        if ($response === null || ! $response->successful()) {
            $reason = (string) ($response?->json('error.errors.0.reason') ?? 'unreachable');
            $this->usage($reason);

            match (true) {
                in_array($reason, ['liveChatEnded', 'liveChatNotFound', 'liveChatDisabled', 'forbidden'], true) => $this->idle($now),
                in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true) => $this->idle($now, 60),
                default => Cache::put(self::STATE, ['chat_id' => $chatId, 'page_token' => $pageToken, 'next_at' => $now->addSeconds(self::interval())->getTimestamp()], 86_400),
            };

            return 0;
        }

        $stored = $this->store((array) $response->json('items', []), $now);

        if (is_string($response->json('offlineAt'))) {
            $this->idle($now);

            return $stored;
        }

        $token = $response->json('nextPageToken');
        $wait = max(self::interval(), (int) ceil((int) $response->json('pollingIntervalMillis', 0) / 1000));
        Cache::put(self::STATE, ['chat_id' => $chatId, 'page_token' => is_string($token) && $token !== '' ? $token : $pageToken, 'next_at' => $now->addSeconds($wait)->getTimestamp()], 86_400);

        return $stored;
    }

    /**
     * The text messages of a response, deduplicated by YouTube's id: no event
     * (super chat, membership, ban, tombstone), not the chat owner's own.
     *
     * @param  array<array-key, mixed>  $items
     */
    private function store(array $items, CarbonImmutable $now): int
    {
        $stored = 0;

        foreach ($items as $item) {
            if (! is_array($item) || ($item['snippet']['type'] ?? null) !== 'textMessageEvent' || ($item['authorDetails']['isChatOwner'] ?? false) === true) {
                continue;
            }

            $id = $item['id'] ?? null;
            $text = $item['snippet']['textMessageDetails']['messageText'] ?? $item['snippet']['displayMessage'] ?? null;

            if (! is_string($id) || $id === '' || strlen($id) > 191 || ! is_string($text)) {
                continue;
            }

            $message = ChatReactions::sanitizeMessage($text);

            if (preg_match('/\p{L}/u', $message) !== 1) {
                continue;
            }

            $published = is_string($item['snippet']['publishedAt'] ?? null) ? rescue(fn (): int => CarbonImmutable::parse($item['snippet']['publishedAt'])->getTimestamp(), null, false) : null;
            $line = YouTubeChatLine::query()->firstOrCreate(['message_id' => $id], [
                'author' => self::speakableAuthor($item['authorDetails']['displayName'] ?? null),
                'message' => $message,
                'said_at' => min($now->getTimestamp(), $published ?? $now->getTimestamp()),
            ]);

            if ($line->wasRecentlyCreated) {
                $stored++;
            }
        }

        return $stored;
    }

    /** A YouTube display name as the caster may speak it: the handle's "@" off, then ChatReactions::speakableName(). */
    public static function speakableAuthor(mixed $displayName): ?string
    {
        return is_string($displayName) ? ChatReactions::speakableName(ltrim(trim($displayName), '@')) : null;
    }

    /**
     * @return int lines removed: older than `retention_hours`, then all but the newest `max_rows`
     */
    public function prune(CarbonImmutable $now): int
    {
        $settings = self::settings();
        $removed = YouTubeChatLine::query()->where('said_at', '<', $now->subHours(max(1, (int) ($settings['retention_hours'] ?? 24)))->getTimestamp())->delete();
        $keep = max(1, (int) ($settings['max_rows'] ?? 500));
        $cut = YouTubeChatLine::query()->orderByDesc('id')->skip($keep)->value('id');

        return $removed + ($cut === null ? 0 : YouTubeChatLine::query()->where('id', '<=', $cut)->delete());
    }

    /**
     * One API call with the key, or null when it could not be made. A failed
     * call is logged once per ten minutes, without the key or the query.
     *
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query, bool $keepErrors = false): ?Response
    {
        try {
            $response = Http::acceptJson()->connectTimeout(4)->timeout(max(2, (int) (self::settings()['timeout_seconds'] ?? 8)))
                ->get(self::API.'/'.$path, [...$query, 'key' => (string) (self::settings()['api_key'] ?? '')]);
        } catch (Throwable $exception) {
            $this->logFailure($path, $exception::class);

            return null;
        }

        if (! $response->successful()) {
            $this->logFailure($path, 'status '.$response->status().' '.(string) ($response->json('error.errors.0.reason') ?? ''));

            return $keepErrors ? $response : null;
        }

        return $response;
    }

    private function usage(string $reason): void
    {
        $this->usage->recordRejection(self::SKIPS, [mb_substr($reason, 0, 40)]);
    }

    private function logFailure(string $path, string $detail): void
    {
        if (Cache::add('caster:youtube:failed-logged', true, 600)) {
            Log::warning('YouTube chat read failed', ['endpoint' => $path, 'detail' => $detail]);
        }
    }
}
