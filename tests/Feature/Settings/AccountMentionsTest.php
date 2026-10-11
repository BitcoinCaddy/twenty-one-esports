<?php

use App\Models\User;
use Livewire\Livewire;

/*
 * The mention switch on the Account tab (plan "KI-Stimme der Liga", P2):
 * whether the league's bot may tag the player on Nostr. On by default, as
 * every bot post did so far; off, the bot names the player without a tag
 * (BotVoice, covered in tests/Feature/Voice and the StreamBot notes).
 */

test('a new player allows mentions, and the switch shows it', function () {
    $user = User::factory()->create();

    expect($user->refresh()->allowsMentions())->toBeTrue();

    Livewire::actingAs($user)->test('pages::settings.account')
        ->assertSet('mentions', true)
        ->assertSeeHtml('data-test="account-mentions-switch"')
        ->assertSee('Mention me on Nostr');
});

test('switching mentions off saves with the form, and on again', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.account')
        ->set('mentions', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('account-saved');

    expect($user->refresh()->nostr_mentions)->toBeFalse()
        ->and($user->allowsMentions())->toBeFalse();

    Livewire::actingAs($user)->test('pages::settings.account')
        ->assertSet('mentions', false)
        ->set('mentions', true)
        ->call('save');

    expect($user->refresh()->allowsMentions())->toBeTrue();
});

test('the switch speaks German on a German page', function () {
    $user = User::factory()->create(['locale' => 'de']);
    app()->setLocale('de');

    Livewire::actingAs($user)->test('pages::settings.account')
        ->assertSee('Auf Nostr erwähnen')
        ->assertSee('ohne Markierung')
        ->call('$refresh')
        ->assertOk();
});
