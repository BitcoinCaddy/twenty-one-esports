<?php

use App\Models\User;
use App\Support\Tournaments\TournamentChampion;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
| The player's own profile page and the prize hint (user 2026-10-25), measured at 390 and 1440 in German; console,
| uncaught errors and failed responses collected, with a positive control.
*/

test('the profile page and the prize hint stay inside the screen at 390 and 1440 in German; clean console', function () {
    fakeLightningAddresses(fakeWallet());
    $tournament = finishedPoolTournament(fakeWallet(), withoutAddress: [1]);
    $tournament->forceFill(['published_at' => now()])->save();
    $champion = app(TournamentChampion::class)->of($tournament);
    $user = User::query()->findOrFail($champion->user_id);
    $user->forceFill(['lud16' => null, 'locale' => 'de'])->save();

    $webpage = visit(BrowserLogin::url($user));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $box = '(s) => { const el = document.querySelector(s); if (! el) return null; const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY) }; }';
    $measured = [];

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('settings.profile')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=profile-save]") !== null && ! document.querySelector("[data-test=profile-name]").disabled', 10_000);
        $profile = $page->evaluate("() => ({ scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], editor: ({$box})('[data-test=profile-editor]'), help: ({$box})('[data-test=profile-help]'), save: ({$box})('[data-test=profile-save]'), text: document.body.innerText })");
        tournamentLiveShot($page, "profile-{$width}");

        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=prize-needs-address]") !== null && document.readyState === "complete"', 10_000);
        $hint = $page->evaluate("() => ({ scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], hint: ({$box})('[data-test=prize-needs-address]') })");
        $page->evaluate('() => document.querySelector("[data-test=prize-needs-address]").scrollIntoView({ block: "center" })');
        tournamentLiveShot($page, "prize-hint-{$width}");
        $measured[$width] = ['profile' => array_diff_key($profile, ['text' => 1]), 'hint' => $hint];

        expect($profile['scroll'][0])->toBeLessThanOrEqual($profile['scroll'][1])
            ->and($profile['editor']['right'])->toBeLessThanOrEqual($width)
            ->and($profile['help']['right'])->toBeLessThanOrEqual($width)
            ->and($profile['text'])->toContain('Noch keine Lightning-Adresse?')->toContain('Coinos')
            ->and($hint['scroll'][0])->toBeLessThanOrEqual($hint['scroll'][1])
            ->and($hint['hint']['right'])->toBeLessThanOrEqual($width);
        $webpage->assertNoJavaScriptErrors();
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.length >= 1', 10_000);
    expect(implode("\n", $page->evaluate('() => window.__errors')))->toContain('positive control');

    fwrite(STDERR, "\n[profile] ".json_encode($measured)."\n");
});

test('a shared moment keeps its text readable beside or under the card at 390, 1180, 1440 and 1920 (user 2026-10-25); clean console', function () {
    $tournament = finishedPoolTournament(fakeWallet());
    $champion = app(App\Support\Tournaments\TournamentChampion::class)->of($tournament);
    $user = User::query()->findOrFail($champion->user_id);
    $user->forceFill(['locale' => 'de'])->save();

    $webpage = visit(BrowserLogin::url($user));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[390, 844], [1180, 900], [1440, 900], [1920, 1080]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('settings.badges')));
        BrowserWait::until($page, '() => document.querySelector("[data-test=share-moment]") !== null && document.readyState === "complete"', 10_000);
        $m = $page->evaluate('() => { const li = document.querySelector("[data-test=share-moment]"), img = li.querySelector("img"), text = li.querySelector("b").parentElement.parentElement;
            return { scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], li: Math.round(li.getBoundingClientRect().width), img: Math.round(img.getBoundingClientRect().width), text: Math.round(text.getBoundingClientRect().width), beside: img.getBoundingClientRect().top === text.getBoundingClientRect().top }; }');
        tournamentLiveShot($page, "badges-{$width}");
        $measured[$width] = $m;

        expect($m['scroll'][0])->toBeLessThanOrEqual($m['scroll'][1])
            ->and($m['text'])->toBeGreaterThanOrEqual(200);
        $webpage->assertNoJavaScriptErrors();
    }

    expect($page->evaluate('() => window.__errors'))->toBe([]);
    fwrite(STDERR, "\n[badges] ".json_encode($measured)."\n");
});

test('every settings page uses the width at 1440 and 1920 and fits 390 (user 2026-10-25: forms stood as narrow tubes); clean console', function () {
    $user = User::factory()->create(['locale' => 'de']);
    $webpage = visit(BrowserLogin::url($user));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach (['settings.account', 'settings.profile', 'gaming.edit', 'settings.chess', 'settings.notifications', 'settings.opponents', 'settings.badges', 'settings.nip05'] as $route) {
        foreach ([[390, 844], [1440, 900], [1920, 1080]] as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->goto(ComputeUrl::from(route($route)));
            BrowserWait::until($page, '() => document.readyState === "complete" && document.querySelector("main section, main form") !== null', 10_000);
            $m = $page->evaluate('() => { const main = document.querySelector("main"), cards = [...main.querySelectorAll(":scope section, :scope form")].filter((el) => el.getBoundingClientRect().height > 40);
                const right = Math.max(...cards.map((el) => el.getBoundingClientRect().right)), left = Math.min(...cards.map((el) => el.getBoundingClientRect().left));
                return { scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth], left: Math.round(left), right: Math.round(right), mainRight: Math.round(main.getBoundingClientRect().right) }; }');
            $measured["{$route}-{$width}"] = $m;
            if ($width !== 390 && in_array($route, ['settings.account', 'settings.profile', 'settings.nip05'], true)) {
                tournamentLiveShot($page, str_replace('.', '-', $route)."-{$width}");
            }

            expect($m['scroll'][0])->toBeLessThanOrEqual($m['scroll'][1]);
            $webpage->assertNoJavaScriptErrors();
        }
    }

    expect($page->evaluate('() => window.__errors'))->toBe([]);
    fwrite(STDERR, "\n[settings] ".json_encode($measured)."\n");
});
