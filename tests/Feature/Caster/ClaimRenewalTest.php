<?php

use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ScenePhase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: a claim the workstation is still voicing never lapses
|--------------------------------------------------------------------------
|
| Prod 2026-10-13: at about real time a block of a bulletin and its news talk
| takes longer to voice than `slot_claim_seconds` (120), so the claim lapsed
| mid-voicing and the upload was refused (409 not_claimed); fetcher runs of
| four to twenty minutes ended "released" and nothing said why. The
| workstation now renews what it holds about once a minute, and says every
| upload outcome in the journal; a dead fetcher's claim still lapses.
|
*/

const CLAIM_RENEWAL_TOKEN = 'claim-renewal-token-0123456789abcdef0123456789';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.fetch.token' => CLAIM_RENEWAL_TOKEN,
        'tv.commentary.fetch.claim_seconds' => 600,
        'tv.commentary.fetch.slot_claim_seconds' => 120,
        'tv.commentary.fetch.renew_seconds' => 60,
        'tv.commentary.programme.bulletin.ready_seconds' => 60,
        'tv.commentary.podcast_replay.enabled' => false,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 13:40', 'Europe/Berlin'));
});

/** @param  array<string, string>  $headers */
function claimRenewalPost(string $uri, array $headers = [], string $content = ''): TestResponse
{
    $server = collect(['Authorization' => 'Bearer '.CLAIM_RENEWAL_TOKEN, AutoPilot::HEADER => 'up', ...$headers])
        ->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all();

    return test()->call('POST', $uri, [], [], [], $server + ['CONTENT_TYPE' => 'audio/ogg'], $content);
}

/** The 14:00 bulletin and its news talk, queued at 13:40. */
function claimRenewalNewsBlock(): array
{
    $slot = CarbonImmutable::parse('2026-10-14 14:00', 'Europe/Berlin');
    $make = fn (string $kind, string $hash, ?string $topic, int $priority, CarbonImmutable $airsAt) => CasterScript::query()->create([
        'kind' => $kind, 'phase' => ScenePhase::BREAK, 'priority' => $priority, 'topic' => $topic, 'text' => 'Die Nachrichten.', 'facts_hash' => $hash,
        'status' => CasterScript::QUEUED, 'source' => CasterScript::SOURCE_AI, 'estimated_seconds' => 120, 'airs_at' => $airsAt, 'expires_at' => $slot->addMinutes(20),
    ]);

    return [$make(CasterScript::BULLETIN, 'bulletin:'.$slot->getTimestamp(), 'news', 75, $slot),
        $make(CasterScript::RUBRIC, 'news_talk:'.$slot->getTimestamp(), 'nachrichten_talk', 70, $slot->addSeconds(130))];
}

/** Uploads the two as one block of 300 s. */
function claimRenewalUpload(array $ids): TestResponse
{
    return claimRenewalPost('/api/caster/blocks', ['X-Caster-Scripts' => implode(',', $ids), 'X-Caster-Duration' => '300.0',
        'X-Caster-Sentence-Ends' => '150.0,300.0', 'X-Caster-Boundaries' => '150.0', 'X-Caster-Protected' => '0'], casterOpus());
}

it('accepts a block voiced for longer than slot_claim_seconds while the workstation renews its claims', function () {
    $scripts = claimRenewalNewsBlock();
    $ids = [claimRenewalPost('/api/caster/claim')->json('script.id'), claimRenewalPost('/api/caster/claim')->json('script.id')];
    expect($ids)->toBe([$scripts[0]->id, $scripts[1]->id]);

    // Five minutes of voicing, a renewal every minute, an urgent poll in between (which frees lapsed claims).
    for ($minute = 1; $minute <= 5; $minute++) {
        $this->travel(60)->seconds();
        claimRenewalPost('/api/caster/claims/renew', ['X-Caster-Scripts' => implode(',', $ids)])->assertOk()->assertJson(['renewed' => 2]);
        claimRenewalPost('/api/caster/claim?only=urgent')->assertOk();
    }

    claimRenewalUpload($ids)->assertStatus(201);
    expect($scripts[0]->fresh())->status->toBe(CasterScript::VOICED)
        // The claim's moment stays: VoiceBudget measures the voicing from it.
        ->claimed_at->getTimestamp()->toBe(CarbonImmutable::parse('2026-10-14 13:40', 'Europe/Berlin')->getTimestamp());
});

it('still frees the claims of a dead fetcher after slot_claim_seconds', function () {
    $scripts = claimRenewalNewsBlock();
    $ids = [claimRenewalPost('/api/caster/claim')->json('script.id'), claimRenewalPost('/api/caster/claim')->json('script.id')];

    // One renewal, then the fetcher dies.
    $this->travel(60)->seconds();
    claimRenewalPost('/api/caster/claims/renew', ['X-Caster-Scripts' => implode(',', $ids)])->assertOk();
    $this->travel(119)->seconds();
    claimRenewalPost('/api/caster/claim?only=urgent');
    expect($scripts[0]->fresh()->status)->toBe(CasterScript::CLAIMED);

    $this->travel(2)->seconds();
    expect(claimRenewalPost('/api/caster/claim?only=urgent')->json('script.id'))->toBe($scripts[0]->id);
    claimRenewalUpload($ids)->assertStatus(409);
});

it('renews only claims, and only with the token', function () {
    [$bulletin, $talk] = claimRenewalNewsBlock();
    claimRenewalPost('/api/caster/claim');

    claimRenewalPost('/api/caster/claims/renew', ['X-Caster-Scripts' => $bulletin->id.','.$talk->id])->assertJson(['renewed' => 1]);
    expect($talk->fresh()->renewed_at)->toBeNull();

    $this->call('POST', '/api/caster/claims/renew', server: ['HTTP_X_CASTER_SCRIPTS' => (string) $bulletin->id])->assertStatus(401);
});

it('renews what the fetcher holds at most once a minute, and stops once it is uploaded', function () {
    Http::fake([
        'https://tv.test/api/caster/claim' => Http::response(['script' => ['id' => 41, 'kind' => 'scene', 'sentences' => ['Eins.']]]),
        'https://tv.test/api/caster/claims/renew' => Http::response(['renewed' => 1]),
    ]);
    $fetcher = new CasterFetcher(fn (float $seconds) => null);
    $fetcher->claim();

    // Right after the claim nothing is due; a minute later one renewal, however often it is asked.
    $fetcher->keepClaims();
    $this->travel(61)->seconds();
    $fetcher->keepClaims();
    $fetcher->keepClaims();
    $fetcher->letGo([41]);
    $this->travel(61)->seconds();
    $fetcher->keepClaims();

    $renewals = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/claims/renew'))->values();
    expect($renewals)->toHaveCount(1)
        ->and($renewals[0][0]->header('X-Caster-Scripts'))->toBe(['41']);
});

it('says every upload outcome in the journal, with the server\'s reason for a refusal', function () {
    Http::fake([
        'https://tv.test/ok' => Http::response(['clip' => 1], 201),
        'https://tv.test/refused' => Http::response(['error' => 'not_claimed', 'script' => 7], 409),
        'https://tv.test/api/caster/claim' => Http::response(['error' => 'unauthorized'], 401),
    ]);
    $lines = [];
    $fetcher = new CasterFetcher(fn (float $seconds) => null);
    $fetcher->log = function (string $line) use (&$lines): void {
        $lines[] = $line;
    };

    expect(CasterFetcher::uploadLine('block 7,8', Http::post('https://tv.test/ok'), 241.26))->toBe('block 7,8: 241.3 s uploaded, 201')
        ->and(CasterFetcher::uploadLine('block 7,8', Http::post('https://tv.test/refused'), 241.26))->toBe('block 7,8: 241.3 s uploaded, 409 not_claimed (script 7)')
        ->and($fetcher->claim())->toBeNull()
        ->and($lines)->toBe(['claim refused: 401']);
});
