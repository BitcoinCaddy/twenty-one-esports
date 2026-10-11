<?php

use App\Support\Nostr\NostrKeys;
use App\Support\Voice\VoiceMentions;

/*
|--------------------------------------------------------------------------
| Mentions in voice posts (NIP-27, P1)
|--------------------------------------------------------------------------
|
| The model writes role tokens ({@winner}); the app turns them into
| `nostr:npub1…` with one `p` tag per person. Each rule on its own.
|
*/

/**
 * @return array<string, mixed>
 */
function voiceMentionFacts(bool $loserMentionable = true): array
{
    return ['people' => [
        ['role' => 'winner', 'name' => 'Satoshi', 'pubkey' => str_repeat('a', 64), 'mentionable' => true],
        ['role' => 'loser', 'name' => 'Hal', 'pubkey' => str_repeat('b', 64), 'mentionable' => $loserMentionable],
        ['role' => 'opponent', 'name' => 'Nick', 'pubkey' => str_repeat('c', 64), 'mentionable' => true],
        ['role' => 'zapper', 'name' => 'Adam', 'pubkey' => str_repeat('d', 64), 'mentionable' => true],
    ]];
}

it('turns role tokens into npub mentions with one p tag each', function () {
    [$text, $tags] = (new VoiceMentions)->resolve('{@winner} schlägt {@loser}.', voiceMentionFacts());

    expect($text)->toBe('nostr:'.NostrKeys::hexToNpub(str_repeat('a', 64)).' schlägt nostr:'.NostrKeys::hexToNpub(str_repeat('b', 64)).'.')
        ->and($tags)->toBe([['p', str_repeat('a', 64)], ['p', str_repeat('b', 64)]]);
});

it('shows the guard plain names in place of the tokens', function () {
    expect((new VoiceMentions)->withNames('{@winner} schlägt {@loser}.', voiceMentionFacts()))->toBe('Satoshi schlägt Hal.');
});

it('gives the model names and tokens but never a key', function () {
    $forModel = VoiceMentions::forModel(voiceMentionFacts(false));

    expect(json_encode($forModel))->not->toContain(str_repeat('a', 64))
        ->and($forModel['people'][0])->toBe(['role' => 'winner', 'name' => 'Satoshi', 'mention' => '{@winner}'])
        ->and($forModel['people'][1]['mention'])->toBeNull();
});

it('accepts tokens of people in the facts', function () {
    expect((new VoiceMentions)->check('{@winner} schlägt {@loser}.', voiceMentionFacts()))->toBe([]);
});

it('rejects a token of nobody in the facts', function () {
    expect((new VoiceMentions)->check('{@champion} jubelt.', voiceMentionFacts()))->toContain(VoiceMentions::UNKNOWN);
});

it('rejects a raw npub, nostr URI or hex key written by the model', function (string $text) {
    expect((new VoiceMentions)->check($text, voiceMentionFacts()))->toContain(VoiceMentions::RAW);
})->with([
    'npub' => 'Glückwunsch npub1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqsxyz12!',
    'nostr URI' => 'Glückwunsch nostr:someone!',
    'hex' => 'Glückwunsch '.str_repeat('e', 64).'!',
]);

it('rejects a mention of a person whose mentions are off', function () {
    expect((new VoiceMentions)->check('{@loser} verliert.', voiceMentionFacts(false)))->toContain(VoiceMentions::NOT_ALLOWED);
});

it('rejects a person without a valid key as a mention', function () {
    $facts = ['people' => [['role' => 'winner', 'name' => 'Satoshi', 'pubkey' => 'npub-not-hex', 'mentionable' => true]]];

    expect((new VoiceMentions)->check('{@winner} gewinnt.', $facts))->toContain(VoiceMentions::NOT_ALLOWED);
});

it('rejects more than three mentions', function () {
    expect((new VoiceMentions)->check('{@winner}, {@loser}, {@opponent} und {@zapper}.', voiceMentionFacts()))->toContain(VoiceMentions::TOO_MANY);
});

it('rejects the same person mentioned twice', function () {
    expect((new VoiceMentions)->check('{@winner} gewinnt, {@winner} jubelt.', voiceMentionFacts()))->toContain(VoiceMentions::REPEATED);
});

it('mentions only the captains in a team match', function () {
    $facts = ['team_match' => true, 'people' => [
        ['role' => 'captain_home', 'name' => 'Satoshi', 'pubkey' => str_repeat('a', 64), 'mentionable' => true],
        ['role' => 'player_home', 'name' => 'Hal', 'pubkey' => str_repeat('b', 64), 'mentionable' => true],
    ]];

    expect((new VoiceMentions)->check('{@captain_home} führt.', $facts))->toBe([])
        ->and((new VoiceMentions)->check('{@player_home} trifft.', $facts))->toContain(VoiceMentions::NOT_ALLOWED);
});

it('repairs a person written as token and by name where the meaning stays', function (string $draft, string $repaired) {
    expect((new VoiceMentions)->repairDuplicates($draft, voiceMentionFacts()))->toBe($repaired);
})->with([
    'name next to the token' => ['Satoshi {@winner} gewinnt.', '{@winner} gewinnt.'],
    'name in brackets after it' => ['{@winner} (Satoshi) gewinnt.', '{@winner} gewinnt.'],
    'possessive next to it' => ["Satoshi's {@winner} wins.", '{@winner} wins.'],
    'addressed at the end' => ['{@winner} gewinnt. Läuft bei dir, Satoshi!', '{@winner} gewinnt. Läuft bei dir!'],
    'addressed at the start' => ['{@winner} gewinnt. Satoshi, starker Lauf.', '{@winner} gewinnt. Starker Lauf.'],
    'named again in a later sentence' => ['Satoshi holt den Sieg. 3:1 gegen Hal. {@winner} läuft bei dir.', '{@winner} holt den Sieg. 3:1 gegen Hal.'],
    'token first, name later' => ['{@winner} takes first place. Satoshi is on top now.', '{@winner} takes first place.'],
]);

it('leaves a double naming it cannot repair to the check', function () {
    $mentions = new VoiceMentions;
    $sameSentence = 'Satoshi schlägt Hal, dann jubelt {@winner}.';
    $neededLater = 'Satoshi holt den Sieg. {@winner} spielt Sat, 3 Oct 2026, 9:00 PM CEST.';

    expect($mentions->repairDuplicates($sameSentence, voiceMentionFacts()))->toBe($sameSentence)
        ->and($mentions->check($sameSentence, voiceMentionFacts()))->toContain(VoiceMentions::REPEATED)
        ->and($mentions->repairDuplicates($neededLater, voiceMentionFacts(), ['Sat, 3 Oct 2026, 9:00 PM CEST']))->toBe($neededLater);
});

it('rejects a mentioned person who is also named in plain text', function () {
    expect((new VoiceMentions)->check('Satoshi holt den Sieg. {@winner} läuft bei dir.', voiceMentionFacts()))->toContain(VoiceMentions::REPEATED)
        ->and((new VoiceMentions)->check('{@winner} holt den Sieg. Läuft bei dir.', voiceMentionFacts()))->toBe([]);
});
