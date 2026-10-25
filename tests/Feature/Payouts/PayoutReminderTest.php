<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use Livewire\Livewire;

test('an admin reminds a winner without a Lightning address once an hour, in the bell (user 2026-10-25)', function () {
    $tournament = Tournament::factory()->create(['payouts_approved_at' => now(), 'pool_opened_at' => now()->subDay(), 'pot_source' => Tournament::POT_LEAGUE, 'status' => TournamentStatus::Finished]);
    $winner = User::factory()->create(['lud16' => null]);
    $payout = TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $winner->id, 'pubkey' => $winner->pubkey,
        'name' => 'Winner', 'place' => 1, 'amount_sats' => 85_764, 'idempotency_key' => 'remind-1', 'status' => PayoutStatus::Open, 'reason' => 'no_lud16']);
    $admin = anAdmin();

    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSeeHtml('data-test="remind-address"')
        ->assertSeeHtml('data-test="refresh-profiles"')
        ->call('remindAddress', $payout->id);

    expect($winner->notifications()->count())->toBe(1)
        ->and($page->get('notice'))->toContain('Winner');

    $page->call('remindAddress', $payout->id);

    expect($winner->notifications()->count())->toBe(1)
        ->and($page->get('notice'))->toBe('This winner was reminded within the last hour.');

    Livewire::actingAs(User::factory()->create())->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->assertForbidden();
});
