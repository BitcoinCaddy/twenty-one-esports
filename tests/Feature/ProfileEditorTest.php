<?php

use App\Models\User;
use App\Support\Tournaments\TournamentChampion;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
| The player's own Nostr profile (user 2026-10-25): Google sign-ins had no profile and no Lightning address, so a
| prize waited. The page checks the address like a payout and returns the kind 0 template; the player's signer signs.
*/

test('the profile page merges name and a payable Lightning address into the old profile and caches the signed result', function () {
    fakeLightningAddresses(fakeWallet());
    $signer = new TestSigner;
    $player = User::factory()->withPubkey($signer->pubkey)->create(['name' => null, 'lud16' => null]);
    $page = Livewire::actingAs($player)->test('pages::settings.profile')->assertOk()->assertSeeHtml('data-test="profile-wallets"');

    $template = $page->instance()->prepareProfile(' Luke ', 'Luke@wallet.example', (string) json_encode(['about' => 'Plays blitz', 'picture' => 'https://example.com/p.png']));
    $content = json_decode($template['content'], true);

    expect($template['kind'])->toBe(0)
        ->and($content)->toMatchArray(['name' => 'Luke', 'display_name' => 'Luke', 'lud16' => 'luke@wallet.example', 'about' => 'Plays blitz', 'picture' => 'https://example.com/p.png']);

    $signed = $signer->sign(0, content: $template['content'], createdAt: $template['created_at']);

    expect($page->instance()->submitProfile((string) json_encode($signed)))->toBeTrue()
        ->and($player->refresh()->lud16)->toBe('luke@wallet.example')
        ->and($player->name)->toBe('Luke');
});

test('the profile page refuses a malformed or unreachable Lightning address and a missing name', function () {
    fakeLightningAddresses(fakeWallet(), broken: ['down']);
    $player = User::factory()->create();
    $page = Livewire::actingAs($player)->test('pages::settings.profile');

    expect($page->instance()->prepareProfile('', 'a@wallet.example', ''))->toBeNull()
        ->and($page->instance()->error)->toBe('Pick a name of 1 to 50 characters.')
        ->and($page->instance()->prepareProfile('Luke', 'not an address', ''))->toBeNull()
        ->and($page->instance()->error)->toContain('looks like an email address')
        ->and($page->instance()->prepareProfile('Luke', 'down@wallet.example', ''))->toBeNull()
        ->and($page->instance()->error)->toContain('did not answer');
});

test('a guest cannot open the profile page, and a player without a Lightning address sees the way there on their own page', function () {
    $this->get(route('settings.profile'))->assertRedirect();
    $player = User::factory()->create(['lud16' => null]);

    $this->actingAs($player)->get(route('players.show', $player->npub))->assertOk()->assertSee('data-test="player-profile-missing"', false);
    $this->actingAs(User::factory()->create())->get(route('players.show', $player->npub))->assertOk()->assertDontSee('data-test="player-profile-missing"', false);
});

test('a prize winner without a Lightning address is led to the profile page from the finished tournament (user 2026-10-25)', function () {
    $tournament = finishedPoolTournament(fakeWallet(), withoutAddress: [1]);
    $champion = app(TournamentChampion::class)->of($tournament);
    $user = User::query()->findOrFail($champion->user_id);
    $user->forceFill(['lud16' => null])->save();

    $hero = $this->actingAs($user)->get(route('tournaments.show', $tournament))->assertOk()->getContent();

    expect($hero)->toContain('data-test="prize-needs-address"')->toContain('href="'.route('settings.profile').'"')->toContain('Add my Lightning address');

    $user->forceFill(['lud16' => 'champ@wallet.example'])->save();

    expect($this->actingAs($user)->get(route('tournaments.show', $tournament))->getContent())->not->toContain('data-test="prize-needs-address"');
});
