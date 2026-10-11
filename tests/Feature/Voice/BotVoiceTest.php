<?php

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Voice\BotVoice;
use App\Support\Voice\SpamGate;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceGuard;
use Carbon\CarbonImmutable;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| The posts meet the league voice (plan "KI-Stimme der Liga", P2)
|--------------------------------------------------------------------------
|
| BotVoice::compose() turns a template and its values into the post: the
| voice's words when its function and channel are on and every check
| passes, else the template; mentions only of players who allow them; the
| link appended by the app, never written by the model. The language
| follows the channel: the chat German, the profile English, and an English
| post uses only canon entries marked en_ok, never as a made-up quote.
|
*/

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-10-11 12:00:00', 'UTC');
    $this->travelTo($this->now);
    $this->alice = User::factory()->create(['name' => 'Alice', 'pubkey' => str_repeat('a', 64)]);
});

function aliceMention(): string
{
    return 'nostr:'.NostrKeys::hexToNpub(str_repeat('a', 64));
}

/**
 * @return array<string, string|int|null>
 */
function winnerValues(): array
{
    return ['winner' => aliceMention(), 'name' => 'Friday Blitz Cup', 'url' => 'https://esports.test/tournaments/7'];
}

it('keeps the template and asks no model while the function is off', function () {
    config(['esports.voice.channels.gg.enabled' => true]);
    VoiceAgent::fake();

    $post = app(BotVoice::class)->compose('chat_gg', 'tournament_gg', 0, winnerValues(), [['p', str_repeat('a', 64)]], $this->now);

    expect($post['content'])->toBe('🏆 GG! '.aliceMention().' gewinnt Friday Blitz Cup 🎉 https://esports.test/tournaments/7')
        ->and($post['tags'])->toBe([['p', str_repeat('a', 64)]])
        ->and($post['voiced'])->toBeFalse();
    VoiceAgent::assertNeverPrompted();
});

it('writes the post with the voice, puts the person in and appends the link itself', function () {
    config(['esports.voice.functions.chat_gg' => true, 'esports.voice.channels.gg.enabled' => true]);
    VoiceAgent::fake([['text' => 'Boah. {@winner} holt sich Friday Blitz Cup!', 'canon_id' => null, 'mood' => 'hype']]);

    $post = app(BotVoice::class)->compose('chat_gg', 'tournament_gg', 0, winnerValues(), [['p', str_repeat('a', 64)]], $this->now);

    expect($post['content'])->toBe('Boah. '.aliceMention()." holt sich Friday Blitz Cup!\n👉 https://esports.test/tournaments/7")
        ->and($post['tags'])->toBe([['p', str_repeat('a', 64)]])
        ->and($post['voiced'])->toBeTrue();
    // The model sees the name and its token, never the key, never the link.
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"name": "Alice"') && str_contains($prompt->prompt, '{@winner}')
        && ! str_contains($prompt->prompt, 'npub1') && ! str_contains($prompt->prompt, 'https://esports.test/tournaments/7'));
});

it('writes the stream chat in German and the profile notes in English', function (string $function, string $template, string $language) {
    config(["esports.voice.functions.{$function}" => true, 'esports.voice.channels.stream_chat.enabled' => true, 'esports.voice.channels.profile_note.enabled' => true]);
    VoiceAgent::fake();

    app(BotVoice::class)->compose($function, $template, 0, winnerValues(), [['p', str_repeat('a', 64)]], $this->now);

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Sprache: '.$language));
})->with([
    'chat' => ['chat_rotation', 'tournament_winner', 'Deutsch'],
    'profile' => ['note_champion', 'tournament_winner', 'Englisch'],
]);

it('names a player who switched mentions off in plain text, without a p tag, template and voice alike', function () {
    $this->alice->forceFill(['nostr_mentions' => false])->save();
    config(['esports.voice.channels.gg.enabled' => true]);
    VoiceAgent::fake();

    $template = app(BotVoice::class)->compose('chat_gg', 'tournament_gg', 0, winnerValues(), [['p', str_repeat('a', 64)]], $this->now);

    config(['esports.voice.functions.chat_gg' => true]);
    app(BotVoice::class)->compose('chat_gg', 'tournament_gg', 0, winnerValues(), [['p', str_repeat('a', 64)]], $this->now);

    expect($template['content'])->toBe('🏆 GG! Alice gewinnt Friday Blitz Cup 🎉 https://esports.test/tournaments/7')
        ->and($template['tags'])->toBe([]);
    // The model gets her name, but no token to mention her with.
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Alice') && ! str_contains($prompt->prompt, '{@winner}'));
});

it('rejects a mention token for a player who switched mentions off, so the template goes out', function () {
    $this->alice->forceFill(['nostr_mentions' => false])->save();
    config(['esports.voice.functions.chat_gg' => true, 'esports.voice.channels.gg.enabled' => true]);
    VoiceAgent::fake([
        ['text' => 'Boah. {@winner} holt sich Friday Blitz Cup!', 'canon_id' => null, 'mood' => 'hype'],
        ['text' => 'Boah. {@winner} holt sich Friday Blitz Cup!', 'canon_id' => null, 'mood' => 'hype'],
    ]);

    $post = app(BotVoice::class)->compose('chat_gg', 'tournament_gg', 0, winnerValues(), [['p', str_repeat('a', 64)]], $this->now);

    expect($post['voiced'])->toBeFalse()
        ->and($post['content'])->not->toContain('nostr:')
        ->and($post['tags'])->toBe([]);
});

it('names a player only once per day on the profile: the spam gate\'s mention pause', function () {
    $voice = app(BotVoice::class);
    $first = $voice->compose('note_pride', 'pride_note_win', 0, ['winner' => aliceMention(), 'loser' => 'Hal', 'mode' => 'blitz chess', 'elo' => null, 'tournament' => '', 'url' => 'https://esports.test/games/1'], [['p', str_repeat('a', 64)]], $this->now);
    $voice->delivered('note_pride', 'pride_note_win', $first['content'], $first['tags'], $this->now);

    $second = $voice->compose('note_pride', 'pride_note_win', 1, ['winner' => aliceMention(), 'loser' => 'Bob', 'mode' => 'blitz chess', 'elo' => null, 'tournament' => '', 'url' => 'https://esports.test/games/2'], [['p', str_repeat('a', 64)]], $this->now->addHour());

    expect($first['tags'])->toBe([['p', str_repeat('a', 64)]])
        ->and($second['tags'])->toBe([])
        ->and($second['content'])->toContain('Alice')->not->toContain('nostr:');
});

it('holds a repeated template back where the caller has another post to offer, and lets it pass where not', function () {
    $voice = app(BotVoice::class);
    $values = ['count' => 3, 'url' => 'https://esports.test/games'];
    $first = $voice->compose('chat_rotation', 'live_games', 0, $values, [], $this->now, ['hold_template' => true]);
    $voice->delivered('chat_rotation', 'live_games', $first['content'], [], $this->now);

    expect($voice->compose('chat_rotation', 'live_games', 0, $values, [], $this->now->addHour(), ['hold_template' => true]))->toBeNull()
        ->and($voice->compose('chat_rotation', 'live_games', 0, $values, [], $this->now->addHour())['content'])->toBe($first['content']);
});

it('sends the template when the spam gate turns the voice\'s text down', function () {
    config(['esports.voice.functions.chat_rotation' => true, 'esports.voice.channels.stream_chat.enabled' => true]);
    app(SpamGate::class)->record(SpamGate::CHAT, "Hey! Drei Partien live. Schau rein.\n👉 https://esports.test/other", 'general', [], $this->now->subHour());
    VoiceAgent::fake([['text' => 'Hey! Drei Partien live. Schau rein.', 'canon_id' => null, 'mood' => 'call']]);

    $post = app(BotVoice::class)->compose('chat_rotation', 'live_games', 0, ['count' => 3, 'url' => 'https://esports.test/games'], [], $this->now);

    expect($post['voiced'])->toBeFalse()
        ->and($post['content'])->toStartWith('🔴 3 Partien laufen gerade');
});

it('picks only canon entries marked en_ok for an English channel', function () {
    $canon = new VoiceCanon;
    $english = $canon->select('win', [], 50, 100, 12_000, 'en');
    $german = $canon->select('win', [], 50, 100, 12_000, 'de');

    expect($english)->not->toBeEmpty()
        ->and(collect($english)->every(fn (array $entry): bool => $entry['en_ok']))->toBeTrue()
        ->and(collect($german)->contains(fn (array $entry): bool => ! $entry['en_ok']))->toBeTrue();
});

it('marks every canon entry, most of them for English too', function () {
    $entries = (new VoiceCanon)->entries();
    $raw = json_decode((string) file_get_contents(resource_path('data/voice/meme-canon.json')), true);

    expect(collect($raw)->every(fn (array $row): bool => is_bool($row['en_ok'] ?? null)))->toBeTrue()
        ->and(count(array_filter($entries, fn (array $entry): bool => $entry['en_ok'])))->toBeGreaterThan(count($entries) / 2);
});

it('lets the guard use a German-only canon entry in German and never in English', function () {
    $entry = collect((new VoiceCanon)->entries())->first(fn (array $entry): bool => ! $entry['en_ok'] && $entry['speaker'] === null && ! str_contains($entry['wording'], '…') && preg_match('/\d/', $entry['wording']) !== 1);
    $guard = new VoiceGuard(new VoiceCanon);

    expect($guard->check('Satoshi gewinnt. '.$entry['wording'], ['winner' => 'Satoshi'], 280, $entry['id'], 'de'))->not->toContain(VoiceGuard::CANON_NOT_ENGLISH)
        ->and($guard->check('Satoshi wins. Nice one.', ['winner' => 'Satoshi'], 280, $entry['id'], 'en'))->toContain(VoiceGuard::CANON_NOT_ENGLISH);
});

it('rejects an English sense translation set beside the name of the person whose line it is', function () {
    $entry = collect((new VoiceCanon)->entries())->first(fn (array $entry): bool => $entry['en_ok'] && is_string($entry['speaker']) && in_array($entry['speaker'], (array) config('esports.voice.persons'), true));
    $guard = new VoiceGuard(new VoiceCanon, [], (array) config('esports.voice.persons'));

    expect($entry)->not->toBeNull()
        ->and($guard->check('Satoshi wins. As '.$entry['speaker'].' would put it: stack and smile.', ['winner' => 'Satoshi'], 280, $entry['id'], 'en'))->toContain(VoiceGuard::PERSON)
        ->and($guard->check('Satoshi wins. Stack and smile.', ['winner' => 'Satoshi'], 280, $entry['id'], 'en'))->not->toContain(VoiceGuard::PERSON);
});

it('rejects an English sense translation in quotation marks: it would read as a verbatim quote', function () {
    $entry = collect((new VoiceCanon)->entries())->first(fn (array $entry): bool => $entry['en_ok'] && preg_match('/[äöüß]/u', $entry['wording']) === 1);

    expect((new VoiceGuard(new VoiceCanon))->check('Satoshi wins. “Stack and smile.”', ['winner' => 'Satoshi'], 280, $entry['id'], 'en'))
        ->toContain(VoiceGuard::CANON_MISQUOTED);
});

it('rejects a German canon wording left word for word in an English post, and keeps an English one', function () {
    $canon = new VoiceCanon;
    $german = collect($canon->entries())->first(fn (array $entry): bool => $entry['en_ok'] && $entry['wording'] === 'bester Mann');
    $english = collect($canon->entries())->first(fn (array $entry): bool => $entry['en_ok'] && $entry['wording'] === 'Few understand');
    $guard = new VoiceGuard($canon);

    expect($guard->check('Ada wins. Bester Mann!', ['winner' => 'Ada'], 280, $german['id'], 'en'))->toContain(VoiceGuard::CANON_UNTRANSLATED)
        ->and($guard->check('Ada wins. Best man!', ['winner' => 'Ada'], 280, $german['id'], 'en'))->not->toContain(VoiceGuard::CANON_UNTRANSLATED)
        ->and($guard->check('Ada wins. Few understand.', ['winner' => 'Ada'], 280, $english['id'], 'en'))->not->toContain(VoiceGuard::CANON_UNTRANSLATED)
        ->and($guard->check('Ada gewinnt. Bester Mann!', ['winner' => 'Ada'], 280, $german['id'], 'de'))->not->toContain(VoiceGuard::CANON_UNTRANSLATED);
});

it('knows everyday English sentence openers, and still takes an unknown capitalised word for an invented name', function () {
    $guard = new VoiceGuard(new VoiceCanon);

    expect($guard->check('Huge win. Congrats, Ada!', ['winner' => 'Ada'], 280, null, 'en'))->toBe([])
        ->and($guard->check('Huge win. Kevin watched it.', ['winner' => 'Ada'], 280, null, 'en'))->toContain(VoiceGuard::NAME);
});

it('rejects a voice text that leaves out a fact it must carry word for word', function () {
    $guard = new VoiceGuard(new VoiceCanon);
    $facts = ['name' => 'Friday Blitz Cup', 'starts' => 'Sat, 3 Oct 2026, 9:00 PM CEST'];

    expect($guard->check('Friday Blitz Cup is open. Who is in?', $facts, 280, null, 'en', ['Sat, 3 Oct 2026, 9:00 PM CEST']))->toContain(VoiceGuard::MISSING_FACT)
        ->and($guard->check('Friday Blitz Cup starts Sat, 3 Oct 2026, 9:00 PM CEST. Who is in?', $facts, 280, null, 'en', ['Sat, 3 Oct 2026, 9:00 PM CEST']))->not->toContain(VoiceGuard::MISSING_FACT);
});
