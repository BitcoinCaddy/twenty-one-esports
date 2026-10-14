<?php

namespace App\Support\Caster\Programme;

use App\Enums\BoardGameStatus;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\CasterScript;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Live comments on a running organizer tournament (user 2026-10-14, Blockli Cup #1: "Live Comments über das Turnier
 * immer wieder mit Jingle … durch echte LIVE Turnierdaten. Lustiges Roasten der Spieler und Matches … Auslesen der
 * Spieler-Nostr-Profile"): every `every_minutes` while such a tournament runs, Markus and Sirius talk about it for a
 * minute from what the database knows right now (the boards on the go, who is to move, finished games, the field and
 * each player's own Nostr bio), queued as the rubric `turnier_live`, so it airs in a block behind the Anchor's
 * "breaking" jingle, also in the music-first live mode. Off unless switched on (`tournament_live.enabled`).
 */
final class TournamentLive
{
    public const TOPIC = 'turnier_live';

    public const CHANNEL = 'caster_tournament_live';

    private const LAST = 'caster:tournament_live:last';

    private const SAID = 'caster:tournament_live:said';

    public function __construct(private readonly DuetScripts $duets, private readonly VoiceUsage $usage) {}

    public static function enabled(): bool
    {
        return config('tv.commentary.tournament_live.enabled') === true && CasterVoice::enabled();
    }

    /**
     * The tournament to talk about: a configured id, else the running organizer tournament that started last.
     */
    public static function tournament(): ?Tournament
    {
        $ids = array_values(array_filter(array_map('intval', (array) config('tv.commentary.tournament_live.tournaments', []))));
        $query = Tournament::query()->where('status', TournamentStatus::Running);

        return $ids !== []
            ? $query->whereIn('id', $ids)->orderByDesc('starts_at')->first()
            : $query->whereNotNull('created_by_id')->whereNull('cup_series')->where('opened_by_league', false)->orderByDesc('starts_at')->first();
    }

    /**
     * Writes and queues the next comment when one is due; returns the scripts written (0 or 1).
     */
    public function fill(ScriptQueue $queue, CarbonImmutable $now): int
    {
        if (! self::enabled() || ($tournament = self::tournament()) === null) {
            return 0;
        }

        $every = 60 * max(2, (int) config('tv.commentary.tournament_live.every_minutes', 8));
        $last = Cache::get(self::LAST);

        if ((is_int($last) && $now->getTimestamp() - $last < $every)
            || CasterScript::query()->where('topic', self::TOPIC)->whereIn('status', [CasterScript::QUEUED, CasterScript::CLAIMED])->where('expires_at', '>', $now)->exists()
            || $this->usage->attemptsToday(self::CHANNEL) >= (int) config('tv.commentary.tournament_live.daily_cap', 80)) {
            return 0;
        }

        Cache::put(self::LAST, $now->getTimestamp(), 6 * 3600);
        $material = self::material($tournament, $now);
        $said = (array) Cache::get(self::SAID.':'.$tournament->id, []);

        try {
            $talk = $this->draft($material, $said);
        } catch (Throwable $exception) {
            Log::warning('Tournament live comment failed', ['exception' => $exception::class, 'message' => mb_substr($exception->getMessage(), 0, 300)]);

            return 0;
        }

        if ($talk === null) {
            return 0;
        }

        Cache::put(self::SAID.':'.$tournament->id, array_slice([...$said, mb_substr(str_replace("\n", ' ', $talk['text']), 0, 400)], -4), 12 * 3600);

        return $queue->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $talk['text'], sha1('tlive:'.$tournament->id.':'.$now->getTimestamp()), 74, $now,
            topic: self::TOPIC, maxAgeMinutes: (int) config('tv.commentary.tournament_live.max_age_minutes', 20), speakers: $talk['speakers']) !== null ? 1 : 0;
    }

    /**
     * The tournament as it stands, in plain German lines: the field with each player's Nostr bio, the boards on the go
     * and the finished games.
     */
    public static function material(Tournament $tournament, CarbonImmutable $now): string
    {
        $participants = TournamentParticipant::query()->where('tournament_id', $tournament->id)->get();
        $users = User::query()->whereIn('id', $participants->pluck('user_id')->filter())->get()->keyBy('id');
        $name = fn (?int $id): string => $id !== null && $users->has($id) ? $users[$id]->displayName() : 'unbekannt';
        $groupOf = $participants->filter(fn ($p) => $p->user_id !== null)->mapWithKeys(fn ($p) => [$p->user_id => $p->group]);
        $options = (array) $tournament->options;

        $lines = [
            'Turnier: '.$tournament->title().' (Spiel '.$tournament->game.', Modus '.$tournament->mode.', Format '.$tournament->format->value
                .(isset($options['groupStage']) ? ', Gruppenphase '.$options['groupStage'].', Gruppen zu '.($options['groupSize'] ?? '?').', die besten '.($options['advance'] ?? '?').' jeder Gruppe kommen weiter' : '').').',
            'Gestartet: '.$tournament->starts_at?->setTimezone('Europe/Berlin')->format('d.m. H:i').' Uhr Berliner Zeit. Jetzt: '.$now->setTimezone('Europe/Berlin')->format('H:i').' Uhr.',
        ];

        if ($tournament->game === 'blockli') {
            $lines[] = 'Blockli ist digitales Quoridor: die eigene Figur als Erster auf die andere Seite bringen und dem Gegner Mauern in den Weg stellen, ohne ihn ganz einzusperren. Fernpartie heißt: bis zu 24 Stunden pro Zug, jeder spielt seine Gruppenpartien gleichzeitig.';
        }

        $lines[] = '';
        $lines[] = 'Spieler ('.$participants->count().'), mit Gruppe und eigener Nostr-Bio:';

        foreach ($participants->sortBy('group') as $participant) {
            $user = $participant->user_id !== null ? $users->get($participant->user_id) : null;
            $about = $user?->about !== null ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $user->about))) : '';
            $lines[] = '- '.$participant->name.($participant->group !== null ? ' (Gruppe '.$participant->group.')' : '').($about !== '' ? ': „'.mb_substr($about, 0, 180).'“' : ': keine Bio');
        }

        $games = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->get();
        $active = $games->where('status', BoardGameStatus::Active);
        $finished = $games->where('status', BoardGameStatus::Finished)->sortByDesc('ended_at');

        $lines[] = '';
        $lines[] = 'Bretter laufen gerade: '.$active->count().', fertig: '.$finished->count().'.';

        foreach ($active->sortByDesc('ply')->take(8) as $game) {
            $toMove = $game->turn === 'w' ? $game->white_id : $game->black_id;
            $lines[] = '- '.$name($game->white_id).' gegen '.$name($game->black_id).($groupOf->has($game->white_id) ? ' (Gruppe '.$groupOf[$game->white_id].')' : '')
                .': '.((int) $game->ply === 0 ? 'noch kein Zug' : (int) $game->ply.' Züge gespielt').', am Zug: '.$name($toMove).'.';
        }

        foreach ($finished->take(6) as $game) {
            $result = (string) ($game->result instanceof \BackedEnum ? $game->result->value : $game->result);
            $winner = match ($result) {
                'white', '1-0', 'w' => $name($game->white_id),
                'black', '0-1', 'b' => $name($game->black_id),
                default => null,
            };
            $lines[] = '- Fertig: '.$name($game->white_id).' gegen '.$name($game->black_id).': '.($winner !== null ? 'gewonnen hat '.$winner : 'Ergebnis '.$result).'.';
        }

        $movers = $active->groupBy(fn (BoardGame $game) => $game->turn === 'w' ? $game->white_id : $game->black_id)->map->count()->sortDesc()->take(3);

        foreach ($movers as $id => $count) {
            $lines[] = 'Am meisten offene Züge: '.$name((int) $id).' ist an '.$count.' Brettern am Zug.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $said  the last comments, so the next one does not repeat them
     * @return array{text: string, speakers: list<string>}|null
     */
    private function draft(string $material, array $said): ?array
    {
        $this->usage->recordAttempt(self::CHANNEL);
        $model = config('tv.commentary.tournament_live.model');
        $response = (new VoiceAgent($this->duets->systemPrompt(gags: false), 0.9, 1500, cacheTtl: CasterVoice::staticCacheTtl(), modelOverride: is_string($model) && $model !== '' ? $model : null))
            ->prompt(self::prompt($material, $said));
        $this->usage->recordTokens(self::CHANNEL, $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

        $answer = $response instanceof StructuredAgentResponse ? $response->toArray() : json_decode($response->text, true);
        $raw = is_array($answer) && is_string($answer['text'] ?? null) ? $answer['text'] : '';
        [$duet, $violations] = $this->duets->evaluate($raw, [], false, 8, 3);

        if ($duet === null || $violations !== []) {
            $this->usage->recordRejection(self::CHANNEL, $violations === [] ? ['empty'] : $violations);
            $this->usage->recordOutcome(self::CHANNEL, 'rejected');

            return null;
        }

        $this->usage->recordOutcome(self::CHANNEL, 'accepted');

        return ['text' => $duet['text'], 'speakers' => $duet['speakers']];
    }

    /**
     * @param  list<string>  $said
     */
    public static function prompt(string $material, array $said): string
    {
        return implode("\n", [
            'Live-Kommentar im Radio zu einem laufenden Turnier: ein kurzer Einwurf von Markus und Sirius, etwa 45 bis 70 Sekunden, 4 bis 7 Wortwechsel, jede Zeile beginnt mit "Markus:" oder "Sirius:".',
            'Ton: wie ein Live-Ticker beim Sport, frech, sarkastisch, liebevoll roastend. Nehmt Spieler und Partien aufs Korn: wer trödelt, wer an vielen Brettern am Zug ist, wer laut seiner Nostr-Bio eigentlich ganz andere Hobbys hat, und wie gut (oder schlecht) seine Chancen stehen. Sprecht Spieler mit ihrem Namen an, als würden sie zuhören.',
            'Regeln: Nur Fakten aus dem Material unten verwenden, nichts erfinden (keine erfundenen Ergebnisse, Züge, Zahlen oder Zitate). Bio-Inhalte nur so verwenden, wie sie dastehen. Keine geschützten Merkmale, keine Beleidigung unter der Gürtellinie, kein Selbstetikett. Keine Uhrzeiten nennen außer der im Material.',
            'Rahmen: Der erste Satz macht klar, dass das ein Live-Einwurf aus dem Turnier ist (der Jingle davor ist "Breaking"). Ein roter Faden, eine Pointe, der letzte Satz gibt zurück an die Musik.',
            $said === [] ? '' : "Diese Einwürfe liefen schon, wiederholt keinen Witz und nehmt andere Spieler dran:\n- ".implode("\n- ", $said),
            '',
            'Material (Stand jetzt):',
            $material,
            '',
            'Antworte als JSON mit dem Feld "text" (der Dialog), "canon_id" null und "mood".',
        ]);
    }
}
