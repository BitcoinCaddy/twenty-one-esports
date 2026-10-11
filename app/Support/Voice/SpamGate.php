<?php

namespace App\Support\Voice;

use App\Support\StreamBot\StreamBot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The spam gate of the bot's two surfaces, the stream chat (`chat`) and its
 * profile notes (`profile`): it only ever holds a post back, it never adds
 * one (config `esports.voice.spam`). The stream bot's own cadence stays the
 * upper bound; the voice replaces a template's wording, not its slot.
 *
 * The publishers (plan "KI-Stimme der Liga", P2, through BotVoice) ask:
 *  1. skip() before a chat tick (StreamBot): switch, quiet hours, daily cap,
 *     interval (doubled after `silent_posts` unanswered posts; with the
 *     defaults the bot's own `alone_minutes` already waits longer), and the
 *     same moment about the same subject within `repeat_hours`. The profile
 *     notes keep their own pacing (ProfileNotes, the same config keys);
 *  2. mentionAllowed() per person: a held-back mention is the plain name;
 *  3. textSkip() on the finished text: the voice's (turned down, the
 *     template goes out) and the template's (lengths only for the voice: a
 *     template is never shortened). A repeated template falls out: the chat
 *     rotation tries its next message, a profile note waits (HeldBack). A
 *     note that carries its calendar event as naddr first loses a repeated
 *     link line (withoutRepeatedLinks(): no link beats a repeated one);
 *  4. record() after a post went out, humanWrote() when the chat answered.
 *
 * The GG (ChampionChat) is never held back as a repetition: it is the one
 * post of its tournament and only good within its window. Several moments
 * at once need no merging here: the notes already bundle a burst into one
 * note (WeeklyBoardNotes `*_top_burst`), the chat posts one message a tick.
 *
 * Every skip counts its reason (VoiceUsage, channel `spam_<surface>`).
 * State lives in the cache as scalars and arrays of scalars only; when the
 * cache is lost, the gate forgets its history and the stream bot's own
 * database rules (interval, cap, never the same text twice) still hold.
 */
final class SpamGate
{
    public const CHAT = 'chat';

    public const PROFILE = 'profile';

    public const QUIET_HOURS = 'quiet_hours';

    public const DAILY_CAP = 'daily_cap';

    public const INTERVAL = 'interval';

    public const TOPIC_REPEAT = 'topic_repeat';

    public const TOO_LONG = 'too_long';

    public const TOO_MANY_SENTENCES = 'too_many_sentences';

    public const TEXT_REPEAT = 'text_repeat';

    public const NEAR_COPY = 'near_copy';

    public const OPENING_REPEAT = 'opening_repeat';

    public const CANON_REPEAT = 'canon_repeat';

    public const FORM_REPEAT = 'form_repeat';

    public const LINK_REPEAT = 'link_repeat';

    public const LINK_KIND_REPEAT = 'link_kind_repeat';

    public const MENTION_COOLDOWN = 'mention_cooldown';

    private const TTL_SECONDS = 3 * 86_400;

    /** Exact texts are remembered this long (the stream bot's own never-twice rule reaches further). */
    private const TEXT_TTL_SECONDS = 30 * 86_400;

    private const KEPT_TEXT_HASHES = 500;

    private const KEPT_POSTS = 50;

    public function __construct(private readonly VoiceUsage $usage) {}

    public function enabled(): bool
    {
        return (bool) config('esports.voice.spam.enabled', true);
    }

    /**
     * Why no post may go out on the surface now (null: the gate lets it pass).
     *
     * @param  list<string>  $subjects  who or what the moment is about (pubkeys, tournament IDs …)
     */
    public function skip(string $surface, string $moment, array $subjects, CarbonImmutable $now): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $reason = match (true) {
            StreamBot::quietAt($now) !== false => self::QUIET_HOURS,
            $surface === self::CHAT && $this->postsOn($surface, $now) >= (int) config('esports.stream_bot.daily_cap', 18) => self::DAILY_CAP,
            $this->lastPostAt($surface) !== null && $now->getTimestamp() - (int) $this->lastPostAt($surface) < $this->intervalSeconds($surface) => self::INTERVAL,
            $this->topicSeenWithin($surface, $moment, $subjects, $now) => self::TOPIC_REPEAT,
            default => null,
        };

        return $this->counted($surface, $reason);
    }

    /**
     * The least time between two posts on the surface: the stream bot's
     * interval minus its jitter in the chat (doubled after unanswered posts),
     * its profile gap on the profile.
     */
    public function intervalSeconds(string $surface): int
    {
        if ($surface === self::PROFILE) {
            return 60 * (int) config('esports.stream_bot.profile_gap_minutes', 180);
        }

        $minutes = max(1, (int) config('esports.stream_bot.interval_minutes', 22) - (int) config('esports.stream_bot.jitter_minutes', 6));
        $silent = (int) Cache::get($this->key(self::CHAT, 'silent'), 0);
        // Shadowed with the defaults: 2 × (22 − 6) = 32 minutes, while the bot itself waits `alone_minutes` (45) for a
        // human before its next post (StreamBot rule 6). It bites only with a shorter alone_minutes or a longer interval.
        $factor = $silent >= (int) config('esports.voice.spam.silent_posts', 3) ? max(1, (int) config('esports.voice.spam.backoff_factor', 2)) : 1;

        return 60 * $minutes * $factor;
    }

    /**
     * Whether the person may be mentioned: on the profile once per
     * `mention_hours`, in the chat once per stream session; a champion or
     * tournament-win moment allows one more.
     */
    public function mentionAllowed(string $surface, string $pubkey, string $moment, CarbonImmutable $now, ?string $session = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $since = $now->getTimestamp() - 3600 * (int) config('esports.voice.spam.mention_hours', 24);
        $earlier = array_filter(
            (array) (Cache::get($this->key($surface, 'mentions'), [])[$pubkey] ?? []),
            fn (mixed $mention): bool => is_array($mention) && ($surface === self::CHAT ? ($mention['session'] ?? null) === $session : (int) ($mention['at'] ?? 0) >= $since),
        );
        $allowed = in_array($moment, (array) config('esports.voice.spam.mention_extra_moments', []), true) ? 2 : 1;

        if (count($earlier) >= $allowed) {
            $this->counted($surface, self::MENTION_COOLDOWN);

            return false;
        }

        return true;
    }

    /**
     * Why this finished text may not go out (null: it may). A template is
     * checked for repetition only; its length is the template's business.
     */
    public function textSkip(string $surface, string $text, CarbonImmutable $now, ?string $canonId = null, ?string $form = null, bool $template = false): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $posts = $this->posts($surface);
        $recent = array_slice($posts, 0, (int) config('esports.voice.spam.recent_posts', 10));
        $normalized = VoiceGuard::normalize($text);
        $opening = $this->opening($text);
        $urls = self::links($text);

        $reason = null;

        if (! $template && mb_strlen($text) > (int) config("esports.voice.spam.max_chars.{$surface}", 280)) {
            $reason = self::TOO_LONG;
        } elseif (! $template && VoiceHygiene::sentences((string) preg_replace('~https?://\S+~u', ' ', $text)) > (int) config('esports.voice.spam.max_sentences', 2)) {
            $reason = self::TOO_MANY_SENTENCES;
        } elseif (in_array(sha1($normalized), (array) Cache::get($this->key($surface, 'hashes'), []), true)) {
            $reason = self::TEXT_REPEAT;
        } elseif ($this->nearCopy(self::prose($text), $recent)) {
            $reason = self::NEAR_COPY;
        } elseif ($opening !== '' && in_array($opening, array_column($recent, 'opening'), true)) {
            $reason = self::OPENING_REPEAT;
        } elseif ($canonId !== null && in_array($canonId, array_column($recent, 'canon'), true)) {
            $reason = self::CANON_REPEAT;
        } elseif ($form !== null && $this->formStreak($form, $recent)) {
            $reason = self::FORM_REPEAT;
        } else {
            $reason = $this->linkSkip($posts, $urls, $now);
        }

        return $this->counted($surface, $reason);
    }

    /**
     * A template's lines without the links that went out too recently: no
     * link is better than a repeated one. The link goes with the label in
     * front of it ("Sign up:"), and a line left without words goes too, so
     * "🏆 Cup starts Sat. Sign up: <url>" keeps "🏆 Cup starts Sat.".
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public function withoutRepeatedLinks(string $surface, array $lines, CarbonImmutable $now): array
    {
        if (! $this->enabled()) {
            return $lines;
        }

        $posts = $this->posts($surface);
        $kept = [];

        foreach ($lines as $line) {
            $repeated = array_filter(self::links($line), fn (string $url): bool => $this->linkSkip($posts, [$url], $now) !== null);

            if ($repeated !== []) {
                $line = rtrim(str_replace($repeated, '', $line));

                // The label of the link ("👉 Sign up:"): everything after the last sentence mark, or the whole line.
                if (str_ends_with($line, ':')) {
                    $cut = max((int) strrpos($line, '.'), (int) strrpos($line, '!'), (int) strrpos($line, '?'));
                    $line = $cut === 0 ? '' : substr($line, 0, $cut + 1);
                }
            }

            if (preg_match('/[\p{L}\p{N}]/u', $line) === 1) {
                $kept[] = $line;
            }
        }

        return $kept;
    }

    /**
     * Remembers a post that went out.
     *
     * @param  list<string>  $subjects
     * @param  list<string>  $mentioned  pubkeys of the people it mentioned
     */
    public function record(string $surface, string $text, string $moment, array $subjects, CarbonImmutable $now, ?string $canonId = null, ?string $form = null, array $mentioned = [], ?string $session = null): void
    {
        $posts = $this->posts($surface);
        array_unshift($posts, [
            'at' => $now->getTimestamp(),
            'text' => self::prose($text),
            'opening' => $this->opening($text),
            'canon' => $canonId,
            'form' => $form,
            'links' => array_map(fn (string $url): string => self::normalizeUrl($url), self::links($text)),
            'kinds' => array_map(fn (string $url): string => self::linkKind($url), self::links($text)),
        ]);
        Cache::put($this->key($surface, 'posts'), array_slice($posts, 0, self::KEPT_POSTS), self::TTL_SECONDS);

        $hashes = array_slice([sha1(VoiceGuard::normalize($text)), ...(array) Cache::get($this->key($surface, 'hashes'), [])], 0, self::KEPT_TEXT_HASHES);
        Cache::put($this->key($surface, 'hashes'), $hashes, self::TEXT_TTL_SECONDS);

        $topics = (array) Cache::get($this->key($surface, 'topics'), []);
        $topics[$this->topicKey($moment, $subjects)] = $now->getTimestamp();
        Cache::put($this->key($surface, 'topics'), $topics, self::TTL_SECONDS);

        $mentions = (array) Cache::get($this->key($surface, 'mentions'), []);
        foreach (array_unique($mentioned) as $pubkey) {
            $mentions[$pubkey] = [...array_slice((array) ($mentions[$pubkey] ?? []), -5), ['at' => $now->getTimestamp(), 'session' => $session]];
        }
        Cache::put($this->key($surface, 'mentions'), $mentions, self::TTL_SECONDS);

        if ($surface === self::CHAT) {
            Cache::put($this->key(self::CHAT, 'silent'), (int) Cache::get($this->key(self::CHAT, 'silent'), 0) + 1, self::TTL_SECONDS);
        }

        $day = $this->usage->day($now);
        Cache::put($this->key($surface, "count:{$day}"), $this->postsOn($surface, $now) + 1, 2 * 86_400);
    }

    /** A human wrote in the chat: the backoff ends. */
    public function humanWrote(): void
    {
        Cache::forget($this->key(self::CHAT, 'silent'));
    }

    /**
     * Every http(s) link of a text, trailing punctuation off.
     *
     * @return list<string>
     */
    public static function links(string $text): array
    {
        preg_match_all('~https?://[^\s<>"“”„]+~u', $text, $matches);

        return array_map(fn (string $url): string => rtrim($url, '.,;:!?)»'), $matches[0]);
    }

    /** Scheme, host and path of a link, lower case, without query, fragment or trailing slash. */
    public static function normalizeUrl(string $url): string
    {
        $parts = parse_url($url) ?: [];

        return strtolower(($parts['host'] ?? '').rtrim((string) ($parts['path'] ?? ''), '/'));
    }

    /** The kind of target a link points at: its host and first path segment (/play, /tournaments, a game page). */
    public static function linkKind(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $segment = explode('/', trim((string) ($parts['path'] ?? ''), '/'))[0];

        return strtolower(($parts['host'] ?? '').'/'.$segment);
    }

    public function postsOn(string $surface, CarbonImmutable $now): int
    {
        return (int) Cache::get($this->key($surface, 'count:'.$this->usage->day($now)), 0);
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @param  list<string>  $urls
     */
    private function linkSkip(array $posts, array $urls, CarbonImmutable $now): ?string
    {
        $linkSince = $now->getTimestamp() - 3600 * (int) config('esports.voice.spam.link_hours', 24);
        $daySince = $now->getTimestamp() - 86_400;

        foreach ($urls as $url) {
            $normalized = self::normalizeUrl($url);
            $kind = self::linkKind($url);
            $sameLink = 0;
            $sameKind = 0;

            foreach ($posts as $post) {
                $at = (int) ($post['at'] ?? 0);
                $sameLink += $at >= $linkSince && in_array($normalized, (array) ($post['links'] ?? []), true) ? 1 : 0;
                $sameKind += $at >= $daySince ? count(array_keys((array) ($post['kinds'] ?? []), $kind, true)) : 0;
            }

            if ($sameLink > 0) {
                return self::LINK_REPEAT;
            }

            if ($sameKind >= (int) config('esports.voice.spam.link_kind_per_day', 2)) {
                return self::LINK_KIND_REPEAT;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $recent
     */
    private function nearCopy(string $normalized, array $recent): bool
    {
        foreach ($recent as $post) {
            similar_text($normalized, (string) ($post['text'] ?? ''), $percent);

            if ($percent >= (int) config('esports.voice.spam.near_copy_percent', 80)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $recent
     */
    private function formStreak(string $form, array $recent): bool
    {
        $streak = (int) config('esports.voice.spam.form_streak', 3);
        $previous = array_slice(array_column($recent, 'form'), 0, $streak - 1);

        return count($previous) === $streak - 1 && array_unique([$form, ...$previous]) === [$form];
    }

    /** The words of a text without its links (links have their own rules), normalised. */
    private static function prose(string $text): string
    {
        return VoiceGuard::normalize((string) preg_replace('~https?://\S+~u', ' ', $text));
    }

    private function opening(string $text): string
    {
        $words = array_slice(explode(' ', self::prose($text)), 0, (int) config('esports.voice.spam.opening_words', 4));

        return count($words) < (int) config('esports.voice.spam.opening_words', 4) ? '' : implode(' ', $words);
    }

    /**
     * @param  list<string>  $subjects
     */
    private function topicSeenWithin(string $surface, string $moment, array $subjects, CarbonImmutable $now): bool
    {
        $at = ((array) Cache::get($this->key($surface, 'topics'), []))[$this->topicKey($moment, $subjects)] ?? null;

        return $at !== null && $now->getTimestamp() - (int) $at < 3600 * (int) config('esports.stream_bot.repeat_hours', 24);
    }

    /**
     * @param  list<string>  $subjects
     */
    private function topicKey(string $moment, array $subjects): string
    {
        sort($subjects);

        return $moment.'|'.implode(',', $subjects);
    }

    private function lastPostAt(string $surface): ?int
    {
        $at = $this->posts($surface)[0]['at'] ?? null;

        return $at === null ? null : (int) $at;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(string $surface): array
    {
        $posts = Cache::get($this->key($surface, 'posts'), []);

        return is_array($posts) ? array_values(array_filter($posts, 'is_array')) : [];
    }

    private function counted(string $surface, ?string $reason): ?string
    {
        if ($reason !== null) {
            $this->usage->recordRejection("spam_{$surface}", [$reason]);
        }

        return $reason;
    }

    private function key(string $surface, string $what): string
    {
        return "voice:spam:{$surface}:{$what}";
    }
}
