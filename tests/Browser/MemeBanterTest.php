<?php

use App\Models\PongMatch;
use App\Models\User;
use App\Support\Memes\BotArchetypes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Meme and banter in the browser (plan "KI-Stimme der Liga", P5)
|--------------------------------------------------------------------------
|
| The bot game at 390x844 and 1440x900: the bot's archetype greets at the
| first serve in a bubble that lies inside the field without overflow, the
| settings' "Bot chat" box mutes it, and the mute holds after a reload. The
| Proof of Pong lobby in German: a meme win offers "Meme posten" next to
| "Post the win", its preview shows the meme card (1200 px, 200), the row
| does not spill. Console and answers clean, proved by a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    PongOn::play();
});

function memeBanterPage(User $user, string $path, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));

    return $page;
}

/** A picture and the measured numbers for the report, only when MEME_SHOTS names a directory. */
function memeBanterShot(Page $page, string $name, array $numbers = []): void
{
    $dir = getenv('MEME_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
    File::put($dir.'/'.$name.'.json', (string) json_encode($numbers));
}

/** The console and the answers stayed clean, and the collector sees a thrown error and a broken image. */
function memeBanterCleanWithControl(Page $page): void
{
    expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);

    $page->evaluate('() => { setTimeout(() => { throw new Error("meme positive control"); }); const img = new Image(); img.src = "/meme-positive-control-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => (window.__errors || []).some((e) => e.includes("meme positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("meme-positive-control-missing") && e.responseStatus === 404)', 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);
}

const MEME_BANTER_BOX = <<<'JS'
() => {
    const box = document.querySelector('[data-test=pong-banter]');
    const field = document.querySelector('[data-test=pong-field]').getBoundingClientRect();
    const r = box.getBoundingClientRect();
    return {
        visible: box.checkVisibility(),
        name: box.querySelector('[data-test=pong-banter-name]').textContent,
        text: box.querySelector('[data-test=pong-banter-text]').textContent,
        inside: r.left >= field.left && r.right <= field.right && r.top >= field.top && r.bottom <= field.bottom,
        box: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)],
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        spill: [...box.querySelectorAll('b, span')].some((el) => el.scrollWidth > el.clientWidth + 1),
    };
}
JS;

test('the bot greets as its archetype inside the field, the settings mute it, and the mute holds after a reload', function (int $width, int $height) {
    $path = route('pong.bot', ['bot' => 'shitcoiner', 'seed' => 11], false);
    $page = memeBanterPage(User::factory()->create(), $path, $width, $height);
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 10_000);
    $startLines = array_map(fn (array $line): string => $line[0], BotArchetypes::ROSTER['shitcoiner']['lines']['start']);

    expect($page->evaluate('() => document.body.dataset.banter'))->toBe('on')
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-banter]").hidden'))->toBeTrue();

    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=pong-banter]").checkVisibility()', 5_000);
    $box = $page->evaluate(MEME_BANTER_BOX);
    memeBanterShot($page, "banter-{$width}", $box);

    expect($box['name'])->toBe('Shitcoiner')
        ->and($startLines)->toContain($box['text'])
        ->and($box['inside'])->toBeTrue()
        ->and($box['overflow'])->toBe(0)
        ->and($box['spill'])->toBeFalse();

    // Muted in the settings: the bubble goes, and nothing shows any more, not even a forced line.
    $page->locator('#settings-btn')->click();
    $page->locator('[data-test=pong-setting-banter]')->click();
    expect($page->evaluate('() => document.body.dataset.banter'))->toBe('off')
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-banter]").hidden'))->toBeTrue()
        ->and($page->evaluate('() => window.pongBanter.say("deuce", { force: true })'))->toBeNull()
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-banter]").hidden'))->toBeTrue();

    // The next game in this browser stays muted.
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 10_000);
    $page->locator('[data-test=pong-start-btn]')->click();
    usleep(400_000);

    expect($page->evaluate('() => document.body.dataset.banter'))->toBe('off')
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-setting-banter]").checked'))->toBeFalse()
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-banter]").hidden'))->toBeTrue();

    memeBanterCleanWithControl($page);
})->with([
    'phone upright 390x844' => [390, 844],
    'desktop 1440x900' => [1440, 900],
]);

test('a meme win offers its meme card in the lobby, in German, without spilling the row', function (int $width, int $height) {
    $user = User::factory()->create(['locale' => 'de']);
    // The viewer's first live win, 22:20: a deuce, the strongest moment of it.
    $match = PongMatch::factory()->finished(0, [22, 20])->create(['left_id' => $user->id]);

    $page = memeBanterPage($user, route('locale.switch', 'de', false), $width, $height);
    $page->goto(ComputeUrl::from(route('pong.index', absolute: false)));
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=pong-last-win] [data-test=share-button][data-type=meme]")', 10_000);

    $row = $page->evaluate('() => { const row = document.querySelector("[data-test=pong-last-win]"); const r = row.getBoundingClientRect(); const b = row.querySelector("[data-type=meme] [data-test=share-post]"); const br = b.getBoundingClientRect(); return { text: b.innerText.trim(), height: Math.round(br.height), inside: br.right <= r.right + 0.5 && br.left >= r.left - 0.5, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }');

    memeBanterShot($page, "meme-lobby-{$width}", $row);
    expect($row['text'])->toBe('Meme posten')
        ->and($row['height'])->toBeGreaterThanOrEqual(44)
        ->and($row['inside'])->toBeTrue()
        ->and($row['overflow'])->toBe(0);

    $page->locator('[data-test=pong-last-win] [data-type=meme] [data-test=share-post]')->click();
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=share-preview-card]")].some((i) => i.checkVisibility() && i.src.includes("/meme/pong/'.$match->ulid.'") && i.complete && i.naturalWidth === 1200)', 20_000);
    memeBanterShot($page, "meme-preview-{$width}");

    memeBanterCleanWithControl($page);
})->with([
    'phone 390x844' => [390, 844],
    'desktop 1440x900' => [1440, 900],
]);
