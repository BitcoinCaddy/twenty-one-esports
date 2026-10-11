<?php

use App\Support\Tournaments\CasualCups;
use Carbon\CarbonImmutable;
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
| One chip row, folded through a Livewire round trip (plan "Refactor und Design-Revamp", P7)
|--------------------------------------------------------------------------
|
| x-ui.chips is the one Priority+ chip row (rule R8): what does not fit folds
| into "+N more". A Livewire round trip of the page around it morphs the chips
| back to the server's markup, every chip shown; resources/js/chipRow.js folds
| them again from the `morphed` hook before the next paint. Measured on the
| ladder page and on /tournaments: the folded count is sampled on every
| animation frame through the round trip and never drops. Positive control:
| a mark set on a folded chip before the round trip is gone after it, so the
| morph really rewrote the chip (without the hook it would be shown).
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
});

function chipRowPage(string $path, int $width, string $row): Page
{
    $page = visit('/robots.txt')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => window.Alpine && window.Livewire && document.querySelector("'.$row.'") !== null && document.fonts.status === "loaded"', 10_000);
    $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve(true))))');

    return $page;
}

/**
 * Folds before, samples every frame while the page's component refreshes, folds after.
 *
 * @return array{before: int, after: int, minimum: int, frames: int, moreShown: bool, markGone: bool, overflow: int}
 */
function chipRowRoundTrip(Page $page, string $row): array
{
    return $page->evaluate('async () => {
        const row = document.querySelector("'.$row.'");
        const folded = () => row.querySelectorAll("[data-chip][hidden]").length;
        const before = folded();
        const marked = row.querySelector("[data-chip][hidden]");
        marked?.setAttribute("data-probe", "1");
        const samples = [];
        let sampling = true;
        const sample = () => { samples.push(folded()); if (sampling) requestAnimationFrame(sample); };
        requestAnimationFrame(sample);
        const component = window.Livewire.all().find((c) => c.el.contains(row));
        await component.$wire.$refresh();
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        sampling = false;
        const chip = row.querySelector("[data-chip=\"" + (marked?.dataset.chip ?? "-1") + "\"]");
        return {
            before,
            after: folded(),
            minimum: Math.min(...samples),
            frames: samples.length,
            moreShown: !row.querySelector("[data-test$=-more]").closest("[hidden]"),
            markGone: chip !== null && !chip.hasAttribute("data-probe"),
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }');
}

test('a Livewire round trip keeps the ladder chips folded, frame by frame', function () {
    $page = chipRowPage('/ladder/chess/blitz', 1024, '[data-test=ladder-chips]');
    $state = chipRowRoundTrip($page, '[data-test=ladder-chips]');
    fwrite(STDERR, '[chips ladder] '.json_encode($state).PHP_EOL);

    expect($state['before'])->toBeGreaterThan(0)
        ->and($state['markGone'])->toBeTrue('control: the morph did not rewrite the folded chip')
        ->and($state['frames'])->toBeGreaterThan(1)
        ->and($state['minimum'])->toBe($state['before'])
        ->and($state['after'])->toBe($state['before'])
        ->and($state['moreShown'])->toBeTrue()
        ->and($state['overflow'])->toBe(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

test('a Livewire round trip keeps the tournament game chips folded, frame by frame', function () {
    // The cup world of CasualCupRegionsTest "the game chips stay inside the window": two cups for five games.
    NineMensMorrisOn::play();
    CheckersGame::play();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27', 'age-of-empires-2', 'nine-mens-morris', 'checkers']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    app(CasualCups::class)->tick();

    $page = chipRowPage('/tournaments', 640, '[data-test=filter-game]');
    $state = chipRowRoundTrip($page, '[data-test=filter-game]');
    fwrite(STDERR, '[chips tournaments] '.json_encode($state).PHP_EOL);

    expect($state['before'])->toBeGreaterThan(0)
        ->and($state['markGone'])->toBeTrue('control: the morph did not rewrite the folded chip')
        ->and($state['minimum'])->toBe($state['before'])
        ->and($state['after'])->toBe($state['before'])
        ->and($state['moreShown'])->toBeTrue()
        ->and($state['overflow'])->toBe(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});
