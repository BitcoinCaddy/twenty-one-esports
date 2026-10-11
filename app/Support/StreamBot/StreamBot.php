<?php

namespace App\Support\StreamBot;

use App\Models\StreamBotPost;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Voice\BotVoice;
use App\Support\Voice\SpamGate;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The stream chat bot (P22): decides whether to post now, picks the next
 * message and publishes it as a NIP-53 live chat message (kind 1311) under
 * the stream's 30311, signed with its own key.
 *
 * The rules, in the order they are checked (blocker()):
 *  1. `esports.stream_bot.enabled` and a valid key, and a stream to talk in;
 *  2. the stream is live (StreamLiveness);
 *  3. not in the quiet hours;
 *  4. fewer than `daily_cap` delivered posts today (bot time zone);
 *  5. the next post is due (`interval_minutes` ± `jitter_minutes` after the
 *     last one, drawn once when that one went out);
 *  6. never two bot posts in a row: a human wrote in the chat since the last
 *     delivered post, or `alone_minutes` have passed.
 * Rule 6 reads the chat relays only when 1-5 already allow a post.
 *
 * The rotation (next()): builders weighted towards facts, none of the last
 * `builder_gap` posts' builders, no fact posted within `repeat_hours`, no
 * line whose exact text already went out (that block does not expire), and
 * no message that breaks StreamBotCopy's rules (a `#`, no link).
 *
 * The league voice and the spam gate (plan "KI-Stimme der Liga", P2): before
 * a tick posts, the gate may hold the chat back (SpamGate::skip(): its
 * backoff after unanswered posts on top of rules 3-5); the message the
 * rotation picked is then written by the voice in its own words, or kept as
 * the template (BotVoice::compose()). A template the gate turns down as a
 * repetition is passed over for the next message, never posted. The voice
 * replaces a message's wording, it never adds one.
 */
class StreamBot
{
    public const KIND_LIVE_CHAT = 1311;

    public function __construct(
        private StreamBotBuilders $builders,
        private StreamLiveness $liveness,
        private StreamBotChat $chat,
        private StreamBotPublisher $publisher,
        private BotVoice $voice,
        private SpamGate $gate,
    ) {}

    /**
     * One scheduler tick: post if every rule allows it. Returns what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        $key = LeagueKey::streamBot();
        $stream = StreamCoordinates::fromConfig();
        $blocker = $this->blocker($now, $key, $stream);

        if ($blocker !== null) {
            return 'no post: '.$blocker;
        }

        assert($key !== null && $stream !== null);
        $held = $this->gate->skip(SpamGate::CHAT, 'rotation', [], $now);

        if ($held !== null) {
            return 'no post: the spam gate holds the chat back ('.$held.')';
        }

        $session = self::session($stream, $now);
        $composed = null;
        $message = $this->next($now, $this->recentBuilders(), $this->recentFacts($now), $this->deliveredContents(),
            function (StreamBotMessage $candidate) use ($now, $session, &$composed): ?StreamBotMessage {
                if ($candidate->template === null) {
                    return $candidate;
                }

                $composed = $this->voice->compose('chat_rotation', $candidate->template, $candidate->variant, $candidate->values, $candidate->tags, $now, ['session' => $session, 'hold_template' => true]);

                return $composed === null ? null : $candidate->withContent($composed['content'], $composed['tags']);
            });

        if ($message === null) {
            return 'no post: nothing new to say';
        }

        $post = $this->post($key, $stream, $message, $now);

        if ($post->relays_accepted > 0 && $message->template !== null) {
            $this->voice->delivered('chat_rotation', $message->template, $post->content, $message->tags, $now, $composed['canon'] ?? null, $composed['form'] ?? null, [$message->factKey], $session);
        }

        return sprintf('posted %s (%s) id=%s to %d/%d relays', $post->builder, $post->fact_key, $post->event_id, $post->relays_accepted, $post->relays_total);
    }

    /**
     * Why the bot may not post at `$now`, null when it may. `$readChat`
     * false (dry run) skips the relay read of rule 6 and reports it instead.
     */
    public function blocker(CarbonImmutable $now, ?LeagueKey $key, ?StreamCoordinates $stream, bool $readChat = true): ?string
    {
        $air = $this->airBlocker($now, $key, $stream);

        if ($air !== null) {
            return $air;
        }

        assert($key !== null && $stream !== null);
        $cap = (int) config('esports.stream_bot.daily_cap', 24);

        if ($this->postsToday($now) >= $cap) {
            return 'the daily cap of '.$cap.' posts is reached';
        }

        $last = StreamBotPost::query()->latest('posted_at')->latest('id')->first();

        if ($last !== null && $last->next_due_at->getTimestamp() > $now->getTimestamp()) {
            return 'the next post is due at '.$last->next_due_at->toImmutable()->setTimezone($this->timezone())->format('H:i');
        }

        $lastDelivered = StreamBotPost::query()->delivered()->latest('posted_at')->latest('id')->first();
        $alone = 60 * (int) config('esports.stream_bot.alone_minutes', 45);

        if ($lastDelivered !== null && $now->getTimestamp() - $lastDelivered->posted_at->getTimestamp() < $alone) {
            if (! $readChat) {
                return 'needs a human chat message since the last post (not read in a dry run)';
            }

            // From the second after the post: a message in the same second may have come before it (fail closed).
            if ($this->chat->humansSince($stream, $key->pubkey(), $lastDelivered->posted_at->getTimestamp() + 1) === []) {
                return 'no human wrote since the last post, and '.intdiv($alone, 60).' min have not passed';
            }

            // Someone answered: the spam gate's backoff ends.
            $this->gate->humanWrote();
        }

        return null;
    }

    /**
     * Rules 1-3 alone: the switch, the key, a stream, live, outside the quiet
     * hours. What a moment the bot must not miss (ChampionChat's GG) still
     * waits for; the cadence rules 4-6 it skips.
     */
    public function airBlocker(CarbonImmutable $now, ?LeagueKey $key, ?StreamCoordinates $stream): ?string
    {
        if (! (bool) config('esports.stream_bot.enabled', false)) {
            return 'ESPORTS_STREAM_BOT_ENABLED is off';
        }

        if ($key === null) {
            return 'ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key';
        }

        if ($stream === null) {
            return 'the stream has no key, d tag or relay (twentyone.nostr, twentyone.stream)';
        }

        $offAir = $this->liveness->problem($now);

        if ($offAir !== null) {
            return 'the stream is not live ('.$offAir.')';
        }

        $quiet = $this->isQuiet($now);

        if ($quiet !== false) {
            return $quiet === null ? 'ESPORTS_STREAM_BOT_QUIET_HOURS is unreadable (treated as quiet)' : 'quiet hours';
        }

        return null;
    }

    /**
     * The next message: builders in weighted random order (facts first more
     * often), skipping the recent builders and facts, every line that already
     * went out, and every message that breaks the copy rules. Null when there
     * is nothing new.
     *
     * @param  list<string>  $recentBuilders
     * @param  list<string>  $recentFacts
     * @param  array<string, true>|null  $usedContents  delivered chat text; null reads the log
     * @param  (Closure(StreamBotMessage): ?StreamBotMessage)|null  $compose  the message as it goes out, null to pass it over (the voice and the spam gate)
     */
    public function next(CarbonImmutable $now, array $recentBuilders, array $recentFacts, ?array $usedContents = null, ?Closure $compose = null): ?StreamBotMessage
    {
        $used = $usedContents ?? $this->deliveredContents();
        $this->builders->skipPosted(fn (string $content): bool => isset($used[$content]));

        try {
            foreach ($this->order($recentBuilders) as $builder) {
                try {
                    $messages = $this->builders->build($builder, $now);
                } catch (Throwable $e) {
                    // One broken builder must not silence the others.
                    report($e);

                    continue;
                }

                foreach ($messages as $message) {
                    if (in_array($message->factKey, $recentFacts, true) || isset($used[$message->content])) {
                        continue;
                    }

                    $problems = StreamBotCopy::violations($message->content, $message->tags);

                    if ($problems !== []) {
                        Log::warning('Stream bot message dropped', ['builder' => $message->builder, 'problems' => $problems]);

                        continue;
                    }

                    if ($compose !== null) {
                        $message = $compose($message);

                        if ($message === null) {
                            continue;
                        }
                    }

                    return $message;
                }
            }
        } finally {
            $this->builders->skipPosted(null);
        }

        return null;
    }

    /**
     * The next `$count` messages the bot would post, in order, starting
     * from the real history and pretending each one went out (dry run).
     *
     * @return list<StreamBotMessage>
     */
    public function preview(CarbonImmutable $now, int $count): array
    {
        $builders = $this->recentBuilders();
        $facts = $this->recentFacts($now);
        $used = $this->deliveredContents();
        $gap = max(0, (int) config('esports.stream_bot.builder_gap', 4));
        $messages = [];

        while (count($messages) < $count && ($message = $this->next($now, $builders, $facts, $used)) !== null) {
            $messages[] = $message;
            $facts[] = $message->factKey;
            $used[$message->content] = true;
            array_unshift($builders, $message->builder);
            $builders = array_slice($builders, 0, $gap);
        }

        return $messages;
    }

    /**
     * The kind-1311 event for a message: one `a` tag (NIP-53) with the
     * stream's first relay as hint and the `root` marker, then the `p` tags
     * of the players the message names (no `t` tags: a standing rule of
     * this project).
     *
     * @param  list<list<string>>  $mentions
     */
    public function event(LeagueKey $key, StreamCoordinates $stream, string $content, int $createdAt, array $mentions = []): SignedEvent
    {
        return $key->sign(self::KIND_LIVE_CHAT, [['a', $stream->address(), $stream->relayHint(), 'root'], ...$mentions], $content, $createdAt);
    }

    /**
     * Sign, publish to the stream relays, log.
     */
    public function post(LeagueKey $key, StreamCoordinates $stream, StreamBotMessage $message, CarbonImmutable $now): StreamBotPost
    {
        $event = $this->event($key, $stream, $message->content, $now->getTimestamp(), $message->tags);
        $problems = StreamBotCopy::violations($event->content, $event->tags);

        if ($problems !== []) {
            throw new \LogicException('The stream bot refused its own message: '.implode(', ', $problems));
        }

        $results = $this->publisher->publish($event->toArray(), $stream->relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));

        $post = StreamBotPost::query()->create([
            'builder' => $message->builder,
            'fact_key' => $message->factKey,
            'event_id' => $event->id,
            'content' => $event->content,
            'relays_accepted' => $accepted,
            'relays_total' => count($results),
            'posted_at' => $now,
            'next_due_at' => $now->addSeconds($accepted > 0 ? $this->intervalSeconds() : 60 * max(1, (int) config('esports.stream_bot.retry_minutes', 5))),
        ]);

        Log::info('Stream bot posted', [
            'builder' => $message->builder,
            'fact' => $message->factKey,
            'id' => $event->id,
            'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results),
        ]);

        return $post;
    }

    /**
     * The stream session the spam gate's mention pause counts by: the stream
     * and its Berlin day (the 24/7 stream has no session of its own).
     */
    public static function session(StreamCoordinates $stream, CarbonImmutable $now): string
    {
        return $stream->address().'|'.$now->setTimezone((string) config('esports.stream_bot.timezone', 'Europe/Berlin'))->toDateString();
    }

    /**
     * true inside the quiet hours, false outside or without any, null when
     * the setting cannot be read (the caller treats that as quiet).
     */
    public function isQuiet(CarbonImmutable $now): ?bool
    {
        return self::quietAt($now);
    }

    /**
     * true inside the quiet hours, false outside or without any, null when
     * the setting cannot be read. Shared with the profile notes.
     */
    public static function quietAt(CarbonImmutable $now): ?bool
    {
        $setting = trim((string) config('esports.stream_bot.quiet_hours'));

        if ($setting === '') {
            return false;
        }

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?$/', $setting, $m) !== 1) {
            return null;
        }

        [$fromHour, $fromMinute, $toHour, $toMinute] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0)];

        if ($fromHour > 23 || $toHour > 23 || $fromMinute > 59 || $toMinute > 59) {
            return null;
        }

        $from = $fromHour * 60 + $fromMinute;
        $to = $toHour * 60 + $toMinute;

        $local = $now->setTimezone((string) config('esports.stream_bot.timezone', 'Europe/Berlin'));
        $minute = $local->hour * 60 + $local->minute;

        if ($from === $to) {
            return false;
        }

        return $from < $to ? ($minute >= $from && $minute < $to) : ($minute >= $from || $minute < $to);
    }

    /** Delivered posts since midnight in the bot's time zone. */
    public function postsToday(CarbonImmutable $now): int
    {
        return StreamBotPost::query()->delivered()
            ->where('posted_at', '>=', $now->setTimezone($this->timezone())->startOfDay()->utc())
            ->count();
    }

    /**
     * Builders of the last `builder_gap` delivered posts, newest first.
     *
     * @return list<string>
     */
    public function recentBuilders(): array
    {
        $gap = max(0, (int) config('esports.stream_bot.builder_gap', 4));

        return $gap === 0 ? [] : array_values(StreamBotPost::query()->delivered()->latest('posted_at')->latest('id')->limit($gap)->pluck('builder')->all());
    }

    /**
     * Exact chat text that already went out. A later tick never posts it again.
     *
     * @return array<string, true>
     */
    public function deliveredContents(): array
    {
        return array_fill_keys(StreamBotPost::query()->delivered()->pluck('content')->all(), true);
    }

    /**
     * Facts of the delivered posts within `repeat_hours`.
     *
     * @return list<string>
     */
    public function recentFacts(CarbonImmutable $now): array
    {
        return array_values(StreamBotPost::query()->delivered()
            ->where('posted_at', '>', $now->subHours((int) config('esports.stream_bot.repeat_hours', 12)))
            ->pluck('fact_key')->unique()->all());
    }

    /**
     * Builder names in weighted random order (Efraimidis-Spirakis: each
     * gets u^(1/weight), highest first), without the recent ones.
     *
     * @param  list<string>  $recentBuilders
     * @return list<string>
     */
    private function order(array $recentBuilders): array
    {
        $keys = [];

        foreach ($this->builders->all() as $name => $weight) {
            if (in_array($name, $recentBuilders, true)) {
                continue;
            }

            $u = random_int(1, PHP_INT_MAX) / PHP_INT_MAX;
            $keys[$name] = $u ** (1 / max(1, $weight));
        }

        arsort($keys);

        return array_keys($keys);
    }

    private function intervalSeconds(): int
    {
        $interval = 60 * max(1, (int) config('esports.stream_bot.interval_minutes', 20));
        $jitter = 60 * max(0, (int) config('esports.stream_bot.jitter_minutes', 5));
        $jitter = min($jitter, $interval - 60);

        return $interval + ($jitter > 0 ? random_int(-$jitter, $jitter) : 0);
    }

    private function timezone(): string
    {
        return (string) config('esports.stream_bot.timezone', 'Europe/Berlin');
    }
}
