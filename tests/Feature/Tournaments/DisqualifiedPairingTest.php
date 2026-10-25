<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\ChessGame;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Support\Chess\ChessGameService;
use App\Support\Tournaments\TournamentRunner;
use Tests\Support\TestSigner;

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

test('a disqualified entry is not paired in the next Swiss round and no game starts against it (user, 2026-10-25)', function () {
    $tournament = runningChess(TournamentFormat::Swiss, 6, TournamentResultsMode::Players, ['swissRounds' => 3]);
    $games = ChessGame::query()->with(['white', 'black'])->whereIn('tournament_match_id', $tournament->matches()->select('id'))->get();
    expect($games)->toHaveCount(3);

    $out = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderByDesc('seed')->firstOrFail();
    $out->forceFill(['disqualified_at' => now(), 'disqualification_reason' => 'test'])->save();

    foreach ($games as $game) {
        app(ChessGameService::class)->resign($game, $game->black);
    }

    app(TournamentRunner::class)->sync($tournament->refresh());

    $round2 = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'like', 's2-%')->with('slots')->get();
    $paired = $round2->flatMap(fn ($match) => $match->slots->pluck('tournament_participant_id'))->all();

    expect($round2)->not->toBeEmpty()
        ->and($paired)->not->toContain($out->id)
        ->and($paired)->toHaveCount(5)
        ->and($round2->where('bracket', 'bye'))->toHaveCount(1);
});

test('no game starts for a match whose side was disqualified after the pairing; the league decides it (user, 2026-10-25)', function () {
    $tournament = runningChess(TournamentFormat::Swiss, 4, TournamentResultsMode::Players, ['swissRounds' => 2]);
    $games = ChessGame::query()->with(['white', 'black'])->whereIn('tournament_match_id', $tournament->matches()->select('id'))->get();
    foreach ($games as $game) {
        app(ChessGameService::class)->resign($game, $game->black);
    }
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 's2-1')->with('slots.participant')->firstOrFail();
    ChessGame::query()->where('tournament_match_id', $match->id)->delete();
    $match->slots[0]->participant->forceFill(['disqualified_at' => now()])->save();

    app(TournamentRunner::class)->sync($tournament->refresh());

    expect(ChessGame::query()->where('tournament_match_id', $match->id)->exists())->toBeFalse()
        ->and($match->refresh()->result['winner'] ?? null)->toBe(1)
        ->and($match->result['decided'] ?? null)->toBe('disqualified');
});
