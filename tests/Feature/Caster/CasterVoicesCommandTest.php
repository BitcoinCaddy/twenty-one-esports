<?php

use App\Support\Caster\Pcm;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: `caster:voices` on the workstation (user 2026-10-12)
|--------------------------------------------------------------------------
|
| The VoiceStudio profiles with the speaker that uses each, and a probe
| that voices the comparison text with one profile in a speaker's place
| through the fetcher's own path, into storage/app/voice-probes (the newest
| six kept). VoiceStudio faked; the storage path a temporary directory.
|
*/

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/voice-probes-test-'.getmypid();
    File::deleteDirectory($this->storage);
    $this->app->useStoragePath($this->storage);
    config([
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.voicestudio.profile_id' => '9cc1a18c',
        'tv.commentary.voicestudio.profiles' => ['markus' => '9cc1a18c', 'sirius' => '446d98e1'],
        'tv.commentary.voicestudio.speed' => 1.05,
        'tv.commentary.voicestudio.speeds' => ['sirius' => 0.92],
    ]);

    $tone = '';
    for ($i = 0; $i < 12000; $i++) {
        $tone .= pack('v', ((int) (8000 * sin($i / 8))) & 0xFFFF);
    }
    Http::fake([
        'http://voicestudio.test/profiles' => Http::response([
            ['id' => '9cc1a18c', 'name' => 'Markus Turm (alt)', 'created_at' => 1791797007.06],
            ['id' => '446d98e1', 'name' => 'Sirius', 'created_at' => 1791789720.5],
            ['id' => 'abcd1234', 'name' => 'Sirius neu', 'created_at' => 1791800000.0],
        ]),
        'http://voicestudio.test/generate' => Http::response(Pcm::toWav($tone, 24000), 200, ['Content-Type' => 'audio/wav', 'x-audio-id' => 'gen00001']),
        'http://voicestudio.test/history/*' => Http::response(['deleted' => true]),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->storage);
});

it('lists the VoiceStudio profiles and which one each speaker uses at which speed', function () {
    $this->artisan('caster:voices')
        ->expectsTable(['id', 'name', 'created', 'used by'], [
            ['9cc1a18c', 'Markus Turm (alt)', '2026-10-12 09:23', 'markus (speed 1.05)'],
            ['446d98e1', 'Sirius', '2026-10-12 07:22', 'sirius (speed 0.92)'],
            ['abcd1234', 'Sirius neu', '2026-10-12 10:13', ''],
        ])
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/generate'));
});

it('voices the comparison text with a probe profile in Sirius place at his speed, into voice-probes', function () {
    $this->artisan('caster:voices', ['--probe' => 'abcd1234', '--speaker' => 'sirius'])->assertSuccessful();

    $path = storage_path('app/voice-probes/sirius-abcd1234.wav');
    expect(File::exists($path))->toBeTrue()
        ->and(Pcm::fromWav(File::get($path))['rate'])->toBe(24000);

    $calls = [];
    Http::assertSent(function (Request $request) use (&$calls): bool {
        if (str_ends_with($request->url(), '/generate')) {
            $fields = collect($request->data())->pluck('contents', 'name');
            $calls[] = $fields['profile_id'].' '.$fields['speed'];
        }

        return true;
    });

    // The fetcher's chunks (at most 25 words): every call with the probe's profile and Sirius' tempo, each history entry deleted.
    expect($calls)->not->toBeEmpty()
        ->and(array_unique($calls))->toBe(['abcd1234 0.92']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/history/gen00001'));
});

it('keeps only the newest six probes', function () {
    $directory = storage_path('app/voice-probes');
    File::ensureDirectoryExists($directory);
    foreach (range(1, 7) as $i) {
        File::put("{$directory}/markus-old{$i}.wav", 'x');
        touch("{$directory}/markus-old{$i}.wav", time() - 100 + $i);
    }

    $this->artisan('caster:voices', ['--probe' => '9cc1a18c', '--speaker' => 'markus'])->assertSuccessful();

    expect(collect(File::files($directory))->map->getFilename()->sort()->values()->all())
        ->toBe(['markus-9cc1a18c.wav', 'markus-old3.wav', 'markus-old4.wav', 'markus-old5.wav', 'markus-old6.wav', 'markus-old7.wav']);
});

it('refuses an unknown profile or speaker before any generation', function (array $options) {
    $this->artisan('caster:voices', $options)->assertFailed();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/generate'));
    expect(File::exists(storage_path('app/voice-probes')))->toBeFalse();
})->with([
    'unknown id' => [['--probe' => 'ffff0000', '--speaker' => 'sirius']],
    'a path as id' => [['--probe' => '../../etc', '--speaker' => 'sirius']],
    'unknown speaker' => [['--probe' => 'abcd1234', '--speaker' => 'blockli']],
]);

it('says so when VoiceStudio is not reachable', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('down')]);

    $this->artisan('caster:voices')->expectsOutputToContain('not reachable')->assertFailed();
});
