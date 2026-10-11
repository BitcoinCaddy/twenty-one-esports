<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\TournamentRunner;
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
| With esports.recap.page on, a finished tournament page shows its upsets
| by Elo and its closest match under the bracket: at 390 x 844 the two cards
| stack inside the viewport, at 1440 x 900 they stand side by side; nothing
| overflows sideways, no text is clipped. Measured as a guest in English
| and as an admin in German.
|
| Console, uncaught errors, rejected promises and responses >= 400 are
| collected (BrowserConsole), with a positive control that throws and breaks
| an image on purpose.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret, 'esports.recap.page' => true]);

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

const RECAP_RECTS = <<<'JS'
    () => {
        const rect = (el) => { if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, width: r.width }; };
        const section = document.querySelector('[data-test=tournament-recap]');
        return {
            section: rect(section),
            upsets: rect(document.querySelector('[data-test=recap-upsets]')),
            closest: rect(document.querySelector('[data-test=recap-closest]')),
            rows: document.querySelectorAll('[data-test=recap-upset]').length,
            text: section ? section.innerText : '',
            widths: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
            clipped: section ? [...section.querySelectorAll('*')].filter((el) => el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === 'hidden').map((el) => el.tagName + ': ' + el.innerText.slice(0, 40)) : [],
        };
    }
    JS;

test('the recap section shows the upsets and the closest match at 390 and 1440, stacked on the phone, side by side on the desktop', function () {
    $tournament = recapBrowserCup();
    $admin = User::factory()->create(['locale' => 'de']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    foreach ([['en', 1440, 900, null], ['de', 390, 844, $admin], ['de', 1440, 900, $admin]] as [$lang, $width, $height, $viewer]) {
        $label = "{$lang} {$width}".($viewer ? ' admin' : '');
        $page = recapBrowserPage($viewer, route('tournaments.show', ['tournament' => $tournament, 'lang' => $lang], false), $width, $height);
        BrowserWait::until($page, '() => document.querySelector("[data-test=tournament-recap]") !== null', 10_000);
        $m = $page->evaluate(RECAP_RECTS);

        expect($m['section'])->not->toBeNull($label)
            ->and($m['rows'])->toBe(2, $label.': both upsets')
            ->and($m['text'])->toContain($lang === 'de' ? 'Rückblick' : 'Recap')
            ->and($m['text'])->toContain($lang === 'de' ? '30 Elo darunter' : '30 Elo below')
            ->and($m['text'])->toContain($lang === 'de' ? 'Knappstes Match' : 'Closest match')
            ->and($m['text'])->toContain('2:1')
            ->and($m['upsets']['left'])->toBeGreaterThanOrEqual(0, $label)
            ->and($m['closest']['right'])->toBeLessThanOrEqual($width, $label)
            ->and($m['clipped'])->toBe([], $label.': clipped text')
            ->and($m['widths'][0])->toBeLessThanOrEqual($m['widths'][1], $label.': overflow');

        if ($width >= 1024) {
            expect(abs($m['upsets']['top'] - $m['closest']['top']))->toBeLessThan(1, $label.': side by side')
                ->and($m['closest']['left'])->toBeGreaterThan($m['upsets']['right'], $label.': closest right of upsets');
        } else {
            expect($m['closest']['top'])->toBeGreaterThanOrEqual($m['upsets']['bottom'], $label.': stacked')
                ->and($m['upsets']['width'])->toBeLessThanOrEqual($width, $label);
        }

        expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses');

        $page->evaluate('() => { setTimeout(() => { throw new Error("recap positive control"); }); const img = document.createElement("img"); img.src = "/recap-missing.png"; document.body.append(img); }');
        BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);
        expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('recap positive control')->toContain('recap-missing.png');
    }
});
