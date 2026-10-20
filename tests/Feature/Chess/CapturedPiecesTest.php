<?php

use App\Models\ChessGame;

/*
 * Captured pieces (resources/js/captured.js) are computed in the browser from
 * the position: tests/js/captured.test.mjs under Node.
 */
test('the captured pieces of each side are read from the position, promotions not counted as captures', function () {
    nodeTest('tests/js/captured.test.mjs', 3);
});

test('every chess game view shows each side\'s captured pieces', function (string $state) {
    $game = match ($state) {
        'live' => ChessGame::factory()->create(),
        'daily' => ChessGame::factory()->daily()->create(),
        'finished' => ChessGame::factory()->finished()->create(),
    };

    $html = $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()->getContent();

    expect($html)->toContain('window.chessCaptured');
    expect(substr_count($html, 'window.chessCaptured'))->toBe(2);
})->with(['live', 'daily', 'finished']);
