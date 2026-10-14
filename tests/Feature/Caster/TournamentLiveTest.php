<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\CasterScript;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Caster\Programme\TournamentLive;
use App\Support\Caster\ScriptQueue;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;

/*
| User 2026-10-14 (Blockli Cup #1): live comments on a running organizer tournament with a jingle, from the live data and
| the players' Nostr bios. A comment is a rubric of topic turnier_live (behind the "breaking" jingle), one per
| every_minutes, never a second while one waits.
*/

beforeEach(function () {
    config(['tv.commentary.enabled' => true, 'tv.commentary.tournament_live.enabled' => true, 'tv.commentary.tournament_live.every_minutes' => 8,
        'tv.commentary.scripts.min_words_per_sentence' => 0]);
});

it('writes a live comment from the field and the players\' Nostr bios, at most one per interval and none while one waits', function () {
    $tournament = Tournament::factory()->create(['name' => 'Blockli Cup #1', 'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::TwoStage,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'status' => TournamentStatus::Running,
        'results_mode' => TournamentResultsMode::Players, 'starts_at' => now()->subMinutes(5)]);
    $user = User::factory()->create(['about' => 'Stacking sats seit 2017, Quoridor-Weltmeister im Kopf']);
    TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => 'Satoshi', 'rating' => 1500, 'group' => 1, 'members' => [$user->id]]);

    expect(TournamentLive::material($tournament, CarbonImmutable::now()))->toContain('Satoshi (Gruppe 1): „Stacking sats seit 2017')->toContain('digitales Quoridor');

    VoiceAgent::fake([['text' => "Markus: Breaking aus dem Blockli Cup, Leute!\nSirius: Satoshi sagt in seiner Bio, er sei Quoridor-Weltmeister im Kopf.\nMarkus: Im Kopf, ja. Auf dem Brett sehen wir das noch.\nSirius: Und jetzt zurück zur Musik.", 'canon_id' => null, 'mood' => 'hype']]);
    $now = CarbonImmutable::now();

    expect(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now))->toBe(1)
        ->and(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now->addMinutes(9)))->toBe(0);

    $script = CasterScript::query()->where('topic', TournamentLive::TOPIC)->sole();
    expect($script->kind)->toBe(CasterScript::RUBRIC)->and($script->speakers)->toContain('markus', 'sirius');

    // Off: nothing.
    config(['tv.commentary.tournament_live.enabled' => false]);
    CasterScript::query()->delete();
    expect(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now->addMinutes(30)))->toBe(0);
});
