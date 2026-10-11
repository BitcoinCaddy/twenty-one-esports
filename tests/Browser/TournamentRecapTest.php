<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
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
| The recap section of a finished tournament (plan "KI-Stimme der Liga", P3)
|--------------------------------------------------------------------------
|
| On by default (esports.recap.page), a finished tournament page shows its
| upsets by Elo and its closest match under the bracket as the bracket's
| match boxes (TournamentDetail.dc.html, frame "Rückblick"): two columns at
| 1440 x 900, one at 390 x 844; nothing overflows sideways, no text is
| clipped, no name is squeezed. Measured as a guest in English and as an
| admin in German, at both widths.
|
| Console, uncaught errors, rejected promises and responses >= 400 are
| collected (BrowserConsole), with a positive control that throws and breaks
| an image on purpose. RECAP_SHOTS=<dir> keeps a screenshot per run.
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
});

/** A finished chess cup of four with two upsets (Player 4 over Player 1 and Player 2) and a 2:1 semifinal. */
function recapBrowserCup(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $runner = app(TournamentRunner::class);
    $winners = ['Player 4', 'Player 2'];

    for ($rounds = 0; $tournament->refresh()->status === TournamentStatus::Running && $rounds < 5; $rounds++) {
        $round = TournamentRunner::currentRound($tournament);

        foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->with('slots.participant')->get() as $match) {
            $names = $match->slots->map(fn ($slot): string => $slot->participant->name)->all();
            $winner = collect($winners)->first(fn (string $name): bool => in_array($name, $names, true));
            $runner->enterResult($match, $tournament->creator, ['result' => $names[0] === $winner ? '1-0' : '0-1']);
        }

        $runner->closeRound($round, $tournament->creator);
    }

    $semifinal = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant')->get()
        ->first(fn (TournamentMatch $match): bool => in_array('Player 3', $match->slots->map(fn ($slot): ?string => $slot->participant?->name)->all(), true));
    $won = (int) $semifinal->result['winner'];
    $semifinal->forceFill(['result' => [...$semifinal->result, 'games_won' => $won === 0 ? [2.0, 1.0] : [1.0, 2.0], 'label' => '2:1']])->save();
    $tournament->forceFill(['name' => 'Recap Blitz Cup', 'published_at' => now()])->save();

    return $tournament->refresh();
}

function recapBrowserPage(?User $user, string $to, int $width, int $height): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && window.Alpine !== undefined', 10_000);

    return $page;
}

/** With RECAP_SHOTS set: the viewport with the recap scrolled to its top, moved out of Pest's cleared folder. */
function recapBrowserShot(Page $page, string $name): void
{
    $dir = getenv('RECAP_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->evaluate('() => { document.querySelector("[data-test=tournament-recap]").scrollIntoView({ block: "start" }); window.scrollBy(0, -16); }');
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

const RECAP_RECTS = <<<'JS'
    () => {
        const rect = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) }; };
        const section = document.querySelector('[data-test=tournament-recap]');
        const boxes = section ? [...section.querySelectorAll('[data-test=recap-match]')] : [];
        return {
            section: section && section.checkVisibility() ? rect(section) : null,
            boxes: boxes.map((box) => ({ ...rect(box), kind: box.dataset.kind, closest: box.hasAttribute('data-closest') })),
            names: boxes.flatMap((box) => [...box.querySelectorAll('span.grow.leading-snug')].map((el) => Math.round(el.getBoundingClientRect().width))),
            rows: boxes.flatMap((box) => [...box.querySelectorAll(':scope > span')].map((el) => Math.round(el.getBoundingClientRect().height))),
            text: section ? section.innerText : '',
            widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            clipped: section ? [...section.querySelectorAll('*')].filter((el) => !el.classList.contains('sr-only') && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === 'hidden').map((el) => el.tagName + ': ' + el.innerText.slice(0, 40)) : [],
        };
    }
    JS;

test('the recap section is on by default and draws the upsets and the closest match as match boxes, two columns at 1440, one at 390', function () {
    $tournament = recapBrowserCup();
    $admin = User::factory()->create(['locale' => 'de']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $measured = [];

    foreach ([['en', 1440, 900, null], ['en', 390, 844, null], ['de', 390, 844, $admin], ['de', 1440, 900, $admin]] as [$lang, $width, $height, $viewer]) {
        $label = "{$lang} {$width}".($viewer ? ' admin' : '');
        $page = recapBrowserPage($viewer, route('tournaments.show', ['tournament' => $tournament, 'lang' => $lang], false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-recap]") !== null', 10_000);
        $m = $page->evaluate(RECAP_RECTS);
        [$first, $second, $third] = $m['boxes'] + [null, null, null];
        $measured[$label] = ['section' => $m['section'], 'boxes' => $m['boxes'], 'names' => $m['names'], 'rows' => $m['rows'], 'widths' => $m['widths']];

        expect($m['section'])->not->toBeNull($label)
            ->and(array_column($m['boxes'], 'kind'))->toBe(['upset', 'upset', 'closest'], $label.': two upsets, then the closest match')
            ->and($m['text'])->toContain($lang === 'de' ? 'Rückblick' : 'Recap')
            ->and($m['text'])->toContain($lang === 'de' ? '30 Elo darunter' : '30 Elo below')
            ->and($m['text'])->toContain($lang === 'de' ? 'Knappstes Match' : 'Closest match')
            ->and($m['text'])->toContain('1460')
            ->and(min($m['names']))->toBeGreaterThan(60, $label.': no name squeezed')
            ->and(min($m['rows']))->toBeGreaterThanOrEqual(32, $label.': box lines')
            ->and(min(array_column($m['boxes'], 'left')))->toBeGreaterThanOrEqual(0, $label)
            ->and(max(array_column($m['boxes'], 'right')))->toBeLessThanOrEqual($width, $label)
            ->and($m['clipped'])->toBe([], $label.': clipped text')
            ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow');

        if ($width >= 1024) {
            expect($second['top'])->toBe($first['top'], $label.': upsets side by side')
                ->and($second['left'] - $first['right'])->toBe(24, $label.': 24 px column gap')
                ->and($third['left'])->toBe($first['left'], $label.': closest match under the first column')
                ->and($third['top'] - max($first['bottom'], $second['bottom']))->toBe(24, $label.': 24 px row gap');
        } else {
            expect($second['top'] - $first['bottom'])->toBe(12, $label.': stacked, 12 px apart')
                ->and($third['top'] - $second['bottom'])->toBe(12, $label)
                ->and($first['width'])->toBe($width - 32, $label.': one column across the content width');
        }

        expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');

        recapBrowserShot($page, 'recap-'.$lang.'-'.$width);

        $page->evaluate('() => { setTimeout(() => { throw new Error("recap positive control"); }); const img = document.createElement("img"); img.src = "/recap-missing.png"; document.body.append(img); }');
        BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);
        expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('recap positive control')->toContain('recap-missing.png');
    }

    if (is_string(getenv('RECAP_SHOTS')) && getenv('RECAP_SHOTS') !== '') {
        File::put(getenv('RECAP_SHOTS').'/measured.json', json_encode($measured, JSON_PRETTY_PRINT));
    }
});
