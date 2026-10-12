<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use App\Models\YouTubeChatLine;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The YouTube restream's live chat, spoken only as a summary (user
 * 2026-10-12: "Aber nur zusammengefasst."): at most once per
 * `every_minutes`, and only with at least `min_messages` new lines
 * (YouTubeChat), the league voice writes one chat clip "Drüben auf YouTube
 * …" (CasterVoice::youTubeSummary(): themes, the funniest or dumbest message
 * roasted, a question answered briefly with at most one web lookup;
 * Sirius joins as a duet at `duet_share`). Never a reply per message.
 *
 * The messages are data: sanitised on the way in, the newest
 * `max_messages` go to the prompt between delimiters, each led by its
 * author's speakable name for at most the two most active authors and
 * "jemand" for everybody else. A draft that speaks more than two author
 * names, or reads QUOTE_WORDS words of a message in a row, is dropped; the
 * voice's own checks (house rules, blocklist, links, hashtags, Lightning
 * addresses) apply as for a chat reply. No model, no clip: there is no
 * pattern summary. The clip queues like a chat reply (chat priority, the
 * chat items' hold), outside the hourly cap of the personal replies.
 */
class YouTubeChatSummary
{
    public const TOPIC = 'youtube_chat';

    public const SKIPS = 'youtube_summary';

    /** Words of one message in a row that make a draft a read-out, not a summary. */
    public const QUOTE_WORDS = 6;

    /** Author names a summary may speak: none (user 2026-10-12: "Die YouTube-Zusammenfassung ganz ohne Autorennamen"). */
    public const MAX_NAMES = 0;

    /** Lines older than this never join a summary (a lost cursor does not dig up the whole day). */
    private const FRESH_MINUTES = 60;

    private const CURSOR = 'caster:youtube:summary_cursor';

    private const LAST_ATTEMPT = 'caster:youtube:summary_at';

    public function __construct(
        private CasterVoice $voice,
        private ScriptQueue $queue,
        private VoiceUsage $usage,
    ) {}

    /**
     * One summary when it is due.
     *
     * @return int clips queued (0 or 1)
     */
    public function summarise(CarbonImmutable $now): int
    {
        if (! YouTubeChat::enabled() || ! CasterVoice::enabled() || ! ScriptQueue::aiEnabled()) {
            return 0;
        }

        $settings = YouTubeChat::settings();
        $every = max(1, (int) ($settings['every_minutes'] ?? 15));

        if ((int) Cache::get(self::LAST_ATTEMPT, 0) > $now->subMinutes($every)->getTimestamp()) {
            return 0;
        }

        $lines = YouTubeChatLine::query()->where('id', '>', (int) Cache::get(self::CURSOR, 0))
            ->where('said_at', '>=', $now->subMinutes(self::FRESH_MINUTES)->getTimestamp())
            ->orderByDesc('id')->limit(max(1, (int) ($settings['max_messages'] ?? 40)))->get()->reverse()->values();

        if ($lines->count() < max(1, (int) ($settings['min_messages'] ?? 3))) {
            return 0;
        }

        // An attempt, passed or not, waits the full cadence: a rejected draft does not cost a model call every minute.
        Cache::put(self::LAST_ATTEMPT, $now->getTimestamp(), 86_400);

        $names = self::namedAuthors($lines);
        $question = $lines->reverse()->first(fn (YouTubeChatLine $line): bool => str_contains($line->message, '?') || ReactionLines::intent($line->message) === 'question')?->message;
        $reply = $this->voice->youTubeSummary(self::digest($lines, $names), $question, self::duetDue());

        if ($reply === null) {
            $this->usage->recordRejection(self::SKIPS, ['voice_rejected']);

            return 0;
        }

        $reason = self::rejection($reply['text'], $lines);

        if ($reason !== null) {
            $this->usage->recordRejection(self::SKIPS, [$reason]);

            return 0;
        }

        $script = $this->queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, $reply['text'], SceneFacts::hash(['youtube_chat' => (string) $lines->count()]),
            (int) config('tv.commentary.scripts.chat_priority', 50), $now, source: CasterScript::SOURCE_AI, topic: self::TOPIC, speakers: $reply['speakers']);
        Cache::put(self::CURSOR, (int) $lines->max('id'), 2 * 86_400);

        return $script === null ? 0 : 1;
    }

    /**
     * The speakable names of the at most MAX_NAMES most active authors
     * (ties: the latest), the only names the prompt sees.
     *
     * @param  Collection<int, YouTubeChatLine>  $lines
     * @return list<string>
     */
    public static function namedAuthors(Collection $lines): array
    {
        $counts = [];

        foreach ($lines as $index => $line) {
            if ($line->author !== null) {
                $counts[$line->author] = [($counts[$line->author][0] ?? 0) + 1, $index];
            }
        }

        uasort($counts, fn (array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return array_slice(array_map('strval', array_keys($counts)), 0, self::MAX_NAMES);
    }

    /**
     * The lines as the prompt sees them, oldest first: "- Name: „text“",
     * "jemand" for every author not in `$names`.
     *
     * @param  Collection<int, YouTubeChatLine>  $lines
     * @param  list<string>  $names
     */
    public static function digest(Collection $lines, array $names): string
    {
        return $lines->map(fn (YouTubeChatLine $line): string => '- '.(in_array($line->author, $names, true) ? $line->author : 'jemand').': „'.$line->message.'“')->implode("\n");
    }

    /**
     * Why a summary draft may not air, or null: more than MAX_NAMES author
     * names of these lines spoken, or QUOTE_WORDS words of a message in a row.
     *
     * @param  Collection<int, YouTubeChatLine>  $lines
     */
    public static function rejection(string $text, Collection $lines): ?string
    {
        $spoken = ScriptGuard::withoutMarkers(str_replace("\n", ' ', $text));
        $authors = $lines->pluck('author')->filter()->unique()
            ->filter(fn (string $name): bool => preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $spoken) === 1);

        if ($authors->count() > self::MAX_NAMES) {
            return 'too_many_names';
        }

        $words = ' '.implode(' ', self::words($spoken)).' ';

        foreach ($lines as $line) {
            $message = self::words($line->message);

            for ($i = 0; $i + self::QUOTE_WORDS <= count($message); $i++) {
                if (str_contains($words, ' '.implode(' ', array_slice($message, $i, self::QUOTE_WORDS)).' ')) {
                    return 'verbatim';
                }
            }
        }

        return null;
    }

    /** Whether this summary is a duet: a draw at `duet_share` (0 never, 1 always). */
    public static function duetDue(): bool
    {
        $share = (float) (YouTubeChat::settings()['duet_share'] ?? 0.0);

        return $share > 0.0 && mt_rand() / (mt_getrandmax() + 1) < $share;
    }

    /** @return list<string> lowercase words, letters and digits only */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $words);

        return $words[0];
    }
}
