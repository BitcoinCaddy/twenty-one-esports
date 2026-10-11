<?php

use App\Enums\TournamentFormat;
use App\Models\Rating;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Pride pictures in the browser (plan "KI-Stimme der Liga", P4)
|--------------------------------------------------------------------------
|
| The Season Wrapped story, the player card on the player page and the tale
| of the tape on a tournament page, at 390 × 844 and 1440 × 900 (the story
| also in German). Measured: the boxes (no sideways overflow, no text cut by
| its box, targets at least 44 px), the story waits for a click (rule R17:
| no page turns by itself, also after 4 s) and turns with a click, a tap and
| the arrow keys. Console, uncaught errors, rejected promises and answers
| >= 400 are collected (BrowserConsole); every "empty" has a positive control.
|
| PRIDE_SHOTS=<dir> writes the screenshots there.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    $this->season = openSeason(['slug' => 'pre-season']);
    $this->player = User::factory()->create(['name' => 'satsjäger', 'locale' => 'en']);
    $this->moments = shareMoments($this->player, $this->season);
});

function p4PridePage(?User $user, string $to, int $width, int $height): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && window.Alpine !== undefined', 10_000);

    return $page;
}

function p4PrideShot(Page $page, string $name): void
{
    $dir = getenv('PRIDE_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** The console and the answers are clean, and would not be: a thrown error and a broken image both land in the collector. */
function p4PrideConsoleClean(Page $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');

    $page->evaluate('() => { setTimeout(() => { throw new Error("pride positive control"); }); const img = document.createElement("img"); img.src = "/pride-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('pride positive control')->toContain('pride-missing.png');
}

/** Boxes of `$selectors`, the document's widths, and every element inside `$scope` whose text is cut by its own box. */
function p4PrideMeasure(Page $page, string $scope, array $selectors): array
{
    return $page->evaluate('([scope, selectors]) => {
        const rect = (s) => { const el = document.querySelector(s); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; };
        const root = document.querySelector(scope);
        const clipped = root ? [...root.querySelectorAll("*")].filter((el) => el.checkVisibility() && !el.closest(".sr-only") && el.children.length === 0 && el.innerText && (el.scrollWidth > el.clientWidth + 1) && getComputedStyle(el).overflowX !== "visible").map((el) => (el.dataset.test ?? el.tagName) + ": " + el.innerText.slice(0, 40)).slice(0, 5) : ["no " + scope];
        const small = root ? [...root.querySelectorAll("a, button")].filter((el) => el.checkVisibility() && el.tabIndex >= 0).map((el) => { const r = el.getBoundingClientRect(); return { t: el.dataset.test ?? el.innerText.slice(0, 20), h: r.height }; }).filter((x) => x.h < 44) : [];
        return { boxes: Object.fromEntries(selectors.map((s) => [s, rect(s)])), widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth], clipped, small };
    }', [$scope, $selectors]);
}

test('the Wrapped story waits for a click, turns by click, tap and arrow key, and fits 390 and 1440 in English and German', function () {
    $url = route('season.wrapped', ['pre-season', $this->player->npub], false);

    foreach ([['en', 390, 844, $this->player], ['en', 1440, 900, $this->player], ['de', 390, 844, null], ['de', 1440, 900, null]] as [$lang, $width, $height, $viewer]) {
        $label = "{$lang} {$width}".($viewer ? ' player' : ' guest');
        $page = p4PridePage($viewer, $url.'?lang='.$lang, $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=wrapped-page-intro]")?.checkVisibility()', 5_000);
        p4PrideShot($page, "wrapped-intro-{$lang}-{$width}".($viewer ? '-player' : ''));

        $m = p4PrideMeasure($page, '[data-test=wrapped-page]', ['[data-test=wrapped-stage]', '[data-test=wrapped-next]', '[data-test=wrapped-back]', '[data-test=wrapped-side]', '[data-test=share-button]']);
        $stage = $m['boxes']['[data-test=wrapped-stage]'];

        expect($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow')
            ->and($m['clipped'])->toBe([], $label.': clipped text')
            ->and($m['small'])->toBe([], $label.': targets under 44 px')
            // The story is the first thing on the screen, 9:16, and its buttons are in the first screen.
            ->and(abs($stage['width'] / $stage['height'] - 9 / 16))->toBeLessThan(0.02, $label.': 9:16')
            ->and($stage['height'])->toBeGreaterThanOrEqual(560, $label.': story height')
            ->and($m['boxes']['[data-test=wrapped-next]']['bottom'])->toBeLessThanOrEqual($height, $label.': next in the first screen')
            ->and($m['boxes']['[data-test=share-button]'] !== null)->toBe($viewer !== null, $label.': share button for the player only');

        if ($width === 1440) {
            // R1: the story in the main columns, the column on the right beside it.
            expect($m['boxes']['[data-test=wrapped-side]']['left'])->toBeGreaterThan($stage['right'], $label.': column right of the story')
                ->and($m['boxes']['[data-test=wrapped-side]']['top'])->toBeLessThan($height, $label.': column in the first screen');
        }

        // R17: nothing turns by itself.
        $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 4000))');
        expect($page->evaluate('() => document.querySelector("[data-test=wrapped-position]").innerText.trim()'))->toBe('1 / 6', $label.': still page 1 after 4 s');

        // The button, the tap zone, the arrow key.
        $page->locator('[data-test=wrapped-next]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=wrapped-page-blocks]").checkVisibility()', 3_000);
        expect($page->evaluate('() => document.querySelector("[data-test=wrapped-blocks]").innerText.trim()'))->toBe('2');
        p4PrideShot($page, "wrapped-blocks-{$lang}-{$width}");

        $page->locator('[data-test=wrapped-tap-next]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=wrapped-page-games]").checkVisibility()', 3_000);
        $page->locator('body')->press('ArrowRight');
        BrowserWait::until($page, '() => document.querySelector("[data-test=wrapped-page-rank]").checkVisibility()', 3_000);
        $page->locator('body')->press('ArrowLeft');
        BrowserWait::until($page, '() => document.querySelector("[data-test=wrapped-page-games]").checkVisibility()', 3_000);
        p4PrideShot($page, "wrapped-games-{$lang}-{$width}");

        foreach (['rank', 'titles', 'card'] as $next) {
            $page->locator('[data-test=wrapped-next]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=wrapped-page-'.$next.']").checkVisibility()', 3_000);
        }
        BrowserWait::until($page, '() => { const img = document.querySelector("[data-test=wrapped-card]"); return img.complete && img.naturalWidth === 1200; }', 10_000);
        $card = p4PrideMeasure($page, '[data-test=wrapped-stage]', ['[data-test=wrapped-card]', '[data-test=wrapped-line]', '[data-test=wrapped-stage]']);
        expect($card['clipped'])->toBe([], $label.': last page clipped')
            ->and($card['boxes']['[data-test=wrapped-line]']['top'])->toBeGreaterThanOrEqual($card['boxes']['[data-test=wrapped-stage]']['top'], $label.': line inside the story')
            ->and($card['boxes']['[data-test=wrapped-card]']['bottom'])->toBeLessThanOrEqual($card['boxes']['[data-test=wrapped-stage]']['bottom'], $label.': card inside the story');
        p4PrideShot($page, "wrapped-card-{$lang}-{$width}");

        p4PrideConsoleClean($page, $label);
    }
});

test('the player card sits on the player page at 390 and 1440, its picture loaded at 1200 × 630, with the post for the player', function () {
    foreach ([[390, 844, $this->player], [1440, 900, $this->player], [390, 844, null], [1440, 900, null]] as [$width, $height, $viewer]) {
        $label = $width.($viewer ? ' player' : ' guest');
        $page = p4PridePage($viewer, route('players.show', $this->player->npub, false), $width, $height);
        // Lazy (R7): the placeholder first, the card after its own roundtrip once it scrolls into view.
        // When the placeholder is already in the first screen, the card may have arrived before this line runs.
        BrowserWait::until($page, '() => document.querySelector("[data-test=player-pride-card-placeholder], [data-test=player-pride-card]") !== null', 5_000);
        $page->evaluate('() => document.querySelector("[data-test=player-pride-card-placeholder], [data-test=player-pride-card]").scrollIntoView({ block: "center" })');
        BrowserWait::until($page, '() => document.querySelector("[data-test=player-pride-card]") !== null', 10_000);
        $page->evaluate('() => document.querySelector("[data-test=player-pride-card]").scrollIntoView({ block: "center" })');
        BrowserWait::until($page, '() => { const img = document.querySelector("[data-test=player-pride-card-image]"); return img.complete && img.naturalWidth === 1200 && img.naturalHeight === 630; }', 10_000);
        p4PrideShot($page, "player-card-{$width}".($viewer ? '-player' : ''));

        $m = p4PrideMeasure($page, '[data-test=player-pride-card]', ['[data-test=player-pride-card]', '[data-test=player-pride-card-image]', '[data-test=share-button]', '[data-test=player-wrapped-link]']);
        $box = $m['boxes']['[data-test=player-pride-card]'];
        $image = $m['boxes']['[data-test=player-pride-card-image]'];

        expect($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow')
            ->and($m['clipped'])->toBe([], $label.': clipped text')
            ->and($m['small'])->toBe([], $label.': targets under 44 px')
            ->and($image['left'])->toBeGreaterThanOrEqual($box['left'])->and($image['right'])->toBeLessThanOrEqual($box['right'])
            ->and(abs($image['width'] / $image['height'] - 1200 / 630))->toBeLessThan(0.02, $label.': picture ratio')
            ->and($m['boxes']['[data-test=player-wrapped-link]'])->not->toBeNull($label.': way to the story')
            ->and($m['boxes']['[data-test=share-button]'] !== null)->toBe($viewer !== null, $label.': post for the player only');

        p4PrideConsoleClean($page, $label);
    }
});

test('the tale of the tape sits on the tournament page before the final at 390 and 1440, both sides and five rows in the box', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['name' => 'Halving Night Cup', 'published_at' => now()->subDay()])->save();
    $runner = app(TournamentRunner::class);
    $round = TournamentRunner::currentRound($tournament);
    foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->with('slots.participant')->get() as $match) {
        $runner->enterResult($match, $tournament->creator, ['result' => $match->slots[0]->participant->seed < $match->slots[1]->participant->seed ? '1-0' : '0-1']);
    }
    $runner->closeRound($round, $tournament->creator);
    foreach ($tournament->participants()->orderBy('seed')->get() as $i => $entry) {
        $name = ['satoshi_nakamura_der_zweite', 'Mempool Max', 'Lia', 'pleb21'][$i];
        $entry->forceFill(['name' => $name])->save();
        User::query()->whereKey($entry->user_id)->update(['name' => $name]);
    }
    $first = $tournament->participants()->orderBy('seed')->first();
    Rating::query()->create(['pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$first->user_id,
        'user_id' => $first->user_id, 'rating' => 1188, 'results' => 12, 'wins' => 9, 'draws' => 1, 'losses' => 2]);
    $finalist = User::query()->findOrFail($first->user_id);

    foreach ([[390, 844, null], [1440, 900, null], [390, 844, $finalist], [1440, 900, $finalist]] as [$width, $height, $viewer]) {
        $label = $width.($viewer ? ' finalist' : ' guest');
        $page = p4PridePage($viewer, route('tournaments.show', $tournament, false), $width, $height);
        $page->evaluate('() => document.querySelector("[data-test=tale-of-the-tape]").scrollIntoView({ block: "start" })');
        p4PrideShot($page, "tape-{$width}".($viewer ? '-finalist' : ''));

        $m = p4PrideMeasure($page, '[data-test=tale-of-the-tape]', ['[data-test=tale-of-the-tape]', '[data-test=tape-side-0]', '[data-test=tape-side-1]', '[data-test=tape-rows]', '[data-test=share-button]', '[data-test=tape-download]']);
        $box = $m['boxes']['[data-test=tale-of-the-tape]'];

        expect($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow')
            ->and($m['clipped'])->toBe([], $label.': clipped text')
            ->and($m['small'])->toBe([], $label.': targets under 44 px')
            ->and($m['boxes']['[data-test=tape-rows]']['right'])->toBeLessThanOrEqual($box['right'], $label.': rows in the box')
            ->and($m['boxes']['[data-test=tape-side-1]']['right'])->toBeLessThanOrEqual($box['right'], $label.': right side in the box')
            ->and($m['boxes']['[data-test=tape-side-0]']['right'])->toBeLessThanOrEqual($m['boxes']['[data-test=tape-side-1]']['left'], $label.': sides apart')
            ->and($page->evaluate('() => document.querySelectorAll("[data-test=tape-rows] tbody tr").length'))->toBe(5)
            ->and($m['boxes']['[data-test=share-button]'] !== null)->toBe($viewer !== null, $label.': post for a finalist')
            ->and($m['boxes']['[data-test=tape-download]'] !== null)->toBe($viewer === null, $label.': picture for everybody else');

        p4PrideConsoleClean($page, $label);
    }
});
