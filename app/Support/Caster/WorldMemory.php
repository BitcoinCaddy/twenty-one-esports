<?php

namespace App\Support\Caster;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\CasterFollowedNote;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Models\ChatterLine;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\PongMatch;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Matches\MempoolStrip;
use App\Support\Moderation\SiteModeration;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The rolling world memory of TWENTY ONE TV (user 2026-10-12: "Wir sind
 * immer noch zu monoton und nicht reaktiv genug auf echte Geschehnisse"):
 * what really happened in the last `tv.commentary.world.hours`, read from
 * the rows the league and the caster already keep, one line per event:
 *
 * - headlines the news segment aired (CasterNews: used, not skipped), with
 *   their outlet;
 * - Nostr notes a followed profile posted and the caster took up
 *   (CasterFollowedNote: reacted, not skipped);
 * - trusted chat lines (ChatterLine, ChatMemory's rows; a chatter muted
 *   since drops out), zap thank-yous and granted chat wishes (CasterScript);
 * - finished matches (chess with its winner and how it ended, the other
 *   games as the mempool strip shows them, voided ones left out),
 *   tournaments that started or finished (with their champion when the
 *   stream's snapshot names one), new players and clans;
 * - the games on now.
 *
 * Players by their league display name only, made speakable
 * (SceneFacts::spoken()); a row without a speakable name is left out.
 * Nothing is derived or guessed: a source that fails leaves its lines out.
 *
 * items() is cached `cache_seconds` (ints and strings only: prod's Redis
 * store refuses objects), digest() renders it newest first with the time in
 * words ("vor zwei Stunden (heute Nachmittag)") within `tokens`
 * (characters / 4). The callback segment ("Rückblick-Spitze") takes one or
 * two items via pickForCallback() and marks them (markCalledBack()), so no
 * item is called back twice within `callback_repeat_hours`.
 *
 * @phpstan-type WorldItem array{key: string, at: int, kind: string, line: string}
 */
class WorldMemory
{
    public const NEWS = 'news';

    public const NOTE = 'note';

    public const CHAT = 'chat';

    public const ZAP = 'zap';

    public const WISH = 'wish';

    public const RESULT = 'result';

    public const TOURNAMENT = 'tournament';

    public const PLAYER = 'player';

    public const CLAN = 'clan';

    public const LIVE = 'live';

    /** The CasterScript topic of a callback segment (ScriptQueue::fillSegments()). */
    public const CALLBACK_TOPIC = 'world_callback';

    /** An item this young is no callback yet ("vorhin" needs a little distance). */
    public const CALLBACK_MIN_AGE_MINUTES = 15;

    private const CACHE_KEY = 'caster:world:items';

    private const CALLED_BACK = 'caster:world:called_back';

    /** Rows read per source at most. */
    private const PER_SOURCE = 30;

    /** Games on now named at most. */
    private const LIVE_GAMES = 4;

    /** How a granted wish is told, by the segment it became. */
    private const WISHES = ['wish:joke' => 'ein paar Witze', 'wish:canon' => 'Geschichten aus dem Podcast'];

    public function __construct(private SceneFacts $facts) {}

    /**
     * The items of the window, newest first (cached; see the class comment).
     *
     * @return list<WorldItem>
     */
    public function items(CarbonImmutable $now): array
    {
        try {
            $stored = Cache::get(self::CACHE_KEY);

            if (is_array($stored) && self::valid($stored)) {
                return $stored;
            }

            $items = $this->build($now);
            Cache::put(self::CACHE_KEY, $items, max(1, (int) config('tv.commentary.world.cache_seconds', 120)));

            return $items;
        } catch (Throwable) {
            return $this->build($now);
        }
    }

    /**
     * The digest as one data block: newest first, one line each, within the token budget; null when nothing
     * happened. `$except`: kinds left out (a chat reply has the chat in blocks of its own, ChatMemory).
     *
     * @param  list<string>  $except
     */
    public function digest(CarbonImmutable $now, ?int $tokens = null, array $except = []): ?string
    {
        $budget = max(1, $tokens ?? (int) config('tv.commentary.world.tokens', 2_000)) * 4;
        $kept = [];
        $used = 0;

        foreach ($this->items($now) as $item) {
            if (in_array($item['kind'], $except, true)) {
                continue;
            }

            $line = '- '.self::relative($item['at'], $now).': '.$item['line'];
            $used += mb_strlen($line) + 1;

            if ($used > $budget) {
                break;
            }

            $kept[] = $line;
        }

        return $kept === [] ? null : implode("\n", $kept);
    }

    /**
     * The digest split for Anthropic's prompt cache (a prefix match: one changed byte re-bills everything behind it).
     * `frozen` is the digest as of the start of the current `$minutes` epoch: the items older than that start, the
     * games on now left out, their time in words counted from that start. It is snapshotted once per epoch (Cache),
     * so every prompt of the epoch carries it byte for byte, also when a late row turns up or the window slides.
     * `fresh` is everything the snapshot does not hold (the games on now, the items since the start, a late row),
     * newest first with the time counted from `$now`, within what is left of the budget (a quarter at least).
     * `since` is the epoch's start (a timestamp).
     *
     * @param  list<string>  $except
     * @return array{frozen: string|null, fresh: string|null, since: int}
     */
    public function epochDigest(CarbonImmutable $now, int $minutes, array $except = [], ?int $tokens = null): array
    {
        $budget = max(1, $tokens ?? (int) config('tv.commentary.world.tokens', 2_000)) * 4;
        $length = max(1, $minutes) * 60;
        $since = intdiv($now->getTimestamp(), $length) * $length;
        sort($except);

        $snapshot = Cache::remember('caster:world:epoch:'.$length.':'.$since.':'.implode(',', $except), $length + 120, function () use ($now, $since, $except, $budget): array {
            $start = CarbonImmutable::createFromTimestamp($since);
            $lines = [];
            $keys = [];
            $used = 0;
            $floor = 0;

            foreach ($this->items($now) as $item) {
                if ($item['kind'] === self::LIVE || $item['at'] >= $since || in_array($item['kind'], $except, true)) {
                    continue;
                }

                $line = '- '.self::relative($item['at'], $start).': '.$item['line'];
                $used += mb_strlen($line) + 1;

                if ($used > $budget) {
                    // Older rows than this one fell off the budget: they are no late arrivals for the fresh part.
                    $floor = $item['at'] + 1;

                    break;
                }

                $lines[] = $line;
                $keys[] = $item['key'];
            }

            return ['lines' => $lines, 'keys' => $keys, 'used' => $used, 'floor' => $floor];
        });

        $held = array_flip((array) ($snapshot['keys'] ?? []));
        $floor = (int) ($snapshot['floor'] ?? 0);
        $left = max(intdiv($budget, 4), $budget - (int) ($snapshot['used'] ?? 0));
        $fresh = [];
        $used = 0;

        foreach ($this->items($now) as $item) {
            if (isset($held[$item['key']]) || $item['at'] < $floor || in_array($item['kind'], $except, true)) {
                continue;
            }

            $line = '- '.self::relative($item['at'], $now).': '.$item['line'];
            $used += mb_strlen($line) + 1;

            if ($used > $left) {
                break;
            }

            $fresh[] = $line;
        }

        $frozen = array_values(array_map('strval', (array) ($snapshot['lines'] ?? [])));

        return ['frozen' => $frozen === [] ? null : implode("\n", $frozen), 'fresh' => $fresh === [] ? null : implode("\n", $fresh), 'since' => $since];
    }

    /**
     * Up to `$count` items for a callback: at least CALLBACK_MIN_AGE_MINUTES
     * old, no game on now, none called back within `callback_repeat_hours`;
     * drawn at random.
     *
     * @return list<WorldItem>
     */
    public function pickForCallback(CarbonImmutable $now, int $count): array
    {
        $used = $this->calledBack($now);
        $latest = $now->getTimestamp() - self::CALLBACK_MIN_AGE_MINUTES * 60;
        $pool = array_values(array_filter($this->items($now), fn (array $item): bool => $item['kind'] !== self::LIVE
            && $item['at'] <= $latest && ! isset($used[$item['key']])));

        shuffle($pool);

        return array_slice($pool, 0, max(0, $count));
    }

    /**
     * Remembers items as called back, so pickForCallback() leaves them out for `callback_repeat_hours`.
     *
     * @param  list<string>  $keys
     */
    public function markCalledBack(array $keys, CarbonImmutable $now): void
    {
        $used = $this->calledBack($now);

        foreach ($keys as $key) {
            $used[$key] = $now->getTimestamp();
        }

        Cache::put(self::CALLED_BACK, $used, $this->repeatSeconds());
    }

    /** Forgets the cached items (a test, or a caller that just changed a source). */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * When something happened, in words, from `$now`: "gerade eben", "vor
     * zwanzig Minuten", "vor einer Stunde", "vor drei Stunden", from an hour
     * on with the part of the day in Berlin ("heute Nachmittag", "gestern
     * Abend"; none further back, a window wider than a day).
     */
    public static function relative(int $at, CarbonImmutable $now): string
    {
        $minutes = max(0, intdiv($now->getTimestamp() - $at, 60));

        if ($minutes < 3) {
            return 'gerade eben';
        }

        if ($minutes < 58) {
            return 'vor '.ReactionLines::spokenNumber(max(5, (int) (round($minutes / 5) * 5))).' Minuten';
        }

        $hours = (int) round($minutes / 60);
        $then = CarbonImmutable::createFromTimestamp($at)->setTimezone('Europe/Berlin');
        $day = match ($then->toDateString()) {
            $now->setTimezone('Europe/Berlin')->toDateString() => 'heute',
            $now->setTimezone('Europe/Berlin')->subDay()->toDateString() => 'gestern',
            default => null,
        };

        return ($hours <= 1 ? 'vor einer Stunde' : 'vor '.ReactionLines::spokenNumber($hours).' Stunden')
            .($day === null ? '' : ' ('.$day.' '.self::dayPart((int) $then->format('G')).')');
    }

    /** The part of the day an hour (0 to 23, Berlin) belongs to. */
    public static function dayPart(int $hour): string
    {
        return match (true) {
            $hour < 5 || $hour >= 23 => 'Nacht',
            $hour < 10 => 'Morgen',
            $hour < 12 => 'Vormittag',
            $hour < 14 => 'Mittag',
            $hour < 18 => 'Nachmittag',
            default => 'Abend',
        };
    }

    /**
     * Every source's items, newest first.
     *
     * @return list<WorldItem>
     */
    public function build(CarbonImmutable $now): array
    {
        $since = $now->subHours(max(1, (int) config('tv.commentary.world.hours', 24)));
        $items = [];

        foreach ([
            fn (): array => $this->news($since),
            fn (): array => $this->notes($since),
            fn (): array => $this->chat($since),
            fn (): array => $this->zaps($since),
            fn (): array => $this->wishes($since),
            fn (): array => $this->chessResults($since),
            fn (): array => $this->otherResults($since),
            fn (): array => $this->tournaments($since),
            fn (): array => $this->players($since),
            fn (): array => $this->clans($since),
            fn (): array => $this->liveGames($now),
        ] as $source) {
            $items = [...$items, ...self::safely($source)];
        }

        usort($items, fn (array $a, array $b): int => [$b['at'], $a['key']] <=> [$a['at'], $b['key']]);

        return $items;
    }

    /** @return list<WorldItem> */
    private function news(CarbonImmutable $since): array
    {
        return array_values(CasterNews::query()->whereNotNull('used_at')->whereNull('skipped')->where('used_at', '>=', $since)
            ->orderByDesc('used_at')->limit(self::PER_SOURCE)->get()
            ->map(fn (CasterNews $news): array => self::item('news:'.$news->id, (int) $news->used_at?->getTimestamp(), self::NEWS,
                'Schlagzeile von '.$news->source.' lief bei dir: „'.self::oneLine($news->title, 200).'“'))->all());
    }

    /** @return list<WorldItem> */
    private function notes(CarbonImmutable $since): array
    {
        return array_values(CasterFollowedNote::query()->whereNotNull('reacted_at')->whereNull('skipped')->where('reacted_at', '>=', $since)
            ->orderByDesc('reacted_at')->limit(self::PER_SOURCE)->get()
            ->map(function (CasterFollowedNote $note): array {
                $name = FollowedNotes::displayName($note->pubkey);
                $who = FollowedScripts::isSelf($name) ? 'Dein eigener Nostr-Post' : 'Nostr-Post von '.$name;

                return self::item('note:'.$note->id, (int) $note->reacted_at?->getTimestamp(), self::NOTE, $who.' lief bei dir: „'.ChatMemory::firstWords($note->content, 30).'“');
            })->all());
    }

    /** @return list<WorldItem> */
    private function chat(CarbonImmutable $since): array
    {
        $items = [];

        foreach (ChatterLine::query()->where('said_at', '>=', $since->getTimestamp())->orderByDesc('said_at')->orderByDesc('id')->limit(self::PER_SOURCE * 2)->get() as $line) {
            if (! SiteModeration::isHidden($line->pubkey)) {
                $items[] = self::item('chat:'.$line->id, $line->said_at, self::CHAT, ($line->name ?? 'Jemand').' schrieb im Chat: „'.self::oneLine($line->message, 200).'“');
            }
        }

        return $items;
    }

    /** @return list<WorldItem> */
    private function zaps(CarbonImmutable $since): array
    {
        return array_values(CasterScript::query()->where('topic', ChatReactions::ZAP_TOPIC)->where('kind', CasterScript::LIVE)->where('status', CasterScript::VOICED)
            ->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(self::PER_SOURCE)->get()
            ->map(fn (CasterScript $script): array => self::item('zap:'.$script->id, (int) $script->created_at?->getTimestamp(), self::ZAP,
                'Zap im Stream, du hast dich bedankt: „'.ChatMemory::firstWords($script->text, 20).'“'))->all());
    }

    /** @return list<WorldItem> */
    private function wishes(CarbonImmutable $since): array
    {
        return array_values(CasterScript::query()->whole()->where('scene', ChatWishes::SCENE)->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->limit(self::PER_SOURCE)->get()
            ->map(fn (CasterScript $script): array => self::item('wish:'.$script->id, (int) $script->created_at?->getTimestamp(), self::WISH,
                'Ein Zuschauer hat sich im Chat '.(self::WISHES[$script->facts_hash] ?? match ($script->kind) {
                    CasterScript::HISTORY => 'eine Folge Bauernkrieg',
                    CasterScript::NEWS => 'Nachrichten',
                    default => 'was zum Spielgeschehen',
                }).' gewünscht und bekommen'))->all());
    }

    /** @return list<WorldItem> */
    private function chessResults(CarbonImmutable $since): array
    {
        $items = [];

        foreach (ChessGame::query()->with(['white', 'black'])->where('status', ChessGameStatus::Finished)->whereNotNull('white_id')->whereNotNull('black_id')
            ->where('ended_at', '>=', $since)->orderByDesc('ended_at')->limit(self::PER_SOURCE)->get() as $game) {
            $reason = $game->end_reason instanceof ChessEndReason ? $game->end_reason->value : null;
            $white = SceneFacts::spoken($game->white->displayName());
            $black = SceneFacts::spoken($game->black->displayName());

            if ($white === null || $black === null || in_array($reason, ['aborted', 'voided'], true)) {
                continue;
            }

            $what = 'Schach'.(isset(SceneFacts::MODES[$game->mode]) ? ' ('.SceneFacts::MODES[$game->mode].')' : '');
            $how = isset(SceneFacts::END_REASONS[(string) $reason]) ? ' durch '.SceneFacts::END_REASONS[(string) $reason] : '';
            $line = match ($game->result) {
                '1-0' => "{$what}: {$white} gewinnt gegen {$black}{$how}",
                '0-1' => "{$what}: {$black} gewinnt gegen {$white}{$how}",
                default => "{$what}: {$white} und {$black} trennen sich remis",
            };
            $items[] = self::item('chess:'.$game->id, (int) $game->ended_at?->getTimestamp(), self::RESULT, $line);
        }

        return $items;
    }

    /**
     * The finished matches of the other games as the mempool strip shows them,
     * each with its end time from its own row; voided series left out.
     *
     * @return list<WorldItem>
     */
    private function otherResults(CarbonImmutable $since): array
    {
        $cubes = [];

        foreach (MempoolStrip::build(history: self::PER_SOURCE)['finished'] as $cube) {
            [$kind, $id] = array_pad(explode('-', (string) ($cube['key'] ?? ''), 2), 2, '');

            if ($kind !== 'chess' && ctype_digit($id)) {
                $cubes[$kind][(int) $id] = $cube;
            }
        }

        $ends = [
            'series' => fn (array $ids) => SeriesMatch::query()->whereIn('id', $ids)->where(fn ($query) => $query->whereNull('resolution')->orWhere('resolution', '!=', SeriesResolution::Void))->pluck('finished_at', 'id'),
            'board' => fn (array $ids) => BoardGame::query()->whereIn('id', $ids)->pluck('ended_at', 'id'),
            'hyper' => fn (array $ids) => HyperMatch::query()->whereIn('id', $ids)->pluck('ended_at', 'id'),
            'pong' => fn (array $ids) => PongMatch::query()->whereIn('id', $ids)->pluck('ended_at', 'id'),
        ];
        $items = [];

        foreach ($cubes as $kind => $byId) {
            if (! isset($ends[$kind])) {
                continue;
            }

            foreach ($ends[$kind](array_keys($byId)) as $id => $endedAt) {
                $at = $endedAt === null ? 0 : CarbonImmutable::parse($endedAt)->getTimestamp();
                $line = $at >= $since->getTimestamp() ? self::cubeLine($byId[(int) $id]) : null;

                if ($line !== null) {
                    $items[] = self::item($kind.':'.$id, $at, self::RESULT, $line);
                }
            }
        }

        return $items;
    }

    /**
     * A finished mempool cube as one line: the game, the winner (a clan by its name) and whom they beat, the score.
     *
     * @param  array<string, mixed>  $cube
     */
    private static function cubeLine(array $cube): ?string
    {
        $game = SceneFacts::spoken(GameNames::game((string) ($cube['slug'] ?? ''))) ?? 'Ein Spiel';
        $names = [];

        foreach ((array) ($cube['sides'] ?? []) as $side) {
            if (! is_array($side)) {
                continue;
            }

            $clan = $side['clan'] ?? null;
            $user = $side['user'] ?? null;
            $name = SceneFacts::spoken($clan instanceof Clan ? (string) $clan->name : ($user instanceof User ? $user->displayName() : (string) ($side['name'] ?? '')));

            if ($name !== null) {
                $names[(bool) ($side['won'] ?? false) ? 'won' : 'lost'][] = $name;
            }
        }

        $winner = $names['won'][0] ?? null;

        if ($winner === null) {
            return null;
        }

        $score = preg_match('/^\s*(\d+)\s*[–:-]\s*(\d+)\s*$/u', (string) ($cube['score'] ?? ''), $match) === 1 ? " ({$match[1]} zu {$match[2]})" : '';

        return isset($names['lost'][0]) ? "{$game}: {$winner} gewinnt gegen {$names['lost'][0]}{$score}" : "{$game}: {$winner} gewinnt{$score}";
    }

    /** @return list<WorldItem> */
    private function tournaments(CarbonImmutable $since): array
    {
        $items = [];
        $champions = [];

        foreach (app(TournamentLiveSlides::class)->cachedSnapshots() ?? [] as $snapshot) {
            $champion = is_array($snapshot['champion'] ?? null) ? SceneFacts::spoken((string) ($snapshot['champion']['name'] ?? '')) : null;
            if ($champion !== null && is_int($snapshot['id'] ?? null)) {
                $champions[$snapshot['id']] = $champion;
            }
        }

        foreach (Tournament::query()->exceptLeagueWeeks()->whereIn('status', [TournamentStatus::Running, TournamentStatus::Finished])
            ->where('starts_at', '>=', $since)->where('starts_at', '<=', now())->orderByDesc('starts_at')->limit(self::PER_SOURCE)->get() as $tournament) {
            $name = SceneFacts::spoken($tournament->name);
            if ($name !== null) {
                $items[] = self::item('tournament-start:'.$tournament->id, $tournament->starts_at->getTimestamp(), self::TOURNAMENT, 'Das Turnier „'.$name.'“ hat begonnen');
            }
        }

        foreach (Tournament::query()->exceptLeagueWeeks()->where('status', TournamentStatus::Finished)->where('updated_at', '>=', $since)
            ->orderByDesc('updated_at')->limit(self::PER_SOURCE)->get() as $tournament) {
            $name = SceneFacts::spoken($tournament->name);
            $finishedAt = TournamentLiveSlides::finishedAt($tournament);

            if ($name !== null && $finishedAt !== null && $finishedAt->getTimestamp() >= $since->getTimestamp()) {
                $items[] = self::item('tournament-end:'.$tournament->id, $finishedAt->getTimestamp(), self::TOURNAMENT,
                    'Das Turnier „'.$name.'“ ist zu Ende'.(isset($champions[$tournament->id]) ? ', gewonnen hat '.$champions[$tournament->id] : ''));
            }
        }

        return $items;
    }

    /** @return list<WorldItem> */
    private function players(CarbonImmutable $since): array
    {
        $items = [];

        foreach (User::query()->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(self::PER_SOURCE)->get() as $user) {
            $name = SceneFacts::spoken($user->displayName());

            if ($name !== null && ! SiteModeration::isHidden($user->pubkey)) {
                $items[] = self::item('player:'.$user->id, (int) $user->created_at?->getTimestamp(), self::PLAYER, 'Neu in der Liga: '.$name);
            }
        }

        return $items;
    }

    /** @return list<WorldItem> */
    private function clans(CarbonImmutable $since): array
    {
        $items = [];

        foreach (Clan::query()->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(self::PER_SOURCE)->get() as $clan) {
            $name = SceneFacts::spoken((string) $clan->name);

            if ($name !== null) {
                $items[] = self::item('clan:'.$clan->id, (int) $clan->created_at?->getTimestamp(), self::CLAN, 'Neuer Clan gegründet: '.$name);
            }
        }

        return $items;
    }

    /**
     * The games on now, as the live-match facts name them.
     *
     * @return list<WorldItem>
     */
    private function liveGames(CarbonImmutable $now): array
    {
        $items = [];

        foreach (array_slice((array) ($this->facts->for(ScenePhase::LIVE_MATCH)['live_games'] ?? []), 0, self::LIVE_GAMES) as $game) {
            if (! is_array($game)) {
                continue;
            }

            $a = (string) ($game['white'] ?? $game['a'] ?? '');
            $b = (string) ($game['black'] ?? $game['b'] ?? '');

            if ($a !== '' && $b !== '') {
                $what = (string) ($game['game'] ?? 'Ein Spiel').(isset($game['mode']) ? ' ('.$game['mode'].')' : '');
                $items[] = self::item('live:'.sha1($what.$a.$b), $now->getTimestamp(), self::LIVE, "Läuft gerade: {$what}, {$a} gegen {$b}".(isset($game['move']) ? ', Zug '.$game['move'] : ''));
            }
        }

        return $items;
    }

    /** @return WorldItem */
    private static function item(string $key, int $at, string $kind, string $line): array
    {
        return ['key' => $key, 'at' => $at, 'kind' => $kind, 'line' => $line];
    }

    private static function oneLine(string $text, int $chars): string
    {
        $text = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $text));

        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars)).' …' : $text;
    }

    /**
     * @param  Closure(): list<WorldItem>  $source
     * @return list<WorldItem>
     */
    private static function safely(Closure $source): array
    {
        try {
            return $source();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<array-key, mixed>  $stored
     *
     * @phpstan-assert-if-true list<WorldItem> $stored
     */
    private static function valid(array $stored): bool
    {
        foreach ($stored as $item) {
            if (! is_array($item) || ! is_string($item['key'] ?? null) || ! is_int($item['at'] ?? null) || ! is_string($item['kind'] ?? null) || ! is_string($item['line'] ?? null)) {
                return false;
            }
        }

        return array_is_list($stored);
    }

    /**
     * The items called back within `callback_repeat_hours`: key => when.
     *
     * @return array<string, int>
     */
    private function calledBack(CarbonImmutable $now): array
    {
        $stored = Cache::get(self::CALLED_BACK);
        $cutoff = $now->getTimestamp() - $this->repeatSeconds();

        return is_array($stored) ? array_filter(array_filter($stored, 'is_int'), fn (int $at, int|string $key): bool => is_string($key) && $at > $cutoff, ARRAY_FILTER_USE_BOTH) : [];
    }

    private function repeatSeconds(): int
    {
        return max(1, (int) config('tv.commentary.world.callback_repeat_hours', 6)) * 3_600;
    }
}
