<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Tournaments\TournamentChampion;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The champion on a finished tournament page (user, 2026-10-03; board TournamentDetail.dc.html, P6 of plan
| "Refactor und Design-Revamp")
|--------------------------------------------------------------------------
|
| "Das mini Ding als Stolz-Moment für den Sieger??? NEINN!!!!! Das muss krasser werden!!!!" The accepted board puts
| the champion into the page's hero beside the pot and the seats, the button "Sieger zappen", and a glowing champion
| card at the end of the bracket with the prize and the record. The winner reads "You won!" and shares the win on
| Nostr with the champion card; a beaten player reads their place.
|
*/

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** A chess knockout of four played out by the better seeds: "Player 1" wins, "Player 2" is second. */
function championMomentTournament(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);
    $tournament->forceFill(['name' => 'Friday Blitz Cup', 'published_at' => now()])->save();

    return $tournament->refresh();
}

/** The hero's markup alone. */
function championHero(string $html): string
{
    $start = strpos($html, 'data-test="tournament-hero"');
    expect($start)->not->toBeFalse();
    $open = strrpos(substr($html, 0, $start), '<section');

    return substr($html, $open, strpos($html, '</section>', $start) - $open);
}

test('a visitor sees the champion in the hero, the champion card at the end of the bracket with the record, and the way to zap the winner', function () {
    $tournament = championMomentTournament();
    $champion = app(TournamentChampion::class)->of($tournament);
    $html = $this->get(route('tournaments.show', $tournament))->assertOk()->getContent();
    $hero = championHero($html);

    expect($champion->name)->toBe('Player 1')
        ->and($hero)->toContain('data-test="champion-hero"')
        ->toContain('data-test="champion-name">Player 1</b>')
        ->toContain('Champion')
        ->toContain('Friday Blitz Cup')
        ->toContain('href="#zap-winner"')
        ->not->toContain('You won!')
        ->not->toContain('data-test="champion-prize"')
        // The champion card closes the bracket: the name and the record (two rounds, two wins, no loss).
        ->and(str($html)->after('data-test="bracket-champion"')->before('</div>')->toString().str($html)->after('data-test="bracket-champion"')->limit(1200)->toString())->toContain('Player 1', '2 wins', 'no loss');
});

test('the winner reads "You won!" and shares the win on Nostr; a beaten player reads their place', function () {
    $tournament = championMomentTournament();
    $champion = app(TournamentChampion::class)->of($tournament);
    $winner = User::query()->findOrFail($champion->user_id);
    $hero = championHero($this->actingAs($winner)->get(route('tournaments.show', $tournament))->assertOk()->getContent());

    expect($hero)->toContain('You won!')
        ->toContain('data-viewer="won"')
        ->toContain('data-test="share-button" data-type="tournament"')
        ->toContain('Share the win');

    $second = User::query()->findOrFail($tournament->participants()->where('name', 'Player 2')->sole()->user_id);
    $other = championHero($this->actingAs($second)->get(route('tournaments.show', $tournament))->assertOk()->getContent());

    expect($other)->toContain('data-viewer="placed"')
        ->toContain('You finished in place 2')
        ->not->toContain('You won!')
        ->not->toContain('data-test="share-button"');
});

test('the prize is named in sats once the league paid it', function () {
    $tournament = championMomentTournament();
    $winner = app(TournamentChampion::class)->of($tournament)->user;
    $payout = TournamentPayout::query()->create([
        'tournament_id' => $tournament->id, 'pubkey' => $winner->pubkey, 'name' => 'Player 1', 'place' => 1, 'amount_sats' => 21000,
        'idempotency_key' => TournamentPayout::keyFor($tournament->id, $winner->pubkey, 1), 'status' => PayoutStatus::Open,
    ]);

    expect(championHero($this->get(route('tournaments.show', $tournament))->getContent()))->not->toContain('data-test="champion-prize"');

    $payout->forceFill(['status' => PayoutStatus::Paid])->save();

    expect(championHero($this->get(route('tournaments.show', $tournament))->getContent()))->toContain('data-test="champion-prize"')->toContain('21,000');
});

test('the champion survives a Livewire roundtrip, for the winner and a guest', function () {
    $tournament = championMomentTournament();
    $winner = User::query()->findOrFail(app(TournamentChampion::class)->of($tournament)->user_id);

    // The guest first: actingAs() stays for every later test() of this case.
    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('$refresh')->assertOk()->assertSeeHtml('data-test="champion-hero"');
    Livewire::actingAs($winner)->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('$refresh')->assertOk()->assertSeeHtml('data-test="champion-hero"')->assertSee('You won!');
});

test('the German page says it in German', function () {
    $tournament = championMomentTournament();
    $hero = championHero($this->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'de']))->assertOk()->getContent());

    expect($hero)->toContain('Champion')->toContain('Sieger zappen')->toContain('Beendet')->not->toContain('Zap the winner');
});
