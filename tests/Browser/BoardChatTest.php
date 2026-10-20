<?php

use App\Models\ChatMute;
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
| The players chat on a Blockli game's page (plan "Blockli-Optimierung", P1)
|--------------------------------------------------------------------------
|
| Two players write to each other over NIP-17 through the in-memory relay (tests/Support/mini-relay.php), as on a
| chess game's page: the desktop panel at 1440, the bottom sheet on a phone. Every message carries the tag
| `board:<id>`, and muting is kept on the account. Console, uncaught errors and answers >= 400 stay empty.
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

/** `$lateMs`: the signer arrives that long after the page, as a browser extension's injected window.nostr does. */
function boardChatPage(User $user, string $to, int $lateMs = 0): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript($lateMs > 0 ? 'setTimeout(() => { '.TestSigner::browserStub($user).' }, '.$lateMs.');' : TestSigner::browserStub($user));
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function boardChatSees(string $from, string $text): string
{
    return '() => [...document.querySelectorAll("section[aria-labelledby=chat-h] [data-test=chat-messages] li[data-from='.$from.']")].some((li) => li.innerText.includes('.json_encode($text).'))';
}

/** In the page: everything the chat shows is read (no badge) and this device's marker holds its newest message. */
function boardChatRead(): string
{
    return '() => { const chat = Alpine.$data(document.querySelector("[data-test=chat]")); const marker = JSON.parse(localStorage.getItem("esports.chat.read") ?? "{}")[chat.readKey]; return chat.messages.length > 0 && chat.unread === 0 && marker === Math.max(...chat.messages.map((m) => m.at)); }';
}

function boardChatSend(Page $page, string $text): void
{
    $page->locator('#chatin')->fill($text);
    $page->locator('section[aria-labelledby=chat-h] [data-test=chat-send]')->click();
    BrowserWait::until($page, '() => document.querySelector("#chatin").value === ""', 1_500);
}

test('two Blockli players chat through a relay without a click, even with a late extension, tagged with the board game, and on a phone in the bottom sheet', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        [$anna, $bert] = User::factory()->count(2)->create();
        TestSigner::forBrowser($anna);
        TestSigner::forBrowser($bert);
        $game = app(BoardGameService::class)->start('blockli', $anna, $bert);
        $path = route('board.show', $game, false);
        $pageA = boardChatPage($anna, $path);
        // Bert's extension hangs window.nostr on the page late: the chat starts by itself, no "Open chat" (user, 2026-10-09).
        $pageB = boardChatPage($bert, $path, 800);

        foreach ([$pageA, $pageB] as $page) {
            BrowserWait::until($page, '() => window.Alpine && Alpine.$data(document.querySelector("[data-test=chat]"))?.status === "live"', 10_000);
        }

        expect($pageB->evaluate('() => document.querySelector("[data-test=chat-connect]")?.checkVisibility() ?? false'))->toBeFalse();

        boardChatSend($pageA, 'gl hf');
        BrowserWait::until($pageB, boardChatSees('them', 'gl hf'), 10_000);
        // The desktop panel shows in the front tab: what came in is read at once (gameChat.markRead(true) in chat.blade.php).
        BrowserWait::until($pageB, boardChatRead(), 5_000);
        boardChatSend($pageB, 'you too');
        BrowserWait::until($pageA, boardChatSees('them', 'you too'), 10_000);

        $tags = $pageB->evaluate('() => Alpine.$data(document.querySelector("[data-test=chat]")).rumors.map((r) => r.tags.find((t) => t[0] === "match")?.[1])');

        $pageB->locator('section[aria-labelledby=chat-h] [data-test=mute]')->click();
        BrowserWait::until($pageB, '() => Alpine.$data(document.querySelector("[data-test=chat]")).opponentMuted === true', 5_000);

        // Phone: the closed sheet sits on the tab bar; opened, it shows the conversation.
        $pageA->setViewportSize(390, 844);
        BrowserWait::until($pageA, '() => document.querySelector("[data-test=chat-sheet-toggle]")?.getBoundingClientRect().height > 0', 5_000);
        $pageA->locator('[data-test=chat-sheet-toggle]')->click();
        BrowserWait::until($pageA, '() => [...document.querySelectorAll("#sheet-body [data-test=chat-messages] li[data-from=them]")].some((li) => li.offsetParent !== null && li.innerText.includes("you too"))', 5_000);

        // A message that arrives while the sheet is open is read at once: no badge, the marker moves (markRead() in chat.blade.php).
        // Narrow (the panel's twin is off), and a full second past the newest message: messages carry whole seconds, one sent
        // in the same second would not count as new against the marker.
        BrowserWait::until($pageA, '() => { const chat = Alpine.$data(document.querySelector("[data-test=chat]")); return chat.wide === false && Date.now() >= Math.max(...chat.messages.map((m) => m.at)) + 1000; }', 5_000);
        boardChatSend($pageB, 'one more');
        BrowserWait::until($pageA, '() => [...document.querySelectorAll("#sheet-body [data-test=chat-messages] li[data-from=them]")].some((li) => li.innerText.includes("one more"))', 10_000);
        BrowserWait::until($pageA, boardChatRead(), 5_000);

        expect(array_values(array_unique($tags)))->toBe(['board:'.$game->id])
            ->and(ChatMute::query()->where('user_id', $bert->id)->pluck('muted_pubkey')->all())->toBe([$anna->pubkey])
            ->and($pageA->evaluate('() => document.documentElement.scrollWidth - document.documentElement.clientWidth'))->toBeLessThanOrEqual(0);

        foreach ([$pageA, $pageB] as $page) {
            expect($page->evaluate('() => window.__errors'))->toBe([])
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
        }
    } finally {
        $relay->stop(1);
    }
});

test('a 39-character opponent name keeps the chat on the page: the mute button wraps under the sentence and truncates only the name, at 390 and 1440, in German', function () {
    // V3 (2026-10-20): the nowrap button squeezed "Nur du und … sehen diesen Chat." to 0 px (165 px tall) at 390 and ran
    // 67 px past the page at 1440.
    [$anna, $bert] = User::factory()->count(2)->create(['locale' => 'de']);
    $bert->forceFill(['name' => 'Bertholdine von Hashrate-Zweiundzwanzig'])->save();
    TestSigner::forBrowser($anna);
    $game = app(BoardGameService::class)->start('blockli', $anna, $bert);
    $page = boardChatPage($anna, route('board.show', $game, false));
    $measure = <<<'JS'
        (scope) => {
            const root = document.querySelector(scope);
            const box = (el) => { const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), w: Math.round(r.width), h: Math.round(r.height) }; };
            const button = [...root.querySelectorAll('button[aria-pressed]')].find((el) => el.checkVisibility());
            const label = [...button.querySelectorAll('[data-test^=mute-label-]')].find((el) => el.checkVisibility());
            const verb = label ? [...label.children].filter((el) => el.dataset.test !== 'mute-name').map((el) => el.textContent).join('').trim() : null;
            const note = [...root.querySelectorAll('span')].find((el) => el.checkVisibility() && el.textContent.trim().startsWith('Nur du und'));
            return {
                section: box(button.closest('section')), button: box(button), note: box(note), verb,
                verbWhole: !! label && [...label.children].every((el) => el.dataset.test === 'mute-name' || el.scrollWidth <= el.clientWidth + 1),
                overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            };
        }
        JS;
    $measured = [];

    foreach ([[390, 844, '#sheet-body'], [1440, 900, '[data-test=chat-panel]']] as [$width, $height, $scope]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('board.show', $game, false)));
        BrowserWait::until($page, '() => document.readyState === "complete" && !! window.Alpine', 10_000);
        if ($width < 1024) {
            $page->locator('[data-test=chat-sheet-toggle]')->click();
            BrowserWait::until($page, '() => document.querySelector("#sheet-body").checkVisibility()', 3_000);
        }
        $m = $measured[$width] = $page->evaluate($measure, $scope);

        expect($m['overflow'])->toBe(0, "{$width}: page overflow")
            ->and($m['button']['right'])->toBeLessThanOrEqual($m['section']['right'], "{$width}: button outside its chat")
            // The sentence is a line of words, not a column of one word each.
            ->and($m['note']['w'])->toBeGreaterThanOrEqual(240, "{$width}: the sentence is squeezed")
            ->and($m['button']['h'])->toBe(44)
            ->and($m['verb'])->toBe('stummschalten')
            ->and($m['verbWhole'])->toBeTrue();
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    fwrite(STDERR, "\n[chat-long-name] ".json_encode($measured)."\n");
});
