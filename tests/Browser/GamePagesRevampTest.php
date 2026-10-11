<?php

use App\Enums\ChessEndReason;
use App\Enums\TournamentFormat;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Rating;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\PongOn;
use Tests\Support\TestSigner;

/*
| The game pages of the revamp (plan "Refactor und Design-Revamp", P4; boards GamesMenu, GamesMenuPhone, GamesIndex,
| PlayPage, PlayGame, GameLobby, HyperIndex, GameScore, GameSeries, LadderPage, LadderHyper, LadderPong,
| LadderStrongest) in a league with every game switched on: at 390 and 1440 the console and every response stay
| quiet, nothing runs past the page, and every lobby renders through the one lobby template (T1, rule R5) and every
| ladder through the one ladder template (T5). DESIGN_SHOTS=1 also saves full-page screenshots for the side-by-side.
*/

beforeEach(function () {
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
});

/** Every game on, a pot tournament, chess and Blockli ladders, running daily games; returns the admin. */
function gamePagesLeague(): User
{
    BlockfillOn::play();
    BlockliOn::play();
    NineMensMorrisOn::play();
    HyperOn::play();
    PongOn::play();
    tmnfOn();
    config(['esports.board_games.games.checkers.enabled' => true]);
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);
    app(BlockfillWeeks::class)->open();

    $players = [];
    foreach (['Erdöpfökasbrot', 'T-Low', 'NostrLlama', 'Busch21', 'Mx12art', 'DerCaddy', 'Nidan21', 'El Presidento Ben', 'floatYaBoat', 'markusturm'] as $name) {
        $players[$name] = User::factory()->create(['name' => $name]);
    }
    $admin = $players['Erdöpfökasbrot'];
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    foreach ([['Erdöpfökasbrot', 1040, 3, 0, 1], ['T-Low', 1039, 2, 0, 0], ['NostrLlama', 1020, 1, 0, 0], ['Busch21', 1002, 2, 0, 2], ['Mx12art', 981, 0, 0, 1]] as [$name, $rating, $wins, $draws, $losses]) {
        Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'chess', 'mode' => 'rapid', 'subject' => 'user:'.$players[$name]->id, 'user_id' => $players[$name]->id,
            'rating' => $rating, 'results' => $wins + $draws + $losses, 'wins' => $wins, 'draws' => $draws, 'losses' => $losses]);
    }
    foreach ([['DerCaddy', 1123], ['Nidan21', 1008], ['El Presidento Ben', 1003]] as [$name, $rating]) {
        Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'blockli', 'mode' => 'correspondence', 'subject' => 'user:'.$players[$name]->id, 'user_id' => $players[$name]->id,
            'rating' => $rating, 'results' => 3, 'wins' => 2, 'draws' => 0, 'losses' => 1]);
    }

    ChessGame::factory()->daily()->create(['number' => 156, 'white_id' => $admin->id, 'black_id' => $players['floatYaBoat']->id, 'ply' => 3]);
    ChessGame::factory()->daily()->finished('1-0', ChessEndReason::Checkmate)->create(['white_id' => $players['T-Low']->id, 'black_id' => $players['Busch21']->id, 'ended_at' => now()->subHour()]);
    foreach ([['markusturm', 'Nidan21', 3], ['DerCaddy', 'El Presidento Ben', 8]] as [$white, $black, $ply]) {
        mempoolBoard('blockli', ['mode' => 'correspondence', 'white_id' => $players[$white]->id, 'black_id' => $players[$black]->id, 'ply' => $ply,
            'initial_ms' => 86_400_000, 'increment_ms' => 0, 'white_ms' => 86_400_000, 'black_ms' => 86_400_000]);
    }

    $cup = openTournament([
        'name' => 'Blockli Cup #1: Das erste Blockli-Turnier', 'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::RoundRobin,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'capacity' => 12, 'starts_at' => now()->addDays(4)->setTime(17, 0),
    ]);
    $cup->forceFill(['pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 21_000, 'pot_balance_at' => now(), 'prize_target_sats' => 21_000])->save();
    foreach ([...User::factory()->count(8)->create()->all(), $players['markusturm'], $players['DerCaddy']] as $user) {
        TournamentSignup::query()->create(['tournament_id' => $cup->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
    }

    return $admin->refresh();
}

/** Opens `$path` at `$width` (German), with the console collector in place. */
function gamePagesOpen(?User $user, string $path, int $width): mixed
{
    $page = visit($user === null ? '/robots.txt' : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

/** Nothing runs past the viewport, no button or link spills its own box (the squeeze detector of the canvas). */
const GAME_PAGES_SQUEEZE = <<<'JS'
() => {
    const out = [];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    for (const el of document.querySelectorAll('main *, header *, footer *')) {
        if (!el.checkVisibility() || el.closest('.rv-chain-vp, [aria-hidden=true], .sr-only, #game-hub, #account-menu, #more-sheet, .gs-tabs, [data-test=chip-row] nav, canvas')) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0) continue;
        if (r.right > width + 1 || r.left < -1) out.push((el.dataset.test || el.tagName) + ' x ' + Math.round(r.left) + '..' + Math.round(r.right));
        if (['A', 'BUTTON'].includes(el.tagName) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow === 'visible') out.push('spill ' + (el.dataset.test || el.textContent.trim().slice(0, 30)));
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

function gamePagesQuiet(mixed $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses')
        ->and($page->evaluate(GAME_PAGES_SQUEEZE))->toBe([], $label.': squeeze');
}

function gamePagesShot(mixed $page, string $name): void
{
    if (getenv('DESIGN_SHOTS')) {
        $page->screenshot(true, $name);
    }
}

dataset('game pages', [
    'play' => ['/play', 'play', null],
    'games index' => ['/games', 'games-index', null],
    'chess lobby' => ['/chess', 'chess', 'lobby'],
    'blockli lobby' => ['/games/blockli', 'blockli', 'lobby'],
    'pong lobby' => ['/proof-of-pong', 'pong', 'lobby'],
    'hyper lobby' => ['/hyperbitcoinization', 'hyper', 'lobby'],
    'blockfill' => ['/scores/blockfill', 'blockfill', 'game'],
    'rocket league' => ['/games/rocket-league', 'rocket-league', 'game'],
    'chess ladder' => ['/ladder/chess/rapid', 'ladder-chess', 'ladder'],
    'hyper ladder' => ['/hyperbitcoinization/ladder', 'ladder-hyper', 'ladder'],
    'pong ladder' => ['/proof-of-pong/ladder', 'ladder-pong', 'ladder'],
    'strongest' => ['/ladder/strongest', 'ladder-strongest', 'ladder'],
]);

test('a game page stays quiet and inside the page for an admin from 390 to 1920, through its shared template', function (string $path, string $name, ?string $template) {
    $admin = gamePagesLeague();

    // 1440 and 390 for the side-by-side with the boards; 768, 1024 and 1920 for the squeeze between them.
    foreach ([1440, 390, 768, 1024, 1920] as $width) {
        $page = gamePagesOpen($admin, $path, $width);
        if (in_array($width, [1440, 390], true)) {
            gamePagesShot($page, "p4-{$name}-{$width}");
        }
        gamePagesQuiet($page, "{$name} {$width}");

        if ($template !== null && $template !== 'ladder') {
            // One game bar (rule R5): the game, its tabs in the fixed order, the primary action.
            expect($page->evaluate('() => document.querySelectorAll("[data-test=game-subnav]").length'))->toBe(1, "{$name} {$width}: game bar");
        }
        if ($template === 'lobby') {
            expect($page->evaluate('() => document.querySelector("[data-template]")?.dataset.template'))->toBe('T1', "{$name}: lobby template");
        }
        if ($template === 'ladder') {
            expect($page->evaluate('() => document.querySelector("[data-template]")?.dataset.template'))->toBe('T5', "{$name}: ladder template");
        }

        $page->evaluate('() => window.Livewire?.all().forEach((c) => c.$wire.$refresh())');
        $page->waitForLoadState('networkidle');
        gamePagesQuiet($page, "{$name} {$width} after refresh");
    }
})->with('game pages');

test('the games menu lists the browser games first, then the own-copy games, at 1440 and as a sheet at 390', function () {
    $admin = gamePagesLeague();

    $page = gamePagesOpen($admin, '/chess', 1440);
    $page->locator('[data-test=games-menu]')->click();
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    gamePagesShot($page, 'p4-menu-1440');
    $browser = $page->evaluate('() => [...document.querySelectorAll("#game-hub [data-test=hub-browser] [data-hub-game]")].map((e) => e.dataset.hubGame)');
    $own = $page->evaluate('() => [...document.querySelectorAll("#game-hub [data-test=hub-own] [data-hub-game]")].map((e) => e.dataset.hubGame)');
    expect(array_slice($browser, 0, 4))->toBe(['hyperbitcoinization', 'proof-of-pong', 'chess', 'blockfill'])
        ->and($own[0] ?? null)->toBe('rocket-league')
        ->and($page->evaluate('() => window.__errors'))->toBe([]);

    $page = gamePagesOpen($admin, '/chess', 390);
    $page->locator('[data-test=tab-games]')->click();
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    gamePagesShot($page, 'p4-menu-390');
    expect($page->evaluate('() => document.querySelector("#game-hub").getBoundingClientRect().right <= document.documentElement.clientWidth'))->toBeTrue()
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});

test('the console collector catches a thrown error on a game page (positive control)', function () {
    $admin = gamePagesLeague();
    $page = gamePagesOpen($admin, '/chess', 1440);
    $page->evaluate('() => setTimeout(() => { throw new Error("p4 control") })');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 100))');

    expect(implode(' ', $page->evaluate('() => window.__errors')))->toContain('p4 control');
});
