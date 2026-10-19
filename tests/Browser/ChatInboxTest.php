<?php

use App\Models\User;
use App\Support\Board\BoardGameService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "Your chats" (DerCaddy, 2026-10-18)
|--------------------------------------------------------------------------
|
| Anna writes in the chat of a Blockli game, through the in-memory relay (tests/Support/mini-relay.php). Bert finds
| it in "Your chats", from the account menu below his matches: the newest message, its time and "1 new"; his silent
| game with Carla folds below the list. The row opens the game, whose chat reads the message; back in the list nothing
| is new. On a phone the list is in the menu behind the top bar's menu button. Console, uncaught errors and answers
| >= 400 stay empty.
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

    BlockliOn::play();
});

function chatInboxVisit(User $user, string $to, int $width = 1440): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, $width < 1024 ? 844 : 900);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && !! window.Alpine', 10_000);

    return $page;
}

/** The list's row of a chat, as the browser shows it. */
function chatInboxRow(Page $page, string $key): mixed
{
    return $page->evaluate('() => { const row = Alpine.$data(document.querySelector("[data-test=me-chats]"))?.rows.find((r) => r.key === '.json_encode($key).'); const a = [...document.querySelectorAll("[data-test=chat-row]")].find((el) => el.getAttribute("href") === row?.href); return row && a ? { unread: row.unread, preview: a.querySelector("[data-test=chat-row-preview]").innerText, time: a.querySelector("[data-test=chat-row-time]").innerText, badge: a.querySelector("[data-test=chat-row-unread]").checkVisibility() ? a.querySelector("[data-test=chat-row-unread]").innerText : null } : null; }');
}

test('a message in a game\'s chat shows in "Your chats" from the account menu, with its time and "new", until the game\'s chat read it; on a phone behind the menu button', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        $anna = User::factory()->create(['name' => 'Anna']);
        $bert = User::factory()->create(['name' => 'Bert']);
        TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);
        $game = app(BoardGameService::class)->start('blockli', $anna, $bert);
        $key = 'board:'.$game->id;
        // Nobody writes in Bert's game with Carla: folded below the list.
        $silent = app(BoardGameService::class)->start('blockli', User::factory()->create(['name' => 'Carla']), $bert);

        $annaPage = chatInboxVisit($anna, route('board.show', $game, false));
        BrowserWait::until($annaPage, '() => Alpine.$data(document.querySelector("[data-test=chat]"))?.status === "live"', 10_000);
        $annaPage->locator('#chatin')->fill('Revanche heute Abend?');
        $annaPage->locator('section[aria-labelledby=chat-h] [data-test=chat-send]')->click();
        BrowserWait::until($annaPage, '() => document.querySelector("#chatin").value === ""', 5_000);

        // Bert: account menu, right below "Your matches and events".
        $page = chatInboxVisit($bert, '/rules');
        $page->locator('[data-test="account-chip"]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=account-menu] [data-test=account-chats]")?.checkVisibility()', 5_000);
        $order = $page->evaluate('() => [...document.querySelectorAll("[data-test=account-menu] a")].map((a) => a.dataset.test)');
        $page->locator('[data-test="account-menu"] [data-test="account-chats"]')->click();
        BrowserWait::until($page, '() => location.pathname === "/me/chats" && Alpine.$data(document.querySelector("[data-test=me-chats]"))?.status === "live"', 10_000);
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=me-chats]")).rows.some((r) => r.unread === 1)', 10_000);
        BrowserWait::until($page, '() => ! Alpine.$data(document.querySelector("[data-test=me-chats]")).searching', 10_000);
        $before = chatInboxRow($page, $key);
        $lists = $page->evaluate('() => ({
            active: [...document.querySelectorAll("[data-test=chats-list] [data-test=chat-row]")].filter((a) => a.checkVisibility()).map((a) => a.querySelector("[data-test=chat-row-title]").innerText),
            quiet: [...document.querySelectorAll("[data-test=chats-quiet] [data-test=chat-row-quiet]")].map((a) => a.getAttribute("href")),
            summary: document.querySelector("[data-test=chats-quiet] summary").innerText,
            folded: document.querySelector("[data-test=chats-quiet]").checkVisibility() && ! document.querySelector("[data-test=chats-quiet]").open,
            none: document.querySelector("[data-test=chats-none]").checkVisibility(),
        })');

        // The row opens the game; its chat, open beside the board, reads the message.
        $page->locator('[data-test=chat-row][href="'.route('board.show', $game).'"]')->click();
        BrowserWait::until($page, '() => location.pathname === '.json_encode(route('board.show', $game, false)).' && Alpine.$data(document.querySelector("[data-test=chat]"))?.messages.length === 1', 10_000);
        BrowserWait::until($page, '() => Object.hasOwn(JSON.parse(localStorage.getItem("esports.chat.read") ?? "{}"), '.json_encode($bert->pubkey.':'.$key).')', 5_000);
        $page->goto(ComputeUrl::from(route('me.chats', absolute: false)));
        BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=me-chats]"))?.rows.some((r) => r.last !== null)', 10_000);
        $after = chatInboxRow($page, $key);

        // Phone: the top bar's menu button.
        $page->setViewportSize(390, 844);
        $page->goto(ComputeUrl::from('/rules'));
        BrowserWait::until($page, '() => !! window.Alpine && document.querySelector("[data-test=tab-more]")?.checkVisibility()', 10_000);
        $page->locator('[data-test="tab-more"]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=mobile-chats]")?.checkVisibility()', 5_000);
        $page->locator('[data-test="mobile-chats"]')->click();
        BrowserWait::until($page, '() => location.pathname === "/me/chats" && document.querySelector("[data-test=chat-row]")?.checkVisibility()', 10_000);

        // First under "Play": a board game is no event, so Bert has no "Your matches and events" above it (the order with
        // one: tests/Feature/ChatInboxTest.php).
        expect($order[array_search('account-chats', $order, true) - 1])->toBe('account-page')
            ->and($lists)->toMatchArray(['active' => ['Anna'], 'quiet' => [route('board.show', $silent)], 'folded' => true, 'none' => false])
            ->and($lists['summary'])->toContain('(1)')
            ->and($before)->toMatchArray(['unread' => 1, 'preview' => 'Anna: Revanche heute Abend?'])
            ->and($before['time'])->toMatch('/^\d{2}:\d{2}$/')
            ->and($before['badge'])->toContain('1')
            ->and($after)->toMatchArray(['unread' => 0, 'preview' => 'Anna: Revanche heute Abend?', 'badge' => null])
            ->and($page->evaluate('() => document.documentElement.scrollWidth - document.documentElement.clientWidth'))->toBeLessThanOrEqual(0);

        foreach ([$annaPage, $page] as $each) {
            expect($each->evaluate('() => window.__errors'))->toBe([])
                ->and($each->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    } finally {
        $relay->stop(1);
    }
});
