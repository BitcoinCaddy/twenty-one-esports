<?php

use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceGuard;

/*
|--------------------------------------------------------------------------
| The fact guard of the league voice (plan "KI-Stimme der Liga", P1)
|--------------------------------------------------------------------------
|
| Every rule on its own: an invented number, name or URL, a hashtag, an
| overlong text, two memes, an unknown canon ID, a misquoted canon wording,
| three emoji, a blocklisted word, an invented quote of a real person. A
| text that keeps to the facts passes.
|
*/

function voiceGuard(): VoiceGuard
{
    return new VoiceGuard(new VoiceCanon, (array) config('esports.voice.blocklist'), (array) config('esports.voice.persons'));
}

/**
 * @return array<string, mixed>
 */
function voiceGuardFacts(): array
{
    return ['winner' => 'Satoshi_Fan', 'loser' => 'Hal', 'score' => '3:1', 'game' => 'Chess', 'url' => 'https://esports.einundzwanzig.space/matches/42'];
}

it('lets a text through that keeps to the facts', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. Stark gespielt! https://esports.einundzwanzig.space/matches/42', voiceGuardFacts(), 280, null))->toBe([]);
});

it('lets a real canon wording through with its ID', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. Heute ist ein guter Tag um Sats zu stapeln.', voiceGuardFacts(), 280, 'e21-sats-stapeln'))->toBe([]);
});

it('rejects an invented number', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1, die fünfte Serie mit 7 Siegen.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::NUMBER);
});

it('accepts a fact number in another spelling', function () {
    expect(voiceGuard()->check('Der Topf steht bei 1.500 Sats.', ['pot' => 1500], 280, null))->toBe([]);
});

it('rejects an invented name', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1, Kevin schaut zu.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::NAME);
});

it('rejects an invented handle', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal_99 deutlich.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::NAME);
});

it('rejects an invented URL', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. https://esports.einundzwanzig.space/matches/43', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::URL);
});

it('rejects a hashtag', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. #bitcoin', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::HASHTAG);
});

it('rejects a text over the channel length', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. '.str_repeat('Stark gespielt. ', 20), voiceGuardFacts(), 120, null))->toContain(VoiceGuard::TOO_LONG);
});

it('rejects a second meme next to the declared one', function () {
    $text = 'Heute ist ein guter Tag um Sats zu stapeln. Das Auto wird sich nicht durchsetzen, hoch lebe das Pferd!';
    $canon = new VoiceCanon;
    expect($canon->find('e21-auto-pferd'))->not->toBeNull()
        ->and(voiceGuard()->check($text, voiceGuardFacts(), 280, 'e21-sats-stapeln'))->toContain(VoiceGuard::CANON_SECOND);
});

it('rejects a meme without its canon ID', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. Heute ist ein guter Tag um Sats zu stapeln.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::CANON_WITHOUT_ID);
});

it('rejects an unknown canon ID', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1.', voiceGuardFacts(), 280, 'e21-erfunden'))->toContain(VoiceGuard::CANON_UNKNOWN);
});

it('rejects a misquoted canon wording', function () {
    // The wording is "Heute ist ein guter Tag um Sats zu stapeln": four words of it in a row without the rest.
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. Heute ist ein guter Abend für Hal.', voiceGuardFacts(), 280, 'e21-sats-stapeln'))->toContain(VoiceGuard::CANON_MISQUOTED);
});

it('rejects a quote that is not the canon wording', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. „Heute stapeln wir Siege“', voiceGuardFacts(), 280, 'e21-sats-stapeln'))->toContain(VoiceGuard::CANON_MISQUOTED);
});

it('rejects three emoji', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1 🏆⚡🔥', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::EMOJI);
});

it('counts an emoji sequence once', function () {
    expect(voiceGuard()->countEmoji('👨‍👩‍👧 🏳️‍🌈'))->toBe(2);
});

it('rejects a blocklisted word', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1, was für ein Depp.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::BLOCKLIST);
});

it('rejects a fee mention', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1, ganz ohne Startgeld.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::BLOCKLIST);
});

it('rejects a sentence put into a real person\'s mouth', function () {
    expect(voiceGuard()->check('Markus Turm sagt: Starker Sieg von Satoshi_Fan.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::PERSON);
});

it('rejects words put into a player\'s mouth without quotation marks', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1. Lag nicht an mir, sagt der Sieger.', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::ATTRIBUTION);
});

it('rejects an invented quote', function () {
    expect(voiceGuard()->check('Satoshi_Fan schlägt Hal 3:1: „Das war mein bester Zug“', voiceGuardFacts(), 280, null))->toContain(VoiceGuard::QUOTE);
});

it('accepts a quoted fact such as a tournament name', function () {
    expect(voiceGuard()->check('Satoshi_Fan gewinnt „Herbst Cup“.', ['winner' => 'Satoshi_Fan', 'tournament' => 'Herbst Cup'], 280, null))->toBe([]);
});

it('takes a fact name in another form for that fact, not an invented name', function (string $text) {
    expect(voiceGuard()->check($text, ['winner' => 'Müller', 'loser' => 'Ada'], 280, null))->not->toContain(VoiceGuard::NAME);
})->with([
    'without diacritics' => 'Muller schlägt Ada.',
    'German possessive' => 'Adas Lauf war stark.',
    'English possessive' => "Ada's run was strong.",
]);

it('still takes an unknown capitalised word for an invented name beside a fact name', function () {
    expect(voiceGuard()->check('Muller schlägt Kevin.', ['winner' => 'Müller', 'loser' => 'Ada'], 280, null))->toContain(VoiceGuard::NAME)
        ->and(VoiceGuard::nameKey("Müller's"))->toBe('muller')
        ->and(VoiceGuard::nameKey('Adas'))->toBe('ada');
});

it('rejects an email or Lightning address', function () {
    expect(voiceGuard()->check('Zaps an pool@esports.einundzwanzig.space', [], 280, null))->toContain(VoiceGuard::ADDRESS);
});
