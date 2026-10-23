<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Support\Prizes\PrizeChips;
use App\Support\Prizes\PrizePool;
use App\Support\Prizes\WalletPrizePool;

/*
| Anonymous top-ups of a pot show on the tournament page (user, 2026-10-04): whoever paid without a Nostr key sees
| their sats arrived; a zap with a key stays on the zap wall instead. They add on top of the pot as set, like a zap
| (user, 2026-10-23: a 2 100 sats top-up of tournament 36 did not, and its organizer raised the target by hand).
*/

/** A payment into the pot of `$tournament`. */
function potPayment(Tournament $tournament, array $values): IncomingPayment
{
    return IncomingPayment::query()->create([
        'pot' => $tournament->potAccount(), 'tournament_id' => $tournament->id, 'payment_hash' => bin2hex(random_bytes(32)),
        'bolt11' => 'lnbc1test', 'late' => false, 'expires_at' => now()->addHour(), ...$values,
    ]);
}

test('an anonymous top-up shows in the pot, a pending one and a zap do not', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    potPayment($tournament, ['source' => 'topup', 'amount_sats' => 2100, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now()]);
    potPayment($tournament, ['source' => 'topup', 'amount_sats' => 500, 'status' => IncomingPaymentStatus::Pending]);
    potPayment($tournament, ['source' => 'zap', 'amount_sats' => 210, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now(), 'payer_pubkey' => str_repeat('a', 64), 'zap_verified' => true]);

    $pool = app(WalletPrizePool::class)->for($tournament->refresh());

    expect(array_column($pool['deposits'], 'sats'))->toBe([2100]);
});

test('an anonymous top-up adds on top of the target like a zap, a late one does not', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $tournament->forceFill(['prize_target_sats' => 14_700])->save();
    potPayment($tournament, ['source' => 'topup', 'amount_sats' => 2100, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now()]);
    potPayment($tournament, ['source' => 'zap', 'amount_sats' => 2100, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now(), 'payer_pubkey' => str_repeat('a', 64), 'zap_verified' => true]);
    potPayment($tournament, ['source' => 'topup', 'amount_sats' => 999, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now(), 'late' => true]);
    $tournament->refresh();

    expect(app(PrizePool::class)->potSats($tournament))->toBe(18_900)
        ->and(app(WalletPrizePool::class)->for($tournament))->toMatchArray(['sats' => 18_900, 'base' => 14_700, 'zaps' => 4_200])
        ->and(PrizeChips::of($tournament)['sats'] ?? null)->toBe(18_900);
});

test('fixed prizes are not funded by top-ups: they split on top', function () {
    $league = fakeWallet();
    $short = finishedPoolTournament($league, 0, 2, fixed: [60_000, 30_000]);
    $short->forceFill(['pool_closed_at' => null])->save();
    fundPool($league, $short, 95_000, topUp: true);
    $pool = app(PrizePool::class);

    expect($pool->potSats($short->refresh()))->toBe(185_000)
        ->and(PrizePool::shortfall($short, $pool->fundedSats($short), $pool->onTopSats($short)))->toBe(90_900)
        ->and(array_column($pool->projection($short), 'sats'))->toBe([123_333, 61_666]);
});
