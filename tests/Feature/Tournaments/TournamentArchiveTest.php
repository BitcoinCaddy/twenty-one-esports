<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Tournaments\TournamentChampion;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The tournament archive and the product findings of P6 (plan "Refactor und Design-Revamp")
|--------------------------------------------------------------------------
|
| Finished and called-off tournaments moved off the tournaments page onto /tournaments/archive (Archive.dc.html):
| the Hall of Fame with the champions, every finished tournament with its winner, the called-off ones receded.
| Findings from the design audit: one public name per player (the payouts showed the account name beside the
| bracket's entry name), a payout place that differs from the bracket says why, no fee sentence on the public page,
| and the sign-up button says "Eintragen".
|
*/

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** A four-player chess knockout played out by the better seeds, published: "Player 1" wins. */
function archivedTournament(string $name = 'Autumn Final'): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);
    $tournament->forceFill(['name' => $name, 'published_at' => now()->subDays(2), 'created_by_id' => organizer()->id])->save();

    return $tournament->refresh();
}

test('the archive lists the champions, every finished tournament with its winner and the called-off ones, the tournaments page none of them', function () {
    $finished = archivedTournament();
    $cancelled = Tournament::factory()->create(['name' => 'Rainy Cup', 'status' => TournamentStatus::Cancelled, 'published_at' => now()->subDay(), 'slug' => 'rainy-cup']);
    $draft = Tournament::factory()->create(['name' => 'Secret Draft', 'status' => TournamentStatus::Finished, 'slug' => 'secret-draft']);

    $html = $this->get(route('tournaments.archive'))->assertOk()
        ->assertSeeInOrder(['data-test="hall-of-fame"', 'Autumn Final', 'Player 1', 'data-test="archive-finished"', 'Autumn Final', 'data-test="archive-champion"', 'Player 1', 'data-test="archive-cancelled"', 'Rainy Cup'], false)
        ->getContent();

    expect($html)->not->toContain('Secret Draft')
        ->and($cancelled->refresh()->status)->toBe(TournamentStatus::Cancelled);
    $this->get(route('tournaments.index'))->assertOk()->assertDontSee('Autumn Final')->assertDontSee('Rainy Cup')
        ->assertSeeHtml('href="'.route('tournaments.archive').'"');
    expect($draft->published_at)->toBeNull();
});

test('the archive narrows to one game, pages the finished list, and survives a Livewire roundtrip', function () {
    archivedTournament();
    Tournament::factory()->rocketLeague()->create(['name' => 'RL Done', 'status' => TournamentStatus::Finished, 'published_at' => now(), 'slug' => 'rl-done']);

    $this->get(route('tournaments.archive', ['game' => 'rocket-league']))->assertOk()->assertSee('RL Done')->assertDontSee('Autumn Final');
    $this->get(route('tournaments.archive', ['game' => 'not-a-game']))->assertOk()->assertSee('RL Done')->assertSee('Autumn Final');

    Livewire::test('pages::tournaments.archive')->call('$refresh')->assertOk()->assertSee('Autumn Final');
});

test('a payout names the entry as the bracket does, never the account name stored beside it', function () {
    $tournament = archivedTournament();
    $champion = app(TournamentChampion::class)->of($tournament);
    $tournament->forceFill(['pool_opened_at' => now()->subDays(3), 'pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 21_000, 'payouts_approved_at' => now()])->save();
    TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $champion->user_id, 'participant_id' => $champion->id, 'pubkey' => $champion->user->pubkey,
        'name' => 'AccountNameNotPublic', 'place' => 1, 'amount_sats' => 10_500, 'idempotency_key' => 'archive-1', 'status' => PayoutStatus::Paid]);

    $html = $this->get(route('tournaments.show', $tournament))->assertOk()->getContent();

    expect($html)->toContain('data-test="payout-name">Player 1')
        ->not->toContain('AccountNameNotPublic')
        // The public page names no fee (rule: no fee mentions).
        ->not->toContain('routing fees');
});

test('a payout whose place is not the bracket\'s says the player moved up because the one ahead did not play', function () {
    $tournament = archivedTournament();
    $placements = app(TournamentPlacements::class)->of($tournament);
    $champion = app(TournamentChampion::class)->of($tournament);
    // The final was won because the other finalist withdrew: they win nothing and everyone behind moves up (PayoutPlan).
    $runnerUp = TournamentParticipant::query()->findOrFail($placements[1]['participants'][0]);
    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots')->get()
        ->first(fn (TournamentMatch $match): bool => $match->slots->pluck('tournament_participant_id')->sort()->values()->all() === collect([$champion->id, $runnerUp->id])->sort()->values()->all());
    $final->forceFill(['result' => [...$final->result, 'decided' => 'withdrawn']])->save();
    $third = TournamentParticipant::query()->findOrFail($placements[2]['participants'][0]);

    $tournament->forceFill(['pool_opened_at' => now()->subDays(3), 'pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 21_000, 'payouts_approved_at' => now()])->save();
    TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $third->user_id, 'participant_id' => $third->id, 'pubkey' => User::query()->find($third->user_id)->pubkey,
        'name' => $third->name, 'place' => 2, 'amount_sats' => 7_000, 'idempotency_key' => 'archive-2', 'status' => PayoutStatus::Forwarded]);

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSee('Place 3 in the bracket, moved up: '.$runnerUp->name.' did not play and win nothing.');
});

test('the sign-up button says "Eintragen" in German', function () {
    $tournament = openTournament();
    [$player] = keyedPlayer();

    Livewire::actingAs($player)->withQueryParams(['lang' => 'de'])->test('pages::tournaments.signup', ['tournament' => $tournament])->assertOk();
    $this->actingAs($player)->get(route('tournaments.signup', ['tournament' => $tournament, 'lang' => 'de']))->assertOk()
        ->assertSee('Eintragen')->assertDontSee('Anmeldung bestätigen');
});
