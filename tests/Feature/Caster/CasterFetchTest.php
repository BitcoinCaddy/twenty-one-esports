<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Pcm;
use App\Support\Caster\Pronunciation;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the fetch API and the workstation fetcher (P6)
|--------------------------------------------------------------------------
|
| The server hands out scripts to a bearer token only and takes their clips
| into the spool (507 at a cap). The fetcher, with VoiceStudio and the
| server faked: one VoiceStudio call per sentence with the pronunciation
| applied, each generation's history entry deleted by its own id, real
| pauses between the sentences, the clip uploaded with its duration and
| sentence ends, retries on hiccups, the script released when VoiceStudio
| stays down, and no file left behind.
|
*/

const CASTER_TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.fetch.token' => CASTER_TOKEN,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.voicestudio.pause.jitter' => 0.0,
    ]);
});

/** A VoiceStudio answer: 24 kHz mono WAV, `$loud` seconds of tone, then `$quiet` seconds of near silence. */
function casterWav(float $loud = 0.5, float $quiet = 0.4): string
{
    $pcm = '';
    for ($i = 0; $i < (int) (24000 * $loud); $i++) {
        $pcm .= pack('v', ((int) (8000 * sin($i / 8))) & 0xFFFF);
    }
    $pcm .= str_repeat(pack('v', 30), (int) (24000 * $quiet));

    return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
}

function casterQueuedScript(string $text = "Hey!\nHier ist Blockli.\nLäuft."): CasterScript
{
    return app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, $text, 'h', 0, CarbonImmutable::now());
}

it('refuses every request without the right token, and any token while none is set', function () {
    casterQueuedScript();

    $this->postJson('/api/caster/claim')->assertStatus(401);
    $this->postJson('/api/caster/claim', [], ['Authorization' => 'Bearer wrong-token-wrong-token-wrong-token'])->assertStatus(401);

    config(['tv.commentary.fetch.token' => null]);
    $this->postJson('/api/caster/claim', [], ['Authorization' => 'Bearer '.CASTER_TOKEN])->assertStatus(401);

    expect(CasterScript::query()->value('status'))->toBe(CasterScript::QUEUED);
});

it('hands out the next script with the pronunciation and marks the fetcher online', function () {
    $script = casterQueuedScript();

    $this->postJson('/api/caster/claim', [], ['Authorization' => 'Bearer '.CASTER_TOKEN])
        ->assertOk()
        ->assertJsonPath('script.id', $script->id)
        ->assertJsonPath('script.sentences', ['Hey!', 'Hier ist Blockli.', 'Läuft.'])
        ->assertJsonPath('full', false)
        ->assertJsonPath('pronunciation.TMNF', Pronunciation::terms()['TMNF']);

    expect($script->fresh()->status)->toBe(CasterScript::CLAIMED)
        ->and(app(ClipSpool::class)->fetcherOnline(CarbonImmutable::now()))->toBeTrue();
});

it('hands out nothing while the spool holds its target', function () {
    config(['tv.commentary.spool.target_seconds' => 10]);
    $voiced = casterQueuedScript();
    $voiced->update(['status' => CasterScript::CLAIMED]);
    app(ClipSpool::class)->store($voiced, casterOpus(), 12, [], CarbonImmutable::now());
    casterQueuedScript("Eins.\nZwei.\nDrei.");

    $this->postJson('/api/caster/claim', [], ['Authorization' => 'Bearer '.CASTER_TOKEN])
        ->assertOk()->assertJsonPath('script', null)->assertJsonPath('full', true);
});

it('takes a clip into the spool, and answers 507 at a cap and 422 for a non-clip', function () {
    $script = casterQueuedScript();
    $script->update(['status' => CasterScript::CLAIMED]);
    $headers = ['Authorization' => 'Bearer '.CASTER_TOKEN, 'X-Caster-Duration' => '4.2', 'X-Caster-Sentence-Ends' => '1.1,2.6,4.2', 'Content-Type' => 'audio/ogg'];

    $this->call('POST', "/api/caster/scripts/{$script->id}/clip", [], [], [], $this->transformHeadersToServerVars($headers), 'RIFFnotopus')->assertStatus(422);
    $this->call('POST', "/api/caster/scripts/{$script->id}/clip", [], [], [], $this->transformHeadersToServerVars($headers), casterOpus())->assertStatus(201);

    expect(CasterClip::query()->value('sentence_ends'))->toBe([1.1, 2.6, 4.2]);

    config(['tv.commentary.spool.max_bytes' => 5000]);
    $next = casterQueuedScript("Eins.\nZwei.\nDrei.");
    $next->update(['status' => CasterScript::CLAIMED]);
    $this->call('POST', "/api/caster/scripts/{$next->id}/clip", [], [], [], $this->transformHeadersToServerVars($headers), casterOpus())->assertStatus(507);
    $this->postJson("/api/caster/scripts/{$next->id}/release", [], ['Authorization' => 'Bearer '.CASTER_TOKEN])->assertOk();

    expect($next->fresh()->status)->toBe(CasterScript::QUEUED);
});

/**
 * Fakes the server and VoiceStudio for the fetcher; returns the requests it saw.
 *
 * @param  list<mixed>  $voiceStudio  responses of POST /generate in order
 */
function casterFakeWorld(array $voiceStudio, int $uploadStatus = 201): void
{
    $generate = Http::sequence();
    foreach ($voiceStudio as $response) {
        $generate->pushResponse($response);
    }

    Http::fake([
        'https://tv.test/api/caster/claim' => Http::response(['script' => ['id' => 7, 'kind' => 'scene', 'sentences' => ['Hey!', 'Hier ist Blockli.', 'Läuft.']], 'full' => false, 'pronunciation' => ['Blockli' => 'Block-li']]),
        'https://tv.test/api/caster/scripts/7/clip' => Http::response(['clip' => 1], $uploadStatus),
        'https://tv.test/api/caster/scripts/7/release' => Http::response(['released' => true]),
        'http://voicestudio.test/generate' => $generate,
        'http://voicestudio.test/history/*' => Http::response(['deleted' => true]),
    ]);
}

function casterWavResponse(string $id): mixed
{
    return Http::response(casterWav(), 200, ['Content-Type' => 'audio/wav', 'x-audio-id' => $id]);
}

it('voices every sentence, deletes each history entry, and uploads one clip with its sentence ends', function () {
    casterFakeWorld([casterWavResponse('aaaa1111'), casterWavResponse('bbbb2222'), casterWavResponse('cccc3333')]);
    Process::fake(['*' => Process::result(output: casterOpus())]);

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED);

    $texts = [];
    Http::assertSent(function (Request $request) use (&$texts): bool {
        if ($request->url() === 'http://voicestudio.test/generate') {
            $fields = collect($request->data())->pluck('contents', 'name');
            $texts[] = $fields['text'];

            return $fields['profile_id'] === 'ee7a2a18' && $fields['speed'] === '1';
        }

        return true;
    });
    expect($texts)->toBe(['Hey!', 'Hier ist Block-li.', 'Läuft.']);

    foreach (['aaaa1111', 'bbbb2222', 'cccc3333'] as $id) {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === "http://voicestudio.test/history/{$id}");
    }
    Http::assertSentCount(3 + 3 + 2);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://tv.test/api/caster/scripts/7/clip') {
            return false;
        }

        $ends = array_map('floatval', explode(',', $request->header('X-Caster-Sentence-Ends')[0]));
        $duration = (float) $request->header('X-Caster-Duration')[0];

        // Tails trimmed to the tone (0.5 s) + 70 ms fade; 0.5 s after "Hey!" (an accent), 0.3 s after a plain sentence.
        // Process::fake() ends its output with a newline.
        return rtrim($request->body(), "\n") === casterOpus() && count($ends) === 3
            && abs($ends[0] - 0.57) < 0.02 && abs($ends[1] - (0.57 + 0.5 + 0.57)) < 0.03
            && abs($duration - $ends[2]) < 0.001 && abs($ends[2] - (0.57 * 3 + 0.5 + 0.3)) < 0.04;
    });

    Process::assertRan(fn ($process): bool => in_array('loudnorm=I=-16:TP=-1.5:LRA=11', $process->command, true) && in_array('libopus', $process->command, true) && in_array('pipe:1', $process->command, true));
});

it('retries a VoiceStudio hiccup and goes on', function () {
    casterFakeWorld([Http::response('crashed', 500), casterWavResponse('aaaa1111'), casterWavResponse('bbbb2222'), casterWavResponse('cccc3333')]);
    Process::fake(['*' => Process::result(output: casterOpus())]);
    $waits = [];

    expect((new CasterFetcher(function (float $seconds) use (&$waits): void {
        $waits[] = $seconds;
    }))->runOnce())->toBe(CasterFetcher::VOICED)
        ->and($waits)->toHaveCount(1);
});

it('releases the script when VoiceStudio stays down', function () {
    casterFakeWorld(array_fill(0, 4, Http::response('down', 503)));
    Process::fake();

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::RELEASED);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/scripts/7/release');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/scripts/7/clip');
    Process::assertNothingRan();
});

it('releases the script and backs off when the spool is full', function () {
    casterFakeWorld([casterWavResponse('aaaa1111'), casterWavResponse('bbbb2222'), casterWavResponse('cccc3333')], 507);
    Process::fake(['*' => Process::result(output: casterOpus())]);

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::FULL);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/scripts/7/release');
});

it('leaves no file behind on the workstation', function () {
    casterFakeWorld([casterWavResponse('aaaa1111'), casterWavResponse('bbbb2222'), casterWavResponse('cccc3333')]);
    Process::fake(['*' => Process::result(output: casterOpus())]);
    $listing = fn (): array => iterator_to_array(Finder::create()->files()->in(sys_get_temp_dir())->depth('< 2')->ignoreUnreadableDirs()->sortByName()->getIterator(), false);
    $before = array_map(fn ($file): string => $file->getPathname(), $listing());

    (new CasterFetcher(fn () => null))->runOnce();

    expect(array_values(array_diff(array_map(fn ($file): string => $file->getPathname(), $listing()), $before)))->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('says the server is unreachable without touching VoiceStudio', function () {
    Http::fake(['https://tv.test/*' => Http::response('', 502), '*' => Http::response('', 500)]);

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::OFFLINE);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://voicestudio.test'));
});

it('trims the quiet tail after the last word with a short fade', function () {
    ['pcm' => $pcm, 'rate' => $rate] = Pcm::fromWav(casterWav(0.5, 0.4));

    $trimmed = Pcm::trimTail($pcm, $rate, 400, 70);
    $last = unpack('s', substr($trimmed, -2))[1];

    expect(Pcm::seconds($pcm, $rate))->toBe(0.9)
        ->and(abs(Pcm::seconds($trimmed, $rate) - 0.57))->toBeLessThan(0.011)
        ->and(abs($last))->toBeLessThan(50);
});

it('respells league terms as whole words, longest first', function () {
    expect(Pronunciation::spoken('Zaps für Blockli und TMNF, nicht Blocklis', ['Zap' => 'Zäpp', 'Zaps' => 'Zäpps', 'Blockli' => 'Block-li', 'TMNF' => 'Trackmania']))
        ->toBe('Zäpps für Block-li und Trackmania, nicht Blocklis');
});

it('keeps the fetcher online only while it asked recently', function () {
    $now = CarbonImmutable::now();
    Cache::put(ClipSpool::FETCHER_SEEN, $now->subSeconds(121)->getTimestamp(), 600);

    expect(app(ClipSpool::class)->fetcherOnline($now))->toBeFalse();
});
