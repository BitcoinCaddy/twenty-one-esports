<?php

use App\Support\Voice\VoiceHygiene;

/*
|--------------------------------------------------------------------------
| Text hygiene of the league voice (P1)
|--------------------------------------------------------------------------
|
| clean() repairs what is harmless; check() rejects artifacts and defects,
| each rule on its own.
|
*/

it('trims outer whitespace and collapses doubled spaces', function () {
    expect((new VoiceHygiene)->clean("  Satoshi   gewinnt 3:1.  \n", false, 'de'))->toBe('Satoshi gewinnt 3:1.');
});

it('removes quotes around the whole answer', function () {
    expect((new VoiceHygiene)->clean('"Satoshi gewinnt 3:1."', false, 'de'))->toBe('Satoshi gewinnt 3:1.');
});

it('collapses stray line breaks in a one-line channel', function () {
    expect((new VoiceHygiene)->clean("Satoshi gewinnt.\n\nStark gespielt.", false, 'de'))->toBe('Satoshi gewinnt. Stark gespielt.');
});

it('keeps line breaks in a multi-line channel', function () {
    expect((new VoiceHygiene)->clean("Satoshi gewinnt.\n\n\n\nStark gespielt.", true, 'de'))->toBe("Satoshi gewinnt.\n\nStark gespielt.");
});

it('turns straight quotes into German ones in a German post', function () {
    expect((new VoiceHygiene)->clean('Wie sagt man: "HODL" und weiter.', false, 'de'))->toBe('Wie sagt man: „HODL“ und weiter.');
});

it('accepts a clean German post', function () {
    expect((new VoiceHygiene)->check('Satoshi schlägt Hal 3:1. Stark gespielt!', 'de'))->toBe([]);
});

it('rejects a leftover placeholder', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->toContain(VoiceHygiene::PLACEHOLDER);
})->with(['{sieger} gewinnt.', 'Sieg für [[name]].', 'Sieg für <player>.', 'TODO: Sieger einsetzen.', 'PLACEHOLDER gewinnt.', 'Sieg für :winner heute.']);

it('rejects Markdown', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->toContain(VoiceHygiene::MARKDOWN);
})->with(['**Satoshi** gewinnt.', 'Satoshi __gewinnt__.', 'Satoshi `gewinnt`.', '- Satoshi gewinnt.']);

it('rejects a preamble or label', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->toContain(VoiceHygiene::PREAMBLE);
})->with(['Hier ist dein Post: Satoshi gewinnt.', 'Post: Satoshi gewinnt.', 'Text: Satoshi gewinnt.', 'Here is the post: Satoshi gewinnt.']);

it('rejects assistant talk', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->toContain(VoiceHygiene::META);
})->with(['Als KI kann ich das nicht bewerten.', 'Gerne! Satoshi gewinnt.', 'Natürlich! Satoshi gewinnt.', 'As an AI I would say Satoshi wins.', 'Ich kann nicht sagen, wer gewinnt.']);

it('rejects an English post on a German channel', function () {
    expect((new VoiceHygiene)->check('Satoshi wins the game and the crowd is going wild for it.', 'de'))->toContain(VoiceHygiene::WRONG_LANGUAGE);
});

it('accepts an English canon quote in a German post', function () {
    expect((new VoiceHygiene)->check('Satoshi gewinnt gegen Hal. There is no second best.', 'de', [], 'There is no second best'))->not->toContain(VoiceHygiene::WRONG_LANGUAGE);
});

it('rejects a cut-off text', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->toContain(VoiceHygiene::TRUNCATED);
})->with(['Satoshi schlägt Hal und dann', 'Satoshi schlägt Hal 3:1 und der Ch']);

it('rejects a text the model stopped at its token limit', function () {
    expect((new VoiceHygiene)->check('Satoshi schlägt Hal.', 'de', [], null, true))->toContain(VoiceHygiene::TRUNCATED);
});

it('accepts a text that ends with a link or an emoji', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->not->toContain(VoiceHygiene::TRUNCATED);
})->with(['Satoshi gewinnt, hier ist die Partie https://esports.einundzwanzig.space/matches/1', 'Satoshi gewinnt 🏆']);

it('rejects a doubled word', function () {
    expect((new VoiceHygiene)->check('Satoshi schlägt der der Favorit.', 'de'))->toContain(VoiceHygiene::REPEATED);
});

it('rejects the same clause twice', function () {
    expect((new VoiceHygiene)->check('Satoshi gewinnt heute. Satoshi gewinnt heute.', 'de'))->toContain(VoiceHygiene::REPEATED);
});

it('rejects unbalanced quotes or brackets', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->toContain(VoiceHygiene::UNBALANCED);
})->with(['Satoshi gewinnt (knapp.', 'Satoshi sagt „ja.', 'Satoshi gewinnt "knapp.']);

it('accepts balanced German and English quotes together', function () {
    expect((new VoiceHygiene)->check('Satoshi sagt „ja“ und Hal “no”.', 'de'))->not->toContain(VoiceHygiene::UNBALANCED);
});

it('rejects broken Unicode', function (string $text) {
    expect((new VoiceHygiene)->check($text, 'de'))->toBe([VoiceHygiene::BROKEN_UNICODE]);
})->with([
    'replacement character' => "Satoshi gew\u{FFFD}nnt.",
    'zero-width space' => "Satoshi\u{200B} gewinnt.",
    'invalid UTF-8' => "Satoshi gewinnt \xC3\x28.",
]);

it('accepts a zero-width joiner inside an emoji sequence', function () {
    expect((new VoiceHygiene)->check('Satoshi gewinnt 👨‍👩‍👧', 'de'))->not->toContain(VoiceHygiene::BROKEN_UNICODE);
});

it('rejects a near copy of a recent post', function () {
    expect((new VoiceHygiene)->check('Satoshi schlägt Hal 3:1. Stark gespielt!', 'de', ['Satoshi schlägt Hal 3:1. Stark gespielt.']))->toContain(VoiceHygiene::NEAR_COPY);
});

it('rejects more sentences than the channel allows', function () {
    expect((new VoiceHygiene)->check('Satoshi gewinnt. Hal verliert. Weiter geht es.', 'de', [], null, false, 2))->toContain(VoiceHygiene::TOO_MANY_SENTENCES);
});
