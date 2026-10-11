<?php

use App\Jobs\WriteMemeCaption;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Memes\MemeCard;
use App\Support\Memes\MemeLibrary;
use App\Support\Memes\MemeMoments;
use App\Support\Memes\MemeShares;
use App\Support\Voice\LeagueVoice;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PongOn;

/*
|--------------------------------------------------------------------------
| The meme library and the meme card (plan "KI-Stimme der Liga", P5)
|--------------------------------------------------------------------------
|
| Canon only: every template quotes an approved canon entry that fits its
| moment, the card prints that entry's wording and nothing else. The line
| under it is the template's sentence until the league voice wrote one in a
| queued job (never while a page waits, only with the `meme` channel on);
| a rejected voice line keeps the template. The winner shares the card with
| the usual signed share post.
|
*/

beforeEach(function () {
    Storage::fake('local');
    PongOn::play();
});

function memeWin(array $attributes = []): PongMatch
{
    // A first win, 21:19: two moments (first win, close game); the card takes the first.
    return PongMatch::factory()->finished(0, [21, 19])->create($attributes);
}

it('quotes only approved canon entries that fit their moment, without a real person as speaker', function () {
    $canon = app(VoiceCanon::class);

    foreach (MemeLibrary::all() as $template) {
        $entry = $canon->find($template['canon_id']);

        expect($entry)->not->toBeNull("{$template['id']}: unknown canon ID {$template['canon_id']}")
            ->and($entry['speaker'])->toBeNull("{$template['id']}: a real person's quote")
            ->and(array_intersect($entry['contexts'], MemeLibrary::CONTEXTS[$template['moment']]))->not->toBe([], "{$template['id']}: canon contexts do not fit {$template['moment']}")
            ->and(VoiceCanon::MOMENTS)->toHaveKey($template['moment']);
    }

    foreach (MemeMoments::TYPES as $moment) {
        expect(count(MemeLibrary::TEMPLATES[$moment] ?? []))->toBeGreaterThanOrEqual(3, "{$moment}: too few templates for variety");
    }
});

it('picks the same template for the same match and varies across matches', function () {
    $picked = array_map(fn (int $i): string => MemeLibrary::for('upset', 'match-'.$i)['id'], range(1, 40));

    expect(MemeLibrary::for('upset', 'match-1'))->toBe(MemeLibrary::for('upset', 'match-1'))
        ->and(count(array_unique($picked)))->toBeGreaterThanOrEqual(3)
        ->and(MemeLibrary::for('no-such-moment', 'x'))->toBeNull();
});

it('draws the card of a match with a moment, with the canon wording and the template line', function () {
    Queue::fake();
    VoiceAgent::fake();
    $match = memeWin();
    $facts = app(MemeShares::class)->facts($match);
    $template = MemeLibrary::for('first_win', $match->ulid);

    expect($facts)->toMatchArray([
        'moment' => 'first_win',
        'template' => $template['id'],
        'quote' => app(VoiceCanon::class)->find($template['canon_id'])['wording'],
        'line' => __('First Proof of Pong win: :score.', ['score' => '21:19']),
        'score' => [21, 19],
    ]);

    foreach (MemeCard::FORMATS as $format => [$width, $height]) {
        $response = $this->get(route('cards.meme', ['locale' => 'de', 'meme' => $match->ulid, 'format' => $format]));
        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        [$w, $h] = getimagesizefromstring((string) $response->getContent());
        expect([$w, $h])->toBe([$width, $height]);
    }

    // The channel is off: no model call, no job; the template line stands.
    VoiceAgent::assertNeverPrompted();
    Queue::assertNothingPushed();
});

it('has no card for a match without a moment, an unfinished match or an unknown one', function () {
    $earlier = PongMatch::factory()->finished(0, [21, 2])->create();
    $plain = PongMatch::factory()->finished(0, [21, 5])->create(['left_id' => $earlier->left_id]);
    $running = PongMatch::factory()->active()->create();

    foreach ([$plain->ulid, $running->ulid, strtolower((string) str()->ulid())] as $ulid) {
        $this->get(route('cards.meme', ['locale' => 'en', 'meme' => $ulid, 'format' => 'wide']))->assertNotFound();
    }
});

it('sends one job for the voice line while the meme channel is on, and keeps the template until it is written', function () {
    config(['esports.voice.channels.meme.enabled' => true]);
    Queue::fake();
    $match = memeWin();

    // In English the voice's German line is never shown, so nothing is written for it.
    app(MemeShares::class)->facts($match);
    Queue::assertNothingPushed();

    App::setLocale('de');
    app(MemeShares::class)->facts($match);
    app(MemeShares::class)->facts($match);

    Queue::assertPushed(WriteMemeCaption::class, 1);
    expect(app(MemeShares::class)->facts($match)['line'])->toBe('Erster Sieg in Proof of Pong: 21:19.');
});

it('shows the voice line once written, in German only, and lets a line with its own meme replace the template quote', function () {
    config(['esports.voice.channels.meme.enabled' => true]);
    App::setLocale('de');
    $match = memeWin();
    VoiceAgent::fake([['text' => 'Erster Sieg! 21:19 und direkt Diamond Hands.', 'canon_id' => 'btc-diamond-hands', 'mood' => 'hype']]);

    (new WriteMemeCaption($match->id))->handle(app(MemeShares::class), app(LeagueVoice::class));
    $german = app(MemeShares::class)->facts($match);
    App::setLocale('en');
    $english = app(MemeShares::class)->facts($match);

    expect($german['line'])->toBe('Erster Sieg! 21:19 und direkt Diamond Hands.')
        ->and($german['quote'])->toBeNull()
        ->and($english['line'])->toBe('First Proof of Pong win: 21:19.')
        ->and($english['quote'])->not->toBeNull();
});

it('keeps the template line when the voice line fails the guard', function () {
    config(['esports.voice.channels.meme.enabled' => true]);
    App::setLocale('de');
    $match = memeWin();
    // An invented number: the guard rejects it.
    VoiceAgent::fake([['text' => 'Erster Sieg nach 99 Versuchen!', 'canon_id' => null, 'mood' => 'hype']]);

    app(MemeShares::class)->write($match, app(LeagueVoice::class));

    expect(Cache::get(MemeShares::captionKey($match, 'first_win')))->toBe(['text' => null, 'canon_id' => null])
        ->and(app(MemeShares::class)->facts($match)['line'])->toBe(__('First Proof of Pong win: :score.', ['score' => '21:19']));
});

it('lets the winner share the meme card, and nobody else', function () {
    Queue::fake();
    $match = memeWin();
    $winner = User::query()->findOrFail($match->winner_id);
    $loser = User::query()->findOrFail($match->right_id);
    $posts = app(SharePosts::class);

    $note = $posts->prepare($winner, 'meme', 'pong-'.$match->ulid);
    $imeta = collect($note['tags'])->firstWhere(0, 'imeta');

    expect($note['content'])->toContain('/cards/'.App::getLocale().'/meme/pong/'.$match->ulid.'-wide.png')
        ->and($note['content'])->not->toContain('#')
        ->and($imeta[1])->toStartWith('url '.rtrim((string) config('app.url'), '/').'/cards/')
        ->and(collect($note['tags'])->where(0, 'p'))->toBeEmpty();

    expect(fn () => $posts->post($loser, 'meme', 'pong-'.$match->ulid))->toThrow(ShareRefused::class)
        ->and(fn () => $posts->post($winner, 'meme', $match->ulid))->toThrow(ShareRefused::class);
});
