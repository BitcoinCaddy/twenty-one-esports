<?php

use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\FakeGame;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Shell navigation, header concept B "game tabs"
|--------------------------------------------------------------------------
|
| Row 1 (game tabs, the hub, Clans, Season, search, account, Admin), row 2
| (the context bar of the active game), the game hub, and below lg the game
| chips, the tab bar and the More sheet (components/shell/). Measured with
| real rects (every width and role: ShellNavigationWidthsTest): the
| chrome height, tap targets, the hub with 4 games and with 12 (FakeGame, a
| test-only registry), the match dock above the tab bar. Every visited page
| keeps a clean console and no response >= 400 (BrowserConsole), with its
| positive control in the last test.
|
| SHELL_SHOTS=<dir> writes the English screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

test('the games menu opens with a click, lists the browser games then the own-copy games, keeps the focus inside and closes with Esc, focus back on its button', function () {
    $player = shellPlayer();
    $problems = [];
    $page = shellPage($player, 1440);
    shellOpen($page, '/clans', $problems);

    // Row 1 has no game tabs (Header.dc.html): the games menu carries every game (GamesMenu.dc.html).
    expect($page->evaluate('() => document.querySelectorAll(".gtab").length'))->toBe(0);

    $page->locator('[data-test=games-menu]')->click();
    BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility() && document.getElementById("game-hub").contains(document.activeElement)', 5_000);
    // Below 13 games no filter row (GamesMenu.dc.html); the registry's order, split in two (rule R19).
    expect($page->evaluate('() => document.getElementById("hub-filter")'))->toBeNull()
        ->and($page->evaluate('() => [...document.querySelectorAll("#game-hub [data-test=hub-browser] [data-hub-game]")].map((el) => el.dataset.hubGame)'))->toBe(['chess'])
        ->and($page->evaluate('() => [...document.querySelectorAll("#game-hub [data-test=hub-own] [data-hub-game]")].map((el) => el.dataset.hubGame)'))
        ->toBe(['rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2']);
    $hub = $page->evaluate('() => { const r = document.getElementById("game-hub").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.height), Math.round(r.left), Math.round(r.right)]; }');
    fwrite(STDERR, "\n[shell-hub] 5 games @1440 top/height/left/right ".json_encode($hub));
    shellShot($page, 'shell-player-1440-hub');

    // Tab stays inside, Esc closes and gives the focus back to the menu button.
    foreach (range(1, 30) as $step) {
        $page->locator(':focus')->press('Tab');
    }
    expect($page->evaluate('() => document.getElementById("game-hub").contains(document.activeElement)'))->toBeTrue();
    $page->locator(':focus')->press('Escape');
    BrowserWait::until($page, '() => !document.getElementById("game-hub").checkVisibility() && document.activeElement?.dataset.test === "games-menu"', 5_000);

    // The keyboard opens it too.
    $page->locator(':focus')->press('Enter');
    BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
    $page->locator(':focus')->press('Escape');

    // "/" jumps to the search field from anywhere but a field.
    $page->evaluate('() => document.activeElement.blur()');
    $page->locator('body')->press('/');
    // The keydown handler binds on mount; under host load, press() can land
    // before it is bound (or before focus() settles), reading an empty
    // activeElement.id if the check runs only once. Poll the real condition.
    // From lg the field stands in row 1 (Header.dc.html).
    BrowserWait::until($page, '() => document.activeElement?.id === "site-search-inline"', 5_000);
    expect($page->evaluate('() => document.activeElement?.id'))->toBe('site-search-inline');

    expect($problems)->toBe([]);
});

/**
 * The open games menu: its rect, whether the list scrolls, and every game row whose name or line is cut (a row
 * clamps both to two lines, GamesMenu.dc.html `.cl2`: a cut one has more content than its box).
 */
const HUB_MEASURE = <<<'JS'
    () => {
        const hub = document.getElementById('game-hub');
        const r = hub.getBoundingClientRect();
        const list = hub.querySelector('[x-ref=hubTiles]');
        const rows = [...hub.querySelectorAll('[data-hub-game]')].filter((el) => el.checkVisibility());
        const cut = rows.flatMap((row) => [...row.querySelectorAll('b, .rv-m')].filter((el) => el.scrollHeight > el.clientHeight + 1 || el.getBoundingClientRect().right > row.getBoundingClientRect().right + 1).map((el) => row.dataset.hubGame + ': ' + el.textContent.trim()));
        return {
            top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height), left: Math.round(r.left), right: Math.round(r.right),
            scrollHeight: list.scrollHeight, clientHeight: list.clientHeight, rows: rows.length, cut,
        };
    }
    JS;

test('the games menu shows every game whole and does not scroll with the registry\'s games at 1280, 1440 and 1920 px, nor run past a phone', function () {
    $player = shellPlayer();
    $problems = [];
    $sizes = [];

    foreach ([1280 => 800, 1440 => 900, 1920 => 1080] as $width => $height) {
        $page = shellPage($player, $width, $height);
        shellOpen($page, '/clans', $problems);
        $page->locator('[data-test=games-menu]')->click();
        BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
        $page->evaluate(SHELL_SETTLE);
        $m = $sizes["5@{$width}"] = $page->evaluate(HUB_MEASURE);
        shellShot($page, "shell-player-{$width}-hub");

        expect($m['scrollHeight'])->toBeLessThanOrEqual($m['clientHeight'], "inner scroll @{$width}: ".json_encode($m))
            ->and($m['bottom'])->toBeLessThanOrEqual($height)
            ->and($m['right'])->toBeLessThanOrEqual($width)
            ->and($m['rows'])->toBe(5)
            ->and($m['cut'])->toBe([], "cut rows @{$width}");
    }

    // The phone's sheet: one column above the tab bar, every row whole.
    $page = shellPage($player, 375, 667);
    shellOpen($page, '/clans', $problems);
    $page->locator('[data-test=tab-games]')->click();
    BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
    $page->evaluate(SHELL_SETTLE);
    $m = $sizes['5@375'] = $page->evaluate(HUB_MEASURE);
    expect($m['rows'])->toBe(5)->and($m['cut'])->toBe([])->and($m['right'])->toBeLessThanOrEqual(375);

    fwrite(STDERR, "\n[shell-hub-grid] ".json_encode($sizes));
    expect($problems)->toBe([]);
});

test('row 2 shows on the pages of a game only, with that game\'s links', function () {
    $player = User::factory()->create();
    $problems = [];
    $page = shellPage($player, 1280);
    $state = '() => ({ game: document.querySelector("[data-test=context-bar]")?.dataset.game ?? null, links: [...document.querySelectorAll("[data-test=context-bar] a")].map((a) => a.dataset.test) })';

    // A page of no game: one row (Header.dc.html).
    shellOpen($page, '/clans', $problems);
    expect($page->evaluate($state))->toMatchArray(['game' => null]);

    // A game's own pages carry its game bar with the fixed tabs (template T1/T5, rule R5).
    shellOpen($page, route('games.rocket-league', absolute: false), $problems);
    $rl = $page->evaluate($state);
    expect($rl['game'])->toBe('rocket-league')
        ->and($rl['links'])->toBe(['ctx-play', 'ctx-matches', 'ctx-ladder', 'ctx-tournaments', 'ctx-rules']);
    shellShot($page, 'shell-player-1280-rocket-league');

    shellOpen($page, route('ladder.show', ['chess', 'blitz'], false), $problems);
    $chess = $page->evaluate($state);
    expect($chess['game'])->toBe('chess')
        ->and($chess['links'])->toBe(['ctx-play', 'ctx-matches', 'ctx-ladder', 'ctx-tournaments', 'ctx-rules']);

    // A page the revamp has not drawn yet keeps the header's row 2 with the game's links (the match list of a game).
    shellOpen($page, route('matches.index', ['game' => 'rocket-league'], false), $problems);
    expect($page->evaluate($state)['game'])->toBe('rocket-league');

    expect($problems)->toBe([]);
});

test('the games menu with 8 and 13 games (a test-only registry): from 13 a search and kind chips that stay while the list scrolls, at 1440 and 375 px', function () {
    $admin = shellAdmin();
    $problems = [];
    $sizes = [];

    foreach ([8, 13] as $count) {
        // FakeGame::registry() builds on the bound registry: the real one each time, not the last fake.
        app()->forgetInstance(GameRegistry::class);
        app()->instance(GameRegistry::class, FakeGame::registry($count));

        foreach ([1440 => 900, 375 => 667] as $width => $height) {
            $page = shellPage($admin, $width, $height);
            shellOpen($page, '/rules', $problems);
            $page->locator($width === 1440 ? '[data-test=games-menu]' : '[data-test=tab-games]')->click();
            BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
            $page->evaluate(SHELL_SETTLE);
            $m = $page->evaluate(HUB_MEASURE);
            $m['filter'] = $page->evaluate('() => !!document.getElementById("hub-filter")');
            $m['tabbarTop'] = $page->evaluate('() => Math.round(document.querySelector("[data-test=tab-bar]").getBoundingClientRect().top)');
            expect($m['rows'])->toBe($count)
                ->and($m['filter'])->toBe($count >= 13)
                ->and($m['bottom'])->toBeLessThanOrEqual($width === 375 ? $m['tabbarTop'] : $height);
            shellShot($page, "shell-admin-{$width}-hub-{$count}games");

            if ($count >= 13) {
                // The search narrows the rows, a kind chip too, and nothing found says so.
                $page->locator('#hub-filter')->fill('rock');
                BrowserWait::until($page, '() => [...document.querySelectorAll("#game-hub [data-hub-game]")].filter((el) => el.checkVisibility()).length === 1', 5_000);
                $page->locator('#hub-filter')->fill('zzz');
                BrowserWait::until($page, '() => document.querySelector("[data-test=hub-empty]").checkVisibility()', 5_000);
                $page->locator('#hub-filter')->fill('');
                $page->locator('[data-test=hub-kind-series]')->click();
                BrowserWait::until($page, '() => document.querySelector("[data-test=hub-kind-series]").getAttribute("aria-pressed") === "true"', 5_000);
                // x-show settles after the pressed state: wait for the rows themselves.
                BrowserWait::until($page, '() => { const shown = [...document.querySelectorAll("#game-hub [data-hub-game]")].filter((el) => el.checkVisibility()).map((el) => el.dataset.hubGame); return shown.includes("rocket-league") && ! shown.includes("chess"); }', 5_000);

                // Scrolled to its end, the list leaves the filter row where it was.
                if ($m['scrollHeight'] > $m['clientHeight']) {
                    $top = $page->evaluate('() => Math.round(document.getElementById("hub-filter").getBoundingClientRect().top)');
                    $page->evaluate('() => { const list = document.querySelector("#game-hub [x-ref=hubTiles]"); list.scrollTop = list.scrollHeight; }');
                    expect($page->evaluate('() => Math.round(document.getElementById("hub-filter").getBoundingClientRect().top)'))->toBe($top);
                }
            }
            $sizes["{$count}@{$width}"] = $m;
        }
    }

    app()->forgetInstance(GameRegistry::class);
    fwrite(STDERR, "\n[shell-hub] ".json_encode($sizes));
    expect($problems)->toBe([]);
});

test('on a phone: the tab bar, the More sheet and the hub sheet; the match dock sits above the tab bar; a guest sees "Log in" whole', function () {
    $player = shellPlayer();
    $problems = [];

    // The guest first: the pages of one test share their cookies, so a guest after the login would not be one.
    $guest = shellPage(null, 375, 667);
    shellOpen($guest, '/', $problems);
    $login = $guest->evaluate('() => { const r = document.querySelector("[data-test=mobile-login]").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.top), Math.round(r.bottom), Math.round(r.height)]; }');
    fwrite(STDERR, "\n[shell-mobile] guest Log in rect ".json_encode($login));
    expect($login[0])->toBeGreaterThanOrEqual(0)
        ->and($login[1])->toBeLessThanOrEqual(375)
        ->and($login[2])->toBeGreaterThanOrEqual(0)
        ->and($login[4])->toBeGreaterThanOrEqual(44);
    shellShot($guest, 'shell-guest-375-home');

    $guest->locator('[data-test=tab-more]')->click();
    BrowserWait::until($guest, '() => document.getElementById("more-sheet").checkVisibility()', 5_000);
    shellShot($guest, 'shell-guest-375-more');

    // "New here?" (not on home and login, which carry their own way in): dismissed, it stays away on the next page.
    $guest->locator(':focus')->press('Escape');
    BrowserWait::until($guest, '() => !document.getElementById("more-sheet").checkVisibility() && document.activeElement?.dataset.test === "tab-more"', 5_000);
    expect($guest->evaluate('() => !!document.querySelector("[data-test=first-steps]")'))->toBeFalse();
    shellOpen($guest, '/rules', $problems);
    $guest->locator('[data-test=first-steps-dismiss]')->click();
    shellOpen($guest, '/clans', $problems);
    expect($guest->evaluate('() => document.querySelector("[data-test=first-steps]").checkVisibility()'))->toBeFalse();

    $page = shellPage($player, 375, 667);
    shellOpen($page, route('games.rocket-league', absolute: false), $problems);
    $rects = '() => { const r = (s) => { const el = document.querySelector(s); if (!el || !el.checkVisibility()) return null; const b = el.getBoundingClientRect(); return [Math.round(b.top), Math.round(b.bottom), Math.round(b.left), Math.round(b.right)]; }; return { dock: r("[data-test=dock-mobile-bar]"), tabbar: r("[data-test=tab-bar]"), tabs: [...document.querySelectorAll("[data-test=tab-bar] .rv-pb-item")].map((t) => t.textContent.trim().replace(/\\s+/g, " ") + " " + Math.round(t.getBoundingClientRect().height)) }; }';
    $m = $page->evaluate($rects);
    fwrite(STDERR, "\n[shell-mobile] player 375x667 ".json_encode($m));

    // The same five places on every page (Header.dc.html "Handy"); the game's own pages are in the More sheet.
    expect(array_map(fn (string $tab): string => preg_replace('/\s*\d+$/', '', preg_replace('/ \d+ (\d+)$/', ' $1', $tab)), $m['tabs']))->toBe(['Game list', 'Tournaments', 'Mempool', 'Actions', 'You'])
        ->and(array_map(fn (string $tab): int => (int) substr(strrchr($tab, ' '), 1), $m['tabs']))->each->toBeGreaterThanOrEqual(44)
        // The action dock is the tab bar's "Aktionen" since P7 (ActionDockPhone.dc.html): nothing floats above the bar.
        ->and($m['dock'])->toBeNull()
        ->and($page->evaluate('() => !!document.querySelector("#more-sheet [data-test=more-game]")'))->toBeTrue();
    shellShot($page, 'shell-player-375-rocket-league');

    $page->locator('[data-test=tab-more]')->click();
    BrowserWait::until($page, '() => document.getElementById("more-sheet").checkVisibility()', 5_000);
    $page->evaluate(SHELL_SETTLE);
    $sheet = $page->evaluate('() => { const r = document.getElementById("more-sheet").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.bottom), document.getElementById("more-sheet").scrollHeight]; }');
    fwrite(STDERR, "\n[shell-mobile] More sheet top/bottom/scrollHeight ".json_encode($sheet));
    expect($sheet[1])->toBe($m['tabbar'][0])
        // No label cut short, and "Season" on one line (it broke into "Sea son" next to its tag).
        ->and($page->evaluate('() => [...document.querySelectorAll("#more-sheet a span")].filter((el) => el.scrollWidth > el.clientWidth + 1).length'))->toBe(0)
        ->and($page->evaluate('() => { const r = document.createRange(); r.selectNodeContents(document.querySelector("[data-test=mobile-season] span").firstChild); return r.getClientRects().length; }'))->toBe(1);
    shellShot($page, 'shell-player-375-more');
    $page->locator(':focus')->press('Escape');
    BrowserWait::until($page, '() => !document.getElementById("more-sheet").checkVisibility()', 5_000);

    $page->locator('[data-test=tab-games]')->click();
    BrowserWait::until($page, '() => document.getElementById("game-hub").checkVisibility()', 5_000);
    shellShot($page, 'shell-player-375-hub');

    expect($problems)->toBe([]);
});

test('/play lists every game for a guest and a player, in the registry\'s order (rule R19)', function () {
    $player = shellPlayer();
    $problems = [];

    foreach (['guest' => null, 'player' => $player] as $role => $user) {
        foreach ([1440 => 900, 375 => 667] as $width => $height) {
            $page = shellPage($user, $width, $height);
            shellOpen($page, route('play', absolute: false), $problems);
            $m = $page->evaluate('() => ({ games: [...document.querySelectorAll("[data-test^=play-game-]")].map((el) => el.dataset.test.replace("play-game-", "")), login: !!document.querySelector("[data-test=play-login]"), widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth] })');
            fwrite(STDERR, "\n[shell-play] {$role} @{$width} ".json_encode($m));
            expect($m['games'])->toHaveCount(count(app(GameRegistry::class)->all()))
                ->and($m['login'])->toBe($role === 'guest')
                ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1]);
            expect(array_slice($m['games'], 0, 2))->toBe(['chess', 'rocket-league']);
            shellShot($page, "shell-{$role}-{$width}-play");
        }
    }

    expect($problems)->toBe([]);
});

test('positive control: the collector of this file sees a thrown error and a 404 fetch', function () {
    $problems = [];
    $page = shellPage(null, 1440);
    shellOpen($page, '/rules', $problems);
    $page->evaluate('() => { setTimeout(() => { throw new Error("shell positive control"); }); fetch("/__probe/missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("shell positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);

    expect($problems)->toBe([]);
});
