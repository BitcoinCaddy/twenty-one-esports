<?php

use App\Enums\TournamentFormat;
use App\Jobs\WritePrideLine;
use App\Models\Rating;
use App\Models\Season;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Cards\PrideCard;
use App\Support\Cards\PrideMoments;
use App\Support\Cards\PrideVoice;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Voice\VoiceAgent;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Pride pictures (plan "KI-Stimme der Liga", P4 "Stolz und Bilder")
|--------------------------------------------------------------------------
|
| The player card (a trading card of real numbers), Season Wrapped as a story
| to click through and the tale of the tape before a final: drawn on the
| share cards' Canvas at 1200 × 630 and 1080 × 1920, shareable through the
| share button. The model writes the title or line only behind its own
| switch (off by default) and never in the request; until then, and on any
| refusal, the template from PrideMoments stands. PRIDE_SHOTS=<dir> writes
| every PNG there, to look at them.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $this->season = openSeason(['slug' => 'pre-season']);
    $this->user = User::factory()->create(['name' => 'satsjäger']);
    $this->moments = shareMoments($this->user, $this->season);
});

/** The PNG at `$path` with its size, written to PRIDE_SHOTS as `$name` when set. */
function prideFetch(string $path, string $name): array
{
    $response = test()->get($path)->assertOk()->assertHeader('Content-Type', 'image/png');
    $dir = getenv('PRIDE_SHOTS');

    if (is_string($dir) && $dir !== '') {
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/{$name}.png", $response->getContent());
    }

    $info = getimagesizefromstring($response->getContent());

    return [$info[0], $info[1]];
}

/** A chess knockout of four with the semi-finals played (the better seeds won): the final waits. */
function prideFinalWaiting(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['name' => 'Halving Night Cup', 'published_at' => now()->subDay()])->save();
    $runner = app(TournamentRunner::class);
    $round = TournamentRunner::currentRound($tournament);

    foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->with('slots.participant')->get() as $match) {
        $better = $match->slots[0]->participant->seed < $match->slots[1]->participant->seed ? 0 : 1;
        $runner->enterResult($match, $tournament->creator, ['result' => $better === 0 ? '1-0' : '0-1']);
    }

    $runner->closeRound($round, $tournament->creator);

    // Player 1 has a season record in the tournament's ladder (chess blitz, rated, the open season); Player 2 none yet.
    $first = $tournament->participants()->where('name', 'Player 1')->sole();
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz',
        'subject' => 'user:'.$first->user_id, 'user_id' => $first->user_id, 'rating' => 1188, 'results' => 12, 'wins' => 9, 'draws' => 1, 'losses' => 2]);

    return $tournament->refresh();
}

function prideSwitchOn(string $function): void
{
    config(['esports.voice.channels.card.enabled' => true, "esports.voice.channels.card.functions.{$function}" => true]);
}

test('the player card renders at 1200 × 630 and 1080 × 1920 in English and German from its public URL', function () {
    foreach (['en', 'de'] as $locale) {
        App::setLocale($locale);
        $card = PrideCard::player($this->user);

        foreach (PrideCard::FORMATS as $format => [$width, $height]) {
            expect($card->url($format))->toContain('/cards/'.$locale.'/player/'.$this->user->npub.'-'.$format.'.png?v=')
                ->and(prideFetch($card->path($format), "player-{$format}-{$locale}"))->toBe([$width, $height]);
        }
    }
});

test('the player card draws the real numbers of the ladders, the season chain and the tournaments', function () {
    $facts = PrideCard::player($this->user)->facts;

    // shareMoments(): chess blitz 1061 after 9 rated results with 7 wins, two mined blocks of 5 000 sats, one tournament won.
    expect($facts)->toMatchArray([
        'name' => 'satsjäger', 'rating' => 1061, 'results' => 9, 'wins' => 7, 'winrate' => 78,
        'blocks' => 2, 'sats' => 10_000, 'tournaments' => 1, 'season' => 'pre-season', 'provisional' => false,
    ])->and($facts['tier'])->toBe(PrideMoments::player($this->user)['tier'])->not->toBeNull()
        ->and($facts['peak'])->toBeGreaterThanOrEqual(1061);
});

test('without a switch the title is the template, and no model is asked', function () {
    Queue::fake();
    VoiceAgent::fake();

    $card = PrideCard::player($this->user);

    expect($card->line)->toBe('Tournament winner')
        ->and(PrideMoments::playerTitle(['tournaments' => 0, 'blocks' => 0, 'results' => 0, 'winrate' => null]))->toBe('Newcomer')
        ->and(PrideMoments::playerTitle(['tournaments' => 0, 'blocks' => 12, 'results' => 3, 'winrate' => 30]))->toBe('Miner of the season');
    Queue::assertNothingPushed();
    VoiceAgent::assertNeverPrompted();
});

test('with the switch on, the card shows the template, queues the line once, and draws the guarded line once it is written', function () {
    Queue::fake();
    prideSwitchOn('player');
    App::setLocale('de');

    $before = PrideCard::player($this->user);
    expect($before->line)->toBe('Turniersieger');
    Queue::assertPushed(WritePrideLine::class, 1);

    VoiceAgent::fake([['text' => 'satsjäger ist der Blitz-Miner der Liga.', 'canon_id' => null, 'mood' => 'stolz']]);
    $job = Queue::pushed(WritePrideLine::class)->first();
    $job->handle(app(PrideVoice::class));

    $after = PrideCard::player($this->user);
    expect($after->line)->toBe('satsjäger ist der Blitz-Miner der Liga.')
        ->and($after->fingerprint('wide'))->not->toBe($before->fingerprint('wide'))
        ->and(prideFetch($after->path('wide'), 'player-wide-de-model'))->toBe([1200, 630]);
});

test('a refused or linked model line keeps the template', function () {
    prideSwitchOn('player');
    App::setLocale('de');
    $facts = PrideCard::playerVoiceFacts(PrideMoments::player($this->user));

    // An invented number fails the guard; a resolved mention or a link has no place on a picture.
    VoiceAgent::fake([['text' => 'satsjäger gewinnt 99 Turniere.', 'canon_id' => null, 'mood' => 'stolz']]);
    expect(app(PrideVoice::class)->write('player', $this->user->npub, $facts))->toBeNull();

    VoiceAgent::fake([['text' => 'Mehr auf https://example.com', 'canon_id' => null, 'mood' => 'stolz']]);
    expect(app(PrideVoice::class)->write('player', $this->user->npub, $facts))->toBeNull()
        ->and(Cache::get(PrideVoice::cacheKey('player', $this->user->npub, $facts)))->toBeNull();

    // English pages never get the German model line.
    App::setLocale('en');
    expect(PrideVoice::enabled('player'))->toBeFalse();
});

test('the player page loads the card after the page, with its post for the player, the card alone for a visitor, and nothing for a player without results', function () {
    // The page itself carries the placeholder only (R7, its query budget); the card arrives with the lazy load.
    expect($this->get(route('players.show', $this->user->npub))->assertOk()->getContent())
        ->toContain('data-test="player-pride-card-placeholder"')->not->toContain('data-test="player-pride-card"');

    Livewire::actingAs($this->user)->withoutLazyLoading()->test('pride-card', ['player' => $this->user])
        ->assertSeeHtml('data-test="player-pride-card"')
        ->assertSeeHtml('data-test="share-button" data-type="player-card"')
        ->assertSeeHtml('/cards/en/player/'.$this->user->npub.'-wide.png?v=')
        ->assertSeeHtml(route('season.wrapped', ['pre-season', $this->user->npub], false))
        ->call('$refresh')->assertOk()->assertSeeHtml('data-test="player-pride-card"');

    auth()->logout();
    Livewire::withoutLazyLoading()->test('pride-card', ['player' => $this->user])
        ->assertSeeHtml('data-test="player-pride-card"')->assertDontSeeHtml('data-type="player-card"');

    $newcomer = User::factory()->create();
    Livewire::withoutLazyLoading()->test('pride-card', ['player' => $newcomer])->assertDontSeeHtml('data-test="player-pride-card"');
    $this->get('/cards/en/player/'.$newcomer->npub.'-wide.png')->assertNotFound();
});

test('the player card post is the player\'s own, with the card and the player page', function () {
    $post = app(SharePosts::class)->post($this->user, 'player-card', $this->user->npub);

    expect($post->sentence)->toBe('My player card on TWENTY ONE Esports: Tournament winner.')
        ->and($post->dimensions)->toBe([1200, 630])
        ->and($post->link)->toEndWith('/players/'.$this->user->npub)
        ->and($post->cardUrl)->toContain('/cards/en/player/')
        ->and(app(SharePosts::class)->template($post)['content'])->not->toContain('#');

    expect(fn () => app(SharePosts::class)->post($this->user, 'player-card', $this->moments['opponent']->npub))->toThrow(ShareRefused::class);

    Livewire::actingAs($this->user)->test('share-button', ['type' => 'player-card', 'moment' => $this->user->npub])
        ->call('$refresh')->assertOk()->assertSeeHtml('data-type="player-card"');
});

test('the Wrapped story pages through the season, its tournament page only when there is a win, and opens for everybody', function () {
    $html = $this->get(route('season.wrapped', ['pre-season', $this->user->npub]))->assertOk()->getContent();

    expect($html)->toContain('data-test="wrapped-story"')
        ->toContain('data-test="wrapped-page-intro"')
        ->toContain('data-test="wrapped-blocks">2</p>')
        ->toContain('data-test="wrapped-results">9</p>')
        ->toContain('data-test="wrapped-titles">1</p>')
        ->toContain('Testnet Cup')
        ->toContain('data-test="wrapped-line">2 blocks and a tournament win. That season stays on the chain.</p>')
        ->toContain('/cards/en/wrapped/pre-season/'.$this->user->npub.'-wide.png?v=')
        // A visitor reads the story, the player alone posts it.
        ->not->toContain('data-test="share-button"');

    $own = $this->actingAs($this->user)->get(route('season.wrapped', ['pre-season', $this->user->npub]))->assertOk()->getContent();
    expect($own)->toContain('data-test="share-button" data-type="wrapped"');

    // The opponent played no rated game: no story. A season that does not exist: none either.
    $this->get(route('season.wrapped', ['pre-season', $this->moments['opponent']->npub]))->assertNotFound();
    $this->get('/season/no-such-season/wrapped/'.$this->user->npub)->assertNotFound();
});

test('a story without a tournament win has no tournament page, and the German story speaks German', function () {
    $player = User::factory()->create(['name' => 'blockfreund']);
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz',
        'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1000, 'results' => 3, 'wins' => 1, 'losses' => 2]);

    $html = $this->get(route('season.wrapped', ['pre-season', $player->npub, 'lang' => 'de']))->assertOk()->getContent();

    expect($html)->not->toContain('data-test="wrapped-page-titles"')
        ->toContain('data-test="wrapped-page-card"')
        ->toContain('Nächste Seite')
        ->toContain('3 gewertete Partien gespielt. Der nächste Block gehört dir.')
        ->toContain('1 Sieg');
});

test('the tale of the tape names the waiting final with real numbers, renders both sizes and leaves once the final is played', function () {
    $tournament = prideFinalWaiting();
    $final = PrideMoments::upcomingFinal($tournament);

    expect($final)->not->toBeNull();
    $facts = PrideMoments::tape($tournament, $final);

    expect(array_column($facts['sides'], 'name'))->toBe(['Player 1', 'Player 2'])
        ->and(array_column($facts['sides'], 'path'))->toBe([1, 1])
        ->and(array_column($facts['sides'], 'seed'))->toBe([1, 2])
        ->and(PrideMoments::tapeLead($facts, 'seed'))->toBe(0)
        ->and(array_column($facts['sides'], 'rating'))->toBe([1188, PrideMoments::tape($tournament, $final)['sides'][1]['rating']])
        ->and([$facts['sides'][0]['wins'], $facts['sides'][0]['draws'], $facts['sides'][0]['losses']])->toBe([9, 1, 2])
        ->and(PrideMoments::tapeLead($facts, 'rating'))->toBe(0)
        ->and(PrideMoments::tapeLead($facts, 'path'))->toBeNull();

    foreach (['en', 'de'] as $locale) {
        App::setLocale($locale);
        $card = PrideCard::tape($tournament, $final);

        foreach (PrideCard::FORMATS as $format => [$width, $height]) {
            expect(prideFetch($card->path($format), "tape-{$format}-{$locale}"))->toBe([$width, $height]);
        }
    }
    App::setLocale('en');

    $page = $this->get(route('tournaments.show', $tournament))->assertOk()->getContent();
    expect($page)->toContain('data-test="tale-of-the-tape"')->toContain('Player 1')->toContain('data-test="tape-line"');

    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])->call('$refresh')->assertOk()->assertSeeHtml('data-test="tale-of-the-tape"');

    playOutAsDirector($tournament);
    expect(PrideMoments::upcomingFinal($tournament->refresh()))->toBeNull();
    $this->get(route('tournaments.show', $tournament))->assertOk()->assertDontSee('data-test="tale-of-the-tape"', false);
    $this->get('/cards/en/tape/'.$tournament->id.'-wide.png')->assertNotFound();
});

test('a finalist posts the tale of the tape with the opponent mentioned; anybody else cannot', function () {
    $tournament = prideFinalWaiting();
    $final = PrideMoments::upcomingFinal($tournament)->load('slots.participant');
    $finalist = User::query()->findOrFail($final->slots[0]->participant->user_id);
    $opponent = User::query()->findOrFail($final->slots[1]->participant->user_id);

    $post = app(SharePosts::class)->post($finalist, 'tape', (string) $tournament->id);
    $note = app(SharePosts::class)->template($post);

    expect($post->sentence)->toBe('Final of Halving Night Cup: me against Player 2.')
        ->and($post->mentions)->toBe([['pubkey' => $opponent->pubkey, 'name' => $opponent->displayName()]])
        ->and($note['content'])->toContain('nostr:'.NostrKeys::hexToNpub($opponent->pubkey))->not->toContain('#')
        ->and($note['tags'])->toContain(['p', $opponent->pubkey]);

    expect(fn () => app(SharePosts::class)->post($this->user, 'tape', (string) $tournament->id))->toThrow(ShareRefused::class);

    $page = $this->actingAs($finalist)->get(route('tournaments.show', $tournament))->assertOk()->getContent();
    expect($page)->toContain('data-test="share-button" data-type="tape"');
});

test('the tape line is the template until the model line is written behind its switch', function () {
    $tournament = prideFinalWaiting();
    $final = PrideMoments::upcomingFinal($tournament);

    expect(PrideCard::tape($tournament, $final)->line)->toBe('Player 1 leads on paper. Finals are not played on paper.');

    Queue::fake();
    prideSwitchOn('tape');
    App::setLocale('de');
    PrideCard::tape($tournament, $final);
    Queue::assertPushed(WritePrideLine::class, fn (WritePrideLine $job) => $job->function === 'tape');
});

test('the wrapped story and both cards stay out of reach of a season that has not begun', function () {
    $future = Season::factory()->create(['slug' => 'next-season', 'genesis_at' => now()->addMonth(), 'ends_at' => now()->addMonths(4)]);

    $this->get(route('season.wrapped', [$future->slug, $this->user->npub]))->assertNotFound();
});
