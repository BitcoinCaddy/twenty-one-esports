<?php

use App\Enums\PayoutStatus;
use App\Support\Cards\ShareCard;
use App\Support\Payouts\PayoutApproval;
use App\Support\Prizes\PrizePool;
use App\Support\Wallet\Ledger;
use Livewire\Livewire;

/*
| A player passes a prize on to the reserve for the next pot (user, 2026-10-04): it stays in the split, is never
| paid, the reserve gets the sats, and the tournament page says so.
*/

test('an admin passes a prize on: never paid, the reserve gets it, the tournament page shows it', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);
    $admin = anAdmin();
    app(PayoutApproval::class)->approve($tournament, $admin);
    $second = $tournament->payouts()->where('place', 2)->sole();
    $reserve = app(Ledger::class)->balance(Ledger::RESERVE);

    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->call('forward', $second->id)->assertSee('passed');

    expect($second->refresh()->status)->toBe(PayoutStatus::Forwarded)
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe($reserve + $second->amount_sats)
        ->and($wallet->paid)->toBeEmpty();

    // Passing on twice changes nothing; a forwarded prize is never paid.
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->call('forward', $second->id)->call('pay', $second->id);
    expect(app(Ledger::class)->balance(Ledger::RESERVE))->toBe($reserve + $second->amount_sats)
        ->and($second->refresh()->status)->toBe(PayoutStatus::Forwarded);

    Livewire::test('tournament-pool', ['tournament' => $tournament])->assertSeeHtml('data-test="payout-forwarded"');
});

test('every ledger booking reason fits the reason column (varchar 24 on PostgreSQL; SQLite does not check)', function () {
    preg_match_all("/book\\([^;]*?'([a-z_]+)'/s", (string) file_get_contents(app_path('Support/Wallet/Ledger.php')), $reasons);
    preg_match_all("/const [A-Z_]+ = '([a-z_]+)';/", (string) file_get_contents(app_path('Support/Wallet/Ledger.php')), $constants);

    foreach ([...$reasons[1], ...$constants[1]] as $reason) {
        expect(strlen($reason))->toBeLessThanOrEqual(24, "ledger reason {$reason} is longer than the column");
    }
});

test('a prize the champion passed on shows by the pot and in the hero, and is no longer to be won', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);
    $admin = anAdmin();
    app(PayoutApproval::class)->approve($tournament, $admin);
    $first = $tournament->payouts()->where('place', 1)->sole();
    $left = app(PrizePool::class)->remainingSats($tournament->refresh());

    // Tournament 36 (2026-10-23): the champion passed the pot on, the page still said all of it was to be won.
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->call('forward', $first->id);

    expect(app(PrizePool::class)->remainingSats($tournament->refresh()))->toBe($left - $first->amount_sats);
    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeHtml('data-test="pool-forwarded"')
        ->assertSee(__(':name passed :sats sats on to the next pot.', ['name' => $first->refresh()->publicName(), 'sats' => ShareCard::sats($first->amount_sats)]))
        ->assertSeeHtml('data-test="pool-forwarded-hero"')
        ->assertSeeHtml('data-test="champion-forwarded"');
});
