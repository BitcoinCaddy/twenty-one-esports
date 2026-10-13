<?php

namespace App\Support\Caster;

use App\Models\CasterScript;
use App\Support\Caster\Blocks\StockFeeder;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\SportWeather;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The voicing budget (`tv.commentary.budget`; prod 2026-10-13: at 64
 * OmniVoice steps the workstation voices about as fast as real time, and of
 * three hours' scripts 27 scenes, 15 news, 15 rubric parts and a bulletin
 * expired unvoiced, while no stock was rendered at all): the server writes
 * only what the workstation can voice, in strict priority, and leaves
 * `stock_share` of the GPU hour to the stock.
 *
 * The throughput (throughput()) is the estimated airtime voiced per wall
 * second, measured from the uploads of the last `measure_minutes`: every
 * voiced script from its claim to its upload, overlapping stretches counted
 * once (a chat reply voiced inside a block's render shares its time), so an
 * idle workstation does not read as a slow one. Too little voiced in the
 * window: the last measured value, else `default_rtf`.
 *
 * The hour's budget (hourBudget()) is throughput × 3600 × (1 − stock_share)
 * seconds of fresh talk. A script of a tier (TIERS, highest first) is written
 * (opens(), admits()) only while
 *  - the scripts written in the last hour and it stay within the budget,
 *  - the scripts written in the last hour less `reserve_minutes`, it, and
 *    what the higher tiers will write in the next `reserve_minutes` (their
 *    slots on the broadcast clock, the chat cadence) stay within it,
 *  - it would be voiced `margin_seconds` before it expires, behind every
 *    script of its tier and above that waits or is being voiced and what
 *    the tiers above will write until then,
 *  - no bulletin is at risk of missing its slot (then everything else waits),
 *  - and, while the budget is tight (with `scene_cap.always` every hour), at
 *    most `scene_cap.per_hour` slide scenes were written this hour.
 * A bulletin is always written. The workstation claims in the same order
 * (rankSql()), and once the fresh talk voiced in the last hour reached the
 * budget, the claim hands out only what the clock places and the replies, so
 * the GPU renders stock meanwhile (stockTurn()).
 *
 * @phpstan-import-type Slot from BroadcastClock
 */
final class VoiceBudget
{
    public const BULLETIN = 'bulletin';

    /** A show or talk show window (Shows), the podcast replay's announcements. */
    public const SHOW = 'show';

    /** A chat reply, a live moment, a wished segment. */
    public const CHAT = 'chat';

    /** The news talk, Sport und Wetter, a news segment. */
    public const NEWS = 'news';

    /** A rubric, the Programmvorschau, a teaser. */
    public const RUBRIC = 'rubric';

    /** A history or followed-profile segment, a Rückblick-Spitze. */
    public const SEGMENT = 'segment';

    /** A slide scene (and a filler). */
    public const SCENE = 'scene';

    /** The tiers, highest priority first. */
    public const TIERS = [self::BULLETIN, self::SHOW, self::CHAT, self::NEWS, self::RUBRIC, self::SEGMENT, self::SCENE];

    /** The last throughput measured over enough voicing: "<rtf>|<unix seconds>". */
    public const MEASURED = 'caster:budget:measured';

    /** @var array<int, list<array{tier: string, seconds: float, writes_at: int, written: bool}>> slot demand per minute */
    private array $slotDemand = [];

    /** @var array<int, array{rtf: float, source: string, voiced_seconds: float, busy_seconds: float}> */
    private array $throughput = [];

    /** @var array<string, list<Slot>> the clock's next two hours, per minute and programme config */
    private static array $upcoming = [];

    public static function enabled(): bool
    {
        return config('tv.commentary.budget.enabled') === true;
    }

    /** The rank of a tier: 0 for the bulletin, the scenes last. */
    public static function rank(string $tier): int
    {
        $rank = array_search($tier, self::TIERS, true);

        return $rank === false ? count(self::TIERS) - 1 : $rank;
    }

    /** The tier of a script. */
    public static function tierOf(string $kind, ?string $topic = null, ?string $scene = null): string
    {
        return match (true) {
            $kind === CasterScript::BULLETIN => self::BULLETIN,
            in_array($kind, [CasterScript::PODCAST_INTRO, CasterScript::PODCAST_OUTRO], true), $kind === CasterScript::RUBRIC && Shows::isShow($topic) => self::SHOW,
            in_array($kind, [CasterScript::CHAT, CasterScript::LIVE], true), $scene === ChatWishes::SCENE => self::CHAT,
            $kind === CasterScript::NEWS, $kind === CasterScript::RUBRIC && in_array($topic, [NewsTalk::TOPIC, SportWeather::TOPIC], true) => self::NEWS,
            in_array($kind, [CasterScript::RUBRIC, CasterScript::TEASER], true) => self::RUBRIC,
            in_array($kind, [CasterScript::HISTORY, CasterScript::FOLLOWED], true), $topic === WorldMemory::CALLBACK_TOPIC => self::SEGMENT,
            default => self::SCENE,
        };
    }

    /**
     * The rank of a `caster_scripts` row in SQL, the same as rank(tierOf()): the claim order.
     *
     * @return array{0: literal-string, 1: list<string>}
     */
    public static function rankSql(): array
    {
        $shows = array_keys(Shows::CATALOGUE);
        $marks = fn (array $values): string => implode(', ', array_fill(0, count($values), '?'));

        return [
            'case when kind = ? then 0'
            .' when kind in (?, ?) or (kind = ? and topic in ('.$marks($shows).')) then 1'
            .' when kind in (?, ?) or scene = ? then 2'
            .' when kind = ? or (kind = ? and topic in (?, ?)) then 3'
            .' when kind in (?, ?) then 4'
            .' when kind in (?, ?) or topic = ? then 5'
            .' else 6 end',
            [
                CasterScript::BULLETIN,
                CasterScript::PODCAST_INTRO, CasterScript::PODCAST_OUTRO, CasterScript::RUBRIC, ...$shows,
                CasterScript::CHAT, CasterScript::LIVE, ChatWishes::SCENE,
                CasterScript::NEWS, CasterScript::RUBRIC, NewsTalk::TOPIC, SportWeather::TOPIC,
                CasterScript::RUBRIC, CasterScript::TEASER,
                CasterScript::HISTORY, CasterScript::FOLLOWED, WorldMemory::CALLBACK_TOPIC,
            ],
        ];
    }

    /**
     * Estimated seconds voiced per wall second: measured over the last `measure_minutes`, else the last measured value
     * (kept `keep_hours`), else `default_rtf`.
     *
     * @return array{rtf: float, source: string, voiced_seconds: float, busy_seconds: float}
     */
    public function throughput(CarbonImmutable $now): array
    {
        $key = $now->getTimestamp();

        if (isset($this->throughput[$key])) {
            return $this->throughput[$key];
        }

        $settings = self::settings();
        $since = $now->utc()->subMinutes(max(1, (int) ($settings['measure_minutes'] ?? 180)));
        $rows = array_values(CasterScript::query()->where('status', CasterScript::VOICED)->where('updated_at', '>', $since)->whereNotNull('claimed_at')
            ->whereNotIn('kind', [CasterScript::REPLAY, CasterScript::PODCAST, CasterScript::FILLER])
            ->whereNotIn('facts_hash', ['replay', 'stock', StockFeeder::HOUR_TOPIC])
            ->get(['estimated_seconds', 'claimed_at', 'updated_at'])
            ->map(fn (CasterScript $script): array => [
                'seconds' => (float) $script->estimated_seconds,
                'from' => (int) $script->claimed_at?->getTimestamp(),
                'to' => (int) $script->updated_at?->getTimestamp(),
            ])->all());

        ['voiced' => $voiced, 'busy' => $busy] = self::measure($rows);
        $default = max(0.05, (float) ($settings['default_rtf'] ?? 0.8));

        if ($busy >= (float) ($settings['min_busy_seconds'] ?? 900) && $voiced > 0) {
            $rtf = self::clamp($voiced / $busy);
            Cache::put(self::MEASURED, sprintf('%.4F|%d', $rtf, $now->getTimestamp()), 3600 * max(1, (int) ($settings['keep_hours'] ?? 24)));
            $result = ['rtf' => $rtf, 'source' => 'measured', 'voiced_seconds' => round($voiced, 1), 'busy_seconds' => round($busy, 1)];
        } else {
            $kept = self::kept(Cache::get(self::MEASURED));
            $result = ['rtf' => $kept ?? $default, 'source' => $kept === null ? 'default' : 'kept', 'voiced_seconds' => round($voiced, 1), 'busy_seconds' => round($busy, 1)];
        }

        return $this->throughput[$key] = $result;
    }

    /**
     * The airtime voiced and the wall seconds the workstation was busy: every voicing from claim to upload, the union
     * of those stretches (one counted once however many overlap). A stretch that ends before it starts counts nothing.
     *
     * @param  list<array{seconds: float, from: int, to: int}>  $voicings
     * @return array{voiced: float, busy: float}
     */
    public static function measure(array $voicings): array
    {
        $voicings = array_values(array_filter($voicings, fn (array $voicing): bool => $voicing['to'] > $voicing['from']));
        usort($voicings, fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        [$voiced, $busy, $start, $end] = [0.0, 0.0, null, null];

        foreach ($voicings as $voicing) {
            $voiced += max(0.0, $voicing['seconds']);

            if ($end === null || $voicing['from'] > $end) {
                $busy += $end === null ? 0 : $end - $start;
                [$start, $end] = [$voicing['from'], $voicing['to']];
            } else {
                $end = max($end, $voicing['to']);
            }
        }

        return ['voiced' => $voiced, 'busy' => $busy + ($end === null ? 0 : $end - $start)];
    }

    /** A throughput read back from the cache: "<rtf>|<ts>" (Redis hands strings back), anything else is none. */
    public static function kept(mixed $value): ?float
    {
        $rtf = is_string($value) ? explode('|', $value)[0] : $value;

        return is_numeric($rtf) && (float) $rtf > 0 ? self::clamp((float) $rtf) : null;
    }

    /** Seconds of fresh talk the workstation can voice in an hour, the stock's share left out. */
    public function hourBudget(CarbonImmutable $now): float
    {
        $share = min(0.95, max(0.0, (float) (self::settings()['stock_share'] ?? 0.3)));

        return $this->throughput($now)['rtf'] * 3600 * (1 - $share);
    }

    /** Estimated seconds of fresh talk written in the last `$minutes` and not expired (stock, fillers and stand-ins left out). */
    public function writtenSeconds(CarbonImmutable $now, int $minutes): float
    {
        return (float) self::fresh(CasterScript::query())->where('status', '!=', CasterScript::EXPIRED)
            ->where('created_at', '>', $now->utc()->subMinutes($minutes))->sum('estimated_seconds');
    }

    /** Estimated seconds of fresh talk voiced in the last `window_minutes`. */
    public function voicedSeconds(CarbonImmutable $now): float
    {
        return (float) self::fresh(CasterScript::query())->where('status', CasterScript::VOICED)
            ->where('updated_at', '>', $now->utc()->subMinutes(max(1, (int) (self::settings()['window_minutes'] ?? 60))))->sum('estimated_seconds');
    }

    /**
     * Estimated seconds the workstation has to voice before a new script of `$tier`: every queued script of that tier
     * or above, and everything claimed (being voiced).
     */
    public function backlog(string $tier, CarbonImmutable $now): float
    {
        [$sql, $bindings] = self::rankSql();

        return (float) self::fresh(CasterScript::query())->where('expires_at', '>', $now->utc())
            ->where(fn (Builder $query) => $query->where('status', CasterScript::CLAIMED)
                ->orWhere(fn (Builder $query) => $query->where('status', CasterScript::QUEUED)->whereRaw("({$sql}) <= ?", [...$bindings, self::rank($tier)])))
            ->sum('estimated_seconds');
    }

    /**
     * What the tiers above `$tier` will write within `$minutes` (default `reserve_minutes`): the slots of the clock whose
     * writing starts by then and that are not written yet, and the chat cadence.
     */
    public function reserve(string $tier, CarbonImmutable $now, ?float $minutes = null): float
    {
        $minutes = max(0.0, $minutes ?? (float) (self::settings()['reserve_minutes'] ?? 30));
        $rank = self::rank($tier);
        $reserve = 0.0;

        foreach ($this->slotDemand($now) as $demand) {
            if (self::rank($demand['tier']) < $rank && ! $demand['written'] && $demand['writes_at'] <= $now->getTimestamp() + 60 * $minutes) {
                $reserve += $demand['seconds'];
            }
        }

        return $reserve + (self::rank(self::CHAT) < $rank ? $this->chatSeconds() * $minutes / 60 : 0.0);
    }

    /** Whether a queued bulletin might not be voiced `ready_seconds` before its slot: then nothing else is written. */
    public function bulletinAtRisk(CarbonImmutable $now): bool
    {
        $bulletins = CasterScript::query()->where('kind', CasterScript::BULLETIN)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])
            ->whereNotNull('airs_at')->where('expires_at', '>', $now->utc())->orderBy('airs_at')->get(['estimated_seconds', 'airs_at']);
        $rtf = $this->throughput($now)['rtf'];
        $ready = (int) config('tv.commentary.programme.bulletin.ready_seconds', 60);
        $ahead = 0.0;

        foreach ($bulletins as $bulletin) {
            $ahead += (float) $bulletin->estimated_seconds;

            if ($now->getTimestamp() + $ahead / $rtf + $this->margin() > (int) $bulletin->airs_at?->getTimestamp() - $ready) {
                return true;
            }
        }

        return false;
    }

    /** Whether the slide scenes are capped: the tiers above them ask at least `tight_share` of the hour's budget. */
    public function tight(CarbonImmutable $now): bool
    {
        $demand = $this->chatSeconds();

        foreach ($this->slotDemand($now) as $slot) {
            $demand += $slot['writes_at'] <= $now->getTimestamp() + 3600 ? $slot['seconds'] : 0.0;
        }

        return $demand >= (float) (self::settings()['scene_cap']['tight_share'] ?? 0.6) * $this->hourBudget($now);
    }

    /**
     * Whether a script of `$tier` may be written now: `$seconds` its estimated airtime (unknown: the tier's
     * `expected_seconds`), `$deadline` when it expires (unknown: no expiry check).
     */
    public function opens(string $tier, CarbonImmutable $now, ?float $seconds = null, ?CarbonImmutable $deadline = null): bool
    {
        if (! self::enabled() || $tier === self::BULLETIN) {
            return true;
        }

        if ($this->bulletinAtRisk($now)) {
            return false;
        }

        $settings = self::settings();
        $seconds ??= (float) ($settings['expected_seconds'][$tier] ?? 60);
        $budget = $this->hourBudget($now);
        $window = max(1, (int) ($settings['window_minutes'] ?? 60));
        $reserveMinutes = min($window, max(0, (int) ($settings['reserve_minutes'] ?? 30)));

        if ($this->writtenSeconds($now, $window) + $seconds > $budget
            || $this->writtenSeconds($now, $window - $reserveMinutes) + $seconds + $this->reserve($tier, $now) > $budget) {
            return false;
        }

        if ($tier === self::SCENE && (($settings['scene_cap']['always'] ?? false) === true || $this->tight($now))
            && $this->scenesThisHour($now) >= (int) ($settings['scene_cap']['per_hour'] ?? 6)) {
            return false;
        }

        if ($deadline === null) {
            return true;
        }

        // Voiced behind what waits of its tier and above, and behind what the tiers above write before it expires.
        $ahead = $this->backlog($tier, $now) + $this->reserve($tier, $now, max(0, $deadline->getTimestamp() - $now->getTimestamp()) / 60);

        return $now->getTimestamp() + ($ahead + $seconds) / $this->throughput($now)['rtf'] + $this->margin() <= $deadline->getTimestamp();
    }

    /**
     * Whether ScriptQueue::enqueue() queues this script: a stock script, a filler and a bulletin always, every other
     * one when its tier opens() for its estimated airtime and expiry.
     */
    public function admits(string $kind, ?string $topic, ?string $scene, float $seconds, CarbonImmutable $deadline, CarbonImmutable $now): bool
    {
        return $scene === StockScripts::SCENE || $kind === CasterScript::FILLER || $this->opens(self::tierOf($kind, $topic, $scene), $now, $seconds, $deadline);
    }

    /**
     * The stock's turn on the GPU: the fresh talk voiced in the last hour reached the hour's budget. The claim then
     * hands out only what the clock places and the replies; with nothing of those, the workstation renders stock.
     */
    public function stockTurn(CarbonImmutable $now): bool
    {
        return self::enabled() && $this->voicedSeconds($now) >= $this->hourBudget($now);
    }

    /**
     * The numbers in one place (the claim's answer, `caster:script`).
     *
     * @return array{rtf: float, source: string, voiced_seconds: float, busy_seconds: float, hour_budget: float, written_hour: float, voiced_hour: float, stock_turn: bool, tight: bool}
     */
    public function snapshot(CarbonImmutable $now): array
    {
        return $this->throughput($now) + [
            'hour_budget' => round($this->hourBudget($now), 1),
            'written_hour' => round($this->writtenSeconds($now, max(1, (int) (self::settings()['window_minutes'] ?? 60))), 1),
            'voiced_hour' => round($this->voicedSeconds($now), 1),
            'stock_turn' => $this->stockTurn($now),
            'tight' => $this->tight($now),
        ];
    }

    /**
     * Fresh talk only: no stock script, no filler, no stand-in of a replay, a podcast, a stock block or the autopilot.
     *
     * @param  Builder<CasterScript>  $query
     * @return Builder<CasterScript>
     */
    private static function fresh(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => ScriptQueue::notStock($query))
            ->whereNotIn('kind', [CasterScript::FILLER, CasterScript::REPLAY, CasterScript::PODCAST])
            ->whereNotIn('facts_hash', ['replay', 'stock', StockFeeder::HOUR_TOPIC]);
    }

    /** Whole slide scenes (a script in parts once) written in the last hour and not expired. */
    private function scenesThisHour(CarbonImmutable $now): int
    {
        return CasterScript::query()->whole()->where('kind', CasterScript::SCENE)->where('status', '!=', CasterScript::EXPIRED)
            ->where(fn (Builder $query) => ScriptQueue::notStock($query))
            ->where(fn (Builder $query) => $query->whereNull('topic')->orWhere('topic', '!=', WorldMemory::CALLBACK_TOPIC))
            ->where('created_at', '>', $now->utc()->subHour())->count();
    }

    /** Estimated chat seconds an hour (the cadence), none while the chat reactions are off. */
    private function chatSeconds(): float
    {
        $settings = self::settings();

        return ChatReactions::enabled() ? max(0, (int) ($settings['chat_per_hour'] ?? 6)) * (float) ($settings['expected_seconds'][self::CHAT] ?? 60) : 0.0;
    }

    /**
     * The clock's slots of the next two hours as demand: a bulletin, the news talk and Sport und Wetter after a :00
     * bulletin, a rubric or show, each with its tier, its reckoned airtime, when its writing starts and whether it is
     * written already.
     *
     * @return list<array{tier: string, seconds: float, writes_at: int, written: bool}>
     */
    private function slotDemand(CarbonImmutable $now): array
    {
        $minute = intdiv($now->getTimestamp(), 60);

        if (isset($this->slotDemand[$minute])) {
            return $this->slotDemand[$minute];
        }

        $clock = BroadcastClock::fromConfig();
        $expected = (array) (self::settings()['expected_seconds'] ?? []);
        $news = ScriptQueue::aiEnabled() && config('tv.commentary.news.enabled') === true;
        $bulletinAhead = 60 * (float) config('tv.commentary.programme.bulletin.write_minutes_ahead', 7);
        $rubricAhead = 60 * (float) config('tv.commentary.programme.rubrics.write_minutes_ahead', 8);
        $shows = app(Shows::class);
        $demand = [];

        foreach ($clock->enabled() ? self::upcoming($clock, $now) : [] as $slot) {
            if ($slot['key'] <= $now->getTimestamp()) {
                continue;
            }

            if ($news && BroadcastClock::isBulletin($slot['type'])) {
                $demand[] = ['tier' => self::BULLETIN, 'seconds' => (float) ($expected[self::BULLETIN] ?? 120), 'writes_at' => (int) ($slot['key'] - $bulletinAhead),
                    'written' => CasterScript::query()->where('kind', CasterScript::BULLETIN)->where('facts_hash', 'bulletin:'.$slot['key'])->exists()];

                if ($slot['type'] === BroadcastClock::NEWS) {
                    foreach (['news_talk:' => NewsTalk::enabled(), 'sport_weather:' => SportWeather::enabled()] as $prefix => $on) {
                        if ($on) {
                            $demand[] = ['tier' => self::NEWS, 'seconds' => (float) ($expected[self::NEWS] ?? 150), 'writes_at' => (int) ($slot['key'] - $bulletinAhead),
                                'written' => CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('facts_hash', $prefix.$slot['key'])->exists()];
                        }
                    }
                }
            }

            if ($slot['type'] === BroadcastClock::RUBRIC && ScriptQueue::aiEnabled() && Rubrics::enabled()) {
                $show = $shows->scheduledFor($slot);
                $format = $show === null ? [] : Shows::settings($show);
                $minutes = $show === null ? null : Shows::slotMinutes($show);
                $seconds = match (true) {
                    $minutes !== null => 60.0 * $minutes - (float) ($format['slot_margin_seconds'] ?? 120),
                    $show !== null => (float) ($format['seconds']['max'] ?? $expected[self::RUBRIC] ?? 150),
                    default => (float) ($expected[self::RUBRIC] ?? 150),
                };
                $ahead = $show === null ? $rubricAhead : max($rubricAhead, 60 * (float) ($format['write_minutes_ahead'] ?? 0));
                $demand[] = ['tier' => $show === null ? self::RUBRIC : self::SHOW, 'seconds' => $seconds, 'writes_at' => (int) ($slot['key'] - $ahead),
                    'written' => Rubrics::written($slot['key'])];
            }
        }

        $this->slotDemand = [$minute => $demand];

        return $demand;
    }

    /**
     * The clock's slots of the next two hours, the same for every call within a minute and programme config (a fill run
     * asks for them a few times per script).
     *
     * @return list<Slot>
     */
    private static function upcoming(BroadcastClock $clock, CarbonImmutable $now): array
    {
        $key = intdiv($now->getTimestamp(), 60).':'.md5((string) json_encode(config('tv.commentary.programme')));

        if (! isset(self::$upcoming[$key])) {
            self::$upcoming = [$key => $clock->upcoming($now->setTime((int) $now->format('H'), (int) $now->format('i')), 121)];
        }

        return self::$upcoming[$key];
    }

    private function margin(): float
    {
        return max(0.0, (float) (self::settings()['margin_seconds'] ?? 60));
    }

    private static function clamp(float $rtf): float
    {
        return min(20.0, max(0.05, $rtf));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function settings(): array
    {
        return (array) config('tv.commentary.budget', []);
    }
}
