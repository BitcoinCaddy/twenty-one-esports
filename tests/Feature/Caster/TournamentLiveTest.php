<?php

use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Caster\Programme\TournamentLive;
use App\Support\Caster\ScriptQueue;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;

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
        // Inside the interval nothing else is written, whatever the model would say (the standby duet of the
        // failed draft comes only when the interval is over again, user 2026-10-15: one every five minutes, by force).
        ->and(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now->addMinutes(3)))->toBe(0);

    $script = CasterScript::query()->where('topic', TournamentLive::TOPIC)->sole();
    expect($script->kind)->toBe(CasterScript::RUBRIC)->and($script->speakers)->toContain('markus', 'sirius');

    // Off: nothing.
    config(['tv.commentary.tournament_live.enabled' => false]);
    CasterScript::query()->delete();
    expect(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now->addMinutes(30)))->toBe(0);
});

it('writes Noerd the way the voices say it, Nörd, in every queued script', function () {
    expect(ScriptQueue::sayAs('Noerd gegen noerd, Noerdlicht bleibt'))->toBe('Nörd gegen Nörd, Noerdlicht bleibt');
    expect(ScriptQueue::sayAs('TWENTY ONE TV, im TV-Studio, TVöD bleibt'))->toBe('TWENTY ONE T Fau, im T Fau-Studio, TVöD bleibt');
    // Clock times: the voice reads the colon as "Uhr", so the written one goes (live 2026-10-15: "22 Uhr 37 Uhr").
    expect(ScriptQueue::sayAs('Es ist 22:37 Uhr Berliner Zeit.'))->toBe('Es ist 22 Uhr 37 Berliner Zeit.');
    expect(ScriptQueue::sayAs('Start um 14:05, Ende 9:59.'))->toBe('Start um 14 Uhr 5, Ende 9 Uhr 59.');
    expect(ScriptQueue::sayAs('Um 8 Uhr, um 8:30 Uhr und um 18:45.'))->toBe('Um 8 Uhr, um 8 Uhr 30 und um 18 Uhr 45.');
});

it('tells a finished game once: later comments see it only in the standings', function () {
    Queue::fake();
    BlockliOn::play();
    $tournament = Tournament::factory()->create(['game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'status' => TournamentStatus::Running,
        'results_mode' => TournamentResultsMode::Players, 'capacity' => 2]);
    [$a, $b] = [User::factory()->create(), User::factory()->create()];
    foreach ([$a, $b] as $index => $user) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => 'P'.$index, 'rating' => 1500 - $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);
    $game = BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->firstOrFail();
    $game->forceFill(['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()])->save();
    $winner = User::query()->findOrFail($game->white_id)->displayName();

    expect(TournamentLive::material($tournament, CarbonImmutable::now()))->toContain('- Fertig: ')->toContain('Siege bisher: '.$winner.' 1')
        ->and(TournamentLive::material($tournament, CarbonImmutable::now(), [], null, [$game->id]))->not->toContain('- Fertig:')->toContain('NEUE Ergebnisse seit dem letzten Einwurf: keine')->toContain('Siege bisher: '.$winner.' 1');
});

it('writes no new comment while the last one waits voiced in the spool, and none expires (user 2026-10-18: no pile of breakings)', function () {
    Tournament::factory()->create(['name' => 'Blockli Cup #2', 'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::TwoStage,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'status' => TournamentStatus::Running,
        'results_mode' => TournamentResultsMode::Players, 'starts_at' => now()->subMinutes(5)]);
    VoiceAgent::fake(fn (): array => ['text' => "Markus: Breaking aus dem Blockli Cup!\nSirius: Die Bretter glühen.", 'canon_id' => null, 'mood' => 'hype']);
    $now = CarbonImmutable::now();

    expect(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now))->toBe(1);
    $script = CasterScript::query()->where('topic', TournamentLive::TOPIC)->sole();
    expect($script->expires_at->greaterThan($now->addYears(9)))->toBeTrue();

    // Voiced, waiting in the spool for its air: still no second one, long after the interval.
    $script->update(['status' => CasterScript::VOICED]);
    CasterClip::query()->create(['caster_script_id' => $script->id, 'phase' => 'break', 'kind' => CasterScript::RUBRIC, 'path' => 'caster/spool/'.$script->id.'.opus',
        'duration_seconds' => 20, 'bytes' => 10, 'sentence_ends' => [20.0], 'status' => CasterClip::READY, 'expires_at' => $script->expires_at]);
    expect(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now->addMinutes(40)))->toBe(0);

    // Aired (a played clip leaves the spool): the next one comes.
    CasterClip::query()->delete();
    expect(app(TournamentLive::class)->fill(app(ScriptQueue::class), $now->addMinutes(41)))->toBe(1);
});

it('airs its one draft when the checks turn it down, without the link, counted, instead of the standby duet (user 2026-10-18)', function () {
    $tournament = Tournament::factory()->create(['name' => 'Blockli Cup #1', 'game' => 'blockli', 'mode' => 'correspondence', 'format' => TournamentFormat::TwoStage,
        'options' => FormatOptions::defaults(GameProfile::for('blockli', 'correspondence'))->toArray(), 'status' => TournamentStatus::Running,
        'results_mode' => TournamentResultsMode::Players, 'starts_at' => now()->subMinutes(5)]);
    $user = User::factory()->create();
    TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => 'Satoshi', 'rating' => 1500, 'group' => 1, 'members' => [$user->id]]);
    VoiceAgent::fake([['text' => "Markus: Breaking aus dem Blockli Cup, alles live unter https://example.com/cup zu sehen.\nSirius: Die Bretter glühen heute richtig, und Satoshi schwitzt.", 'canon_id' => null, 'mood' => 'hype']]);

    expect(app(TournamentLive::class)->fill(app(ScriptQueue::class), CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->where('topic', TournamentLive::TOPIC)->sole();
    expect($script->text)->toBe('Die Bretter glühen heute richtig, und Satoshi schwitzt.')
        ->and($script->speakers)->toBe(['sirius'])
        ->and(DB::table('voice_rejections')->where('channel', TournamentLive::CHANNEL)->where('reason', VoiceGuard::URL)->value('count'))->toBe(1)
        ->and(DB::table('voice_usage')->where('channel', TournamentLive::CHANNEL)->value('accepted'))->toBe(1);
});
