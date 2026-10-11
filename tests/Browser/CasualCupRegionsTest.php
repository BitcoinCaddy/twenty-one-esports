<?php

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\TournamentLanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| EU and US casual cups side by side (user, 2026-09-28)
|--------------------------------------------------------------------------
|
| The league opens a Rocket League cup per region. The game page lists both,
| the EU cup's own page names the US one; a guest's browser in New York
| sees each start on its own clock. Measured at 375 and 1440: nothing wider
| than the window, every row a 44 px target, EU and US next to each other
| from lg and stacked below; the console and the answers clean, with a
| positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['rocket-league']]);
    // Monday 5 October 2026: the EU cup starts Saturday 20:00 Berlin (14:00 New York), the US cup Saturday 20:00 New York.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    app(CasualCups::class)->tick();
});

/**
 * The browser's clock follows the server's travelled one (Monday 5 October 2026 from beforeEach) and keeps running.
 * Without it the page's countdowns count to the cups' real start on the real clock: the FC 26 EU cup starts Friday
 * 9 October 18:00 Berlin, and from then on its countdown hit zero on load, "countdown-zero" hid the sign-up button
 * (resources/views/components/tournaments/cup-hall.blade.php, x-show="! started") and the head test measured a
 * button of height 0 (CUP_HEAD_STATE cta, "0 >= 44", from 16:00 UTC on 2026-10-09).
 */
function cupRegionsClock(): string
{
    $offset = now()->getTimestampMs() - (int) floor(microtime(true) * 1000);

    return '(() => { const RealDate = Date; const offset = '.$offset.'; window.Date = class extends RealDate { constructor(...args) { args.length ? super(...args) : super(RealDate.now() + offset); } static now() { return RealDate.now() + offset; } }; })();';
}

function cupRegionsPage(string $path, int $width, int $height, string $ready): Page
{
    $page = visit('/robots.txt')->withTimezone('America/New_York')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(cupRegionsClock());
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("'.$ready.'") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function cupRegionsShot(Page $page, string $name): void
{
    $dir = getenv('CASUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * The cup rows: name, start as shown, box; and the page's overflow.
 *
 * @return array{overflow: int, rows: list<array{name: string, start: string, left: int, right: int, top: int, bottom: int, height: int}>, width: int}
 */
function cupRegionsRows(Page $page): array
{
    return $page->evaluate('() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        width: window.innerWidth,
        rows: [...document.querySelectorAll("[data-test=cup-mention]")].filter((el) => el.checkVisibility()).map((el) => {
            const r = el.getBoundingClientRect();
            const name = [...el.querySelectorAll("span")].map((s) => s.textContent).find((t) => t.includes("Casual Cup")) || "";
            const start = (el.querySelector("[data-test=cup-mention-start]")?.innerText || "").replace(/\s+/g, " ").trim();
            return { name, start, left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height) };
        }),
    })');
}

function cupRegionsControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('the game page lists the EU and the US cup, each start on the viewer\'s clock, side by side from lg and stacked on a phone', function () {
    $wide = cupRegionsPage('/games/rocket-league', 1440, 900, '[data-test=cup-mentions]');
    cupRegionsControl($wide);
    $desk = cupRegionsRows($wide);
    cupRegionsShot($wide, 'cup-regions-game-1440');

    $narrow = cupRegionsPage('/games/rocket-league', 375, 812, '[data-test=cup-mentions]');
    $phone = cupRegionsRows($narrow);
    cupRegionsShot($narrow, 'cup-regions-game-375');

    foreach ([$desk, $phone] as $measured) {
        expect($measured['overflow'])->toBe(0)
            ->and(array_column($measured['rows'], 'name'))->toBe(['Rocket League Casual Cup EU #1', 'Rocket League Casual Cup US #1'])
            // The browser's own zone (New York), not the league's: 20:00 Berlin is 14:00 there.
            ->and(array_column($measured['rows'], 'start'))->toBe(['2:00 PM Sat, Oct 10, New York', '8:00 PM Sat, Oct 10, New York'])
            ->and(collect($measured['rows'])->every(fn (array $row): bool => $row['left'] >= 0 && $row['right'] <= $measured['width'] && $row['height'] >= 44))->toBeTrue();
    }

    [$eu, $us] = $desk['rows'];
    [$euPhone, $usPhone] = $phone['rows'];

    expect($us['top'])->toBe($eu['top'])
        ->and($us['left'])->toBeGreaterThan($eu['right'])
        ->and($usPhone['top'])->toBeGreaterThanOrEqual($euPhone['bottom']);

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('the EU cup\'s page names the US cup with its start on the viewer\'s clock, at 1440 and 375', function () {
    $eu = Tournament::query()->where('cup_open_series', 'rocket-league-eu')->sole();

    $wide = cupRegionsPage(route('tournaments.show', $eu, false), 1440, 900, '[data-test=cup-other-regions] [data-test=cup-mention]');
    cupRegionsControl($wide);
    $desk = cupRegionsRows($wide);
    $title = $wide->evaluate('() => document.querySelector("#t-name").innerText');
    cupRegionsShot($wide, 'cup-regions-page-1440');

    $narrow = cupRegionsPage(route('tournaments.show', $eu, false), 375, 812, '[data-test=cup-other-regions] [data-test=cup-mention]');
    $phone = cupRegionsRows($narrow);
    cupRegionsShot($narrow, 'cup-regions-page-375');

    expect($title)->toBe('Rocket League Casual Cup EU #1');

    foreach ([$desk, $phone] as $measured) {
        expect($measured['overflow'])->toBe(0)
            ->and($measured['rows'])->toHaveCount(1)
            ->and($measured['rows'][0]['name'])->toBe('Rocket League Casual Cup US #1')
            ->and($measured['rows'][0]['start'])->toBe('8:00 PM Sat, Oct 10, New York')
            ->and($measured['rows'][0]['right'])->toBeLessThanOrEqual($measured['width'])
            ->and($measured['rows'][0]['height'])->toBeGreaterThanOrEqual(44);
    }

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

/*
| The cups on the tournaments page (P53, redrawn in plan "Refactor und
| Design-Revamp", P6: Tournaments.dc.html, TournamentsPhone.dc.html). The
| boards drop the per-game cup groups, the cup hall and the organizer cards:
| one "Open for sign-up" list carries every tournament, the organizers' (with
| a pot) first, then the casual cups by start, each row with its game's cover;
| the game chips filter it, from lg also the region chips and "Only free
| places" (the phone board shows the game chips only and names the region in
| the row). The chips are links: the filter is the page's URL. Running cups
| stand under "Running", finished ones in the archive with its Hall of Fame
| (Archive.dc.html). Measured at 1440 and 375 in English and at 375 in German:
| no overflow, each row's cover drawn, and the console and the answers clean,
| with a positive control.
*/

/**
 * The open list and the running list as the browser shows them: game and region of each visible row in page order,
 * whether its cover is drawn, the count beside the heading, the overflow and the URL.
 *
 * @return array{open: list<array{id: string, game: string, region: string, cover: bool}>, running: list<array{id: string, game: string, region: string}>, count: string|null, empty: bool, overflow: int, url: string}
 */
function cupBoardState(Page $page): array
{
    return $page->evaluate('() => ({
        open: [...document.querySelectorAll("[data-test=open-row], [data-test=open-row-phone]")].filter((r) => r.checkVisibility()).map((r) => ({
            id: r.dataset.tournament,
            game: r.dataset.game,
            region: r.dataset.region,
            cover: (() => { const img = r.querySelector("[data-game-cover] img"); return !!img && img.getBoundingClientRect().width >= 64; })(),
        })),
        running: [...document.querySelectorAll("[data-test=running-row]")].filter((r) => r.checkVisibility()).map((r) => ({ id: r.dataset.tournament, game: r.dataset.game, region: r.dataset.region })),
        count: document.querySelector("[data-test=open-tournaments] .rv-n")?.checkVisibility() ? document.querySelector("[data-test=open-tournaments] .rv-n").innerText.trim() : null,
        empty: !!document.querySelector("[data-test=open-empty]")?.checkVisibility(),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        url: location.href,
    })');
}

/** @param  list<array{game: string, region: string}>  $rows */
function cupPairs(array $rows): array
{
    return array_map(fn (array $row): string => $row['game'].' '.$row['region'], $rows);
}

test('the tournaments page lists the cups with covers and filters them by game, region and free places, at 1440 and 375, German at 375', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    app(CasualCups::class)->tick();
    // The Rocket League US cup is running: it leaves the open list for "Running".
    Tournament::query()->where('cup_open_series', 'rocket-league-us')->sole()->forceFill(['status' => TournamentStatus::Running])->save();
    $open = ['chess eu', 'chess us', 'ea-sports-fc-26 eu', 'ea-sports-fc-26 us', 'rocket-league eu'];

    foreach ([[1440, 900, 'en'], [375, 812, 'en'], [375, 812, 'de']] as [$width, $height, $lang]) {
        $page = cupRegionsPage('/tournaments', $width, $height, '[data-test=open-tournaments]');

        if ($lang === 'de') {
            $page->goto(ComputeUrl::from('/locale/de'));
            $page->goto(ComputeUrl::from('/tournaments'));
            BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=open-tournaments]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        if ($width === 1440) {
            cupRegionsControl($page);
        }

        $start = cupBoardState($page);
        cupRegionsShot($page, "cup-board-{$width}-{$lang}");

        $sorted = cupPairs($start['open']);
        sort($sorted);
        expect([$width, $lang, $sorted, $start['overflow']])->toBe([$width, $lang, $open, 0])
            ->and(collect($start['open'])->every(fn (array $row): bool => $row['cover']))->toBeTrue()
            ->and(cupPairs($start['running']))->toContain('rocket-league us')
            ->and($start['count'])->toBe($width >= 1024 ? '5' : null);

        if ($width >= 1024) {
            // Region US (lg up, Tournaments.dc.html): the US cups only, the open list and the running one; the URL says so.
            $page->locator('[data-test=filter-region-us]')->click();
            BrowserWait::until($page, '() => document.readyState === "complete" && location.search.includes("region=us") && document.querySelector("[data-test=filter-region-us]").getAttribute("aria-current") === "page"', 5_000);
            $us = cupBoardState($page);
            expect(collect($us['open'])->every(fn (array $row): bool => $row['region'] === 'us'))->toBeTrue()
                ->and(collect(cupPairs($us['open']))->sort()->values()->all())->toBe(['chess us', 'ea-sports-fc-26 us'])
                ->and(cupPairs($us['running']))->toBe(['rocket-league us'])
                ->and($us['count'])->toBe('2');

            // Plus free places only: the running Rocket League US cup stays out of the open list.
            $page->locator('[data-test=filter-free]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=filter-free]").getAttribute("aria-checked") === "true"', 5_000);
            expect(collect(cupPairs(cupBoardState($page)['open']))->sort()->values()->all())->toBe(['chess us', 'ea-sports-fc-26 us']);

            // Plus Rocket League: nothing open matches, the way back resets game, region and free places.
            $page->locator('[data-test=filter-game-rocket-league]')->click();
            BrowserWait::until($page, '() => document.readyState === "complete" && !!document.querySelector("[data-test=open-empty]")?.checkVisibility()', 5_000);
            expect(cupBoardState($page)['open'])->toBe([]);
            $page->locator('[data-test=open-empty] a')->click();
            BrowserWait::until($page, '() => document.readyState === "complete" && location.search === "" && document.querySelectorAll("[data-test=open-row]").length > 0', 5_000);
            $back = cupPairs(cupBoardState($page)['open']);
            sort($back);
            expect($back)->toBe($open);
        } else {
            // A phone (TournamentsPhone.dc.html): the game chips, the region in each row's line.
            expect($page->evaluate('() => document.querySelector("[data-test=filter-region]").checkVisibility()'))->toBeFalse()
                ->and($page->evaluate('() => [...document.querySelectorAll("[data-test=open-row-phone]")].filter((r) => r.checkVisibility()).every((r) => /\b(EU|US)\b/.test(r.innerText))'))->toBeTrue();
            $page->locator('[data-test=filter-game-rocket-league]')->click();
            BrowserWait::until($page, '() => document.readyState === "complete" && location.search.includes("game=rocket-league") && !!document.querySelector("[data-test=open-tournaments]")', 5_000);
            expect(cupPairs(cupBoardState($page)['open']))->toBe(['rocket-league eu'])
                ->and(cupPairs(cupBoardState($page)['running']))->toBe(['rocket-league us']);
        }

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('the open list puts the cups in start order, whatever their game', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league']]);
    app(CasualCups::class)->tick();
    // The chess EU cup moves two days later: it is then the last cup, after Rocket League's EU cup (Sat 20:00) and both US cups (Sun 02:00).
    $chessEu = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    $chessEu->forceFill(['starts_at' => $chessEu->starts_at->addDays(2)])->save();

    $page = cupRegionsPage('/tournaments', 1440, 900, '[data-test=open-tournaments]');
    $ids = array_column(cupBoardState($page)['open'], 'id');
    $starts = array_map(fn (string $id): int => Tournament::query()->findOrFail($id)->starts_at->getTimestamp(), $ids);
    $ordered = $starts;
    sort($ordered);

    expect($ids)->toHaveCount(4)
        ->and($starts)->toBe($ordered)
        ->and(end($ids))->toBe((string) $chessEu->id)
        ->and(cupPairs(cupBoardState($page)['open'])[0])->toBe('rocket-league eu')
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});

test('the game chips stay inside the window with every cup game, from 640 to 1920 px in English and German', function () {
    // The board games run no cup since 2026-10-07 (correspondence only): five games with a cup, the board games with none.
    NineMensMorrisOn::play();
    CheckersGame::play();
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27', 'age-of-empires-2', 'nine-mens-morris', 'checkers']]);
    app(CasualCups::class)->tick();
    $cupGames = ['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27', 'age-of-empires-2'];
    // By count (two cups each), ties in the registry's order (TournamentsBoard::gameChips()).
    $expected = array_values(array_filter(array_keys(app(GameRegistry::class)->all()), fn (string $slug): bool => in_array($slug, $cupGames, true)));

    $page = cupRegionsPage('/tournaments', 640, 900, '[data-test=filter-game]');
    cupRegionsControl($page);
    $measured = [];

    foreach (['en', 'de'] as $lang) {
        if ($lang === 'de') {
            $page->goto(ComputeUrl::from('/locale/de'));
            $page->goto(ComputeUrl::from('/tournaments'));
            BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=filter-game]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        foreach ([640, 1024, 1280, 1440, 1536, 1920] as $width) {
            $page->setViewportSize($width, 900);
            // chipRow folds on resize (ResizeObserver): wait two frames.
            $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))');
            // Past: px of a visible chip beyond the window or its row; clipped: a chip whose label is cut; reachable: the game keys a viewer
            // can pick, as a chip in the row or in the "+N more" menu; names: each visible chip's accessible name and its shown text.
            $measured["{$lang} {$width}"] = $page->evaluate('() => {
                const row = document.querySelector("[data-test=filter-game]");
                const chips = [...row.querySelectorAll("[data-chip]")];
                const shown = chips.filter((c) => c.checkVisibility());
                const edge = Math.min(window.innerWidth, row.getBoundingClientRect().right);
                const menu = [...row.querySelectorAll("[data-chip-menu]")].filter((m) => !m.hidden).map((m) => chips[Number(m.dataset.chipMenu)].dataset.test);
                return {
                    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                    past: Math.max(0, ...shown.map((c) => Math.round(c.getBoundingClientRect().right - edge))),
                    clipped: shown.filter((c) => c.scrollWidth > c.clientWidth + 1).map((c) => c.dataset.test),
                    keys: chips.map((c) => c.dataset.test.replace("filter-game-", "")),
                    counts: chips.map((c) => c.querySelector(".rv-m")?.textContent.trim() ?? null),
                    reachable: [...shown.map((c) => c.dataset.test), ...menu].map((t) => t.replace("filter-game-", "")),
                    names: shown.map((c) => [c.getAttribute("aria-label") ?? c.textContent.replace(/\s+/g, " ").trim(), c.innerText.replace(/\s+/g, " ").trim()]),
                };
            }');
            cupRegionsShot($page, "cup-filter-seven-{$width}-{$lang}");
        }
    }

    fwrite(STDERR, json_encode($measured).PHP_EOL);

    foreach ($measured as $at => $state) {
        expect([$at, $state['overflow'], $state['past'], $state['clipped']])->toBe([$at, 0, 0, []])
            // "All" first, then the five cup games with two cups each.
            ->and(array_slice($state['keys'], 0, 6))->toBe(['all', ...$expected])
            ->and(array_slice($state['counts'], 1, 5))->toBe(['2', '2', '2', '2', '2'])
            // Every chip can be picked: in the row or folded into the menu.
            ->and(array_diff($state['keys'], $state['reachable']))->toBe([]);

        // Label in name (WCAG 2.5.3): every name starts with the text its chip shows.
        foreach ($state['names'] as [$name, $text]) {
            expect(str_starts_with($name, $text))->toBeTrue("{$at}: \"{$name}\" does not start with \"{$text}\"");
        }
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

/*
 * The organizers' tournaments above the casual cups (user, 2026-09-28: "alle
 * manuell angelegten Turniere sind die WICHTIGSTEN ... oben groß und nicht
 * unten klein"). Tournaments.dc.html keeps the order and draws it so: the
 * featured organizer tournament as the large card with its cover on top, then
 * the open list with the organizers' first ("Tournaments with a pot first,
 * then casual cups"), a running one under "Running", a finished one in the
 * archive. The board drops the large organizer cards in a grid: every other
 * tournament is a row with a 16:9 thumb. At 1440 and 375 in English and German.
 */
const ORGANIZER_STATE = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; };
        const cover = (el) => { const img = el.querySelector('[data-game-cover] img'); if (!img) return null; const r = img.getBoundingClientRect(); return { loaded: img.complete && img.naturalWidth > 0, ratio: Math.round(100 * r.width / r.height) / 100, height: Math.round(r.height), loading: img.getAttribute('loading') }; };
        const hero = document.querySelector('[data-test=featured-tournament]');
        const rows = [...document.querySelectorAll('[data-test=open-row], [data-test=open-row-phone]')].filter((r) => r.checkVisibility());
        return {
            lang: document.documentElement.lang,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            width: window.innerWidth,
            hero: hero ? { id: hero.dataset.tournament, ...box(hero), cover: cover(hero) } : null,
            rows: rows.map((el) => ({ id: el.dataset.tournament, cup: el.dataset.region !== '', ...box(el), cover: cover(el) })),
            running: [...document.querySelectorAll('[data-test=running-row]')].map((el) => el.dataset.tournament),
            openEnd: document.querySelector('[data-test=featured-open-end]')?.checkVisibility() ? document.querySelector('[data-test=featured-open-end]').innerText : null,
        };
    }
    JS;

test('the organizers\' tournaments stand above the casual cups, the featured one large with its cover, at 1440 and 375 in English and German', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    app(CasualCups::class)->tick();
    $now = CarbonImmutable::now();
    $made = function (string $name, TournamentStatus $status, CarbonImmutable $startsAt, array $attributes = []): Tournament {
        $tournament = Tournament::factory()->create(['name' => $name, 'starts_at' => $startsAt, 'created_by_id' => organizer()->id, ...$attributes]);
        $tournament->forceFill(['status' => $status, 'published_at' => now()->subDay(), 'signup_closes_at' => $startsAt->subHour()])->save();

        return $tournament;
    };
    $hero = $made('EINUNDZWANZIG Fifa 2026', TournamentStatus::Signup, $now->addDays(3), ['game' => 'ea-sports-fc-26', 'mode' => '1v1']);
    $open = $made('21,000 Sats, Zero Ball Control', TournamentStatus::Signup, $now->addDays(4), ['game' => 'rocket-league', 'mode' => '3v3']);
    $running = $made('Friday Blitz at the Bitcoin Bar Berlin', TournamentStatus::Running, $now->subHour());
    $finished = $made('Blitz Night Leipzig', TournamentStatus::Finished, $now->subDays(8));
    $endLine = function (string $lang) use ($hero): ?string {
        app()->setLocale($lang);
        $line = TournamentLanding::openEnd($hero->refresh(), LeagueTime::zone());
        app()->setLocale('en');

        return $line;
    };

    foreach ([[1440, 900, 'en'], [375, 812, 'en'], [1440, 900, 'de'], [375, 812, 'de']] as [$width, $height, $lang]) {
        $page = cupRegionsPage('/tournaments', $width, $height, '[data-test=featured-tournament]');

        if ($lang === 'de') {
            $page->goto(ComputeUrl::from('/locale/de'));
            $page->goto(ComputeUrl::from('/tournaments'));
            BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=featured-tournament]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        if ($width === 1440 && $lang === 'en') {
            cupRegionsControl($page);
        }

        // Every cover drawn: scroll the lazy ones into view, then back.
        $page->evaluate('() => new Promise((resolve) => { window.scrollTo(0, document.body.scrollHeight); setTimeout(() => { window.scrollTo(0, 0); resolve(true); }, 400); })');
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=featured-tournament] [data-game-cover] img, [data-test=open-tournaments] [data-game-cover] img")].filter((img) => img.checkVisibility()).every((img) => img.complete && img.naturalWidth > 0)', 10_000);
        $state = $page->evaluate(ORGANIZER_STATE);
        cupRegionsShot($page, "organizers-first-{$width}-{$lang}");

        $cups = array_values(array_filter($state['rows'], fn (array $row): bool => $row['cup']));
        $organizers = array_values(array_filter($state['rows'], fn (array $row): bool => ! $row['cup']));

        expect([$width, $lang, $state['lang'], $state['overflow']])->toBe([$width, $lang, $lang, 0])
            ->and($state['hero']['id'])->toBe((string) $hero->id)
            ->and($state['hero']['cover']['loaded'])->toBeTrue()
            ->and($state['hero']['cover']['loading'])->toBe('eager')
            ->and($state['hero']['bottom'])->toBeLessThan($cups[0]['top'])
            // The table repeats the featured tournament on a wide screen; the phone list leaves it to the card above.
            ->and(array_column($organizers, 'id'))->toBe($width >= 1024 ? [(string) $hero->id, (string) $open->id] : [(string) $open->id])
            ->and(max(array_column($organizers, 'bottom')))->toBeLessThanOrEqual(min(array_column($cups, 'top')))
            ->and($state['running'])->toContain((string) $running->id)
            ->and(array_column($state['rows'], 'id'))->not->toContain((string) $finished->id);

        foreach ($state['rows'] as $row) {
            expect($row['cover']['loaded'])->toBeTrue()
                ->and($row['cover']['ratio'])->toBeGreaterThanOrEqual(1.74)->toBeLessThanOrEqual(1.8)
                ->and($row['right'])->toBeLessThanOrEqual($state['width']);
        }

        // Large: the featured cover at 1440 is the board's 480 px wide card art (270 px high), far above a row's thumb; on a phone the card spans the column.
        $width === 1440
            ? expect($state['hero']['cover']['height'])->toBeGreaterThanOrEqual(250)->and($state['hero']['cover']['height'])->toBeGreaterThan(4 * max(array_column(array_column($state['rows'], 'cover'), 'height')))
                ->and($state['openEnd'])->toBe($endLine($lang))
            : expect($state['hero']['width'])->toBe(343);

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    // The finished one lists in the archive (Archive.dc.html).
    $archive = cupRegionsPage('/tournaments/archive', 1440, 900, '[data-test=archive-finished]');
    expect($archive->evaluate('() => [...document.querySelectorAll("[data-test=archive-row]")].map((r) => r.dataset.tournament)'))->toContain((string) $finished->id);
});

/*
 * The cups under the organizers' tournaments (P3 of plan mempool-streifen;
 * user, 2026-09-29: the cups "gehen ... total unter", "Trotzdem haben die
 * anderen Turniere oben drüber absolute Prio"). Tournaments.dc.html and
 * TournamentsPhone.dc.html drop the cup hall with its explainer, last winner
 * and next-cup card: the next cup is the first cup row of the open list, with
 * its seats and its sign-up, the last winner stands in the archive's Hall of
 * Fame (Archive.dc.html). At 320, 375 and 1280 in English and German: the
 * featured organizer tournament first and larger, the next cup's row with
 * its taken seats and a sign-up target, no overflow and no text box cut, the
 * winner's full name in the Hall of Fame, and after a Livewire round trip the
 * console and the answers stay clean (positive control first).
 */
const CUP_HEAD_STATE = <<<'JS'
    () => {
        const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), width: Math.round(r.width), height: Math.round(r.height) }; };
        const size = (el) => el ? parseFloat(getComputedStyle(el).fontSize) : 0;
        const own = (el) => [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== '');
        const scope = [...document.querySelectorAll('[data-test=featured-tournament], [data-test=open-tournaments], [data-test=hall-of-fame]')];
        // A visually hidden heading (max-lg:sr-only, 1 px) is cut on purpose; everything else counts.
        const cut = scope.flatMap((root) => [root, ...root.querySelectorAll('*')])
            .filter((el) => own(el) && el.checkVisibility() && !el.closest('.sr-only') && getComputedStyle(el).display !== 'inline' && el.getBoundingClientRect().width > 1 && el.scrollWidth > el.clientWidth + 1)
            .map((el) => (el.dataset.test || el.tagName) + ': ' + el.innerText.trim().slice(0, 40));
        const rows = [...document.querySelectorAll('[data-test=open-row], [data-test=open-row-phone]')].filter((r) => r.checkVisibility());
        const cup = rows.find((r) => r.dataset.region !== '');
        const target = cup ? (cup.querySelector('[data-test=row-signup]') ?? cup.querySelector('a')) : null;
        return {
            lang: document.documentElement.lang,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            title: document.querySelector('h1')?.innerText.trim(),
            hero: box(document.querySelector('[data-test=featured-tournament]')),
            heroName: size(document.querySelector('#ft-h')),
            heroCover: box(document.querySelector('[data-test=featured-tournament] [data-game-cover]')),
            cup: cup ? { id: cup.dataset.tournament, top: box(cup).top, name: size(cup.querySelector('a.font-bold, b')), cover: box(cup.querySelector('[data-game-cover]')), seats: cup.innerText.match(/(\d+)\/(\d+)/)?.[1] ?? null } : null,
            target: box(target),
            champion: document.querySelector('[data-test=hof-champion]')?.innerText.trim() ?? null,
            cut,
        };
    }
    JS;

test('the next cup stands as the first cup row under the organizers\' featured tournament, its winner in the Hall of Fame, at 320, 375 and 1280 in English and German', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    // 39 characters without a break (review of P3: a long name widened the page from 21 characters at 320 px).
    $won = wonCasualCup(User::factory()->create(['name' => 'SatoshiNakamotoTheFirstMinerOfBlockZero']), User::factory()->create(['name' => 'hal_finney']));
    // Published as the league publishes every cup (TournamentPublisher::openSignup()): the archive lists published tournaments only.
    $won->forceFill(['published_at' => $won->starts_at->subDay()])->save();
    app(CasualCups::class)->tick();
    // The FC 26 EU cup (Friday 18:00 Berlin) starts first, so it is the first cup row.
    $next = Tournament::query()->where('cup_open_series', 'ea-sports-fc-26-eu')->sole();
    cupSignups($next, 3);
    $now = CarbonImmutable::now();
    $made = function (string $name, TournamentStatus $status, CarbonImmutable $startsAt, array $attributes = []): Tournament {
        $tournament = Tournament::factory()->create(['name' => $name, 'starts_at' => $startsAt, 'created_by_id' => organizer()->id, ...$attributes]);
        $tournament->forceFill(['status' => $status, 'published_at' => now()->subDay(), 'signup_closes_at' => $startsAt->subHour()])->save();

        return $tournament;
    };
    $made('EINUNDZWANZIG Fifa 2026', TournamentStatus::Signup, $now->addDays(3), ['game' => 'ea-sports-fc-26', 'mode' => '1v1']);
    $made('21,000 Sats, Zero Ball Control', TournamentStatus::Signup, $now->addDays(4), ['game' => 'rocket-league', 'mode' => '3v3']);
    $titles = ['en' => 'Tournaments', 'de' => 'Turniere'];

    foreach ([[1280, 900, 'en'], [375, 812, 'en'], [320, 640, 'en'], [1280, 900, 'de'], [375, 812, 'de'], [320, 640, 'de']] as [$width, $height, $lang]) {
        $page = cupRegionsPage('/tournaments', $width, $height, '[data-test=open-tournaments]');
        // Every page of a run shares its cookies (the locale too), so each one says its language.
        $page->goto(ComputeUrl::from('/locale/'.$lang));
        $page->goto(ComputeUrl::from('/tournaments'));
        BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=open-tournaments]") !== null && document.fonts.status === "loaded"', 10_000);

        if ($width === 1280 && $lang === 'en') {
            cupRegionsControl($page);
        }

        $state = $page->evaluate(CUP_HEAD_STATE);
        cupRegionsShot($page, "cup-head-{$width}-{$lang}");

        expect([$width, $lang, $state['lang'], $state['overflow'], $state['cut']])->toBe([$width, $lang, $lang, 0, []])
            ->and($state['title'])->toBe($titles[$lang])
            // The organizers first and larger: the featured card ends before the first cup row, its name and cover are bigger.
            ->and($state['hero']['bottom'])->toBeLessThan($state['cup']['top'])
            ->and($state['heroName'])->toBeGreaterThan($state['cup']['name'])
            ->and($state['heroCover']['height'])->toBeGreaterThan($state['cup']['cover']['height'])
            // The next cup: its row first among the cups, its three taken seats, its sign-up a target (the phone row is the link, 68 px; the
            // table's "Eintragen" the board's 32 px button, over WCAG 2.5.8's 24).
            ->and($state['cup']['id'])->toBe((string) $next->id)
            ->and($state['cup']['seats'])->toBe('3')
            ->and($state['target']['height'])->toBeGreaterThanOrEqual($width >= 1024 ? 32 : 44);

        // A Livewire round trip on the page: the list stays, the console and the answers stay clean.
        $page->evaluate('() => window.Livewire.find(document.querySelector("[data-test=tournaments-index]").closest("[wire\\\\:id]").getAttribute("wire:id")).$refresh()');
        BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("/livewire") && e.name.includes("update") && e.responseStatus > 0)', 5_000);
        expect($page->evaluate('() => document.querySelector("[data-test=open-tournaments]") !== null'))->toBeTrue()
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

        // The last cup's winner, in full, in the archive's Hall of Fame (Archive.dc.html).
        $page->goto(ComputeUrl::from('/tournaments/archive'));
        BrowserWait::until($page, '() => document.querySelector("[data-test=hall-of-fame]") !== null && document.fonts.status === "loaded"', 10_000);
        $hall = $page->evaluate(CUP_HEAD_STATE);
        expect([$width, $lang, $hall['overflow'], $hall['cut'], $hall['champion']])->toBe([$width, $lang, 0, [], 'SatoshiNakamotoTheFirstMinerOfBlockZero'])
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

/*
| A cup's format follows its sign-ups (user, 2026-10-03: "Am besten mit
| Heartbeat und ständiger Anpassung, damit die Live-Bracket-Preview auch
| stimmig bleibt"): the page is open while other players sign up, and the
| preview, the note and the format chip change by push (TournamentChanged),
| without a reload. Measured at 1440 and 375 in English and at 375 in German.
*/
const PLANNED_STATE = <<<'JS'
    () => {
        const preview = document.querySelector('[data-test=bracket-preview]');
        const note = document.querySelector('[data-test=format-follows]');
        const r = note ? note.getBoundingClientRect() : null;
        const svg = [...(preview?.querySelectorAll('svg') ?? [])].find((s) => s.checkVisibility());
        return {
            lang: document.documentElement.lang,
            format: preview?.dataset.format ?? null,
            players: Number(preview?.dataset.players ?? 0),
            note: note?.innerText.trim() ?? null,
            chip: document.querySelector('[data-test=tournament-format]')?.innerText.trim() ?? null,
            matches: document.querySelectorAll('[data-test=projected-match]').length,
            openSpots: [...document.querySelectorAll('[data-test=bracket]')].some((b) => b.innerText.includes('Open spot') || b.innerText.includes('Offener Platz')),
            svgWidth: svg ? Math.round(svg.getBoundingClientRect().width) : 0,
            noteInside: r !== null && r.left >= 0 && r.right <= window.innerWidth,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }
    JS;

test('the page of a cup follows the sign-ups by push, without a reload: round robin of 6, of 7, then a double elimination of 9', function () {
    // The push is the trigger; the 15 s poll only the fallback.
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');
    config(['esports.casual_cups.enabled' => ['chess']]);
    app(CasualCups::class)->tick();
    $cup = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    cupSignups($cup, 6);
    $path = route('tournaments.show', $cup, false);

    $page = cupRegionsPage($path, 1440, 900, '[data-test=bracket-preview]');
    cupRegionsControl($page);
    $six = $page->evaluate(PLANNED_STATE);
    cupRegionsShot($page, 'cup-planned-6-1440-en');

    // Subscribed before anyone signs up; the page counts the pushes it hears.
    BrowserWait::until($page, '() => window.Echo?.connector.pusher.connection.state === "connected" && window.Echo.connector.channels["tournament.'.$cup->id.'"]?.subscription?.subscribed === true', 10_000);
    $page->evaluate('() => { window.__pushes = 0; window.Echo.connector.pusher.connection.bind("message", (m) => { if (m.event === "tournament.changed") window.__pushes++; }); }');
    $page->evaluate('() => { window.__sameDocument = "same-document"; }');

    // A 7th player signs up elsewhere: still a round robin, now of 7, within a few seconds (well under the 15 s poll).
    cupSignups($cup, 1);
    BrowserWait::until($page, '() => document.querySelector("[data-test=bracket-preview]")?.dataset.players === "7"', 5_000);
    $seven = $page->evaluate(PLANNED_STATE);

    // Two more: 9 players play a double elimination of 9.
    cupSignups($cup, 2);
    BrowserWait::until($page, '() => document.querySelector("[data-test=bracket-preview]")?.dataset.players === "9"', 5_000);
    $nine = $page->evaluate(PLANNED_STATE);
    cupRegionsShot($page, 'cup-planned-9-1440-en');

    expect($six)->toMatchArray(['format' => 'round-robin', 'players' => 6, 'note' => 'Format follows the sign-ups: 6 players → Round Robin', 'chip' => 'Round Robin', 'matches' => 3, 'openSpots' => false, 'overflow' => 0, 'noteInside' => true])
        ->and($six['svgWidth'])->toBeGreaterThan(300)
        ->and($seven)->toMatchArray(['format' => 'round-robin', 'players' => 7, 'note' => 'Format follows the sign-ups: 7 players → Round Robin', 'openSpots' => false])
        ->and($nine)->toMatchArray(['format' => 'double-elimination', 'players' => 9, 'note' => 'Format follows the sign-ups: 9 players → Double Elimination', 'chip' => 'Double Elimination', 'openSpots' => false, 'overflow' => 0])
        // No reload: the same document, and every step came by push.
        ->and($page->evaluate('() => window.__sameDocument'))->toBe('same-document')
        ->and($page->evaluate('() => window.__pushes'))->toBeGreaterThanOrEqual(2)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // On a phone, in English and in German: the same plan, nothing wider than the window.
    foreach (['en', 'de'] as $lang) {
        $narrow = cupRegionsPage($path, 375, 812, '[data-test=bracket-preview]');

        if ($lang === 'de') {
            $narrow->goto(ComputeUrl::from('/locale/de'));
            $narrow->goto(ComputeUrl::from($path));
            BrowserWait::until($narrow, '() => window.Alpine && document.querySelector("[data-test=bracket-preview]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        $narrow->evaluate('() => document.querySelector("[data-test=bracket]").scrollIntoView()');
        $state = $narrow->evaluate(PLANNED_STATE);
        cupRegionsShot($narrow, "cup-planned-9-375-{$lang}");

        expect([$lang, $state['lang'], $state['format'], $state['players'], $state['overflow'], $state['noteInside'], $state['openSpots']])->toBe([$lang, $lang, 'double-elimination', 9, 0, true, false])
            ->and($state['note'])->toBe($lang === 'de' ? 'Das Format folgt den Anmeldungen: 9 Spieler → Double Elimination' : 'Format follows the sign-ups: 9 players → Double Elimination')
            ->and($state['svgWidth'])->toBeGreaterThan(250)->toBeLessThanOrEqual(375)
            ->and($narrow->evaluate('() => window.__errors'))->toBe([])
            ->and($narrow->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});
