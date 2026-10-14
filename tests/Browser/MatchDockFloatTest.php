<?php

use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\File;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
| The floating match dock is back (user, 2026-10-14, after DerCaddy missed "die 'x Spiele warten auf deine Aktion'
| Leiste"): on every page of a player with open matches but a running game's, the bar with its tabs at the bottom
| right from 1024 px and the bar above the tab bar on a phone, beside the header's "Aktionen". Measured at 375 x 667
| and 1440 x 900, in German: it shows with its count, opens and closes, never covers the footer's last line and never
| pushes the page sideways; on a running board game a phone's play controls and the chat's send button stay free.
| DOCK_SHOTS=<dir> saves the home page closed and open at both widths.
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
 * Satoshi of meHubPlayer (daily games on both sides, a casual 1v1 sent, one scheduled) plus a running Blockli game on
 * his move: at least two open matches, one of them a board game that needs him.
 *
 * @return array{me: User, board: BoardGame}
 */
function dockFloatWorld(): array
{
    BlockliOn::play();
    $world = meHubPlayer();
    $board = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $world['me'], User::factory()->create(['name' => 'Nidan21'])), 'e7 e3 10 10 w - 0');

    return ['me' => $world['me']->refresh(), 'board' => $board];
}

function dockFloatOpen(User $user, string $path, int $width, int $height): mixed
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => window.Alpine !== undefined && !!document.querySelector("[data-test=match-dock-root]")?._x_dataStack', 10_000);

    return $page;
}

function dockFloatShot(mixed $page, string $name): void
{
    $dir = getenv('DOCK_SHOTS');
    if (! is_string($dir) || $dir === '') {
        return;
    }
    $page->screenshot(false, $name);
    File::ensureDirectoryExists($dir);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function dockFloatQuiet(mixed $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');
}

/** The floating bar of this width, its count, what floats, sideways overflow, and whether the footer's last line is hit. */
const DOCK_FLOAT_PROBE = <<<'JS'
(selector) => new Promise((resolve) => {
    const box = (el) => { if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.right), Math.round(r.bottom)]; };
    const root = document.querySelector('[data-test=match-dock-root]');
    const bar = document.querySelector(selector);
    const closed = { bar: box(bar), need: Number(root.dataset.need), open: Number(root.dataset.open), text: bar?.innerText.replace(/\s+/g, ' ').trim() ?? null };
    // The page's end: scrolled to the bottom, the footer's last line must be on top where it is drawn, above the bar.
    window.scrollTo(0, document.documentElement.scrollHeight);
    requestAnimationFrame(() => requestAnimationFrame(() => {
        const lines = [...document.querySelectorAll('footer *')].filter((el) => el.checkVisibility() && el.children.length === 0 && el.textContent.trim() !== '' && el.getBoundingClientRect().height > 0);
        const last = lines.sort((a, b) => a.getBoundingClientRect().bottom - b.getBoundingClientRect().bottom).at(-1);
        const r = last.getBoundingClientRect();
        const hit = document.elementFromPoint(r.left + Math.min(r.width, 40) / 2, r.top + r.height / 2);
        const barTop = Math.min(...[...document.querySelectorAll('[data-test=match-dock], [data-test=dock-mobile-bar], [data-test=dock-cup], [data-test=tab-bar]')].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect().top));
        resolve({
            ...closed,
            footerLast: { text: last.textContent.trim().slice(0, 30), bottom: Math.round(r.bottom), onTop: !!hit && (hit === last || last.contains(hit)), barTop: Math.round(barTop) },
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            scrollY: Math.round(scrollY),
        });
    }));
})
JS;

test('the floating dock shows with its count on home, tournaments and a profile at 375 and 1440, opens, closes and leaves the footer free', function () {
    ['me' => $me] = dockFloatWorld();
    $profile = route('players.show', $me->npub, false);

    foreach ([[375, 667, '[data-test=dock-mobile-bar]'], [1440, 900, '[data-test=match-dock]']] as [$width, $height, $selector]) {
        foreach (['/' => 'home', '/tournaments' => 'tournaments', $profile => 'profile'] as $path => $name) {
            $label = "{$name} {$width}";
            $page = dockFloatOpen($me, $path, $width, $height);
            if ($name === 'home') {
                dockFloatShot($page, "dock-home-{$width}-closed");
            }
            $m = $page->evaluate(DOCK_FLOAT_PROBE, $selector);
            fwrite(STDERR, "[dock float {$label}] ".json_encode($m).PHP_EOL);

            expect($m['bar'])->not->toBeNull($label.': the floating dock shows')
                ->and($m['open'])->toBeGreaterThanOrEqual(2, $label)
                ->and($m['need'])->toBeGreaterThanOrEqual(1, $label)
                ->and($m['bar'][0])->toBeGreaterThanOrEqual(0, $label)
                ->and($m['bar'][2])->toBeLessThanOrEqual($width, $label)
                ->and($m['bar'][3])->toBeLessThanOrEqual($height, $label)
                ->and($m['text'])->toContain((string) $m['need'])
                // The desktop handle says how many need the player, the phone's bar what is first ("Dein Zug, Hal").
                ->and($m['text'])->toContain($width >= 1024 ? ($m['need'] === 1 ? 'braucht dich' : 'brauchen dich') : 'offen')
                ->and($m['overflow'])->toBeLessThanOrEqual(0, $label.': no sideways scroll')
                ->and($m['footerLast']['onTop'])->toBeTrue($label.': the footer\'s last line is not covered')
                ->and($m['footerLast']['bottom'])->toBeLessThanOrEqual($m['footerLast']['barTop'], $label.': the footer ends above the dock');

            if ($width < 1024) {
                // The phone's bar opens the sheet, Escape closes it and gives the focus back to the bar.
                $page->evaluate('() => window.scrollTo(0, 0)');
                $page->locator('[data-test=dock-mobile-bar]')->click();
                BrowserWait::until($page, '() => !!document.querySelector("#dock-sheet")?.checkVisibility()', 5_000);
                $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
                if ($name === 'home') {
                    dockFloatShot($page, "dock-home-{$width}-open");
                }
                $sheet = $page->evaluate('() => { const s = document.querySelector("#dock-sheet").getBoundingClientRect(); return { top: Math.round(s.top), bottom: Math.round(s.bottom), cards: document.querySelectorAll("#dock-sheet [data-test=dock-card]").length, expanded: document.querySelector("[data-test=dock-mobile-bar]").getAttribute("aria-expanded") }; }');
                fwrite(STDERR, "[dock float {$label} sheet] ".json_encode($sheet).PHP_EOL);
                expect($sheet['cards'])->toBe($m['open'], $label)
                    ->and($sheet['bottom'])->toBe($height, $label)
                    ->and($sheet['expanded'])->toBe('true', $label);
                $page->locator('#dock-sheet .dk-x')->press('Escape');
                BrowserWait::until($page, '() => !document.querySelector("#dock-sheet").checkVisibility()', 5_000);
                expect($page->evaluate('() => document.activeElement?.dataset.test'))->toBe('dock-mobile-bar', $label.': focus back on the bar');
            } else {
                // A tab opens its panel (the island, loaded on first open), a click outside closes it.
                $page->evaluate('() => window.scrollTo(0, 0)');
                $page->locator('[data-test=dock-tab] >> nth=0')->click();
                BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=dock-panel]")].some((el) => el.checkVisibility())', 8_000);
                $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
                if ($name === 'home') {
                    dockFloatShot($page, "dock-home-{$width}-open");
                }
                $panel = $page->evaluate('() => { const p = [...document.querySelectorAll("[data-test=dock-panel]")].find((el) => el.checkVisibility()).getBoundingClientRect(); const bar = document.querySelector("[data-test=match-dock]").getBoundingClientRect(); return { panel: [Math.round(p.left), Math.round(p.top), Math.round(p.right), Math.round(p.bottom)], barTop: Math.round(bar.top), expanded: document.querySelector("[data-test=dock-tab]").getAttribute("aria-expanded") }; }');
                fwrite(STDERR, "[dock float {$label} panel] ".json_encode($panel).PHP_EOL);
                expect($panel['panel'][0])->toBeGreaterThanOrEqual(16, $label)
                    ->and($panel['panel'][2])->toBeLessThanOrEqual($width - 16, $label)
                    ->and($panel['panel'][3])->toBeLessThanOrEqual($panel['barTop'], $label.': the panel rises above the bar')
                    ->and($panel['expanded'])->toBe('true', $label);
                $page->locator('footer')->click(['position' => ['x' => 4, 'y' => 4]]);
                BrowserWait::until($page, '() => ![...document.querySelectorAll("[data-test=dock-panel]")].some((el) => el.checkVisibility())', 5_000);
            }

            dockFloatQuiet($page, $label);
        }
    }
});

test('at 1440 the floating dock steps with the arrow keys, Escape gives the focus back to the tab, and the header\'s Aktionen closes the panel and stays open', function () {
    ['me' => $me] = dockFloatWorld();

    // The handle's words are whole in German ("brauchen dich" was cut at 104 px), and the bar fits from 1024 px.
    $handle = '() => { const h = document.querySelector("[data-test=dock-handle]"); const bar = document.querySelector("[data-test=match-dock]").getBoundingClientRect(); return { width: Math.round(h.getBoundingClientRect().width), overflow: h.scrollWidth - h.clientWidth, bar: [Math.round(bar.left), Math.round(bar.right)], text: h.innerText.replace(/\\s+/g, " ").trim() }; }';
    foreach ([1024, 1440] as $width) {
        $page = dockFloatOpen($me, '/', $width, 900);
        $m = $page->evaluate($handle);
        fwrite(STDERR, "[dock float handle {$width}] ".json_encode($m).PHP_EOL);
        expect($m['text'])->toContain('brauchen dich')
            // At 104 px the words ran 18 px past the handle's box (measured): its scroll width says so.
            ->and($m['overflow'])->toBeLessThanOrEqual(0, "{$width}: the handle's words stay inside it")
            ->and($m['bar'][0])->toBeGreaterThanOrEqual(16)
            ->and($m['bar'][1])->toBeLessThanOrEqual($width);
    }

    // Left and right move between the visible tabs.
    $page->locator('[data-test=dock-tab] >> nth=0')->press('ArrowRight');
    $tabs = $page->evaluate('() => { const tabs = [...document.querySelectorAll("[data-test=dock-tab]")].filter((el) => el.checkVisibility()); return { count: tabs.length, at: tabs.indexOf(document.activeElement) }; }');
    expect($tabs['count'])->toBeGreaterThanOrEqual(2)->and($tabs['at'])->toBe(1);

    // Enter opens the second tab's panel, Escape closes it and the focus is back on that tab.
    $page->locator(':focus')->press('Enter');
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=dock-panel]")].some((el) => el.checkVisibility())', 8_000);
    $page->locator(':focus')->press('Escape');
    BrowserWait::until($page, '() => ![...document.querySelectorAll("[data-test=dock-panel]")].some((el) => el.checkVisibility())', 5_000);
    expect($page->evaluate('() => [...document.querySelectorAll("[data-test=dock-tab]")].filter((el) => el.checkVisibility()).indexOf(document.activeElement)'))->toBe(1);

    // A panel open, then the header's "Aktionen": the panel closes, the sheet opens and stays open (one dock, one state).
    $page->locator('[data-test=dock-tab] >> nth=0')->click();
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=dock-panel]")].some((el) => el.checkVisibility())', 8_000);
    $page->locator('[data-test=nav-actions]')->click();
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    $state = $page->evaluate('() => ({ sheet: document.querySelector("#dock-sheet").checkVisibility(), panel: [...document.querySelectorAll("[data-test=dock-panel]")].some((el) => el.checkVisibility()) })');
    fwrite(STDERR, '[dock float 1440 header] '.json_encode($state).PHP_EOL);
    expect($state)->toBe(['sheet' => true, 'panel' => false]);

    // The handle folds the tabs away and back.
    $page->locator('#dock-sheet .dk-x')->press('Escape');
    BrowserWait::until($page, '() => !document.querySelector("#dock-sheet").checkVisibility()', 5_000);
    $page->locator('[data-test=dock-handle]')->click();
    BrowserWait::until($page, '() => !document.querySelector("[data-test=dock-tabs]").checkVisibility() && document.querySelector("[data-test=dock-handle]").getAttribute("aria-expanded") === "false"', 3_000);
    $page->locator('[data-test=dock-handle]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=dock-tabs]").checkVisibility() && document.querySelector("[data-test=dock-handle]").getAttribute("aria-expanded") === "true"', 3_000);
    dockFloatQuiet($page, 'keys 1440');
});

test('on a running board game at 375 x 667 nothing floats over the play controls or the chat\'s send button', function () {
    ['me' => $me, 'board' => $board] = dockFloatWorld();
    $page = dockFloatOpen($me, route('board.show', $board, false), 375, 667);

    $probe = '() => {
        const hit = (el) => { const r = el.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return h && (h === el || el.contains(h)) ? "ok" : (el.dataset.test || el.innerText.trim().slice(0, 20)) + " covered by " + (h?.closest("[data-test]")?.dataset.test ?? h?.tagName); };
        const controls = [...document.querySelectorAll("[data-test=block-input] button, [data-test=block-input] a[href]")].filter((el) => el.checkVisibility() && el.getBoundingClientRect().height > 0);
        // The board is the way to play: its four corners, 6 px in, are the board where they are drawn.
        const board = document.querySelector("[data-focus-board]");
        const b = board.getBoundingClientRect();
        const corners = [[b.left + 6, b.top + 6], [b.right - 6, b.top + 6], [b.left + 6, b.bottom - 6], [b.right - 6, b.bottom - 6]]
            .map(([x, y]) => { const h = document.elementFromPoint(x, y); return h && board.contains(h) ? "ok" : Math.round(x) + "," + Math.round(y) + " covered by " + (h?.closest("[data-test]")?.dataset.test ?? h?.tagName); });
        return {
            board: [Math.round(b.left), Math.round(b.top), Math.round(b.right), Math.round(b.bottom)],
            corners,
            floating: ["[data-test=match-dock]", "[data-test=dock-mobile-bar]", "[data-test=dock-cup]"].filter((s) => document.querySelector(s)?.checkVisibility()),
            controls: controls.length,
            hits: controls.map(hit),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }';
    $m = $page->evaluate($probe);
    fwrite(STDERR, '[dock float board 375 controls] '.json_encode($m).PHP_EOL);
    expect($m['floating'])->toBe([])
        ->and($m['controls'])->toBeGreaterThan(0)
        ->and(array_unique($m['hits']))->toBe(['ok'])
        ->and($m['corners'])->toBe(['ok', 'ok', 'ok', 'ok'])
        ->and($m['overflow'])->toBeLessThanOrEqual(0);

    // The chat sheet open and live (the composer needs a relay: its own state is set), the send button is hit where it is drawn.
    $page->locator('[data-test=chat-sheet-toggle]')->click();
    $page->evaluate('() => { const form = [...document.querySelectorAll("form")].find((f) => f.querySelector("[data-test=chat-send]")); window.Alpine.$data(form).status = "live"; return new Promise((r) => setTimeout(() => requestAnimationFrame(() => r(true)), 400)); }');
    $send = $page->evaluate('() => ["[data-test=chat-send]", "[data-test=chat-input]"].map((s) => { const el = [...document.querySelectorAll(s)].find((e) => e.checkVisibility()); if (!el) return s + " missing"; const r = el.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return h && (h === el || el.contains(h)) ? "ok" : s + " covered by " + (h?.closest("[data-test]")?.dataset.test ?? h?.tagName); })');
    fwrite(STDERR, '[dock float board 375 chat] '.json_encode($send).PHP_EOL);
    expect($send)->toBe(['ok', 'ok']);
    dockFloatQuiet($page, 'board 375');
});

test('positive control: the collector of this file sees a thrown error and a 500 answer', function () {
    ['me' => $me] = dockFloatWorld();
    $page = dockFloatOpen($me, '/', 375, 667);

    $page->evaluate('() => { setTimeout(() => { throw new Error("dock-probe"); }); return fetch("/__test/server-error").then((r) => r.text()).then(() => true); }');
    BrowserWait::until($page, '() => (window.__errors ?? []).some((e) => e.includes("dock-probe"))', 5_000);

    expect(implode(' | ', $page->evaluate('() => window.__errors')))->toContain('dock-probe')->toContain('500')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->not->toBe([]);
});
