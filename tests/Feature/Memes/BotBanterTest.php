<?php

use App\Jobs\WriteBotBanter;
use App\Models\User;
use App\Support\Memes\BotArchetypes;
use App\Support\Memes\BotBanter;
use App\Support\Pong\PongCast;
use App\Support\Voice\LeagueVoice;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceGuard;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PongOn;

/*
|--------------------------------------------------------------------------
| Bot opponents with a Bitcoin archetype (plan "KI-Stimme der Liga", P5)
|--------------------------------------------------------------------------
|
| Every template line passes the league voice's own guard (canon quotes only
| with their ID, no invented name or number) and BotBanter's person guard;
| a bot wearing a real person's caricature talks as its archetype. The voice
| writes into a pool from a queued job only (never while a page waits, only
| with `bot_chat` on), and a voice line that names a person is never kept.
| The game page hands the lines over and offers the mute.
|
*/

// The voice guard's `name` rule rejects any capitalised word its vocabulary (canon, examples, style guide) lacks; it
// exists to catch a name the MODEL invented. The templates are the league's own reviewed lines with German nouns the
// vocabulary does not hold (Sparbuch, Tresor), so `name` is the one rule they are exempt from: every other rule
// (canon quote with its ID and exact wording, no number, link, person, quote or blocklist word) holds, and the person
// guard below covers names.
it('keeps every template line within the voice guard and the person guard', function () {
    $guard = app(LeagueVoice::class)->guard();
    $banter = app(BotBanter::class);
    $max = (int) config('esports.voice.channels.bot_chat.max_chars');

    foreach (BotArchetypes::ROSTER as $id => $character) {
        expect(array_keys($character['lines']))->toBe(BotArchetypes::OCCASIONS);

        foreach ($character['lines'] as $occasion => $lines) {
            expect(count($lines))->toBeGreaterThanOrEqual(2, "{$id}/{$occasion}");

            foreach ($lines as [$text, $canonId]) {
                expect(array_values(array_diff($guard->check($text, [], $max, $canonId), [VoiceGuard::NAME])))->toBe([], "{$id}/{$occasion}: {$text}")
                    ->and($banter->refusals($text))->toBe([], "{$id}/{$occasion}: {$text}");
            }
        }
    }
});

it('refuses a line that names a person, carries a handle, link or number, or insults', function (string $line, string $reason) {
    expect(app(BotBanter::class)->refusals($line))->toContain($reason);
})->with([
    'a real person of the canon' => ['Saylor hätte den gehalten.', BotBanter::REFUSE_PERSON],
    'a figure of the game' => ['Lagarde druckt dir neue Punkte.', BotBanter::REFUSE_PERSON],
    'a mention' => ['Hey @satoshi, gut gespielt.', BotBanter::REFUSE_HANDLE],
    'an npub' => ['Grüße an nostr:npub1abc', BotBanter::REFUSE_HANDLE],
    'a link' => ['Schau mal https://example.com an.', BotBanter::REFUSE_HANDLE],
    'a number' => ['Du hast 3 Punkte. Mehr nicht.', BotBanter::REFUSE_NUMBER],
    'an insult' => ['Spielst wie ein Noob.', BotBanter::REFUSE_BLOCKLIST],
    'empty' => ['  ', BotBanter::REFUSE_EMPTY],
]);

it('gives every Proof of Pong bot an archetype, and a bot with a real face talks as its archetype', function () {
    Queue::fake();

    foreach (PongCast::bots() as $bot) {
        $banter = app(BotBanter::class)->forPongBot($bot['id']);

        expect($banter)->not->toBeNull($bot['id'])
            ->and(BotArchetypes::ROSTER)->toHaveKey($banter['archetype'])
            ->and(array_keys($banter['lines']))->toBe(BotArchetypes::OCCASIONS);
    }

    $lagarde = app(BotBanter::class)->forPongBot('lagarde');
    expect($lagarde['name'])->toBe('Notenbanker')
        ->and(implode(' ', array_merge(...array_values($lagarde['lines']))))->not->toContain('Lagarde');
    // Off by default: no model, no job.
    Queue::assertNothingPushed();
});

it('falls back to the templates while the channel is off', function () {
    Queue::fake();
    VoiceAgent::fake();
    $templates = array_map(fn (array $line): string => $line[0], BotArchetypes::ROSTER['shitcoiner']['lines']['start']);

    $banter = app(BotBanter::class)->forArchetype('shitcoiner');

    expect($banter['lines']['start'])->toEqualCanonicalizing($templates);
    VoiceAgent::assertNeverPrompted();
    Queue::assertNothingPushed();
});

it('sends one job per archetype while the channel is on and keeps a guarded voice line in the pool', function () {
    config(['esports.voice.channels.bot_chat.enabled' => true]);
    Queue::fake();

    app(BotBanter::class)->forArchetype('hodler');
    app(BotBanter::class)->forArchetype('hodler');
    Queue::assertPushed(WriteBotBanter::class, 1);

    VoiceAgent::fake([['text' => 'Nur ein Dip. Ich bleib ruhig.', 'canon_id' => null, 'mood' => 'deadpan']]);
    (new WriteBotBanter('hodler', 'player_point'))->handle(app(BotBanter::class), app(LeagueVoice::class));

    expect(app(BotBanter::class)->pool('hodler', 'player_point'))->toBe(['Nur ein Dip. Ich bleib ruhig.']);
});

it('never keeps a voice line that names a person, even when the voice guard let it through', function () {
    config(['esports.voice.channels.bot_chat.enabled' => true]);
    VoiceAgent::fake([['text' => 'Satoshi hätte den Ball gehalten.', 'canon_id' => null, 'mood' => 'deadpan']]);

    $kept = app(BotBanter::class)->write('hodler', 'bot_point', app(LeagueVoice::class));

    expect($kept)->toBeFalse()
        ->and(app(BotBanter::class)->pool('hodler', 'bot_point'))->toBe([]);
});

it('hands the lines to the bot game and offers the mute there, not in a live match', function () {
    PongOn::play();
    Queue::fake();

    $html = (string) $this->actingAs(User::factory()->create())->get(route('pong.bot', ['bot' => 'schiff']))->assertOk()->getContent();
    preg_match('~<script type="application/json" id="pong-config">(.*?)</script>~s', $html, $config);
    $banter = json_decode(html_entity_decode($config[1]), true)['banter'];

    expect($banter['name'])->toBe('Goldbug')
        ->and($html)->toContain('data-test="pong-banter"')
        ->and($html)->toContain('data-test="pong-setting-banter"')
        ->and($html)->not->toContain('Schiff druckt');
});
