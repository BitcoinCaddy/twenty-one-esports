<?php

use App\Enums\TournamentFormat;
use App\Models\TournamentParticipant;
use App\Support\Payouts\TournamentPlacements;
use App\Support\Tournaments\TournamentView;
use Tests\Support\TestSigner;

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

test('a disqualified entry stands below everyone who stayed in, in the table and in the placements (user, 2026-10-25)', function () {
    $tournament = runningChess(TournamentFormat::Swiss, 4, options: ['swissRounds' => 2]);
    playOutAsDirector($tournament);
    $tournament->refresh();

    // The best seed won both rounds: disqualified anyway, it drops to the last place and off the prize places.
    $best = TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->firstOrFail();
    $best->forceFill(['disqualified_at' => now()])->save();

    $places = app(TournamentPlacements::class)->of($tournament);
    $rows = collect((new TournamentView($tournament->refresh()))->stages())->flatMap(fn (array $stage): array => collect($stage['parts'] ?? [])->flatMap(fn (array $part): array => $part['rows'] ?? [])->all())->values();

    expect($places)->not->toBeNull()
        ->and(collect($places)->last()['participants'])->toBe([$best->id])
        ->and(collect($places)->first()['participants'])->not->toContain($best->id)
        ->and($rows->last()['name'])->toBe($best->name)
        ->and($rows->last()['out'])->toBeTrue()
        ->and($rows->last()['rank'])->toBe($rows->count())
        ->and($rows->where('out', true))->toHaveCount(1);
});
