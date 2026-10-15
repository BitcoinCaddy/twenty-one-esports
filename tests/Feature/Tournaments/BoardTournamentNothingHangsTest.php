<?php

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\NostrEvent;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use App\Support\Tournaments\Engine\Standings;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\NoShowDisqualifier;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Nothing hangs, board games (plan "Mühle und Dame", P5 review)
|--------------------------------------------------------------------------
|
| The board games' own ways a players-mode tournament match could block,
| after tests/Feature/Tournaments/TournamentNothingHangsTest.php for chess:
| endless knockout draws (replayed with the colours swapped up to
| `drawn_replays`, then the higher seed advances; no Armageddon), both
| sides missing the first move (restarted once with the same colours, then
| the double no-show rule), and a Black who misses the first move after
| White's (loses by forfeit, unrated, attested as `forfeit` in a live season).
|
*/

beforeEach(function () {
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
});

/**
 * A running players-mode tournament of one board game, bracket stored and
 * synced; rated (the open ladder frozen) when a season is live.
 */
function hangsBoardTournament(string $game, TournamentFormat $format = TournamentFormat::SingleElimination, int $n = 2): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => $game, 'mode' => 'correspondence', 'format' => $format,
        'options' => FormatOptions::fromArray([], GameProfile::for($game, 'correspondence'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'board-hangs-'.fake()->unique()->numberBetween(1, 1_000_000),
        // Frozen as a publish would freeze it: the open ladder, or none (unrated).
        'ladder_address' => Ladders::address($game, 'correspondence'),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The one live board game of a tournament. */
function hangsBoardGame(Tournament $tournament): BoardGame
{
    return BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
}

/** Let the first-move deadline of `$game` pass and have the clock checked. */
function hangsMissFirstMove(BoardGame $game): BoardGame
{
    test()->travelTo(CarbonImmutable::createFromTimestampMs((int) $game->refresh()->deadline_ms)->addSecond());

    return app(BoardGameService::class)->checkClock($game)->refresh();
}

function hangsMatch(Tournament $tournament): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->with('slots.participant', 'round.stage', 'boardGame', 'tournament')->sole();
}

/* ---------- Endless knockout draws -------------------------------------------------------------------------- */

test('knockout board game: once the drawn replays are used up the higher seed advances instead of another game', function (int $replays) {
    config(['esports.tournaments.drawn_replays' => $replays]);
    $tournament = hangsBoardTournament(NineMensMorris::SLUG);
    $service = app(BoardGameService::class);
    $whites = [];

    foreach (range(1, $replays + 1) as $number) {
        $game = hangsBoardGame($tournament);
        $whites[] = $game->white_id;
        $service->offerDraw($game, $game->white);
        $service->acceptDraw($game->refresh(), $game->black);
    }

    $match = hangsMatch($tournament);

    expect(BoardGame::query()->count())->toBe($replays + 1)
        ->and(BoardGame::query()->where('status', BoardGameStatus::Active)->count())->toBe(0)
        ->and(TournamentMatchMaker::needsBoardGame($match))->toBeFalse()
        // Each replay swaps the colours.
        ->and($whites[0])->not->toBe($whites[1])
        ->and($match->slots[$match->result['winner']]->participant->seed)->toBe(1)
        ->and($match->result['decided'])->toBe('seed')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
})->with(['two draws (one replay)' => 1, 'three draws (the default two replays)' => 2]);

/* ---------- Both sides missing ------------------------------------------------------------------------------ */

test('White misses the first move: the board game restarts once with the same colours, then White loses the pairing by forfeit', function (TournamentFormat $format, Closure $check) {
    // User 2026-10-15: Black cannot move before White, so the miss is White's alone; the double no-show rule punished an opponent who was there.
    $tournament = hangsBoardTournament(Checkers::SLUG, $format);
    $first = hangsBoardGame($tournament);

    // A player cannot abort a tournament game.
    expect(fn () => app(BoardGameService::class)->abort($first, $first->black))->toThrow(BoardRuleViolation::class, 'tournament_game');

    hangsMissFirstMove($first);
    $second = hangsBoardGame($tournament);

    expect($first->refresh()->status)->toBe(BoardGameStatus::Aborted)
        ->and($second->tournament_game)->toBe(2)
        ->and($second->white_id)->toBe($first->white_id)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);

    hangsMissFirstMove($second);
    $match = hangsMatch($tournament);

    expect(BoardGame::query()->count())->toBe(2)
        ->and($second->refresh()->status)->toBe(BoardGameStatus::Aborted)
        // A run that saw the match before its result was stored starts no third game either.
        ->and(TournamentMatchMaker::needsBoardGame($match))->toBeFalse()
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    $whiteSlot = in_array((int) $first->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;
    expect($match->result)->toMatchArray(['winner' => 1 - $whiteSlot, 'forfeit' => true, 'decided' => 'noshow', 'noshow' => [$whiteSlot], 'by' => 'league'])
        ->and($match->result)->not->toHaveKey('double_loss');

    $check($tournament, $match, $whiteSlot);
})->with([
    'round robin: Black wins, White loses' => [TournamentFormat::RoundRobin, function (Tournament $tournament, TournamentMatch $match, int $whiteSlot) {
        $table = Standings::table($tournament->participants()->orderBy('seed')->pluck('id')->all(), app(TournamentRunner::class)->games($tournament));
        $points = collect($table)->mapWithKeys(fn ($row) => [$row->entrant => [$row->points, $row->losses]])->all();

        expect($points[$match->slots[1 - $whiteSlot]->tournament_participant_id])->toBe([1.0, 0])
            ->and($points[$match->slots[$whiteSlot]->tournament_participant_id])->toBe([0.0, 1]);
    }],
    'knockout: Black advances' => [TournamentFormat::SingleElimination, function (Tournament $tournament, TournamentMatch $match, int $whiteSlot) {
        expect($match->result['winner'])->toBe(1 - $whiteSlot);
    }],
]);

/* ---------- Black misses the first move after White's ------------------------------------------------------- */

test('a Black who misses the first move after White\'s loses by forfeit, unrated, and a live season attests it as a forfeit without a block', function () {
    $season = openSeason();
    publishLadders($season);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $tournament = hangsBoardTournament(NineMensMorris::SLUG);
    $game = hangsBoardGame($tournament);

    // Rated: the tournament froze the open ladder, and the gate passed at the pairing.
    expect($game->rated)->toBeTrue()
        ->and($game->ladder_address)->toBe($tournament->ladder_address);

    $game = app(BoardGameService::class)->move($game, $game->white, 'a1', 1);
    $game = hangsMissFirstMove($game);

    $match = hangsMatch($tournament);
    $attestation = SeasonAttestation::query()->sole();
    $tags = NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'];
    $whiteSlot = in_array($game->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->end_reason)->toBe(BoardEndReason::Forfeit->value)
        ->and($game->result)->toBe('1-0')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($match->result['winner'])->toBe($whiteSlot)
        ->and($match->result['forfeit'])->toBeTrue()
        ->and($attestation->only(['source', 'source_id', 'candidate', 'height']))->toBe(['source' => SeasonAttestation::BOARD, 'source_id' => $game->id, 'candidate' => null, 'height' => null])
        ->and($tags)->toContain(['resolution', 'forfeit'], ['winner', 'challenger'], ['a', $tournament->ladder_address, ''])
        ->and(collect($tags)->where(0, 'elo')->all())->toBe([])
        // Tournament games never mine: no block tag at all.
        ->and(collect($tags)->where(0, 'block')->all())->toBe([])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($season->refresh()->attestations()->count())->toBe(1);
});

/* ---------- Repeated no-shows ------------------------------------------------------------------------------- */

test('an entry that lost two matches by not showing up is disqualified by the league: its open games go to the opponents, the log names the league', function () {
    // User 2026-10-15: "haben wir ein Auto-Disqualify, damit das Turnier dann auch mal weitergehen kann?"
    $tournament = hangsBoardTournament(Checkers::SLUG, TournamentFormat::RoundRobin, 4);
    $absent = $tournament->participants()->orderBy('id')->firstOrFail();
    $mine = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->whereHas('slots', fn ($slot) => $slot->where('tournament_participant_id', $absent->id))->with('slots')->orderBy('id')->get();
    $lose = function (TournamentMatch $match) use ($absent): void {
        $slot = $match->slots->firstWhere('tournament_participant_id', $absent->id)->slot;
        app(TournamentRunner::class)->store($match->refresh(), ['winner' => 1 - $slot, 'games_won' => $slot === 0 ? [0.0, 1.0] : [1.0, 0.0], 'points' => [],
            'forfeit' => true, 'decided' => 'noshow', 'noshow' => [$slot], 'label' => 'no first move: forfeit', 'by' => 'league']);
    };

    expect($mine)->toHaveCount(3);

    // One no-show: still in.
    $lose($mine[0]);
    expect(app(NoShowDisqualifier::class)->tick())->toBe(0)
        ->and($absent->refresh()->isDisqualified())->toBeFalse();

    // The second: out, and the third match goes to the opponent.
    $lose($mine[1]);
    expect(NoShowDisqualifier::counts($tournament))->toMatchArray([$absent->id => 2])
        ->and(app(NoShowDisqualifier::class)->tick())->toBe(1)
        ->and($absent->refresh()->isDisqualified())->toBeTrue()
        ->and($absent->disqualified_by_id)->toBeNull()
        ->and($absent->disqualification_reason)->toBe('Disqualified by the league: 2 matches lost by not showing up.');

    $third = $mine[2]->refresh();
    $slot = $third->slots->firstWhere('tournament_participant_id', $absent->id)->slot;
    expect($third->result['winner'])->toBe(1 - $slot)
        ->and($third->result['decided'])->toBe('disqualified')
        ->and(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->where('action', 'disqualified')->sole()->only(['user_id', 'user_name', 'subject']))
        ->toBe(['user_id' => null, 'user_name' => 'League', 'subject' => $absent->name])
        // Once out, never again; the disqualification's own forfeit does not count.
        ->and(app(NoShowDisqualifier::class)->tick())->toBe(0)
        ->and(NoShowDisqualifier::counts($tournament->refresh())[$absent->id])->toBe(2);
});

test('the no-show count reads the stored results: a named no-show, a double no-show, a lost forfeit; never a disqualification or withdrawal, and 0 switches it off', function () {
    expect(NoShowDisqualifier::noShowSlots(['forfeit' => true, 'winner' => 1, 'decided' => 'noshow', 'noshow' => [0]]))->toBe([0])
        ->and(NoShowDisqualifier::noShowSlots(['forfeit' => true, 'winner' => null, 'double_loss' => true, 'decided' => 'noshow']))->toBe([0, 1])
        ->and(NoShowDisqualifier::noShowSlots(['forfeit' => true, 'winner' => 0]))->toBe([1])
        ->and(NoShowDisqualifier::noShowSlots(['forfeit' => true, 'winner' => 0, 'decided' => 'disqualified']))->toBe([])
        ->and(NoShowDisqualifier::noShowSlots(['forfeit' => true, 'winner' => 1, 'decided' => 'withdrawn']))->toBe([])
        ->and(NoShowDisqualifier::noShowSlots(['forfeit' => false, 'winner' => 0]))->toBe([]);

    config(['esports.tournaments.auto_disqualify_noshows' => 0]);
    expect(app(NoShowDisqualifier::class)->tick())->toBe(0);
});
