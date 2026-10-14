<?php

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

/*
| Blockli Cup #1 (2026-10-14): the hero's cover art (533 px wide at 300 px height) slid under a long tournament name
| whenever the main column was narrower than about 1330 px, and the logo baked into the Blockli cover overlapped the
| title. The cover now takes at most the 38 % the text leaves free. Measured: title and cover never intersect, from
| 1024 to 1440 px, as the organizer (with the director chat beside the page), with a clean console.
*/

const HERO_BOXES = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height }; };
        const title = document.querySelector('[data-test="tournament-name"]');
        const cover = document.querySelector('[data-test="tournament-hero"] [data-test="game-cover"]');
        const range = document.createRange();
        range.selectNodeContents(title);
        const lines = [...range.getClientRects()].map((r) => ({ left: r.left, right: r.right, top: r.top, bottom: r.bottom }));
        return { title: box(title), lines, cover: cover && cover.checkVisibility() ? box(cover) : null };
    }
    JS;

it('keeps a long tournament name clear of the hero cover from 1024 to 1440 px, with a clean console', function () {
    $organizer = User::factory()->create();
    $tournament = Tournament::factory()->signup()->create([
        'name' => 'Blockli Cup #1: Das erste Blockli-Turnier', 'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::TwoStage,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'capacity' => 20,
        'created_by_id' => $organizer->id, 'published_at' => now(),
    ]);

    $page = visit(BrowserLogin::url($organizer))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    foreach ([1024, 1180, 1280, 1440] as $width) {
        $page->setViewportSize($width, 900);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament, false)));
        BrowserWait::until($page, '() => document.readyState === "complete"', 10_000);
        $boxes = $page->evaluate(HERO_BOXES);

        expect($boxes['cover'])->not->toBeNull("{$width} px: no cover");

        foreach ($boxes['lines'] as $line) {
            $overlap = $line['right'] > $boxes['cover']['left'] && $line['left'] < $boxes['cover']['right']
                && $line['bottom'] > $boxes['cover']['top'] && $line['top'] < $boxes['cover']['bottom'];
            expect($overlap)->toBeFalse("{$width} px: a title line (right {$line['right']}) runs under the cover (left {$boxes['cover']['left']})");
        }

        [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
        expect($scroll)->toBeLessThanOrEqual($client, "{$width} px: sideways scroll")
            ->and($page->evaluate('() => window.__errors'))->toBe([], "{$width} px: console")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$width} px: responses");
        fwrite(STDERR, sprintf("%d px: title right %.0f, cover left %.0f, cover %dx%d\n", $width, max(array_column($boxes['lines'], 'right')), $boxes['cover']['left'], $boxes['cover']['width'], $boxes['cover']['height']));
    }
});
