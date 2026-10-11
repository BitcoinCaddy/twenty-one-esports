<?php

namespace App\Support\StreamBot;

use App\Enums\TournamentStatus;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentRecap;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;
use App\Support\Voice\LeagueVoice;
use App\Support\Voice\SpamGate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The recap of a finished tournament on the stream bot's profile (plan
 * "KI-Stimme der Liga", P3): one kind-1 note per published tournament with
 * a recap (TournamentRecap: the biggest upset by Elo, the closest match,
 * the champion), after its champion note went out (ChampionNotes), in
 * English (profile posts stay English, user 2026-10-11).
 *
 * The text: the league voice writes it from the recap's facts (channel
 * `recap`, moment `tournament_final`) when that channel is on; whatever the
 * voice returns null for (switch off, cap, failure, a text its guards
 * reject), and a voice text the copy rules or the spam gate refuse, takes
 * the template instead. The app adds the link line when the voice left it
 * out. People are mentioned as `nostr:npub1…` with a `p` tag: the champion
 * and an upset's winner, at most PrideNotes::LOBBY_MENTIONS, only by the
 * account's Nostr key and never next to their name; a loser is named.
 *
 * The spam gate (profile surface) is asked for every mention and for the
 * finished text; a text it refuses holds the note back, the claim is
 * released and a later run tries again. ProfileNotes::hold() paces it with
 * every other note of the profile.
 *
 * Exactly once per tournament (BotPost, subject SUBJECT), claimed before it
 * is signed, signed once, a failed send retried with the same event after
 * `retry_minutes`. Fail closed: off unless `esports.recap.notes.enabled`
 * (default off) and TournamentNotes::setup() (flag, key, relays).
 */
class TournamentRecapNotes
{
    public const KIND_NOTE = 1;

    public const SUBJECT = 'tournament_recap';

    /** The note type for ProfileNotes (wording, pacing). */
    public const NOTE_TYPE = 'tournament_recap';

    /** The league voice's moment and channel of the recap. */
    public const MOMENT = 'tournament_final';

    public const CHANNEL = 'recap';

    /** The template's wordings: lines with a placeholder whose value is missing are left out. */
    public const TEMPLATES = [
        [
            '🏆 :champion wins :name (:game)',
            '🤯 Biggest upset: :upset_winner (:upset_winner_elo Elo) beat :upset_loser (:upset_loser_elo Elo), :upset_score',
            '😮‍💨 Closest match: :close_winner beat :close_loser :close_score',
            '👉 Recap and bracket: :url',
        ],
        [
            '🏁 :name is done, :champion takes the title',
            '📉 :upset_winner beat :upset_loser :upset_score, :upset_gap Elo below on paper',
            '⚖️ Tightest one: :close_winner vs :close_loser, :close_score',
            '👉 :url',
        ],
    ];

    /** Names get this many characters in a note, as in TournamentNotes. */
    private const NAME_LENGTH = 60;

    public function __construct(
        private StreamBotPublisher $publisher,
        private TournamentNotes $notes,
        private TournamentRecap $recaps,
        private LeagueVoice $voice,
        private SpamGate $spam,
    ) {}

    /**
     * One scheduler tick; what happened, for the log.
     */
    public function run(CarbonImmutable $now): string
    {
        if (! (bool) config('esports.recap.notes.enabled', false)) {
            return 'no recap notes: esports.recap.notes.enabled is off';
        }

        $setup = $this->notes->setup();

        if (is_string($setup)) {
            return str_replace('no notes', 'no recap notes', $setup);
        }

        [$key, $relays] = $setup;
        $lines = [];

        foreach ($this->due($now) as $tournament) {
            if (($hold = ProfileNotes::hold($now)) !== null) {
                $lines[] = 'held: '.$hold;

                break;
            }

            try {
                $lines[] = $this->post($key, $tournament, $relays, $now);
            } catch (Throwable $e) {
                // One broken note must not hold back the others; its claim expires and it is tried again.
                report($e);
                $lines[] = 'tournament '.$tournament->id.': recap note failed, '.$e->getMessage();
            }
        }

        return $lines === [] ? 'no recap notes: no newly finished tournament without its recap' : implode("\n", $lines);
    }

    /**
     * Published, finished tournaments (no league week) that ended within
     * `days`, have a recap, whose champion note went out (or champion notes
     * are off) and have no delivered or freshly claimed recap: oldest finish
     * first, at most `per_run` and what the note type's pacing allows.
     *
     * @return list<Tournament>
     */
    public function due(CarbonImmutable $now): array
    {
        $limit = min(max(0, (int) config('esports.recap.notes.per_run', 1)), ProfileNotes::allowance(self::NOTE_TYPE, $now));

        if ($limit <= 0) {
            return [];
        }

        $since = $now->subDays(max(1, (int) config('esports.recap.notes.days', 5)));
        $championFirst = (bool) config('esports.stream_bot.champion_notes.enabled', true);

        $candidates = Tournament::query()
            ->with('event')
            ->where('status', TournamentStatus::Finished)
            ->whereNotNull('event_id')
            ->whereNotNull('slug')
            ->exceptLeagueWeeks()
            ->where('updated_at', '>=', $since)
            ->whereNotExists(fn (Builder $query) => $query->from('bot_posts')
                ->where('bot_posts.subject_type', self::SUBJECT)
                ->whereColumn('bot_posts.subject_id', 'tournaments.id')
                ->where('bot_posts.kind', self::KIND_NOTE)
                ->where(fn (Builder $taken) => $taken->whereNotNull('bot_posts.published_at')
                    ->orWhere('bot_posts.attempted_at', '>', $this->claimCutoff($now))))
            ->when($championFirst, fn ($query) => $query->whereExists(fn (Builder $champion) => $champion->from('bot_posts')
                ->where('bot_posts.subject_type', ChampionNotes::SUBJECT)
                ->whereColumn('bot_posts.subject_id', 'tournaments.id')
                ->whereNotNull('bot_posts.published_at')))
            ->orderBy('id')
            ->get();

        $due = [];

        foreach ($candidates as $tournament) {
            $finished = TournamentLiveSlides::finishedAt($tournament);

            if ($finished !== null && $finished->gte($since) && $this->recaps->of($tournament) !== null) {
                $due[] = ['tournament' => $tournament, 'finished' => $finished->getTimestamp()];
            }
        }

        usort($due, fn (array $a, array $b): int => [$a['finished'], $a['tournament']->id] <=> [$b['finished'], $b['tournament']->id]);

        return array_map(fn (array $item): Tournament => $item['tournament'], array_slice($due, 0, $limit));
    }

    /**
     * The note in the wording `$variant`: the voice's text or the template,
     * then the calendar event as `nostr:naddr1…` after a blank line. `skip`
     * names the spam gate's reason when neither may go out; null without a
     * recap or without the calendar event.
     *
     * @return array{content: string, tags: list<list<string>>, voiced: bool, canon: string|null, mentioned: list<string>, skip: string|null}|null
     */
    public function compose(Tournament $tournament, int $variant, CarbonImmutable $now): ?array
    {
        $address = $tournament->address();
        $previous = app()->getLocale();
        app()->setLocale('en');

        try {
            $recap = $this->recaps->of($tournament);

            if ($recap === null || $address === null) {
                return null;
            }

            $url = route('tournaments.show', $tournament);
            $people = $this->people($recap, $now);
            $note = $this->voiced($recap, $people, $url, $now) ?? $this->template($tournament, $recap, $people, $url, $variant, $now);
        } finally {
            app()->setLocale($previous);
        }

        $note['tags'][] = ['q', $address, $this->notes->relayHint() ?? ''];

        return [...$note, 'content' => $note['content']."\n\nnostr:".$this->notes->naddr($tournament)];
    }

    /**
     * The facts the league voice gets: the recap without ids, the people
     * with their role (the champion, the biggest upset's winner and loser,
     * the closest match's sides), the link.
     *
     * @param  array<string, mixed>  $recap
     * @param  array<string, array{role: string, name: string, pubkey: string|null, mentionable: bool}>  $people
     * @return array<string, mixed>
     */
    public static function voiceFacts(array $recap, array $people, string $url): array
    {
        $side = fn (?array $side): ?array => $side === null ? null : ['name' => $side['name'], 'elo' => $side['rating']];

        return [
            'tournament' => StreamBotCopy::resultName((string) $recap['tournament'], self::NAME_LENGTH),
            'game' => $recap['game'],
            'entrants' => $recap['entrants'],
            'matches_played' => $recap['played'],
            'champion' => $recap['champion']['name'] ?? null,
            'champion_path' => array_map(fn (array $step): array => ['round' => $step['round'], 'opponent' => $step['opponent'], 'result' => $step['result'], 'score' => $step['score']], $recap['path']),
            'upsets' => array_map(fn (array $upset): array => [
                'round' => $upset['round'], 'winner' => $side($upset['winner']), 'loser' => $side($upset['loser']), 'elo_gap' => $upset['gap'], 'score' => $upset['score'],
            ], $recap['upsets']),
            'closest_match' => $recap['closest'] === null ? null : [
                'round' => $recap['closest']['round'], 'winner' => $recap['closest']['winner']['name'], 'loser' => $recap['closest']['loser']['name'], 'score' => $recap['closest']['score'],
            ],
            'url' => $url,
            'people' => array_values($people),
        ];
    }

    /**
     * The people a recap may mention, by role: the champion, the biggest
     * upset's winner (a different person). A team is no person; a player
     * without a Nostr key is named only; the spam gate decides the rest.
     *
     * @param  array<string, mixed>  $recap
     * @return array<string, array{role: string, name: string, pubkey: string|null, mentionable: bool}>
     */
    private function people(array $recap, CarbonImmutable $now): array
    {
        $candidates = ['champion' => $recap['champion'], 'upset_winner' => $recap['upsets'][0]['winner'] ?? null];
        $users = User::query()->whereKey(array_filter(array_map(fn (?array $side): ?int => $side['user_id'] ?? null, $candidates)))->pluck('pubkey', 'id');
        $people = [];
        $seen = [];

        foreach ($candidates as $role => $side) {
            if ($side === null || in_array($side['id'], $seen, true)) {
                continue;
            }

            $seen[] = $side['id'];
            $pubkey = $side['user_id'] === null ? null : $users->get($side['user_id']);
            $pubkey = is_string($pubkey) && preg_match('/^[0-9a-f]{64}$/', $pubkey) === 1 ? $pubkey : null;
            $people[$role] = [
                'role' => $role,
                'name' => StreamBotCopy::clean($side['name'], self::NAME_LENGTH),
                'pubkey' => $pubkey,
                // Two people at most, below PrideNotes::LOBBY_MENTIONS and VoiceMentions::MAX.
                'mentionable' => $pubkey !== null && $this->spam->mentionAllowed(SpamGate::PROFILE, $pubkey, self::MOMENT, $now),
            ];
        }

        return $people;
    }

    /**
     * The league voice's note, null to take the template.
     *
     * @param  array<string, mixed>  $recap
     * @param  array<string, array{role: string, name: string, pubkey: string|null, mentionable: bool}>  $people
     * @return array{content: string, tags: list<list<string>>, voiced: bool, canon: string|null, mentioned: list<string>, skip: string|null}|null
     */
    private function voiced(array $recap, array $people, string $url, CarbonImmutable $now): ?array
    {
        $line = $this->voice->say(self::MOMENT, self::voiceFacts($recap, $people, $url), self::CHANNEL);

        if ($line === null) {
            return null;
        }

        $body = str_contains($line->text, $url) ? $line->text : $line->text."\n👉 ".$url;

        if (StreamBotCopy::violations($body, $line->tags) !== [] || $this->spam->textSkip(SpamGate::PROFILE, $line->text, $now, $line->canonId) !== null) {
            return null;
        }

        $mentioned = array_values(array_map(fn (array $tag): string => $tag[1], array_filter($line->tags, fn (array $tag): bool => ($tag[0] ?? null) === 'p')));

        return ['content' => $body, 'tags' => $line->tags, 'voiced' => true, 'canon' => $line->canonId, 'mentioned' => $mentioned, 'skip' => null];
    }

    /**
     * The template's note: its lines whose values are all there.
     *
     * @param  array<string, mixed>  $recap
     * @param  array<string, array{role: string, name: string, pubkey: string|null, mentionable: bool}>  $people
     * @return array{content: string, tags: list<list<string>>, voiced: bool, canon: string|null, mentioned: list<string>, skip: string|null}
     */
    private function template(Tournament $tournament, array $recap, array $people, string $url, int $variant, CarbonImmutable $now): array
    {
        $tags = [];
        $upset = $recap['upsets'][0] ?? null;
        $closest = $recap['closest'];
        // The same person is mentioned wherever the note names them, never once by key and once by name.
        $roleOf = [];
        foreach (['champion' => $recap['champion'], 'upset_winner' => $upset['winner'] ?? null] as $role => $side) {
            if ($side !== null && ! isset($roleOf[$side['id']])) {
                $roleOf[$side['id']] = $role;
            }
        }

        $named = function (?array $side) use ($people, $roleOf, &$tags): ?string {
            if ($side === null) {
                return null;
            }

            $person = $people[$roleOf[$side['id']] ?? ''] ?? null;

            if ($person !== null && $person['mentionable'] && $person['pubkey'] !== null) {
                if (! in_array(['p', $person['pubkey']], $tags, true)) {
                    $tags[] = ['p', $person['pubkey']];
                }

                return 'nostr:'.NostrKeys::hexToNpub($person['pubkey']);
            }

            return StreamBotCopy::clean($side['name'], self::NAME_LENGTH);
        };

        $values = [
            'champion' => $named($recap['champion']),
            'name' => StreamBotCopy::resultName($tournament->title(), self::NAME_LENGTH),
            'game' => $this->notes->gameLine($tournament),
            'upset_winner' => $named($upset['winner'] ?? null),
            'upset_winner_elo' => $upset === null ? null : (string) $upset['winner']['rating'],
            'upset_loser' => $named($upset['loser'] ?? null),
            'upset_loser_elo' => $upset === null ? null : (string) $upset['loser']['rating'],
            'upset_gap' => $upset === null ? null : (string) $upset['gap'],
            'upset_score' => $upset['score'] ?? null,
            'close_winner' => $named($closest['winner'] ?? null),
            'close_loser' => $named($closest['loser'] ?? null),
            'close_score' => $closest['score'] ?? null,
            'url' => $url,
        ];

        $lines = [];

        foreach (self::TEMPLATES[$variant % count(self::TEMPLATES)] as $line) {
            preg_match_all('/:([a-z_]+)/', $line, $matches);
            $replace = [];

            foreach ($matches[1] as $name) {
                if (($values[$name] ?? null) === null || $values[$name] === '') {
                    continue 2;
                }

                $replace[':'.$name] = $values[$name];
            }

            // Longest placeholder first: `:upset_winner_elo` before `:upset_winner`.
            uksort($replace, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $lines[] = strtr($line, $replace);
        }

        $body = implode("\n", $lines);
        $problems = StreamBotCopy::violations($body, $tags);

        if ($problems !== []) {
            throw new LogicException('The stream bot refused its own recap note: '.implode(', ', $problems));
        }

        return [
            'content' => $body,
            'tags' => $tags,
            'voiced' => false,
            'canon' => null,
            'mentioned' => array_map(fn (array $tag): string => $tag[1], $tags),
            'skip' => $this->spam->textSkip(SpamGate::PROFILE, $body, $now, template: true),
        ];
    }

    /**
     * Claim, compose and sign once, send; a line for the log.
     *
     * @param  list<string>  $relays
     */
    private function post(LeagueKey $key, Tournament $tournament, array $relays, CarbonImmutable $now): string
    {
        $subject = ['subject_type' => self::SUBJECT, 'subject_id' => $tournament->id, 'kind' => self::KIND_NOTE];

        BotPost::query()->insertOrIgnore([...$subject, 'created_at' => $now, 'updated_at' => $now]);

        // The claim: only one run gets the row, and only when nobody tried within retry_minutes.
        $claimed = BotPost::query()->where($subject)->whereNull('published_at')
            ->where(fn ($query) => $query->whereNull('attempted_at')->orWhere('attempted_at', '<=', $this->claimCutoff($now)))
            ->update(['attempted_at' => $now, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return 'tournament '.$tournament->id.': recap note taken by another run';
        }

        $post = BotPost::query()->where($subject)->firstOrFail();
        $note = null;

        // Signed once; a retry sends the stored event again, never a new one.
        if ($post->event === null) {
            $variant = ProfileNotes::nextVariant(self::NOTE_TYPE, count(self::TEMPLATES));
            $note = $this->compose($tournament, $variant, $now) ?? throw new LogicException('Tournament '.$tournament->id.' has no recap.');

            if ($note['skip'] !== null) {
                // Held by the spam gate: the claim is released, a later run tries again.
                $post->forceFill(['attempted_at' => null])->save();

                return 'tournament '.$tournament->id.': recap held by the spam gate, '.$note['skip'];
            }

            $signed = $key->sign(self::KIND_NOTE, $note['tags'], $note['content'], $now->getTimestamp());
            $post->forceFill([
                'event_id' => $signed->id,
                'event' => json_encode($signed->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'note_type' => self::NOTE_TYPE,
                'variant' => $variant,
            ])->save();
        }

        $event = SignedEvent::fromInput(json_decode((string) $post->event, true))
            ?? throw new LogicException('The stored recap note of tournament '.$tournament->id.' is not a valid event.');
        $results = $this->publisher->publish($event->toArray(), $relays);
        $accepted = count(array_filter($results, fn ($result): bool => $result->accepted));
        $post->forceFill(['relays_accepted' => $accepted, 'relays_total' => count($results), 'published_at' => $accepted > 0 ? $now : null])->save();

        if ($accepted > 0) {
            $this->spam->record(SpamGate::PROFILE, explode("\n\nnostr:", $event->content, 2)[0], self::MOMENT, ['tournament:'.$tournament->id], $now,
                $note['canon'] ?? null, null, $note['mentioned'] ?? array_values(array_map(fn (array $tag): string => $tag[1], array_filter($event->tags, fn (array $tag): bool => ($tag[0] ?? null) === 'p'))));
        }

        Log::info('Stream bot recap note', [
            'tournament' => $tournament->id,
            'id' => $event->id,
            'voiced' => $note['voiced'] ?? null,
            'relays' => array_map(fn ($result): string => $result->accepted ? 'ok' : 'failed: '.$result->message, $results),
        ]);

        return sprintf('tournament %d: recap %s id=%s to %d/%d relays', $tournament->id, $accepted > 0 ? 'posted' : 'not accepted, retried later', $event->id, $accepted, count($results));
    }

    /** A claim older than this is released: its run failed or crashed. */
    private function claimCutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMinutes(max(1, (int) config('esports.recap.notes.retry_minutes', 10)));
    }
}
