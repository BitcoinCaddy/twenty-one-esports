<?php

use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

/*
| Helpers of the shell navigation browser tests (header concept B):
| tests/Browser/ShellNavigationTest.php and ShellNavigationWidthsTest.php.
| Two files, two shards: together they take longer than one shard should.
*/

/** Rects of the chrome: header rows, tab bar, sideways scroll and every control under 44 px (below lg) or 24 px (from lg). */
const SHELL_MEASURE = <<<'JS'
    () => {
        const rect = (el) => { if (!el || !el.checkVisibility({ checkVisibilityCSS: true })) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height), width: Math.round(r.width) }; };
        const header = document.querySelector('body > header');
        const rows = [...header.children].filter((el) => el.checkVisibility({ checkVisibilityCSS: true }) && getComputedStyle(el).position === 'static');
        const desktop = window.innerWidth >= 1024;
        const min = desktop ? 24 : 44;
        const small = [];
        for (const root of [header, document.getElementById('mobile-nav')]) {
            for (const el of root.querySelectorAll('a[href], button, input:not([type=hidden])')) {
                if (!el.checkVisibility({ checkVisibilityCSS: true }) || el.closest('#bell-panel')) continue;
                const r = el.getBoundingClientRect();
                if (r.width === 0 || r.height === 0) continue;
                if (Math.round(r.height) < min || Math.round(r.width) < min) small.push(`${(el.dataset.test || el.textContent.trim().replace(/\s+/g, ' ').slice(0, 30) || el.tagName)} ${Math.round(r.width)}x${Math.round(r.height)}`);
            }
        }
        const tabs = [...document.querySelectorAll('.gtab:not(.gtab-hub)')].filter((el) => el.checkVisibility({ checkVisibilityCSS: true })).map((el) => el.dataset.test.replace('game-tab-', '') + ':' + el.querySelector(getComputedStyle(el.querySelector('.gtab-full')).display === 'none' ? '.gtab-short' : '.gtab-full').textContent.trim());
        // Squeezed, not scrolled: a row whose content is wider than its box, a label cut short.
        // Not a `.sr-only` span: it is a 1 px clipped box by design (the More tab's ", 1 upcoming match", 93653e8a), its text is for the screen reader.
        const squeezed = [...document.querySelectorAll('[data-test=main-nav], [data-test=context-bar], [data-test=tab-bar] .rv-pb, [data-test=tab-bar] .rv-pb-item span, .rv-nv, .ctx-link')]
            // The game bar of the templates (T1/T5, rule R5) scrolls its tabs sideways on a phone by design: measured below.
            .filter((el) => el.checkVisibility({ checkVisibilityCSS: true }) && !el.closest('.sr-only') && !el.closest('.gs-bar') && el.scrollWidth > el.clientWidth + 1)
            .map((el) => `${el.dataset.test || el.className.split(' ')[0] || el.tagName} ${el.scrollWidth}>${el.clientWidth}`);
        // The game bar: no tab label cut inside its tab, and the current tab whole inside the bar's visible part.
        const gameBar = document.querySelector('.gs-bar .gs-tabs');
        if (gameBar && gameBar.checkVisibility()) {
            const bar = gameBar.getBoundingClientRect();
            for (const tab of gameBar.querySelectorAll('.gs-tab')) {
                if (tab.scrollWidth > tab.clientWidth + 1) squeezed.push(`game tab ${tab.textContent.trim()} ${tab.scrollWidth}>${tab.clientWidth}`);
                const r = tab.getBoundingClientRect();
                if (tab.hasAttribute('aria-current') && (r.left < bar.left - 1 || r.right > bar.right + 1)) squeezed.push(`current game tab ${tab.textContent.trim()} outside the bar`);
            }
        }
        // The phone's game chips: no label cut inside its chip, no chip wider than the row, the active one whole inside the row's visible part.
        const chipRow = document.getElementById('game-chips');
        if (chipRow && chipRow.checkVisibility()) {
            const row = chipRow.getBoundingClientRect();
            for (const span of chipRow.querySelectorAll('.gchip span')) {
                if (span.scrollWidth > span.clientWidth + 1) squeezed.push(`chip label ${span.textContent.trim()} ${span.scrollWidth}>${span.clientWidth}`);
            }
            for (const chip of chipRow.querySelectorAll('.gchip')) {
                const r = chip.getBoundingClientRect();
                if (r.width > row.width + 1) squeezed.push(`chip ${chip.textContent.trim()} ${Math.round(r.width)} wider than its row ${Math.round(row.width)}`);
                if (chip.hasAttribute('aria-current') && (r.left < row.left - 1 || r.right > row.right + 1)) squeezed.push(`active chip ${chip.textContent.trim()} ${Math.round(r.left)}-${Math.round(r.right)} outside its row ${Math.round(row.left)}-${Math.round(row.right)}`);
            }
        }
        const nav = document.querySelector('[data-test=main-nav]');
        // The search field, first after the nav from lg (Header.dc.html).
        const search = document.querySelector('[data-test=search-inline]');
        if (nav && search && nav.checkVisibility() && search.checkVisibility()) {
            const last = [...nav.children].filter((el) => el.checkVisibility()).pop();
            if (last && last.getBoundingClientRect().right > search.getBoundingClientRect().left) squeezed.push(`row 1 runs under the search: ${Math.round(last.getBoundingClientRect().right)} > ${Math.round(search.getBoundingClientRect().left)}`);
        }
        return {
            squeezed,
            width: window.innerWidth,
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
            header: rect(header),
            rows: rows.map((el) => (el.dataset.test || el.tagName.toLowerCase()) + ' ' + Math.round(el.getBoundingClientRect().height)),
            tabbar: rect(document.querySelector('[data-test=tab-bar]')),
            tabs,
            small,
            lang: document.documentElement.lang,
        };
    }
    JS;

/**
 * Every visible item of desktop row 1, the game tabs and the chain rail's
 * links one by one (plan "Mempool-Streifen", P4): `name left-right`, and
 * what is wrong with them. An item is cut when its content is wider than its
 * box, overlaps when it starts before the previous one ends, and is out when
 * it leaves the viewport.
 */
const SHELL_ROW1 = <<<'JS'
    () => {
        const vis = (el) => el.checkVisibility({ checkVisibilityCSS: true }) && el.getBoundingClientRect().width > 0;
        const row = document.querySelector('body > header > div');
        const items = [];
        const walk = (el) => {
            if (!vis(el)) return;
            if (el.matches('[data-test=main-nav]')) { [...el.children].forEach(walk); return; }
            if (el.matches('span.grow')) return;
            items.push(el);
        };
        [...row.children].forEach(walk);
        const name = (el) => el.dataset.test || el.getAttribute('aria-label') || el.tagName.toLowerCase();
        const problems = [];
        let previous = null;
        const out = items.map((el) => {
            const r = el.getBoundingClientRect();
            const [left, right] = [Math.round(r.left), Math.round(r.right)];
            if (el.scrollWidth > el.clientWidth + 1) problems.push(`${name(el)} cut ${el.scrollWidth}>${el.clientWidth}`);
            if (previous !== null && left < previous[1] - 0.5) problems.push(`${name(el)} ${left} overlaps ${previous[0]} ${previous[1]}`);
            if (left < 0 || right > window.innerWidth) problems.push(`${name(el)} ${left}-${right} out of ${window.innerWidth}`);
            previous = [name(el), right];
            return `${name(el)} ${left}-${right}`;
        });
        // The search field's placeholder is whole or not shown, never cut ("Searc" at 1024 in English on air with a live cup game, E3).
        const field = document.querySelector('#site-search-inline');
        if (field && vis(field) && field.placeholder) {
            const style = getComputedStyle(field), context = document.createElement('canvas').getContext('2d');
            context.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
            const need = context.measureText(field.placeholder).width, room = field.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            if (getComputedStyle(field, '::placeholder').color !== 'rgba(0, 0, 0, 0)' && need > room + 0.5) problems.push(`search placeholder cut ${Math.round(need)}>${Math.round(room)}`);
        }
        const text = (sel) => { const el = document.querySelector(sel); return el && vis(el) ? el.innerText.trim() : null; };
        // Any two items overlapping, not only neighbours (the LIVE badge ran under the search button once).
        const rects = items.map((el) => [name(el), el.getBoundingClientRect()]);
        rects.forEach(([a, ra], i) => rects.slice(i + 1).forEach(([b, rb]) => { const h = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left); if (h > 0.5) problems.push(`${a} overlaps ${b} by ${Math.round(h)}`); }));
        const admin = document.querySelector('[data-test=admin-count]');
        return { items: out, problems, count: text('[data-test=mempool-count]'), tag: text('[data-test=season-tag]'), casual: text('[data-test=nav-casual]'),
            admin: admin && vis(admin) ? [...admin.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join('') : null };
    }
    JS;

/** The desktop footer's counts and links (E2): each on one line, none past the window. */
const SHELL_FOOTER = <<<'JS'
    () => {
        const row = document.querySelector('[data-test=footer-row]');
        if (!row || !row.checkVisibility()) return null;
        const lines = (el) => { const tops = new Set(); const walk = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); let n; while ((n = walk.nextNode())) { if (!n.textContent.trim()) continue; const r = document.createRange(); r.selectNodeContents(n); for (const q of r.getClientRects()) if (q.width > 1) tops.add(Math.round(q.top)); } return tops.size; };
        const items = [...row.querySelectorAll('span:has(> b), a, nav > b')].filter((el) => el.checkVisibility());
        return { wrapped: items.filter((el) => lines(el) > 1).map((el) => `${el.textContent.trim().replace(/\s+/g, ' ')} ${lines(el)} lines`), items: items.length,
            right: Math.round(Math.max(...items.map((el) => el.getBoundingClientRect().right))), height: Math.round(row.getBoundingClientRect().height) };
    }
    JS;

/** Every finite animation finished (a sheet measured mid-rise is 24 px off), then two frames. */
const SHELL_SETTLE = '() => Promise.all(document.getAnimations().filter((a) => a.effect?.getComputedTiming().iterations !== Infinity).map((a) => a.finished.catch(() => null))).then(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true)))))';

function shellPage(?User $user, int $width, int $height = 900): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);

    return $page;
}

/**
 * @param  list<string>  $problems
 */
function shellOpen(Page $page, string $url, array &$problems): void
{
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!window.Alpine', 10_000);
    $page->evaluate(SHELL_SETTLE);

    foreach ([...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)] as $problem) {
        $problems[] = "{$url}: {$problem}";
    }
}

function shellShot(Page $page, string $name): void
{
    $dir = getenv('SHELL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->evaluate(SHELL_SETTLE);
    // The viewport, not the full page: a full-page shot paints the fixed tab bar in the middle of a long page.
    $page->screenshot(false, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * A player whose last match was Rocket League, the one before chess, and an
 * open daily game (so the match dock shows).
 */
function shellPlayer(): User
{
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->clan->owner;
    $player->forceFill(['name' => 'Pia Player'])->save();
    ChessGame::factory()->daily()->create(['white_id' => $player->id, 'created_at' => now()->subDays(2)]);
    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'challenged_lineup_id' => Lineup::factory()->mode('3v3')->ready()->create()->id, 'created_at' => now()->subDay()]);

    return $player;
}

function shellAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Ada Admin']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}
