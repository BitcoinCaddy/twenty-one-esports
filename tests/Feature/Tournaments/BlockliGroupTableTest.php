<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| A Blockli table ranks pairings of two legs (Nidan21, 2026-10-17)
|--------------------------------------------------------------------------
|
| "Mein Gegner Anomark hat gerade aufgegeben, aber trotzdem steht bei mir ein
| Remis und Null Siege": a won and a lost leg are one drawn pairing, and a
| pairing enters the table only after both legs. The table says so and lists
| the pairings under way with their standing.
|
*/

beforeEach(function () {
    BlockliOn::play();
});

test('a Blockli round robin explains that the table counts pairings and lists the pairings under way with their standing', function () {
    $tournament = Tournament::factory()->create([
        'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::RoundRobin, 'name' => 'Blockli Table Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('blockli', 'correspondence'))->toArray(),
        'capacity' => 4, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'blockli-table-cup',
    ]);
    foreach (['Anna Racer', 'Bert Racer', 'Cara Racer', 'Dora Racer'] as $index => $name) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);
    $service = app(BoardGameService::class);
    $live = fn (TournamentMatch $match): BoardGame => BoardGame::query()->where('tournament_match_id', $match->id)->where('status', BoardGameStatus::Active)->sole();
    $loser = fn (TournamentMatch $match, int $slot): User => User::query()->findOrFail((int) $match->slots[$slot]->participant->memberIds()[0]);
    $playing = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('status', 'ready')->with('slots.participant')->orderBy('id')->get();
    expect($playing->count())->toBeGreaterThanOrEqual(2);
    [$drawn, $open] = [$playing[0], $playing[1]];

    // One leg each: a drawn pairing. One leg of the other: under way, 1–0.
    [$drawnA, $drawnB, $openB] = [$loser($drawn, 0), $loser($drawn, 1), $loser($open, 1)];
    $names = fn (TournamentMatch $match): array => $match->slots->sortBy('slot')->map(fn ($slot) => $slot->participant->name)->values()->all();
    [$a, $b] = $names($open);
    $service->resign($live($drawn), $drawnB);
    $service->resign($live($drawn), $drawnA);
    $service->resign($live($open), $openB);

    expect($drawn->refresh()->result)->toMatchArray(['winner' => null, 'games_won' => [1, 1]])
        ->and($open->refresh()->result)->toBeNull();

    $part = (new TournamentView($tournament->refresh()))->stages()[0]['parts'][0];

    expect($part['two_legs'])->toBeTrue()
        ->and($part['open'])->toBe([['a' => $a, 'b' => $b, 'score' => '1–0']])
        ->and(collect($part['rows'])->sum('ties'))->toBe(2)
        ->and(collect($part['rows'])->sum('wins'))->toBe(0);

    $this->get(route('tournaments.show', $tournament).'?lang=de')->assertOk()
        ->assertSeeText('S R N zählt Paarungen: Hin- und Rückspiel zusammen. Eine Paarung kommt erst nach beiden Partien in die Tabelle.')
        ->assertSeeText('Laufende Paarungen')
        ->assertSeeInOrder([$a, '1–0', $b]);
});

test('a one-game table (chess) carries neither the pairing note nor pairings under way', function () {
    $tournament = Tournament::factory()->create([
        'game' => 'chess', 'mode' => 'correspondence', 'format' => TournamentFormat::RoundRobin, 'name' => 'Chess Table Cup',
        'options' => FormatOptions::fromArray([], GameProfile::for('chess', 'correspondence'))->toArray(),
        'capacity' => 4, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'chess-table-cup',
    ]);
    foreach (['Anna Rook', 'Bert Rook', 'Cara Rook'] as $index => $name) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));

    $part = (new TournamentView($tournament->refresh()))->stages()[0]['parts'][0];

    expect($part['two_legs'])->toBeFalse()
        ->and($part['open'])->toBe([]);
    $this->get(route('tournaments.show', $tournament).'?lang=de')->assertOk()
        ->assertDontSeeText('S R N zählt Paarungen')
        ->assertDontSeeText('Laufende Paarungen');
});
