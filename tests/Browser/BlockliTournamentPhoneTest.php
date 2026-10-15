<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
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

test('a tournament Blockli board is as large on a phone as a casual one, its banner one line above it in the title row', function (int $width, int $height) {
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination, 'name' => 'Blockli Phone Cup with a rather long name',
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
    // The same player in a casual game: the board the tournament one must match.
    $casual = app(BoardGameService::class)->start('blockli', $player, User::factory()->create());

    $page = visit(BrowserLogin::url($player))->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $measure = <<<'JS'
        () => {
            const box = (sel) => { const el = document.querySelector(sel); if (!el || !el.checkVisibility()) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), w: Math.round(r.width), h: Math.round(r.height) }; };
            const row = document.querySelector('[data-test=board-game] > div:first-child');
            const name = document.querySelector('[data-test=tournament-banner-row-name]');
            return {
                board: box('[data-test=board]'), title: box('[data-test=board-game] > div:first-child'), banner: box('[data-test=tournament-banner]'), line: box('[data-test=tournament-banner-row]'),
                chip: box('[data-test=tournament-banner-row] > span'), desk: box('[data-test=board-game] [data-test=desk-button]'),
                nameText: name?.textContent ?? null, nameCut: !!name && name.scrollWidth > name.clientWidth, round: document.querySelector('[data-test=tournament-banner-row-round]')?.textContent ?? null,
                rowFits: !!row && row.scrollWidth <= row.clientWidth, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            };
        }
        JS;
    $settle = function () use ($page): void {
        BrowserWait::until($page, '() => window.Alpine && !!document.querySelector("[data-test=board]")', 10_000);
        BrowserWait::until($page, '() => document.getAnimations().every((a) => a.playState !== "running")', 5_000);
    };
    $page->goto(ComputeUrl::from(route('board.show', $casual, false)));
    $settle();
    $plain = $page->evaluate($measure);
    $page->goto(ComputeUrl::from(route('board.show', $game, false)));
    $settle();
    $m = $page->evaluate($measure);
    fwrite(STDERR, PHP_EOL."tournament {$width}x{$height} ".json_encode(['casual' => $plain, 'tournament' => $m]).PHP_EOL);

    // DerCaddy, 2026-10-15: the banner above the game, one line only, and the board no smaller than in a casual game.
    expect($m['board']['w'])->toBe($plain['board']['w'], 'as large as a casual board')
        ->and($m['board']['top'])->toBe($plain['board']['top'], 'starting where a casual board starts')
        ->and($m['title']['h'])->toBe($plain['title']['h'], 'the title row keeps its height')
        ->and($m['banner'])->toBeNull('the full banner is for lg and up')
        ->and($m['line'])->not->toBeNull()
        ->and($m['line']['bottom'])->toBeLessThanOrEqual($m['board']['top'], 'the banner line stands above the board')
        ->and($m['line']['h'])->toBeGreaterThanOrEqual(44, 'its tap area')
        ->and($m['chip']['h'])->toBeLessThanOrEqual(34, 'one line')
        ->and($m['nameText'])->toBe('Blockli Phone Cup with a rather long name')
        ->and($m['round'])->toContain('1')
        ->and($m['rowFits'])->toBeTrue('nothing spills out of the title row')
        ->and($m['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
})->with([
    'phone 390' => [390, 844],
    'phone 360' => [360, 740],
    'iPhone SE in Safari' => [375, 553],
]);
