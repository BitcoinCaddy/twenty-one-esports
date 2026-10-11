<?php

use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
| The league and account pages of the revamp (plan "Refactor und Design-Revamp", P7; canvas pages "Liga" and
| "Spieler & Konto": Mempool, MempoolPhone, Season, SeasonPhone, Clans, ClanShow, ClanCreate, ClanManage, Profile,
| ProfilePhone, PlayerPublic, Settings*, ActionDock, ActionDockPhone) in a league that looks like the canvas: an admin
| with a clan, daily games on both sides, finished blitz games, a won series, Blockfill runs and Blockli games.
| At 390 and 1440, and squeezed from 390 to 1920: the console and every response stay quiet, nothing runs past the
| page or out of its box. DESIGN_SHOTS=1 also saves full-page screenshots for the side-by-side with the boards.
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

/**
 * The canvas league of the P7 pages; returns the admin (Satoshi of meHubPlayer) and his clan.
 *
 * @return array{me: User, clan: Clan}
 */
function leagueRevampWorld(): array
{
    BlockfillOn::play();
    BlockliOn::play();
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);
    app(BlockfillWeeks::class)->open();

    $world = meHubPlayer();
    Admin::query()->create(['pubkey' => $world['me']->pubkey]);

    $players = [];
    foreach (['TickTrickSat', 'Stefan', 'Erdöpfökasbrot', 'floatYaBoat', 'markusturm', 'Nidan21', 'DerCaddy', 'Lotte'] as $name) {
        $players[$name] = User::factory()->create(['name' => $name]);
    }
    foreach ([[11_800, 'TickTrickSat', 70], [11_200, 'Stefan', 100], [12_100, 'TickTrickSat', 40], [10_700, 'Stefan', 130]] as [$ticks, $name, $minutes]) {
        $run = StackerRun::factory()->verified($ticks)->create(['user_id' => $players[$name]->id]);
        $run->forceFill(['created_at' => now()->subMinutes($minutes)])->save();
    }
    foreach ([['markusturm', 'Nidan21', 3], ['DerCaddy', 'Lotte', 19]] as [$white, $black, $ply]) {
        mempoolBoard('blockli', ['mode' => 'correspondence', 'white_id' => $players[$white]->id, 'black_id' => $players[$black]->id, 'ply' => $ply,
            'initial_ms' => 86_400_000, 'increment_ms' => 0, 'white_ms' => 86_400_000, 'black_ms' => 86_400_000]);
    }

    // A second clan with members, so the clan list has a ranking.
    $hamburg = Clan::factory()->create(['owner_id' => $players['markusturm']->id, 'name' => 'Einundzwanzig Hamburg', 'clantag' => 'HH21']);
    Lineup::factory()->mode('3v3')->ready()->create(['clan_id' => $hamburg->id]);

    return ['me' => $world['me']->refresh(), 'clan' => $world['clan']->refresh()];
}

/** Opens `$path` at `$width` (German), logged in or as a guest, with the console collector in place. */
function leagueRevampOpen(?User $user, string $path, int $width, int $height = 900): mixed
{
    $page = visit($user === null ? '/robots.txt' : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

/** Nothing runs past the viewport, no button or link spills its own box (the squeeze detector of the canvas). */
const LEAGUE_REVAMP_SQUEEZE = <<<'JS'
() => {
    const out = [];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    // A box inside a sideways scroller (chain, chip track, tab row) may leave the window: the scroller itself may not.
    const inScroller = (el) => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            if (['auto', 'scroll', 'hidden', 'clip'].includes(getComputedStyle(p).overflowX)) return true;
        }
        return false;
    };
    for (const el of document.querySelectorAll('main *, header *, footer *')) {
        if (!el.checkVisibility() || el.closest('[aria-hidden=true], .sr-only, #game-hub, #account-menu, #more-sheet, canvas') || inScroller(el)) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0) continue;
        if (r.right > width + 1 || r.left < -1) out.push((el.dataset.test || el.tagName) + ' x ' + Math.round(r.left) + '..' + Math.round(r.right));
        if (['A', 'BUTTON'].includes(el.tagName) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow === 'visible') out.push('spill ' + (el.dataset.test || el.textContent.trim().slice(0, 30)));
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

function leagueRevampQuiet(mixed $page, string $label): void
{
    if (getenv('LEAGUE_SOFT')) {
        fwrite(STDERR, '['.$label.'] '.json_encode([$page->evaluate('() => window.__errors'), $page->evaluate(BrowserConsole::BAD_RESPONSES), $page->evaluate(LEAGUE_REVAMP_SQUEEZE)]).PHP_EOL);

        return;
    }

    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses')
        ->and($page->evaluate(LEAGUE_REVAMP_SQUEEZE))->toBe([], $label.': squeeze');
}

function leagueRevampShot(mixed $page, string $name): void
{
    if (getenv('DESIGN_SHOTS')) {
        $page->screenshot(true, $name);
    }
}

dataset('league pages', [
    'mempool' => ['/matches', 'mempool'],
    'season' => ['/mining', 'season'],
    'clans' => ['/clans', 'clans'],
    'clan' => ['clan', 'clan-show'],
    'clan create' => ['/clans/create', 'clan-create'],
    'clan manage' => ['clan-manage', 'clan-manage'],
    'profile' => ['profile', 'player'],
    'me' => ['/me', 'me'],
    'correspondence' => ['/me/correspondence', 'correspondence'],
    'settings account' => ['/settings/account', 'settings-account'],
    'settings gaming' => ['/settings/gaming', 'settings-gaming'],
    'settings notifications' => ['/settings/notifications', 'settings-notifications'],
    'settings chess' => ['/settings/chess', 'settings-chess'],
    'settings opponents' => ['/settings/opponents', 'settings-opponents'],
    'settings badges' => ['/settings/badges', 'settings-badges'],
    'settings nip05' => ['/settings/nostr-address', 'settings-nip05'],
]);

test('a league or account page stays quiet and inside the page for an admin from 390 to 1920, and after a round trip', function (string $path, string $name) {
    ['me' => $me, 'clan' => $clan] = leagueRevampWorld();
    $path = match ($path) {
        'clan' => route('clans.show', $clan, false),
        'clan-manage' => route('clans.manage', $clan, false),
        'profile' => route('players.show', $me->npub, false),
        default => $path,
    };

    // 1440 and 390 for the side-by-side with the boards; 768, 1024 and 1920 for the squeeze between them.
    foreach ([1440, 390, 768, 1024, 1920] as $width) {
        $page = leagueRevampOpen($me, $path, $width);
        if (in_array($width, [1440, 390], true)) {
            leagueRevampShot($page, "p7-{$name}-{$width}");
        }
        leagueRevampQuiet($page, "{$name} {$width}");

        $page->evaluate('() => window.Livewire?.all().forEach((c) => c.$wire.$refresh())');
        $page->waitForLoadState('networkidle');
        leagueRevampQuiet($page, "{$name} {$width} after refresh");
    }
})->with('league pages');

test('the Mempool chain loads older blocks at its left end and keeps the cube it showed in place', function () {
    ['me' => $me] = leagueRevampWorld();

    // 1024 px: the chain of this league is wider than its window, so there is room to keep the cube in place.
    $page = leagueRevampOpen($me, '/matches', 1024);
    $before = $page->evaluate('() => {
        const chain = document.querySelector("[data-test=mempool-chain]");
        const vp = chain.querySelector(".rv-chain-vp");
        vp.scrollLeft = 0;
        const first = vp.querySelector("[data-test=chain-cube]");
        return { more: chain.dataset.more, cubes: vp.querySelectorAll("[data-test=chain-cube]").length, href: first.getAttribute("href"), x: Math.round(first.getBoundingClientRect().left) };
    }');
    // A wheel up at the left end (the chain moves sideways on a vertical wheel) asks for older blocks.
    $page->evaluate('() => document.querySelector("[data-test=mempool-chain] .rv-chain-vp").dispatchEvent(new WheelEvent("wheel", { deltaY: -120, bubbles: true, cancelable: true }))');
    $page->waitForLoadState('networkidle');
    $page->evaluate('() => new Promise((r) => setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(r)), 300))');
    $after = $page->evaluate('(href) => {
        const vp = document.querySelector("[data-test=mempool-chain] .rv-chain-vp");
        const same = [...vp.querySelectorAll("[data-test=chain-cube]")].find((c) => c.getAttribute("href") === href);
        return { cubes: vp.querySelectorAll("[data-test=chain-cube]").length, x: same ? Math.round(same.getBoundingClientRect().left) : null, scroll: Math.round(vp.scrollLeft) };
    }', $before['href']);
    fwrite(STDERR, '[chain history] '.json_encode([$before, $after]).PHP_EOL);

    expect($before['more'])->toBe('1')
        ->and($after['cubes'])->toBeGreaterThan($before['cubes'])
        ->and($after['scroll'])->toBeGreaterThan(0)
        ->and(abs($after['x'] - $before['x']))->toBeLessThanOrEqual(2);
    leagueRevampQuiet($page, 'chain history');
});
