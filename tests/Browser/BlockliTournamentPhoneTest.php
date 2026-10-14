<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

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

test('a tournament Blockli board fills the phone width like a casual one, its banner one row under it', function (int $width, int $height) {
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => 'Blockli Phone Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'blockli-phone-cup',
    ]);
    $users = [];
    foreach (['Anna Phone', 'Bert Phone'] as $index => $name) {
        $users[] = $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));
    app(TournamentRunner::class)->sync($tournament);
    $game = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
    $player = $game->white;

    $page = visit(BrowserLogin::url($player))->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('board.show', $game, false)));
    BrowserWait::until($page, '() => window.Alpine && !!document.querySelector("[data-test=board]")', 10_000);
    BrowserWait::until($page, "() => document.getAnimations().every((a) => a.playState !== \"running\")", 5_000);
    $m = $page->evaluate('() => { const r = document.querySelector("[data-test=board]").getBoundingClientRect(); const b = document.querySelector("[data-test=tournament-banner]").getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), bannerTop: Math.round(b.top), bannerH: Math.round(b.height), sheet: Math.round(document.querySelector("[data-test=chat-sheet-toggle]")?.getBoundingClientRect().top ?? innerHeight), dock: [...document.querySelectorAll("[data-test=match-dock], [data-test=open-matches]")].filter((el) => el.checkVisibility()).map((el) => Math.round(el.getBoundingClientRect().top)), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }');
    fwrite(STDERR, PHP_EOL."tournament {$width}x{$height} ".json_encode($m).PHP_EOL);

    // As wide as on a casual game (BlockliMobileTest): the full width at 390, at least 300 px at 360.
    expect($m['w'])->toBeGreaterThanOrEqual($width === 390 ? $width - 32 : 300, 'the board takes the phone width')
        ->and($m['top'] + $m['h'])->toBeLessThanOrEqual($m['sheet'], 'nothing covers the board: it ends above the chat sheet')
        ->and($m['bannerTop'])->toBeGreaterThanOrEqual($m['top'] + $m['h'], 'the tournament banner sits under the board')
        ->and($m['bannerH'])->toBeLessThanOrEqual(72, 'the banner is one row on a phone')
        ->and($m['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
})->with([
    'phone 390' => [390, 844],
    'phone 360' => [360, 740],
]);
