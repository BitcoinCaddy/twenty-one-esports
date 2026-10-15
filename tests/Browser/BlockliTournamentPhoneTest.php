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
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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

test('a tournament Blockli board is as large on a phone as a casual one, its banner one line above it in the title row, then a chip in the header once scrolled', function (int $width, int $height, bool $onAir = false) {
    // On air the header's LIVE mark takes room beside the chip: the narrowest header.
    $hls = sys_get_temp_dir().'/esports-chip-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($hls);
    config(['twentyone.stream.hls_dir' => $hls, 'twentyone.stream.public_url' => '/__test/live/stream.m3u8']);
    if ($onAir) {
        file_put_contents($hls.'/stream.m3u8', "#EXTM3U\n");
        Cache::put(LiveStatus::ANNOUNCED_KEY, ['viewers' => 1234, 'title' => 'Live now'], 600);
    }
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
                pill: box('[data-test=board-game] > div:first-child [role=status]'), heading: box('[data-test=board-game] h1'),
                shell: box('[data-test=shell-tournament]'), shellChip: box('[data-test=shell-tournament] > span'), shellRow: box('.shell-header > div:not(.shell-edge)'),
                shellFits: (() => { const r = document.querySelector('.shell-header > div:not(.shell-edge)'); return !!r && r.scrollWidth <= r.clientWidth; })(),
                shellName: document.querySelector('[data-test=shell-tournament-name]')?.textContent ?? null,
                search: box('.shell-header [aria-controls=mobile-search]'),
            };
        }
        JS;
    $settle = function () use ($page): void {
        BrowserWait::until($page, '() => window.Alpine && !!document.querySelector("[data-test=board]")', 10_000);
        BrowserWait::until($page, '() => document.getAnimations().every((a) => a.playState !== "running" || a.effect?.getTiming().iterations === Infinity)', 5_000);
    };
    $page->goto(ComputeUrl::from(route('board.show', $casual, false)));
    $settle();
    $plain = $page->evaluate($measure);
    $page->goto(ComputeUrl::from(route('board.show', $game, false)));
    $settle();
    $m = $page->evaluate($measure);
    // Scrolled on, the title row's line is gone under the header: the header's chip takes over (2026-10-15).
    $page->evaluate('() => window.scrollTo(0, document.documentElement.scrollHeight)');
    BrowserWait::until($page, '() => document.querySelector("[data-test=shell-tournament]")?.checkVisibility()', 5_000);
    $scrolled = $page->evaluate($measure);
    fwrite(STDERR, PHP_EOL."tournament {$width}x{$height} ".json_encode(['casual' => $plain, 'tournament' => $m, 'scrolled' => $scrolled]).PHP_EOL);

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
        // The connection pill stays in the title row (at 320 px it fell onto a line of its own): one row, the pill beside the name.
        ->and($plain['pill']['top'])->toBeLessThan($plain['heading']['bottom'], 'the pill beside the name, casual')
        ->and($m['pill']['top'])->toBeLessThan($m['line']['bottom'], 'the pill beside the banner line')
        // The header's chip: none for a casual game, none while the title row's line is in view, then always at the top.
        ->and($plain['shell'])->toBeNull('no chip for a casual game')
        ->and($m['shell'])->toBeNull('no chip while the line is in view')
        ->and($scrolled['line']['bottom'])->toBeLessThanOrEqual($scrolled['shellRow']['bottom'], 'the line has scrolled away')
        ->and($scrolled['shell']['h'])->toBeGreaterThanOrEqual(44, 'its tap area')
        ->and($scrolled['shellChip']['h'])->toBeLessThanOrEqual(30)
        ->and($scrolled['shellChip']['top'])->toBeGreaterThanOrEqual($scrolled['shellRow']['top'])
        ->and($scrolled['shellChip']['bottom'])->toBeLessThanOrEqual($scrolled['shellRow']['bottom'], 'inside the sticky header')
        ->and($scrolled['shell']['right'])->toBeLessThanOrEqual($scrolled['search']['left'], 'clear of the header\'s buttons')
        ->and($scrolled['shellChip']['w'])->toBeGreaterThanOrEqual($onAir ? 30 : 44, $onAir ? 'the trophy at least' : 'room for the trophy and a word')
        ->and($scrolled['shellFits'])->toBeTrue('the header row keeps one line')
        ->and($scrolled['shellName'])->toBe('Blockli Phone Cup with a rather long name')
        ->and($scrolled['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
    expect(! $onAir || $page->evaluate('() => [...document.querySelectorAll(".shell-header [data-test=live-badge]")].some((el) => el.checkVisibility())'))->toBeTrue('on air: the LIVE mark beside the chip');
    File::deleteDirectory($hls);
})->with([
    'phone 320, on air' => [320, 640, true],
    'phone 320' => [320, 640],
    'phone 390' => [390, 844],
    'phone 360' => [360, 740],
    'iPhone SE in Safari' => [375, 553],
]);
