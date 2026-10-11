<?php

namespace App\Support\Caster;

use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Support\Voice\VoiceCanon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Wishes of trusted chatters (user 2026-10-11: "Ich habe ihn nach den
 * Bauernkriegen gefragt, er soll den Leuten im Level 1 Web of Trust gerne
 * die Wünsche erfüllen können"; live, the reply only teased the story and no
 * story followed). A trusted chatter (ChatterCache::mayWish()) who asks for
 * something the commentary can build gets it: the reply acknowledges the
 * wish by name (ReactionLines::wish()) and the segment is queued right behind
 * it, both at live priority, so it plays at the next clip boundary without a
 * music gap (ClipSpool::nextLive() takes wish segments, marked by SCENE). A
 * long segment is queued in parts (ScriptQueue::parts()): the first part is
 * voiced within seconds, the rest follow back to back (SceneDirector).
 *
 * What can be wished (intent(), keywords, first match wins):
 * - HISTORY: a long Bauernkrieg episode (HistoryScripts), none told within
 *   `wishes_history_repeat_minutes` again;
 * - NEWS: the newest unused headline (NewsScripts, long), Bitcoin or Germany
 *   first by keyword;
 * - GAME: a pattern scene (PatternScripts) of the live games, the live
 *   tournament, the results or the ladders, whichever has data;
 * - CANON: EINUNDZWANZIG lore, two verified canon entries with their meaning;
 * - JOKE: three verified canon jokes;
 * - MORE ("erzähl mehr", "weiter"): the type of the last wish or segment again.
 *
 * The league voice tells each of them freely (user 2026-10-12: "FREIE
 * KI-generierte Rede!!!"): a Bauernkrieg episode from its facts, a news
 * segment from its headline, a game scene from its facts, a joke or lore run
 * with the canon as inspiration; the builders and the canon stand in when
 * the voice gives nothing. Limits: one wish per person per `wishes_person_minutes`, at most
 * `wishes_per_hour` in all; a wish that cannot be built (no headline, no live
 * data) falls back to the plain reply and costs no limit.
 */
class ChatWishes
{
    public const HISTORY = 'history';

    public const NEWS = 'news';

    public const JOKE = 'joke';

    public const GAME = 'game';

    public const CANON = 'canon';

    public const MORE = 'more';

    /** The `scene` of a wished segment: ClipSpool and the fetcher treat it as a live script. */
    public const SCENE = 'chat_wish';

    private const PERSON = 'caster:wish:person:';

    private const LAST_TYPE = 'caster:wish:last_type';

    private const CANON_RECENT = 'caster:wish:canon_recent';

    /** Canon entries not repeated within this many picks. */
    private const CANON_MEMORY = 30;

    /** The keyword patterns, first match wins; MORE only when no topic matched. */
    private const PATTERNS = [
        self::HISTORY => '/bauernkrieg|\bbauern\b|müntzer|memmingen|zwölf artikel|12 artikel/u',
        self::NEWS => '/\bnews\b|\bnachrichten\b|schlagzeile|was gibt\'?s neues|was gibt es neues|neuigkeit/u',
        self::GAME => '/\bspiel|\bpartie|wer führt|\bstand\b|spielstand|turnier|\bmatch|ladder|tabelle/u',
        self::CANON => '/podcast-?wissen|einundzwanzig|über den podcast|vom podcast/u',
        self::JOKE => '/\bwitz|\bspruch|sprüche|\bmeme|podcast|lustig/u',
        self::MORE => '/erzähl mehr|mehr davon|\bweiter\b|erzähl weiter|noch eine|noch einen|nochmal|noch mal/u',
    ];

    public function __construct(
        private ChatterCache $cache,
        private HistoryScripts $history,
        private NewsScripts $news,
        private PatternScripts $patterns,
        private SceneFacts $facts,
        private VoiceCanon $canon,
        private ReactionLines $lines,
        private CasterVoice $voice,
    ) {}

    public static function enabled(): bool
    {
        return config('tv.commentary.chat.wishes', true) === true;
    }

    /** The wish in a chat message, or null. */
    public static function intent(string $message): ?string
    {
        $text = mb_strtolower($message);

        foreach (self::PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The segment a trusted chatter wished for, built now, or null (no wish,
     * not trusted enough, a limit, nothing to build). A granted wish starts
     * the person's cooldown.
     *
     * @return array{type: string, kind: string, text: string, facts_hash: string, source: string, topic: string|null}|null
     */
    public function grant(string $pubkey, string $message, CarbonImmutable $now): ?array
    {
        $type = self::intent($message);

        // 0 means no limit, for the cooldown and the hourly cap alike.
        $cooldown = max(0, (int) config('tv.commentary.chat.wishes_person_minutes', 5));
        $lastWish = Cache::get(self::PERSON.$pubkey);

        if (! self::enabled() || $type === null || ! $this->cache->mayWish($pubkey) || ($cooldown > 0 && is_int($lastWish) && $lastWish > $now->subMinutes($cooldown)->getTimestamp())) {
            return null;
        }

        $perHour = (int) config('tv.commentary.chat.wishes_per_hour', 6);
        // A long wished segment is queued in parts (ScriptQueue::parts()): one wish, however many parts.
        if ($perHour > 0 && CasterScript::query()->whole()->where('scene', self::SCENE)->where('created_at', '>', $now->subHour())->count() >= $perHour) {
            return null;
        }

        if ($type === self::MORE) {
            $type = $this->lastType();

            if ($type === null) {
                return null;
            }
        }

        $segment = $this->build($type, $message, $now);

        if ($segment === null) {
            return null;
        }

        Cache::put(self::PERSON.$pubkey, $now->getTimestamp(), 86_400);
        Cache::put(self::LAST_TYPE, $type, 86_400);

        return ['type' => $type, ...$segment];
    }

    /**
     * @return array{kind: string, text: string, facts_hash: string, source: string, topic: string|null}|null
     */
    private function build(string $type, string $message, CarbonImmutable $now): ?array
    {
        return match ($type) {
            self::HISTORY => $this->historySegment($now),
            self::NEWS => $this->newsSegment($message, $now),
            self::GAME => $this->gameSegment($message, $now),
            self::CANON => $this->canonSegment(),
            self::JOKE => $this->jokeSegment(),
            default => null,
        };
    }

    /** @return array{kind: string, text: string, facts_hash: string, source: string, topic: string|null}|null */
    private function historySegment(CarbonImmutable $now): ?array
    {
        $segment = $this->history->write($now, true, (int) config('tv.commentary.chat.wishes_history_repeat_minutes', 60));

        return $segment === null ? null : ['kind' => CasterScript::HISTORY, 'text' => $segment['text'], 'facts_hash' => HistoryScripts::HASH_PREFIX.$segment['episode'], 'source' => $segment['source'], 'topic' => null];
    }

    /** @return array{kind: string, text: string, facts_hash: string, source: string, topic: string|null}|null */
    private function newsSegment(string $message, CarbonImmutable $now): ?array
    {
        $text = mb_strtolower($message);
        $section = match (true) {
            preg_match('/bitcoin|\bbtc\b|krypto|\bsats?\b|kurs/u', $text) === 1 => CasterNews::BITCOIN,
            preg_match('/deutschland|politik|berlin|regierung|inland/u', $text) === 1 => CasterNews::GERMANY,
            default => null,
        };
        $item = $this->news->next($now, $section);
        $segment = $item === null ? null : $this->news->write($item, $now, ScriptQueue::aiEnabled(), true);

        return $item === null || $segment === null ? null : ['kind' => CasterScript::NEWS, 'text' => $segment['text'], 'facts_hash' => 'news:'.$item->id, 'source' => $segment['source'], 'topic' => $item->section];
    }

    /** @return array{kind: string, text: string, facts_hash: string, source: string, topic: string|null}|null */
    private function gameSegment(string $message, CarbonImmutable $now): ?array
    {
        $topics = preg_match('/turnier/u', mb_strtolower($message)) === 1
            ? [SceneTopic::LIVE_TOURNAMENT, SceneTopic::TOURNAMENTS, SceneTopic::LIVE_GAMES]
            : [SceneTopic::LIVE_GAMES, SceneTopic::LIVE_TOURNAMENT, SceneTopic::RESULTS, SceneTopic::LADDERS];
        $night = ScenePhase::isNight($now);

        foreach ($topics as $topic) {
            if ($night && ! in_array($topic, SceneTopic::NIGHT, true)) {
                continue;
            }

            $facts = $this->facts->forTopic($topic, $night);

            if (! SceneFacts::hasData($topic, $facts)) {
                continue;
            }

            // The league voice first (user 2026-10-12: "FREIE KI-generierte Rede!!!"), the pattern scene as the fallback.
            $range = (array) config('tv.commentary.scripts.sentences', []);
            $ai = ScriptQueue::aiEnabled() ? $this->voice->write(CasterScript::SCENE, SceneTopic::phase($topic, $now), $facts, (int) ($range['min'] ?? 2), (int) ($range['max'] ?? 8)) : null;

            if ($ai !== null) {
                return ['kind' => CasterScript::LIVE, 'text' => $ai['text'], 'facts_hash' => SceneFacts::hash($facts), 'source' => CasterScript::SOURCE_AI, 'topic' => $topic];
            }

            $script = $this->patterns->write($topic, SceneTopic::phase($topic, $now), $facts, $now);

            if ($script !== null) {
                return ['kind' => CasterScript::LIVE, 'text' => $script['text'], 'facts_hash' => SceneFacts::hash($facts), 'source' => CasterScript::SOURCE_PATTERN, 'topic' => $topic];
            }
        }

        return null;
    }

    /**
     * Three verified jokes of the canon (the curated `flavour.jokes` first),
     * none of the last CANON_MEMORY picks again.
     *
     * @return array{kind: string, text: string, facts_hash: string, source: string, topic: string|null}|null
     */
    private function jokeSegment(): ?array
    {
        $picked = $this->freshCanon($this->patterns->canonPool(), 3);
        $riff = $this->riff(self::JOKE, $picked);

        if ($riff !== null) {
            return ['kind' => CasterScript::LIVE, 'text' => $riff, 'facts_hash' => 'wish:joke', 'source' => CasterScript::SOURCE_AI, 'topic' => null];
        }

        $lines = [];
        foreach ($picked as $turn => $id) {
            $lines[] = (string) $this->patterns->spokenCanon($id, $turn, $lines);
        }

        if (count($lines) < 2) {
            return null;
        }

        $lines[count($lines) - 1] = '[laughter] '.$lines[count($lines) - 1];

        return ['kind' => CasterScript::LIVE, 'text' => implode("\n", [$this->lines->wishIntro(self::JOKE), ...$lines]), 'facts_hash' => 'wish:joke', 'source' => CasterScript::SOURCE_PATTERN, 'topic' => null];
    }

    /**
     * EINUNDZWANZIG lore: two verified canon entries of the podcast (running
     * gags, memes, its regular segments), each with its meaning as the canon
     * states it, only where both read as plain spoken sentences.
     *
     * @return array{kind: string, text: string, facts_hash: string, source: string, topic: string|null}|null
     */
    private function canonSegment(): ?array
    {
        $stories = [];
        foreach ($this->canon->entries() as $id => $entry) {
            if ($entry['category'] !== 'einundzwanzig' || ! in_array($entry['type'], ['running_gag', 'meme', 'segment'], true)) {
                continue;
            }

            $meaning = self::plainMeaning($entry['meaning']);
            $wording = $this->patterns->spokenCanon($id);

            if ($meaning !== null && $wording !== null && self::speakable($wording)) {
                $stories[$id] = [$wording, $meaning];
            }
        }

        $picked = $this->freshCanon(array_keys($stories), 2);
        $riff = $this->riff(self::CANON, $picked);

        if ($riff !== null) {
            return ['kind' => CasterScript::LIVE, 'text' => $riff, 'facts_hash' => 'wish:canon', 'source' => CasterScript::SOURCE_AI, 'topic' => null];
        }

        $lines = [];
        foreach ($picked as $id) {
            array_push($lines, ...$stories[$id]);
        }

        if (count($lines) < 4) {
            return null;
        }

        return ['kind' => CasterScript::LIVE, 'text' => implode("\n", [$this->lines->wishIntro(self::CANON), ...$lines]), 'facts_hash' => 'wish:canon', 'source' => CasterScript::SOURCE_PATTERN, 'topic' => null];
    }

    /**
     * The league voice's free take on a joke or lore wish, the picked canon
     * entries as its inspiration (CasterVoice::riff()); null when the AI is
     * off or gave nothing, and the pattern run stands in.
     *
     * @param  list<string>  $ids
     */
    private function riff(string $type, array $ids): ?string
    {
        if (! ScriptQueue::aiEnabled() || $ids === []) {
            return null;
        }

        $inspiration = [];
        foreach ($ids as $id) {
            $entry = $this->canon->find($id);
            if ($entry !== null) {
                $inspiration[] = ['wording' => $entry['wording'], 'meaning' => $entry['meaning']];
            }
        }

        return $this->voice->riff($type, $inspiration);
    }

    /**
     * Up to `$count` ids of `$pool` not among the last picks (the oldest of them when the pool is used up).
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function freshCanon(array $pool, int $count): array
    {
        $recent = Cache::get(self::CANON_RECENT);
        $recent = is_array($recent) ? array_values(array_filter($recent, 'is_string')) : [];
        $pool = array_values(array_filter($pool, fn (string $id): bool => $this->canon->find($id) !== null && self::speakable((string) $this->patterns->spokenCanon($id))));
        $fresh = array_values(array_diff($pool, $recent));

        if (count($fresh) < $count) {
            $fresh = [...$fresh, ...array_values(array_intersect(array_reverse($recent), $pool))];
        }

        shuffle($fresh);
        $picked = array_slice(array_values(array_unique($fresh)), 0, $count);
        Cache::put(self::CANON_RECENT, array_slice([...$picked, ...array_diff($recent, $picked)], 0, self::CANON_MEMORY), 86_400);

        return $picked;
    }

    /** The type of the last wish, else of the last news or Bauernkrieg segment, else null. */
    private function lastType(): ?string
    {
        $last = Cache::get(self::LAST_TYPE);

        if (is_string($last) && $last !== self::MORE) {
            return $last;
        }

        return match (CasterScript::query()->whereIn('kind', CasterScript::SEGMENTS)->orderByDesc('id')->value('kind')) {
            CasterScript::HISTORY => self::HISTORY,
            CasterScript::NEWS => self::NEWS,
            default => null,
        };
    }

    /**
     * A canon meaning as one or two spoken sentences: the explanation after
     * its label ("Laufband-Spruch: …"), only without brackets, quotes, digits
     * or abbreviations, at most two sentences.
     */
    private static function plainMeaning(string $meaning): ?string
    {
        $meaning = trim((string) preg_replace('/^[^:]{0,60}:\s*/u', '', $meaning));
        $meaning = $meaning === '' ? '' : mb_strtoupper(mb_substr($meaning, 0, 1)).mb_substr($meaning, 1);

        if ($meaning === '' || preg_match('/[()\[\]„“”"»«:;\d\/]|(?<![\p{L}])\p{L}{1,3}\.\s|\bz\. ?B\./u', $meaning) === 1) {
            return null;
        }

        $sentences = ScriptGuard::splitSentences(self::sentence($meaning), (int) config('tv.commentary.scripts.max_words_per_sentence', 32));
        $lines = ScriptGuard::lines($sentences);

        return count($lines) <= 2 && array_filter($lines, fn (string $line): bool => ! self::speakable($line)) === [] ? implode("\n", $lines) : null;
    }

    /** A line the script checks take on its own: one complete sentence between the word minimum and limit, at most the comma limit. */
    private static function speakable(string $line): bool
    {
        return (new ScriptGuard)->check($line, 1, 1, (int) config('tv.commentary.scripts.max_words_per_sentence', 32)) === [];
    }

    private static function sentence(string $text): string
    {
        $text = trim($text);

        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
