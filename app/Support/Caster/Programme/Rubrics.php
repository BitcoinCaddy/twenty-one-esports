<?php

namespace App\Support\Caster\Programme;

use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Models\ChatterLine;
use App\Models\User;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\HistoryScripts;
use App\Support\Caster\NewsFilter;
use App\Support\Caster\PodcastFeed;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\WorldMemory;
use App\Support\Moderation\SiteModeration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The rubrics of TWENTY ONE Radio (plan "TWENTY ONE Radio", P5): what airs
 * at the clock's rubric slots (:15 and :45), each with real material:
 *
 * - Liga-Rubrik: the league's finished matches, tournaments, new players and
 *   clans of the last `rubrics.league_hours` (WorldMemory, read from the
 *   league's rows) and the ladders' top rows (SceneFacts);
 * - Presseschau: three headlines of the feeds from three outlets, the same
 *   story told by several of them first (NewsBulletin::sameStory());
 * - Podcast-Ecke: the digest of the newest EINUNDZWANZIG episode's
 *   transcript, else the feed's show notes;
 * - Geschichtsstunde: the next Bauernkrieg episode (HistoryScripts);
 * - Chat-Stunde: questions viewers asked in the chat in the last
 *   `rubrics.chat_hours` (trusted chatters only, no names);
 * - the eight roast formats of P10 (Roasts, written by RoastScripts).
 *
 * The rotation (`rubrics.rotation`) starts at the slot's place in the day
 * (the hour times the rubric slots per hour, plus the slot's place in its
 * hour), so the order moves on over the day; a rubric without material, or
 * with the same material it aired within its `repeat_hours`, is skipped.
 * plannedFor() keeps the choice for a slot once made (cache), so a teaser
 * that named the rubric and the rubric written later agree.
 *
 * @phpstan-import-type Slot from BroadcastClock
 *
 * @phpstan-type RubricMaterial array{data: string, names: list<string>, sources: list<string>, hash: string, keys: list<string>, same_story: bool}
 */
class Rubrics
{
    public const LIGA = 'liga';

    public const PRESS = 'presseschau';

    public const PODCAST = 'podcast';

    public const HISTORY = 'geschichte';

    public const CHAT = 'chat';

    /**
     * Each rubric: how it is named on air, what it brings (a teaser's data), and how a line names it.
     *
     * @var array<string, array{name: string, what: string, pattern: string}>
     */
    public const CATALOGUE = [
        self::LIGA => ['name' => 'die Liga-Rubrik', 'what' => 'die Ergebnisse, Turniere und Tabellen der letzten vierundzwanzig Stunden aus der TWENTY ONE Liga', 'pattern' => '/liga[\s-]*rubrik/iu'],
        self::PRESS => ['name' => 'die Presseschau', 'what' => 'drei Schlagzeilen aus drei Blättern im bissigen Vergleich', 'pattern' => '/presse[\s-]*schau/iu'],
        self::PODCAST => ['name' => 'die Podcast-Ecke', 'what' => 'das Wichtigste aus der neuesten Folge des EINUNDZWANZIG-Podcasts', 'pattern' => '/podcast[\s-]*ecke/iu'],
        self::HISTORY => ['name' => 'die Geschichtsstunde', 'what' => 'ein Kapitel aus dem Bauernkrieg', 'pattern' => '/geschichts[\s-]*stunde/iu'],
        self::CHAT => ['name' => 'die Chat-Stunde', 'what' => 'die besten Fragen aus dem Chat der letzten Stunden, beantwortet', 'pattern' => '/chat[\s-]*stunde/iu'],
    ] + Roasts::CATALOGUE;

    private const PLAN = 'caster:rubric:plan:';

    private const AIRED = 'caster:rubric:aired:';

    private const PRESS_USED = 'caster:rubric:press_used';

    private const CHAT_USED = 'caster:rubric:chat_used';

    public function __construct(private readonly ?BroadcastClock $clock = null) {}

    private function clock(): BroadcastClock
    {
        return $this->clock ?? BroadcastClock::fromConfig();
    }

    /** Whether rubrics run at all (`programme.rubrics.enabled` and the clock on). */
    public static function enabled(): bool
    {
        return config('tv.commentary.programme.rubrics.enabled') === true && config('tv.commentary.programme.enabled') === true;
    }

    /**
     * The rubric planned for a rubric slot, or null (rubrics off, a slot of
     * another type, nothing in the rotation has material). The first answer
     * for a slot is kept until an hour after it.
     *
     * @param  array{type: string, minute: int, key: int}  $slot
     */
    public function plannedFor(array $slot, CarbonImmutable $now): ?string
    {
        if (! self::enabled() || $slot['type'] !== BroadcastClock::RUBRIC) {
            return null;
        }

        $kept = Cache::get(self::PLAN.$slot['key']);

        if (is_string($kept) && isset(self::CATALOGUE[$kept])) {
            return $kept;
        }

        foreach ($this->rotationFor($slot) as $id) {
            // A roast format (P10) only within its hours and never right after another one.
            if (Roasts::owns($id) && ! app(Roasts::class)->mayTake($id, $slot, self::PLAN)) {
                continue;
            }

            if ($this->material($id, $now) !== null) {
                Cache::put(self::PLAN.$slot['key'], $id, CarbonImmutable::createFromTimestamp($slot['key'])->addHour());

                return $id;
            }
        }

        return null;
    }

    /**
     * The rotation for a slot, starting at its place in the day.
     *
     * @param  array{minute: int, key: int}  $slot
     * @return list<string>
     */
    public function rotationFor(array $slot): array
    {
        $rotation = array_values(array_filter((array) config('tv.commentary.programme.rubrics.rotation', []), fn (mixed $id): bool => is_string($id) && isset(self::CATALOGUE[$id])));

        if ($rotation === []) {
            return [];
        }

        $minutes = $this->clock()->minutesOf(BroadcastClock::RUBRIC);
        $place = array_search($slot['minute'], $minutes, true);
        $start = (intdiv($slot['key'], 3600) * max(1, count($minutes)) + ($place === false ? 0 : $place)) % count($rotation);

        return [...array_slice($rotation, $start), ...array_slice($rotation, 0, $start)];
    }

    /**
     * The material of a rubric now, or null (nothing there, or the same as it aired within its `repeat_hours`).
     *
     * @return RubricMaterial|null
     */
    public function material(string $id, CarbonImmutable $now): ?array
    {
        try {
            $material = match ($id) {
                self::LIGA => $this->league($now),
                self::PRESS => $this->press($now),
                self::PODCAST => $this->podcast(),
                self::HISTORY => $this->history($now),
                self::CHAT => $this->chat($now),
                default => Roasts::owns($id) ? app(Roasts::class)->material($id, $now) : null,
            };
        } catch (Throwable) {
            return null;
        }

        return $material !== null && Cache::get(self::AIRED.$id) === $material['hash'] ? null : $material;
    }

    /**
     * Remembers what a rubric aired: its material is not used again within the rubric's `repeat_hours`, and the
     * headlines of a Presseschau and the questions of a Chat-Stunde not within a day.
     *
     * @param  RubricMaterial  $material
     */
    public function aired(string $id, array $material, CarbonImmutable $now): void
    {
        $hours = (array) config('tv.commentary.programme.rubrics.repeat_hours', []);
        Cache::put(self::AIRED.$id, $material['hash'], $now->addHours(max(1, (int) ($hours[$id] ?? $hours['default'] ?? 2))));

        if (Roasts::owns($id)) {
            app(Roasts::class)->aired($id, $material, $now);
        }

        foreach ([self::PRESS => self::PRESS_USED, self::CHAT => self::CHAT_USED] as $rubric => $key) {
            if ($rubric === $id) {
                $used = $this->used($key, $now);
                foreach ($material['keys'] as $item) {
                    $used[$item] = $now->getTimestamp();
                }
                Cache::put($key, $used, $now->addDay());
            }
        }
    }

    /**
     * The keys used within the last day (key => timestamp).
     *
     * @return array<string, int>
     */
    private function used(string $key, CarbonImmutable $now): array
    {
        $used = Cache::get($key);

        return is_array($used) ? array_filter($used, fn (mixed $at): bool => is_int($at) && $at > $now->subDay()->getTimestamp()) : [];
    }

    /**
     * The league's events of the last `league_hours` (newest first, at most twelve) and the ladders' top rows; null
     * without a single event (the ladders alone stay the same all day). `names`: the players the material names.
     *
     * @return RubricMaterial|null
     */
    private function league(CarbonImmutable $now): ?array
    {
        $since = $now->subHours(max(1, (int) config('tv.commentary.programme.rubrics.league_hours', 24)))->getTimestamp();
        $events = array_slice(array_values(array_filter(app(WorldMemory::class)->items($now),
            fn (array $item): bool => in_array($item['kind'], [WorldMemory::RESULT, WorldMemory::TOURNAMENT, WorldMemory::PLAYER, WorldMemory::CLAN], true) && $item['at'] >= $since)), 0, 12);

        if ($events === []) {
            return null;
        }

        try {
            $ladders = (array) (app(SceneFacts::class)->forTopic(SceneTopic::LADDERS)['ladders'] ?? []);
        } catch (Throwable) {
            $ladders = [];
        }

        // The players the material names: the ladders' leaders and every league name in an event line.
        $names = array_values(array_filter(array_map(fn (array $ladder): string => (string) ($ladder['top'][0]['name'] ?? ''), $ladders)));
        $text = mb_strtolower(implode("\n", array_column($events, 'line')));

        foreach (User::query()->whereNotNull('name')->orderByDesc('id')->limit(5000)->pluck('name') as $name) {
            $spoken = SceneFacts::spoken((string) $name);

            if ($spoken !== null && mb_strlen($spoken) >= 3 && str_contains($text, mb_strtolower($spoken))) {
                $names[] = $spoken;
            }
        }

        $names = array_values(array_unique($names));
        // One player name per event (real Haiku 2026-10-12 kept "X gewinnt gegen Y" and lost the sentence to the one-name rule).
        $lines = array_map(fn (array $item): string => '- '.WorldMemory::relative($item['at'], $now).': '.self::oneName($item['line'], $names), $events);

        foreach ($ladders as $ladder) {
            $top = $ladder['top'][0] ?? null;

            if (is_array($top)) {
                $lines[] = '- Tabelle '.($ladder['ladder'] ?? '').': vorn liegt '.$top['name'].' mit Elo '.$top['elo'].' ('.$top['wins'].' Siege, '.$top['losses'].' Niederlagen)'
                    .(isset($ladder['gap_first_second']) ? ', '.$ladder['gap_first_second'].' Punkte vor Platz zwei' : '');
            }
        }

        return ['data' => implode("\n", $lines), 'names' => array_values(array_unique($names)), 'sources' => [], 'hash' => sha1(implode(',', array_column($events, 'key'))), 'keys' => array_column($events, 'key'), 'same_story' => false];
    }

    /**
     * Three headlines of the last `bulletin.fresh_hours` from three outlets (Deutschland first, then Bitcoin): the
     * outlets of one story first (the best comparison), the rest of other outlets; none a Presseschau had within
     * a day. null with fewer than three outlets.
     *
     * @return RubricMaterial|null
     */
    private function press(CarbonImmutable $now): ?array
    {
        $used = $this->used(self::PRESS_USED, $now);
        $rows = CasterNews::query()->whereNull('skipped')
            ->where('published_at', '>=', $now->utc()->subHours((int) config('tv.commentary.programme.bulletin.fresh_hours', 12)))->where('published_at', '<=', $now->utc())
            ->orderByRaw("case when section = 'germany' then 0 else 1 end")->orderByDesc('published_at')->orderByDesc('id')->limit(200)->get()
            ->filter(fn (CasterNews $row): bool => ! isset($used['news:'.$row->id]) && NewsFilter::reason($row->title, $row->language) === null)->values();

        $picked = [];

        // One story told by the most outlets first.
        $best = [];
        foreach ($rows as $index => $row) {
            $story = [$row];
            foreach ($rows as $other => $candidate) {
                if ($other !== $index && $candidate->source !== $row->source && ! in_array($candidate->source, array_map(fn (CasterNews $taken): string => $taken->source, $story), true)
                    && NewsBulletin::sameStory($row->title, $candidate->title)) {
                    $story[] = $candidate;
                }
            }
            if (count($story) > count($best)) {
                $best = $story;
            }
        }

        if (count($best) >= 2) {
            $picked = array_slice($best, 0, 3);
        }

        foreach ($rows as $row) {
            if (count($picked) >= 3) {
                break;
            }
            if (! in_array($row->source, array_map(fn (CasterNews $taken): string => $taken->source, $picked), true)
                && array_filter($picked, fn (CasterNews $taken): bool => NewsBulletin::sameStory($taken->title, $row->title)) === []) {
                $picked[] = $row;
            }
        }

        if (count($picked) < 3) {
            return null;
        }

        $sameStory = count($best) >= 2;

        return [
            'data' => implode("\n", array_map(fn (CasterNews $row): string => '- '.$row->source.': „'.CasterVoice::withoutHashtags($row->title).'“', $picked)),
            'names' => [],
            'sources' => array_map(fn (CasterNews $row): string => $row->source, $picked),
            'hash' => sha1(implode(',', array_map(fn (CasterNews $row): string => (string) $row->id, $picked))),
            'keys' => array_map(fn (CasterNews $row): string => 'news:'.$row->id, $picked),
            'same_story' => $sameStory && count(array_filter($picked, fn (CasterNews $row): bool => NewsBulletin::sameStory($picked[0]->title, $row->title))) >= 2,
        ];
    }

    /**
     * The newest episode: the digest of its transcript, else the feed's newest episode with its show notes.
     *
     * @return RubricMaterial|null
     */
    private function podcast(): ?array
    {
        $block = app(PodcastTranscripts::class)->digestBlock();

        if ($block === null) {
            $episode = app(PodcastFeed::class)->episodes()[0] ?? null;
            $block = $episode === null || trim($episode['notes']) === '' ? null
                : 'Die neueste Folge des EINUNDZWANZIG-Podcasts, „'.$episode['title'].'“'.($episode['published'] > 0 ? ', erschienen '.PodcastFeed::spokenDay($episode['published']) : '').', laut Shownotes: '.$episode['notes'];
        }

        return $block === null ? null : ['data' => $block, 'names' => [], 'sources' => [], 'hash' => sha1($block), 'keys' => [], 'same_story' => false];
    }

    /**
     * The next Bauernkrieg episode (HistoryScripts::nextEpisode()) with its facts and their sources.
     *
     * @return RubricMaterial|null
     */
    private function history(CarbonImmutable $now): ?array
    {
        $episode = app(HistoryScripts::class)->nextEpisode($now);

        if ($episode === null) {
            return null;
        }

        $facts = implode("\n", array_map(fn (array $fact): string => '- '.str_replace("\n", ' ', $fact['text']).' (Quelle: '.$fact['source'].')', $episode['facts']));

        return ['data' => "Folge „{$episode['title']}“\n".$facts, 'names' => [], 'sources' => [], 'hash' => HistoryScripts::HASH_PREFIX.$episode['id'], 'keys' => [HistoryScripts::HASH_PREFIX.$episode['id']], 'same_story' => false];
    }

    /**
     * Up to four questions from the chat of the last `chat_hours` (a line with a question mark and at least four
     * words, a hidden chatter left out, none a Chat-Stunde answered within a day), without names.
     *
     * @return RubricMaterial|null
     */
    private function chat(CarbonImmutable $now): ?array
    {
        $used = $this->used(self::CHAT_USED, $now);
        $questions = [];

        foreach (ChatterLine::query()->where('said_at', '>=', $now->subHours(max(1, (int) config('tv.commentary.programme.rubrics.chat_hours', 3)))->getTimestamp())
            ->where('said_at', '<=', $now->getTimestamp())->orderByDesc('said_at')->orderByDesc('id')->limit(200)->get() as $line) {
            $words = count(preg_split('/\s+/u', trim($line->message), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if (count($questions) < 4 && str_contains($line->message, '?') && $words >= 4 && ! isset($used['chat:'.$line->id]) && ! SiteModeration::isHidden($line->pubkey)) {
                $questions['chat:'.$line->id] = '- Ein Zuschauer fragte '.WorldMemory::relative($line->said_at, $now).': „'.CasterVoice::withoutHashtags(trim($line->message)).'“';
            }
        }

        return $questions === [] ? null : ['data' => implode("\n", $questions), 'names' => [], 'sources' => [], 'hash' => sha1(implode(',', array_keys($questions))), 'keys' => array_keys($questions), 'same_story' => false];
    }

    /**
     * A line with the first player of `$names` it mentions kept and every later one said as "die Gegenseite".
     *
     * @param  list<string>  $names
     */
    public static function oneName(string $line, array $names): string
    {
        $found = [];

        foreach ($names as $name) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $line, $match, PREG_OFFSET_CAPTURE) === 1) {
                $found[$match[0][1]] = $name;
            }
        }

        ksort($found);

        foreach (array_slice(array_values($found), 1) as $name) {
            $line = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', 'die Gegenseite', $line);
        }

        return $line;
    }

    /**
     * The facts_hash a rubric script carries: a Geschichtsstunde its episode (HistoryScripts counts it as told), any other its slot.
     *
     * @param  RubricMaterial  $material
     */
    public static function hashFor(string $id, array $material, int $slotKey): string
    {
        return $id === self::HISTORY ? $material['hash'] : 'rubric:'.$id.':'.$slotKey;
    }

    /** Whether a rubric script for this slot is queued already (or was). */
    public static function written(int $slotKey): bool
    {
        return CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('airs_at', CarbonImmutable::createFromTimestamp($slotKey)->utc())->exists();
    }
}
