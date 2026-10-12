<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: bulletins that air, and an autopilot that stays off
|--------------------------------------------------------------------------
|
| Prod 2026-10-13: the 00:00 and 01:00 Berlin bulletins (scripts 2644, 2706)
| expired unvoiced. Each was claimed by a fetcher that was restarted
| (SIGKILL after the stop timeout) while voicing it, and the claim stayed
| held for `claim_seconds` (600 on prod), past the slot. Sport und Wetter and
| the news talk aired anyway and spoke of news that never aired. And the
| autopilot ran with the workstation polling: the Redis cache hands the
| fetcher's last-seen timestamp back as a numeric string, which never
| counted as seen.
|
*/

const BULLETIN_RELIABILITY_TOKEN = 'bulletin-token-0123456789abcdef0123456789ab';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.fetch.token' => BULLETIN_RELIABILITY_TOKEN,
        'tv.commentary.fetch.online_seconds' => 120,
        'tv.commentary.fetch.claim_seconds' => 600,
        'tv.commentary.fetch.slot_claim_seconds' => 120,
        'tv.commentary.programme.bulletin.ready_seconds' => 60,
        'tv.commentary.blocks.enabled' => true,
        'tv.commentary.blocks.stock.enabled' => true,
        'tv.commentary.blocks.stock.library.enabled' => true,
        'tv.commentary.blocks.autopilot.enabled' => true,
        'tv.commentary.podcast_replay.enabled' => false,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:50', 'Europe/Berlin'));
});

/** @param  array<string, string>  $headers */
function bulletinReliabilityPost(string $uri, array $headers = []): TestResponse
{
    $server = collect(['Authorization' => 'Bearer '.BULLETIN_RELIABILITY_TOKEN, AutoPilot::HEADER => 'up', ...$headers])
        ->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all();

    return test()->call('POST', $uri, [], [], [], $server);
}

/** The 14:00 bulletin, queued at 13:50 like fillBulletin() does. */
function bulletinReliabilityBulletin(): CasterScript
{
    $slot = CarbonImmutable::parse('2026-10-14 14:00', 'Europe/Berlin');

    return app(ScriptQueue::class)->enqueue(CasterScript::BULLETIN, ScenePhase::BREAK, 'Es ist vierzehn Uhr, die Nachrichten. Der Bitcoin-Kurs steigt.', 'bulletin:'.$slot->getTimestamp(),
        75, CarbonImmutable::now(), topic: 'news', airsAt: $slot, expiresAt: $slot->addSeconds(120));
}

function bulletinReliabilityFollowUp(string $prefix, string $text): CasterScript
{
    $slot = CarbonImmutable::parse('2026-10-14 14:00', 'Europe/Berlin');

    return app(ScriptQueue::class)->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, $text, $prefix.':'.$slot->getTimestamp(), 70, CarbonImmutable::now(),
        topic: $prefix, airsAt: $slot->addSeconds(150), expiresAt: $slot->addSeconds(900));
}

it('hands the bulletin out first in every claim mode the workstation polls with', function (string $uri, bool $full) {
    $queue = app(ScriptQueue::class);
    $live = $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, 'Tor im Finale, das Spiel ist entschieden.', 'live-1', 90, CarbonImmutable::now());
    $bulletin = bulletinReliabilityBulletin();

    if ($full) {
        config(['tv.commentary.spool.target_seconds' => 0]);
    }

    $claim = bulletinReliabilityPost($uri)->assertOk();

    expect($live)->not->toBeNull()
        ->and($claim->json('script.id'))->toBe($bulletin->id)
        ->and($claim->json('script.kind'))->toBe(CasterScript::BULLETIN);
})->with([
    'single clip and block mode' => ['/api/caster/claim', false],
    'urgent poll during a block or stock render' => ['/api/caster/claim?only=urgent', false],
    'full spool' => ['/api/caster/claim', true],
]);

it('frees a bulletin held by a killed fetcher in time for its slot, not after claim_seconds', function () {
    $queue = app(ScriptQueue::class);
    $bulletin = bulletinReliabilityBulletin();
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Die Liga läuft heute heiß.', 'scene-1', 0, CarbonImmutable::now());

    expect($queue->claim(CarbonImmutable::now())->id)->toBe($bulletin->id)
        ->and($queue->claim(CarbonImmutable::now())->id)->toBe($scene->id);

    // The fetcher is killed mid-voicing; the restarted one polls.
    $this->travel(119)->seconds();
    expect($queue->claim(CarbonImmutable::now()))->toBeNull();

    $this->travel(2)->seconds();
    expect($queue->claim(CarbonImmutable::now())?->id)->toBe($bulletin->id)
        ->and($scene->fresh()->status)->toBe(CasterScript::CLAIMED);
});

it('skips a bulletin not voiced a minute before its slot, and its follow-ups with it, voiced or not', function () {
    $bulletin = bulletinReliabilityBulletin();
    $sportWeather = bulletinReliabilityFollowUp('sport_weather', 'Nach den Nachrichten jetzt Sport und Wetter.');
    $newsTalk = bulletinReliabilityFollowUp('news_talk', 'Wir haben euch jetzt die Nachrichten vorgelesen.');
    $otherSlot = app(ScriptQueue::class)->enqueue(CasterScript::RUBRIC, ScenePhase::BREAK, 'Sport und Wetter von gestern.', 'sport_weather:123', 70, CarbonImmutable::now(),
        topic: 'sport_weather', airsAt: CarbonImmutable::now()->addMinutes(30), expiresAt: CarbonImmutable::now()->addHour());
    $sportWeather->update(['status' => CasterScript::VOICED]);
    $clip = CasterClip::query()->create(['caster_script_id' => $sportWeather->id, 'phase' => 'break', 'kind' => CasterScript::RUBRIC, 'path' => 'caster/spool/'.$sportWeather->id.'.opus',
        'duration_seconds' => 5, 'bytes' => 10, 'sentence_ends' => [5.0], 'status' => CasterClip::READY, 'expires_at' => CarbonImmutable::now()->addMinutes(30)]);
    Storage::disk('local')->put($clip->path, 'opus');
    $queue = app(ScriptQueue::class);

    // 61 s before the slot it may still be voiced.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:58:59', 'Europe/Berlin'));
    $queue->expire(CarbonImmutable::now());
    expect($bulletin->fresh()->status)->toBe(CasterScript::QUEUED)
        ->and($clip->fresh())->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:59:00', 'Europe/Berlin'));
    expect($queue->expire(CarbonImmutable::now()))->toBeGreaterThanOrEqual(1)
        ->and($bulletin->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($sportWeather->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and($newsTalk->fresh()->status)->toBe(CasterScript::EXPIRED)
        ->and(CasterClip::query()->find($clip->id))->toBeNull()
        ->and(Storage::disk('local')->exists($clip->path))->toBeFalse()
        ->and($otherSlot->fresh()->status)->toBe(CasterScript::QUEUED);

    // Nothing of that slot is handed out any more.
    expect(bulletinReliabilityPost('/api/caster/claim')->json('script.id'))->toBe($otherSlot->id);
});

it('leaves a voiced bulletin and its follow-ups alone at the slot', function () {
    $bulletin = bulletinReliabilityBulletin();
    $newsTalk = bulletinReliabilityFollowUp('news_talk', 'Wir haben euch jetzt die Nachrichten vorgelesen.');
    $bulletin->update(['status' => CasterScript::VOICED]);

    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:59:30', 'Europe/Berlin'));
    app(ScriptQueue::class)->expire(CarbonImmutable::now());

    expect($bulletin->fresh()->status)->toBe(CasterScript::VOICED)
        ->and($newsTalk->fresh()->status)->toBe(CasterScript::QUEUED);
});

it('counts the workstation as there while it polls, idle or not, with the timestamp the Redis cache hands back as a string', function () {
    // An idle claim poll: nothing to voice, the workstation is alive all the same.
    bulletinReliabilityPost('/api/caster/claim')->assertOk()->assertJson(['script' => null]);
    expect(AutoPilot::active(CarbonImmutable::now()))->toBeFalse();

    // What the Redis store returns for a number it kept unserialized (prod 2026-10-13: "1791847226").
    Cache::put(ClipSpool::FETCHER_SEEN, (string) CarbonImmutable::now()->getTimestamp(), 3600);
    expect(app(ClipSpool::class)->fetcherOnline(CarbonImmutable::now()))->toBeTrue()
        ->and(AutoPilot::active(CarbonImmutable::now()))->toBeFalse();

    // A reported VoiceStudio down still counts, stored the same way.
    Cache::put(AutoPilot::VOICE_DOWN, (string) CarbonImmutable::now()->getTimestamp(), 3600);
    expect(AutoPilot::voiceDown(CarbonImmutable::now()))->toBeTrue()
        ->and(AutoPilot::active(CarbonImmutable::now()))->toBeTrue();
    Cache::forget(AutoPilot::VOICE_DOWN);

    // No request for longer than the online window: gone.
    $this->travel(121)->seconds();
    expect(AutoPilot::active(CarbonImmutable::now()))->toBeTrue();

    // Garbage never counts as seen.
    Cache::put(ClipSpool::FETCHER_SEEN, 'soon', 3600);
    expect(app(ClipSpool::class)->fetcherOnline(CarbonImmutable::now()))->toBeFalse();
});
