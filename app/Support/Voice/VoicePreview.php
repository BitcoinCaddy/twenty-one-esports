<?php

namespace App\Support\Voice;

use App\Enums\ChessGameStatus;
use App\Enums\PongMatchStatus;
use App\Enums\TournamentStatus;
use App\Http\Controllers\PrideController;
use App\Models\CasterScript;
use App\Models\ChessGame;
use App\Models\PongMatch;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Cards\PrideCard;
use App\Support\Cards\PrideMoments;
use App\Support\Cards\PrideVoice;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Memes\BotArchetypes;
use App\Support\Memes\BotBanter;
use App\Support\Memes\MemeShares;
use App\Support\StreamBot\BlockfillNotes;
use App\Support\StreamBot\ChampionChat;
use App\Support\StreamBot\ChampionNotes;
use App\Support\StreamBot\FreePlaceNotes;
use App\Support\StreamBot\PrideNotes;
use App\Support\StreamBot\ProfileNotes;
use App\Support\StreamBot\StreamBot;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\StreamBot\TmnfNotes;
use App\Support\StreamBot\TournamentNotes;
use App\Support\StreamBot\TournamentRecapNotes;
use App\Support\StreamBot\WeeklyBoardNotes;
use App\Support\StreamBot\WeeklyReport;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentSignups;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

/**
 * The tone review of the league voice (plan "KI-Stimme der Liga", P7,
 * `voice:preview`): for each voice function up to N real moments from the
 * database it runs against, newest first, each with the template text and
 * the text the voice writes for it through the production path (LeagueVoice,
 * BotVoice, CasterVoice, PrideVoice, MemeShares, BotBanter, with their
 * guards, repairs and fact check), and the outcome: voiced, or the template
 * with the reason codes the checks counted (VoiceUsage).
 *
 * Nothing goes out and nothing is kept, except the voice's own usage
 * accounting (`voice_usage`, `voice_rejections`: the tokens are spent and
 * count against the monthly budget):
 * - the switches are forced on in this process only, the daily caps lifted
 *   in this process only (the monthly budget stays the ceiling); the config
 *   is restored afterwards;
 * - the cache reads the real cache but writes into this process only
 *   (PreviewCacheStore), so the channels' memory of recent posts and the
 *   spam gate's memory stay as they were;
 * - every database write other than the usage accounting is refused before
 *   it runs, and the run reports it;
 * - the queue is faked: a job the path would send is counted, never pushed;
 * - nothing is signed: the preview calls the voice with the arguments the
 *   post would get, never the post itself.
 *
 * Differences from production, on purpose: the spam gate never holds the
 * template back (`hold_template` off), so the voice's text is seen even for
 * a moment whose template went out before; the voice's own text still
 * passes the gate as in production.
 *
 * @phpstan-type Moment array{label: string, template: string|null, voice: Closure(): ?string}
 * @phpstan-type Row array{function: string, label: string, template: string|null, ai: string|null, outcome: string, reasons: list<string>, repairs: list<string>, cost_usd: float}
 */
final class VoicePreview
{
    /** The voice functions, in the order of the plan's phases (P2 posts, P3 recap, P4 pictures, P5 memes, P6 caster). */
    public const FUNCTIONS = [
        'chat_rotation', 'chat_gg', 'note_tournament', 'note_free_places', 'note_champion', 'note_pride', 'note_weekly', 'share_post',
        'recap', 'weekly_report',
        'card_player', 'card_wrapped', 'card_tape',
        'meme_caption', 'bot_banter',
        'caster_scene',
    ];

    public const VOICED = 'voiced';

    public const TEMPLATE = 'template';

    public const DRY = 'dry';

    /** The usage tables the preview may write; every other write is refused. */
    private const USAGE_WRITE = '/^\s*(?:insert|update)\s+(?:or\s+ignore\s+|ignore\s+)?(?:into\s+)?[`"\[]?(?:voice_usage|voice_rejections)[`"\]]?[\s(]/i';

    private const ANY_WRITE = '/^\s*(?:insert|update|delete|replace|upsert|merge|create|alter|drop|truncate)\b/i';

    private bool $guarding = false;

    /** @var list<string> */
    private array $refusedWrites = [];

    public function __construct(private readonly VoiceUsage $usage) {}

    /**
     * Runs the preview. `$dry`: the templates only, no model call.
     *
     * @param  list<string>  $functions
     * @return array{rows: list<Row>, refused_writes: list<string>, queued_jobs: int, kept_cache_keys: int, errors: list<string>}
     */
    public function run(array $functions, int $limit, bool $dry, CarbonImmutable $now): array
    {
        $config = ['esports.voice' => config('esports.voice'), 'tv.commentary' => config('tv.commentary')];
        $defaultCache = (string) config('cache.default');
        $queue = Queue::getFacadeRoot();
        $locale = App::getLocale();
        $cache = new PreviewCacheStore(Cache::store());
        $rows = [];
        $errors = [];

        Cache::extend('voice-preview', fn () => Cache::repository($cache));
        config(['cache.stores.voice-preview' => ['driver' => 'voice-preview']]);
        Cache::setDefaultDriver('voice-preview');
        $fake = Queue::fake();
        DB::connection()->beforeExecuting(function (string $query): void {
            if ($this->guarding && preg_match(self::ANY_WRITE, $query) === 1 && preg_match(self::USAGE_WRITE, $query) !== 1) {
                $this->refusedWrites[] = mb_substr($query, 0, 160);

                throw new RuntimeException('voice:preview refused a database write: '.mb_substr($query, 0, 80));
            }
        });
        $this->guarding = true;

        try {
            foreach ($functions as $function) {
                $this->switches(false);

                try {
                    $moments = $this->moments($function, $limit, $now);
                } catch (Throwable $exception) {
                    $errors[] = $function.': moments not read ('.$exception::class.': '.mb_substr($exception->getMessage(), 0, 160).')';

                    continue;
                }

                if (! $dry) {
                    $this->switches(true);
                }

                foreach ($moments as $moment) {
                    $rows[] = $this->row($function, $moment, $dry);
                }
            }
        } finally {
            $this->guarding = false;
            config($config);
            Cache::setDefaultDriver($defaultCache);
            Cache::forgetDriver('voice-preview');
            Queue::swap($queue);
            App::setLocale($locale);
        }

        return [
            'rows' => $rows,
            'refused_writes' => $this->refusedWrites,
            'queued_jobs' => count($fake->pushedJobs()),
            'kept_cache_keys' => count($cache->writtenKeys()),
            'errors' => $errors,
        ];
    }

    /**
     * Per function: moments, voiced, and the reason codes counted; plus the cost of the voiced runs.
     *
     * @param  list<Row>  $rows
     * @return array{functions: array<string, array{total: int, voiced: int}>, reasons: array<string, int>, cost_usd: float}
     */
    public static function summary(array $rows): array
    {
        $functions = [];
        $reasons = [];
        $cost = 0.0;

        foreach ($rows as $row) {
            $functions[$row['function']] ??= ['total' => 0, 'voiced' => 0];
            $functions[$row['function']]['total']++;
            $functions[$row['function']]['voiced'] += $row['outcome'] === self::VOICED ? 1 : 0;
            $cost += $row['cost_usd'];

            foreach ($row['reasons'] as $reason) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        arsort($reasons);

        return ['functions' => $functions, 'reasons' => $reasons, 'cost_usd' => round($cost, 6)];
    }

    /**
     * One moment: the template, the voice's text and what the usage counters say about it.
     *
     * @param  Moment  $moment
     * @return Row
     */
    private function row(string $function, array $moment, bool $dry): array
    {
        $row = ['function' => $function, 'label' => $moment['label'], 'template' => $moment['template'], 'ai' => null, 'outcome' => self::DRY, 'reasons' => [], 'repairs' => [], 'cost_usd' => 0.0];

        if ($dry) {
            return $row;
        }

        $before = $this->snapshot();

        try {
            $ai = ($moment['voice'])();
        } catch (Throwable $exception) {
            $ai = null;
            $row['reasons'][] = 'preview error: '.$exception::class;
        }

        $delta = $this->delta($before, $this->snapshot());
        $voiced = is_string($ai) && trim($ai) !== '' && $ai !== $moment['template'];
        $reasons = [...$row['reasons'], ...$delta['reasons']];

        if (! $voiced) {
            if ($delta['failed'] > 0) {
                $reasons[] = 'request failed';
            } elseif ($delta['attempts'] === 0) {
                $reasons[] = 'not asked (cap, budget or nothing to say)';
            } elseif ($delta['accepted'] > 0 && $delta['reasons'] === []) {
                $reasons[] = 'turned down after the voice (copy rules, spam gate or the caller\'s own filter)';
            }
        }

        return [...$row, 'ai' => $voiced ? $ai : null, 'outcome' => $voiced ? self::VOICED : self::TEMPLATE, 'reasons' => array_values(array_unique($reasons)), 'repairs' => $delta['repairs'], 'cost_usd' => $delta['cost']];
    }

    /**
     * All switches of the voice on (lifted daily caps) or off, in this process only.
     */
    private function switches(bool $on): void
    {
        foreach (array_keys((array) config('esports.voice.functions', [])) as $function) {
            config(["esports.voice.functions.{$function}" => $on]);
        }

        foreach ((array) config('esports.voice.channels', []) as $channel => $settings) {
            config(["esports.voice.channels.{$channel}.enabled" => $on]);

            if ($on) {
                config(["esports.voice.channels.{$channel}.daily_cap" => PHP_INT_MAX]);
            }

            foreach (array_keys((array) (((array) $settings)['functions'] ?? [])) as $sub) {
                config(["esports.voice.channels.{$channel}.functions.{$sub}" => $on]);
            }
        }

        config(['tv.commentary.enabled' => $on]);

        if ($on) {
            config(['tv.commentary.scripts.daily_cap' => PHP_INT_MAX]);
        }
    }

    /**
     * @return array{totals: array<string, array{attempts: int, accepted: int, rejected: int, failed: int, input_tokens: int, cache_read_tokens: int, cache_write_tokens: int, output_tokens: int}>, rejections: array<string, array<string, int>>}
     */
    private function snapshot(): array
    {
        $day = $this->usage->day();
        $from = CarbonImmutable::parse($day)->subDay()->toDateString();

        return ['totals' => $this->usage->totals($from, $day), 'rejections' => $this->usage->rejections($from, $day)];
    }

    /**
     * @param  array{totals: array<string, array<string, int>>, rejections: array<string, array<string, int>>}  $before
     * @param  array{totals: array<string, array<string, int>>, rejections: array<string, array<string, int>>}  $after
     * @return array{attempts: int, accepted: int, failed: int, reasons: list<string>, repairs: list<string>, cost: float}
     */
    private function delta(array $before, array $after): array
    {
        $out = ['attempts' => 0, 'accepted' => 0, 'failed' => 0, 'reasons' => [], 'repairs' => [], 'cost' => 0.0];

        foreach ($after['totals'] as $channel => $counters) {
            $diff = [];
            foreach ($counters as $key => $value) {
                $diff[$key] = $value - ($before['totals'][$channel][$key] ?? 0);
            }

            // The fact check's attempts belong to the text it reads, not to a text of its own.
            if ($channel !== LeagueVoice::FACT_CHECK) {
                $out['attempts'] += $diff['attempts'];
                $out['accepted'] += $diff['accepted'];
            }
            $out['failed'] += $diff['failed'];
            $out['cost'] += $this->usage->cost(['input_tokens' => $diff['input_tokens'], 'cache_read_tokens' => $diff['cache_read_tokens'], 'cache_write_tokens' => $diff['cache_write_tokens'], 'output_tokens' => $diff['output_tokens']]);
        }

        foreach ($after['rejections'] as $channel => $reasons) {
            foreach ($reasons as $reason => $count) {
                if ($count - ($before['rejections'][$channel][$reason] ?? 0) <= 0) {
                    continue;
                }

                if (str_ends_with($channel, '_repaired')) {
                    $out['repairs'][] = $reason;
                } else {
                    $out['reasons'][] = $reason;
                }
            }
        }

        return [...$out, 'cost' => round($out['cost'], 6)];
    }

    /**
     * The moments of one function, newest first, at most `$limit`.
     *
     * @return list<Moment>
     */
    public function moments(string $function, int $limit, CarbonImmutable $now): array
    {
        $moments = match ($function) {
            'chat_rotation' => $this->chatRotation($limit, $now),
            'chat_gg' => $this->chatGg($limit, $now),
            'note_tournament' => $this->noteTournament($limit, $now),
            'note_free_places' => $this->noteFreePlaces($limit, $now),
            'note_champion' => $this->noteChampion($limit, $now),
            'note_pride' => $this->notePride($now),
            'note_weekly' => $this->noteWeekly($limit, $now),
            'share_post' => $this->sharePost($limit),
            'recap' => $this->recap($limit, $now),
            'weekly_report' => $this->weeklyReport($limit),
            'card_player' => $this->cardPlayer($limit),
            'card_wrapped' => $this->cardWrapped($limit),
            'card_tape' => $this->cardTape($limit),
            'meme_caption' => $this->memeCaption($limit),
            'bot_banter' => $this->botBanter(),
            'caster_scene' => $this->casterScene($now),
            default => throw new RuntimeException("Unknown voice function {$function}."),
        };

        return array_slice($moments, 0, $limit);
    }

    /**
     * A BotVoice post: its template as production renders it (the function
     * off), and the voice's post from the same arguments (the function on).
     *
     * @param  array<string, string|int|null>  $values
     * @param  list<list<string>>  $tags
     * @param  array{session?: string, must_contain?: list<string>}  $options
     * @return Moment
     */
    private function composed(string $label, string $function, string $template, int $variant, array $values, array $tags, CarbonImmutable $now, array $options = []): array
    {
        $voice = app(BotVoice::class);
        $templateContent = $voice->compose($function, $template, $variant, $values, $tags, $now, $options)['content'] ?? null;

        return [
            'label' => $label,
            'template' => $templateContent,
            'voice' => function () use ($voice, $function, $template, $variant, $values, $tags, $now, $options): ?string {
                $post = $voice->compose($function, $template, $variant, $values, $tags, $now, $options);

                return ($post['voiced'] ?? false) === true ? $post['content'] : null;
            },
        ];
    }

    /** @return list<Moment> */
    private function chatRotation(int $limit, CarbonImmutable $now): array
    {
        $stream = StreamCoordinates::fromConfig();
        $options = $stream === null ? [] : ['session' => StreamBot::session($stream, $now)];
        $moments = [];

        foreach (app(StreamBot::class)->preview($now, 2 * $limit) as $message) {
            if ($message->template !== null) {
                $moments[] = $this->composed($message->builder.' · '.$message->factKey, 'chat_rotation', $message->template, $message->variant, $message->values, $message->tags, $now, $options);
            }
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function chatGg(int $limit, CarbonImmutable $now): array
    {
        $stream = StreamCoordinates::fromConfig();
        $options = $stream === null ? [] : ['session' => StreamBot::session($stream, $now)];
        $chat = app(ChampionChat::class);
        $moments = [];

        foreach ($this->finishedTournaments($limit)->whereNotNull('published_at') as $tournament) {
            $gg = $chat->compose($tournament);

            if ($gg !== null) {
                $moments[] = $this->composed('tournament '.$tournament->id, 'chat_gg', ChampionChat::TEMPLATE, $gg['variant'], $gg['values'], $gg['tags'], $now, $options);
            }
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function noteTournament(int $limit, CarbonImmutable $now): array
    {
        $notes = app(TournamentNotes::class);
        $moments = [];
        $tournaments = Tournament::query()->with('event')->whereNotNull('event_id')->whereNotNull('slug')
            ->whereNotIn('status', [TournamentStatus::Draft, TournamentStatus::Cancelled])->exceptLeagueWeeks()
            ->orderByDesc('published_at')->orderByDesc('id')->limit(2 * $limit)->get();

        foreach ($tournaments as $tournament) {
            $address = $tournament->address();

            if ($address !== null) {
                $moments[] = $this->composed('tournament '.$tournament->id.' ('.$tournament->status->value.')', 'note_tournament', $notes->template($tournament), $notes->nextVariant($tournament),
                    $notes->values($tournament), [['q', $address, $notes->relayHint() ?? '']], $now, ['must_contain' => ['starts']]);
            }
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function noteFreePlaces(int $limit, CarbonImmutable $now): array
    {
        $notes = app(FreePlaceNotes::class);
        $relayHint = app(TournamentNotes::class)->relayHint() ?? '';
        $signups = app(TournamentSignups::class);
        $moments = [];
        $tournaments = Tournament::query()->with('event')->where('status', TournamentStatus::Signup)->whereNotNull('event_id')->whereNotNull('slug')
            ->where('signup_closes_at', '>', $now)->orderBy('signup_closes_at')->orderBy('id')->limit(4 * $limit)->get();

        foreach ($tournaments as $tournament) {
            $address = $tournament->address();
            $places = $signups->places($tournament);
            $free = $places['places'] - $places['taken'];

            if ($address !== null && $free > 0) {
                $moments[] = $this->composed('tournament '.$tournament->id.' ('.$free.'/'.$places['places'].' free)', 'note_free_places', FreePlaceNotes::TEMPLATE, FreePlaceNotes::nextVariant(),
                    $notes->values($tournament, $free, $places['places'], $now), [['q', $address, $relayHint]], $now);
            }
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function noteChampion(int $limit, CarbonImmutable $now): array
    {
        $notes = app(ChampionNotes::class);
        $variant = ProfileNotes::nextVariant(ChampionNotes::NOTE_TYPE, StreamBotCopy::variants(ChampionNotes::TEMPLATE));
        $moments = [];

        foreach ($this->finishedTournaments($limit)->whereNotNull('event_id') as $tournament) {
            $draft = $notes->draft($tournament);

            if ($draft !== null) {
                $moments[] = $this->composed('tournament '.$tournament->id, 'note_champion', ChampionNotes::TEMPLATE, $variant, $draft['values'], $draft['tags'], $now);
            }
        }

        return $moments;
    }

    /**
     * The pride notes read the league's current data: one moment per type.
     *
     * @return list<Moment>
     */
    private function notePride(CarbonImmutable $now): array
    {
        $notes = app(PrideNotes::class);
        $moments = [];

        foreach (PrideNotes::TYPES as $type => [$name, , $template]) {
            $variant = ProfileNotes::nextVariant(PrideNotes::noteType($type), StreamBotCopy::variants($template));
            $note = $notes->compose($type, $variant);

            if ($note !== null) {
                $moments[] = $this->composed('pride '.$name, 'note_pride', $note['template'], $variant, $note['values'], $note['tags'], $now);
            }
        }

        return $moments;
    }

    /**
     * The winner notes of the league weeks (Blockfill, TMNF), newest first.
     *
     * @return list<array{notes: WeeklyBoardNotes, week: Tournament}>
     */
    private function weeks(int $limit): array
    {
        $weeks = [];

        foreach ([app(BlockfillNotes::class), app(TmnfNotes::class)] as $notes) {
            foreach ($notes->finishedWeeks($limit) as $week) {
                $weeks[] = ['notes' => $notes, 'week' => $week];
            }
        }

        usort($weeks, fn (array $a, array $b): int => [$b['week']->starts_at->getTimestamp(), $b['week']->id] <=> [$a['week']->starts_at->getTimestamp(), $a['week']->id]);

        return array_slice($weeks, 0, $limit);
    }

    /** @return list<Moment> */
    private function noteWeekly(int $limit, CarbonImmutable $now): array
    {
        $relayHint = app(TournamentNotes::class)->relayHint() ?? '';
        $moments = [];

        foreach ($this->weeks($limit) as ['notes' => $notes, 'week' => $week]) {
            $note = $notes->compose($week, WeeklyBoardNotes::SLOT_WINNER);
            $address = $week->address();
            $tags = $address === null ? $note['tags'] : [...$note['tags'], ['q', $address, $relayHint]];
            $moments[] = $this->composed($notes->label().' week '.$week->id, 'note_weekly', $note['template'], $note['variant'], $note['values'], $tags, $now);
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function weeklyReport(int $limit): array
    {
        $moments = [];

        foreach ($this->weeks($limit) as ['notes' => $notes, 'week' => $week]) {
            $note = $notes->compose($week, WeeklyBoardNotes::SLOT_WINNER);
            $moments[] = [
                'label' => $notes->label().' week '.$week->id,
                'template' => $note['content'],
                'voice' => fn (): ?string => app(WeeklyReport::class)->line($week, $notes->standings($week), $notes->label(), (string) ($note['values']['url'] ?? ''))['content'] ?? null,
            ];
        }

        return $moments;
    }

    /**
     * Share posts of the player's own wins (Proof of Pong, chess), newest first, in English.
     *
     * @return list<Moment>
     */
    private function sharePost(int $limit): array
    {
        $shares = app(SharePosts::class);
        $candidates = [];

        foreach (PongMatch::query()->where('status', PongMatchStatus::Finished)->whereNotNull('winner_id')->with('winner')->orderByDesc('ended_at')->orderByDesc('id')->limit($limit)->get() as $match) {
            $candidates[] = ['at' => $match->ended_at?->getTimestamp() ?? 0, 'user' => $match->winner, 'type' => 'pong', 'id' => $match->ulid];
        }

        foreach (ChessGame::query()->where('status', ChessGameStatus::Finished)->whereIn('result', ['1-0', '0-1'])->with(['white', 'black'])->orderByDesc('id')->limit($limit)->get() as $game) {
            $candidates[] = ['at' => $game->updated_at?->getTimestamp() ?? 0, 'user' => $game->result === '1-0' ? $game->white : $game->black, 'type' => 'game', 'id' => (string) $game->id];
        }

        usort($candidates, fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $moments = [];
        App::setLocale('en');

        foreach ($candidates as $candidate) {
            if (! $candidate['user'] instanceof User) {
                continue;
            }

            try {
                $sentence = $shares->post($candidate['user'], $candidate['type'], $candidate['id'])->sentence;
            } catch (ShareRefused) {
                continue;
            }

            $type = $candidate['type'];
            $key = 'voice-preview:'.$candidate['user']->id.':'.$type.':'.$candidate['id'];
            $moments[] = [
                'label' => $type.' '.$candidate['id'],
                'template' => $sentence,
                'voice' => function () use ($type, $sentence, $key): ?string {
                    $written = app(BotVoice::class)->sentence($type, $sentence, 'en', $key);

                    return $written === $sentence ? null : $written;
                },
            ];
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function recap(int $limit, CarbonImmutable $now): array
    {
        $notes = app(TournamentRecapNotes::class);
        $variant = ProfileNotes::nextVariant(TournamentRecapNotes::NOTE_TYPE, count(TournamentRecapNotes::TEMPLATES));
        $moments = [];

        foreach ($this->finishedTournaments($limit)->whereNotNull('event_id') as $tournament) {
            $template = $notes->compose($tournament, $variant, $now);

            if ($template !== null) {
                $moments[] = [
                    'label' => 'tournament '.$tournament->id,
                    'template' => $template['content'],
                    'voice' => function () use ($notes, $tournament, $variant, $now): ?string {
                        $note = $notes->compose($tournament, $variant, $now);

                        return ($note['voiced'] ?? false) === true ? $note['content'] : null;
                    },
                ];
            }
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function cardPlayer(int $limit): array
    {
        $moments = [];

        foreach ($this->ratedUsers(null, 3 * $limit) as $user) {
            $facts = PrideMoments::player($user);

            if (PrideMoments::hasCard($facts)) {
                $moments[] = $this->pride('player '.$user->id, 'player', (string) $user->npub, PrideCard::playerVoiceFacts($facts), PrideMoments::playerTitle($facts));
            }
        }

        return $moments;
    }

    /** @return list<Moment> */
    private function cardWrapped(int $limit): array
    {
        $season = Season::query()->where('genesis_at', '<=', now())->orderByDesc('genesis_at')->first();
        $moments = [];

        if ($season === null) {
            return [];
        }

        foreach ($this->ratedUsers($season->slug, $limit) as $user) {
            $facts = PrideMoments::wrapped($season, $user);
            $moments[] = $this->pride('wrapped '.$season->slug.' · player '.$user->id, 'wrapped', $season->slug.'-'.$user->npub, PrideController::wrappedVoiceFacts($facts), PrideMoments::wrappedLine($facts));
        }

        return $moments;
    }

    /**
     * The tale of the tape of the latest finals (finished tournaments: the
     * final as it stood before it was played, today's ratings).
     *
     * @return list<Moment>
     */
    private function cardTape(int $limit): array
    {
        $moments = [];

        foreach ($this->finishedTournaments(2 * $limit) as $tournament) {
            $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotIn('bracket', ['third-place', 'reset', 'bye'])
                ->with(['round.stage', 'slots.participant'])->orderByDesc('id')->get()
                ->first(fn (TournamentMatch $match): bool => TournamentRunner::isFinal($match) && $match->slots->count() === 2 && $match->slots->every(fn ($slot): bool => $slot->participant !== null));

            if ($final instanceof TournamentMatch) {
                $facts = PrideMoments::tape($tournament, $final);
                $moments[] = $this->pride('tournament '.$tournament->id.' · final '.$final->id, 'tape', $tournament->id.'-'.$final->id, PrideCard::tapeVoiceFacts($facts), PrideMoments::tapeLine($facts));
            }
        }

        return $moments;
    }

    /**
     * A pride picture's line: the model writes German only (PrideVoice::enabled()).
     *
     * @param  array<string, mixed>  $facts
     * @return Moment
     */
    private function pride(string $label, string $function, string $key, array $facts, string $template): array
    {
        return [
            'label' => $label,
            'template' => $template,
            'voice' => function () use ($function, $key, $facts): ?string {
                App::setLocale('de');

                return app(PrideVoice::class)->write($function, $key, $facts);
            },
        ];
    }

    /** @return list<Moment> */
    private function memeCaption(int $limit): array
    {
        $memes = app(MemeShares::class);
        $moments = [];
        App::setLocale('de');

        foreach (PongMatch::query()->where('status', PongMatchStatus::Finished)->whereNotNull('winner_id')->orderByDesc('ended_at')->orderByDesc('id')->limit(10 * $limit)->get() as $match) {
            $facts = $memes->facts($match);

            if ($facts === null) {
                continue;
            }

            $moments[] = [
                'label' => 'pong '.$match->ulid.' ('.$facts['moment'].')',
                'template' => $facts['line'],
                'voice' => function () use ($memes, $match, $facts): ?string {
                    $memes->write($match, app(LeagueVoice::class));
                    $caption = Cache::get(MemeShares::captionKey($match, $facts['moment']));

                    return is_array($caption) && is_string($caption['text'] ?? null) ? $caption['text'] : null;
                },
            ];

            if (count($moments) >= $limit) {
                break;
            }
        }

        return $moments;
    }

    /**
     * The bots' lines have no moment of their own: one per occasion and archetype, occasion by occasion.
     *
     * @return list<Moment>
     */
    private function botBanter(): array
    {
        $banter = app(BotBanter::class);
        $moments = [];

        foreach (BotArchetypes::OCCASIONS as $occasion) {
            foreach (BotArchetypes::ROSTER as $archetype => $character) {
                $moments[] = [
                    'label' => $archetype.' · '.$occasion,
                    'template' => $character['lines'][$occasion][0][0],
                    'voice' => function () use ($banter, $archetype, $occasion): ?string {
                        return $banter->write($archetype, $occasion, app(LeagueVoice::class)) ? ($banter->pool($archetype, $occasion)[0] ?? null) : null;
                    },
                ];
            }
        }

        return $moments;
    }

    /**
     * One scene script per programme phase, from the league's current facts.
     * A scene has no template: without a script the stream plays the music.
     *
     * @return list<Moment>
     */
    private function casterScene(CarbonImmutable $now): array
    {
        $facts = app(SceneFacts::class);
        $queue = app(ScriptQueue::class);
        $sentences = (array) config('tv.commentary.scripts.sentences', []);
        $moments = [];

        foreach (ScenePhase::ALL as $phase) {
            $sceneFacts = $facts->for($phase);
            $topics = $queue->topics($phase, $now);
            $moments[] = [
                'label' => 'phase '.$phase,
                'template' => null,
                'voice' => fn (): ?string => app(CasterVoice::class)->write(CasterScript::SCENE, $phase, $sceneFacts, (int) ($sentences['min'] ?? 3), (int) ($sentences['max'] ?? 8), [], $topics)['text'] ?? null,
            ];
        }

        return $moments;
    }

    /**
     * Finished tournaments (no league week), the latest finished first.
     *
     * @return Collection<int, Tournament>
     */
    private function finishedTournaments(int $limit): Collection
    {
        return Tournament::query()->with('event')->where('status', TournamentStatus::Finished)->whereNotNull('slug')->exceptLeagueWeeks()
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(2 * $limit)->get()->toBase();
    }

    /**
     * Players with rated results, the most recently rated first (one season, or any).
     *
     * @return list<User>
     */
    private function ratedUsers(?string $season, int $limit): array
    {
        $ids = Rating::query()->where('pool', Rating::RATED)->whereNotNull('user_id')->where('results', '>', 0)
            ->when($season !== null, fn ($query) => $query->where('season', $season))
            ->orderByDesc('updated_at')->limit(10 * $limit)->pluck('user_id')->unique()->take($limit)->values()->all();
        $users = User::query()->whereKey($ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?User => $users->get($id), $ids)));
    }
}
