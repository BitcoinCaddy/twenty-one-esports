<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\OverlayPreset;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentOrganizer;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Support\Navigation\AdminNavigation;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

/*
| The revamped admin area (plan "Refactor und Design-Revamp", P8; boards Admin*): every admin page in the one
| frame (sidebar from 1024 px, console breadcrumb bar, display title), as an admin in German at 390 and 1440 and
| across 390–1920: the console and every response stay quiet, nothing runs past the page or out of its box, and
| the sidebar marks the page on screen. DESIGN_SHOTS=1 also saves full-page screenshots for the side-by-side.
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
});

/**
 * An admin in a league with an organizer, an organizer tournament with sign-ups and a disputed series.
 *
 * @return array{0: User, 1: Tournament, 2: SeriesMatch}
 */
function adminRevampLeague(): array
{
    // Blockfill on routes the Blockfill review and the score submissions (routes/stacker.php, routes/score.php).
    BlockfillOn::play();
    app()->forgetInstance(GameRegistry::class);
    $admin = User::factory()->create(['name' => 'Erdöpfökasbrot']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $caddy = User::factory()->create(['name' => 'DerCaddy']);
    TournamentOrganizer::query()->create(['pubkey' => $caddy->pubkey]);

    $tournament = openTournament([
        'name' => 'Blockli Cup #1: Das erste Blockli-Turnier', 'format' => TournamentFormat::RoundRobin, 'capacity' => 12,
        'options' => FormatOptions::defaults(GameProfile::for('chess', 'blitz'))->toArray(), 'created_by_id' => $caddy->id,
    ]);
    foreach (['El Presidento Ben', 'markusturm', 'Anomark', 'webdiverblue'] as $name) {
        $user = User::factory()->create(['name' => $name]);
        TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $user->displayName(), 'members' => [$user->id]]);
    }

    $dispute = SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Disputed]);

    return [$admin->refresh(), $tournament->refresh(), $dispute];
}

/** Every admin page: label => path. */
function adminRevampPaths(Tournament $tournament, SeriesMatch $dispute): array
{
    $paths = [];
    foreach (array_keys(AdminNavigation::PAGES) as $key) {
        $name = $key === 'seasons' ? 'admin.season' : 'admin.'.$key;
        if (Route::has($name)) {
            $paths[$key] = route($name, absolute: false);
        }
    }

    return $paths + [
        'dispute' => route('admin.disputes.show', $dispute, false),
        'tournament-create' => route('admin.tournaments.create', absolute: false),
        'tournament-edit' => route('admin.tournaments.edit', $tournament, false),
    ];
}

function adminRevampPage(User $user, string $path, int $width): mixed
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

/** Nothing runs past the viewport or out of its own box (the squeeze detector of HomeRevampTest). */
const ADMIN_REVAMP_SQUEEZE = <<<'JS'
() => {
    const out = [];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    for (const el of document.querySelectorAll('main *, header *, footer *')) {
        // copy-npub widens its 24 px button to a 44 px hit area with an absolute ::after: that is the target, not a spill.
        if (!el.checkVisibility() || el.closest('.rv-chips-track, .rv-tabs, [aria-hidden=true], .sr-only, #game-hub, #account-menu, #more-sheet, .overflow-x-auto, [data-test=copy-npub]')) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0) continue;
        if (r.right > width + 1 || r.left < -1) out.push((el.dataset.test || el.tagName) + ' x ' + Math.round(r.left) + '..' + Math.round(r.right));
        if (['A', 'BUTTON'].includes(el.tagName) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow === 'visible') out.push('spill ' + (el.dataset.test || el.textContent.trim().slice(0, 30)));
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

/** What is loud or out of its box on the page: console errors, failed responses, the squeeze. */
function adminRevampLoud(mixed $page): array
{
    return array_merge(
        array_map(fn (string $e): string => 'console '.$e, $page->evaluate('() => window.__errors')),
        array_map(fn (mixed $r): string => 'response '.json_encode($r), $page->evaluate(BrowserConsole::BAD_RESPONSES)),
        $page->evaluate(ADMIN_REVAMP_SQUEEZE),
    );
}

test('every admin page sits in the one frame and stays quiet and inside the page, at 390 and 1440', function () {
    [$admin, $tournament, $dispute] = adminRevampLeague();
    $paths = adminRevampPaths($tournament, $dispute);
    expect(count($paths))->toBe(count(AdminNavigation::PAGES) + 3 - (Route::has('admin.scores') ? 0 : 1) - (Route::has('admin.blockfill') ? 0 : 1));
    $problems = [];

    foreach ([1440, 390] as $width) {
        foreach ($paths as $key => $path) {
            $page = adminRevampPage($admin, $path, $width);
            if (getenv('DESIGN_SHOTS')) {
                $page->screenshot(true, "admin-{$key}-{$width}");
            }
            $frame = $page->evaluate('() => ({
                side: document.querySelector("[data-test=admin-nav-side]")?.checkVisibility() ?? false,
                current: document.querySelector("[data-test=admin-nav-side] [aria-current=page]")?.dataset.test ?? null,
                crumbs: document.querySelector("[data-test=admin-crumbs] [aria-current=page]")?.innerText.trim() ?? "",
                h1: document.querySelector("main h1")?.innerText.trim() ?? "",
                menu: document.querySelector("[data-test=admin-nav-menu]")?.checkVisibility() ?? false,
            })');
            $want = $width === 1440
                ? ['side' => true, 'menu' => false, 'current' => isset(AdminNavigation::PAGES[$key]) ? 'admin-nav-'.$key : $frame['current']]
                : ['side' => false, 'menu' => true];
            $loud = adminRevampLoud($page);
            if ($frame['h1'] === '' || $frame['crumbs'] === '' || array_intersect_key($frame, $want) != $want || $loud !== []) {
                $problems["{$key} {$width}"] = ['frame' => $frame, 'loud' => $loud];
            }
        }
    }

    // Positive control, same collector: a thrown error is caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("admin revamp control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("admin revamp control"))', 5_000);

    expect($problems)->toBe([]);
});

test('the admin pages fit every width from 390 to 1920 without a squeeze', function () {
    [$admin, $tournament, $dispute] = adminRevampLeague();
    $paths = adminRevampPaths($tournament, $dispute);
    $seen = [];

    foreach ([768, 1024, 1280, 1920] as $width) {
        foreach ($paths as $key => $path) {
            $page = adminRevampPage($admin, $path, $width);
            $squeeze = $page->evaluate(ADMIN_REVAMP_SQUEEZE);
            if ($squeeze !== []) {
                $seen["{$key} {$width}"] = $squeeze;
            }
        }
    }

    expect($seen)->toBe([]);
});

test('the board controls that replace native select and checkbox work by mouse and keyboard and reach the server', function () {
    [$admin] = adminRevampLeague();
    $page = adminRevampPage($admin, route('admin.events', absolute: false), 1440);
    expect($page->evaluate('() => document.querySelectorAll("main select, main input[type=checkbox], main input[type=radio]").length'))->toBe(0);

    // Mouse: open the day dropdown and pick Friday.
    $page->locator('[data-test=slot-weekday]')->click();
    BrowserWait::until($page, '() => document.querySelector("#dd-weekday-list")?.checkVisibility() === true', 5_000);
    $page->locator('#dd-weekday-list [data-value="5"]')->click();
    // Keyboard: the game dropdown opens on Enter, Down moves, Enter picks, focus comes back to the button.
    $page->locator('[data-test=slot-ladder]')->press('Enter');
    BrowserWait::until($page, '() => document.activeElement?.getAttribute("role") === "option"', 5_000);
    $page->locator('#dd-ladder-list [role=option]:focus')->press('ArrowDown');
    $picked = $page->evaluate('() => document.activeElement.dataset.value');
    $page->locator('#dd-ladder-list [role=option]:focus')->press('Enter');
    BrowserWait::until($page, '() => document.activeElement?.dataset.test === "slot-ladder"', 5_000);

    $page->locator('[data-test=slot-title]')->fill('Freitagsblitz');
    $page->locator('[data-test=slot-add]')->click();
    BrowserWait::until($page, '() => document.querySelectorAll("[data-test=slot-row]").length === 1', 10_000);
    $slot = WeeklySlot::query()->sole();
    expect($slot->weekday)->toBe(5)
        ->and($slot->game.'/'.$slot->mode)->toBe($picked)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);

    // The checkbox of the disputes filter: a click checks it and the server hears it.
    $disputes = adminRevampPage($admin, route('admin.disputes', absolute: false), 1440);
    $disputes->locator('[data-test=hide-own]')->click();
    BrowserWait::until($disputes, '() => document.querySelector("[data-test=hide-own]").getAttribute("aria-checked") === "true"', 5_000);
    $disputes->waitForLoadState('networkidle');
    expect($disputes->evaluate('() => window.Livewire.find(document.querySelector("[data-test=admin-disputes]").getAttribute("wire:id")).get("hideOwn")'))->toBeTrue()
        ->and($disputes->evaluate('() => window.__errors'))->toBe([])
        ->and($disputes->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

test('the system pages (SystemPages.dc.html) open on one card with the code, the heading and the action, quiet and inside the page, at 390 and 1440', function () {
    [$admin] = adminRevampLeague();
    $problems = [];

    foreach ([1440, 390] as $width) {
        foreach (['404' => '/no-such-page-here', 'match' => route('matches.show', 987654, false)] as $name => $path) {
            $page = adminRevampPage($admin, $path, $width);
            if (getenv('DESIGN_SHOTS')) {
                $page->screenshot(true, "system-{$name}-{$width}");
            }
            $panel = $page->evaluate('() => { const p = document.querySelector("[data-test=system-panel], [data-test=match-not-found]"); const r = p?.getBoundingClientRect(); return p ? { h1: p.querySelector("h1").innerText, action: !!p.querySelector("a.rv-b, a.rv-b2"), left: Math.round(r.left), right: Math.round(r.right) } : null; }');
            $loud = array_merge($page->evaluate('() => window.__errors'), $page->evaluate(ADMIN_REVAMP_SQUEEZE));
            if ($panel === null || ! $panel['action'] || $panel['left'] < 16 || $panel['right'] > $width - 16 || $loud !== []) {
                $problems["{$name} {$width}"] = [$panel, $loud];
            }
        }
    }

    expect($problems)->toBe([]);
});

test('every overlay preset row shows its URL with a working copy button, also after a round trip and when the clipboard refuses, at 390 and 1440', function () {
    [$admin] = adminRevampLeague();
    $token = OverlayPreset::newToken();
    OverlayPreset::factory()->withToken($token)->create(['name' => 'Stream PC']);
    OverlayPreset::factory()->create(['name' => 'Old laptop']);
    $url = OverlayPreset::url($token);
    // A clipboard stub: writeText resolves and keeps the text, or refuses while window.__clipRefuse is set.
    $stub = '() => { window.__clip = null; Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: (t) => window.__clipRefuse ? Promise.reject(new Error("denied")) : (window.__clip = t, Promise.resolve()) } }); }';
    $problems = [];

    foreach ([1440, 390] as $width) {
        $page = adminRevampPage($admin, route('admin.overlays', absolute: false), $width);
        $page->evaluate($stub);
        if (getenv('DESIGN_SHOTS')) {
            $page->screenshot(true, "admin-overlays-urls-{$width}");
        }
        $page->locator('[data-test=overlay-row-copy]')->click();
        BrowserWait::until($page, '() => window.__clip !== null', 5_000);
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=overlay-row-copy] span")].some((s) => s.checkVisibility() && s.innerText === "Kopiert")', 5_000);
        $state = $page->evaluate('() => ({ clip: window.__clip, copied: [...document.querySelectorAll("[data-test=overlay-row-copy] span")].filter((s) => s.checkVisibility()).map((s) => s.innerText), text: document.querySelector("[data-test=overlay-row-url-text]").innerText, legacy: !!document.querySelector("[data-test=overlay-row-legacy]"), copies: document.querySelectorAll("[data-test=overlay-row-copy]").length })');

        // A Livewire round trip (Edit), then the clipboard refuses: the URL is selected instead.
        $page->locator('[data-test=overlay-edit] >> nth=0')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=overlay-cancel]") !== null', 10_000);
        $page->evaluate('() => { window.__clipRefuse = true; }');
        $page->locator('[data-test=overlay-row-copy]')->click();
        BrowserWait::until($page, '() => window.getSelection().toString().length > 0', 5_000);
        $selected = $page->evaluate('() => window.getSelection().toString().trim()');

        $loud = adminRevampLoud($page);
        if ($state['clip'] !== $url || $state['copied'] !== ['Kopiert'] || $state['text'] !== $url || ! $state['legacy'] || $state['copies'] !== 1 || $selected !== $url || $loud !== []) {
            $problems[$width] = [$state, $selected, $loud];
        }
    }

    expect($problems)->toBe([]);
});
