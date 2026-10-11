<?php

test('switching to german renders home in german', function () {
    $this->from(route('login'))
        ->get(route('locale.switch', 'de'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('locale', 'de');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertSee('aria-label="Hauptnavigation"', false);
});

test('unsupported locales are rejected', function () {
    $this->get('/locale/fr')->assertNotFound();
});

test('a 404 of a missing model answers in the session language: SetLocale runs before route-model binding', function () {
    $this->withSession(['locale' => 'de'])
        ->get('/tournaments/999999')
        ->assertNotFound()
        ->assertSee('<html lang="de"', false)
        ->assertSee('Diese Seite gibt es nicht');
});

test('an unsupported locale in the session falls back to english', function () {
    $this->withSession(['locale' => 'fr'])
        ->get(route('home'))
        ->assertSee('<html lang="en"', false)
        ->assertSee('aria-label="Main navigation"', false);
});
