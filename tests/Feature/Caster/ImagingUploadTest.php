<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio, P11: the imaging library goes to the server
|--------------------------------------------------------------------------
|
| The workstation uploads the Anchor's rendered jingles once (caster:imaging-upload):
| content-addressed files under their sha1, then a manifest that names them.
| Same bearer token as the fetcher, fail closed, bounded.
|
*/

const IMAGING_TOKEN = 'test-token-0123456789abcdef0123456789abcdef';

beforeEach(function () {
    $this->imagingDir = storage_path('framework/testing/imaging-up-'.uniqid());
    config(['tv.commentary.fetch.token' => IMAGING_TOKEN, 'tv.commentary.imaging.dir' => $this->imagingDir,
        'tv.commentary.imaging.max_items' => 5, 'tv.commentary.imaging.max_file_bytes' => 10_000, 'tv.commentary.imaging.max_bytes' => 25_000, 'tv.commentary.imaging.max_seconds' => 40]);
});

afterEach(function () {
    File::deleteDirectory($this->imagingDir);
});

function imagingPostFile(string $body, ?string $hash = null, string $token = IMAGING_TOKEN): TestResponse
{
    return test()->call('POST', '/api/caster/imaging/files/'.($hash ?? sha1($body)), [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'audio/ogg'], $body);
}

/** @param  list<array<string, mixed>>  $items */
function imagingPostManifest(array $items, string $token = IMAGING_TOKEN): TestResponse
{
    return test()->withToken($token)->postJson('/api/caster/imaging', ['items' => $items]);
}

function imagingItem(string $id, string $opus, string $category = 'station', float $seconds = 5.0): array
{
    return ['id' => $id, 'line' => preg_replace('/-[ab]$/', '', $id), 'category' => $category, 'seconds' => $seconds, 'hash' => sha1($opus), 'text' => 'TWENTY ONE ESPORTS RADIO.'];
}

it('refuses both endpoints without the token or with a wrong one, and with no token configured at all', function () {
    $opus = casterOpus(2000);

    $this->postJson('/api/caster/imaging', ['items' => [imagingItem('a', $opus)]])->assertStatus(401);
    imagingPostFile($opus, token: str_repeat('x', 40))->assertStatus(401);
    config(['tv.commentary.fetch.token' => null]);
    imagingPostFile($opus, token: '')->assertStatus(401);

    expect(is_dir($this->imagingDir))->toBeFalse();
});

it('stores a file only when it is Ogg Opus, has the hash it claims and stays within the bounds', function () {
    imagingPostFile('not an opus file at all')->assertStatus(422)->assertJson(['reason' => 'format']);
    imagingPostFile(casterOpus(2000), sha1('something else'))->assertStatus(422)->assertJson(['reason' => 'hash']);
    imagingPostFile(casterOpus(10_001))->assertStatus(422)->assertJson(['reason' => 'size']);

    imagingPostFile(casterOpus(9_000))->assertCreated();
    imagingPostFile(casterOpus(9_001))->assertCreated();
    // The library is at 18 000 of 25 000 bytes: another 9 000 do not fit.
    imagingPostFile(casterOpus(9_002))->assertStatus(507);
    // The same file again is no new byte.
    imagingPostFile(casterOpus(9_000))->assertCreated();

    expect(glob($this->imagingDir.'/files/*.opus'))->toHaveCount(2);
});

it('answers a manifest with the missing hashes, takes it once every file is there and drops the files it no longer names', function () {
    [$one, $two, $old] = [casterOpus(2000), casterOpus(2001), casterOpus(2002)];
    imagingPostFile($old)->assertCreated();

    imagingPostManifest([imagingItem('station-01-a', $one), imagingItem('sweeper-01-a', $two, 'sweeper')])
        ->assertStatus(409)->assertJson(['missing' => [sha1($one), sha1($two)]]);
    expect(is_file($this->imagingDir.'/manifest.json'))->toBeFalse();

    imagingPostFile($one)->assertCreated();
    imagingPostFile($two)->assertCreated();
    imagingPostManifest([imagingItem('station-01-a', $one), imagingItem('sweeper-01-a', $two, 'sweeper')])->assertOk()->assertJson(['items' => 2]);

    $manifest = json_decode((string) file_get_contents($this->imagingDir.'/manifest.json'), true);

    expect(array_column($manifest['items'], 'category'))->toBe(['station', 'sweeper'])
        ->and($manifest['items'][0])->toMatchArray(['line' => 'station-01', 'file' => 'files/'.sha1($one).'.opus', 'seconds' => 5.0])
        ->and(is_file($this->imagingDir.'/files/'.sha1($old).'.opus'))->toBeFalse()
        ->and(glob($this->imagingDir.'/files/*.opus'))->toHaveCount(2);
});

it('refuses a malformed manifest: too many items, a repeated id, a bad category, a length out of bounds, a path in the id', function () {
    $opus = casterOpus(2000);
    imagingPostFile($opus)->assertCreated();

    imagingPostManifest([])->assertStatus(422);
    imagingPostManifest(array_map(fn (int $n): array => imagingItem('station-0'.$n, $opus), range(1, 6)))->assertStatus(422);
    imagingPostManifest([imagingItem('station-01', $opus), imagingItem('station-01', $opus)])->assertStatus(422);
    imagingPostManifest([imagingItem('station-01', $opus, 'Station ID!')])->assertStatus(422);
    imagingPostManifest([imagingItem('station-01', $opus, 'station', 0.0)])->assertStatus(422);
    imagingPostManifest([imagingItem('station-01', $opus, 'station', 41.0)])->assertStatus(422);
    imagingPostManifest([imagingItem('../../etc', $opus)])->assertStatus(422);

    expect(is_file($this->imagingDir.'/manifest.json'))->toBeFalse();
});

it('uploads the rendered library from the workstation: manifest, exactly the missing files, manifest again', function () {
    $local = storage_path('framework/testing/jingles-'.uniqid());
    File::ensureDirectoryExists($local.'/anchor');
    file_put_contents($local.'/anchor/station-01-a.opus', casterOpus(2000));
    file_put_contents($local.'/anchor/sweeper-01-a.opus', casterOpus(2001));
    file_put_contents($local.'/manifest.json', json_encode(['items' => [
        ['id' => 'station-01-a', 'line' => 'station-01', 'category' => 'station', 'file' => 'anchor/station-01-a.opus', 'seconds' => 4.4, 'text' => 'TWENTY ONE ESPORTS RADIO.'],
        ['id' => 'sweeper-01-a', 'line' => 'sweeper-01', 'category' => 'sweeper', 'file' => 'anchor/sweeper-01-a.opus', 'seconds' => 6.1],
    ]]));
    config(['tv.commentary.voicestudio.server' => 'https://tv.test']);
    $manifests = 0;
    Http::fake(function (Request $request) use (&$manifests) {
        if (str_ends_with($request->url(), '/api/caster/imaging')) {
            return ++$manifests === 1 ? Http::response(['missing' => [sha1(casterOpus(2001))]], 409) : Http::response(['items' => 2]);
        }

        return Http::response(['stored' => true], 201);
    });

    $this->artisan('caster:imaging-upload', ['--dir' => $local])->assertSuccessful();
    File::deleteDirectory($local);

    $sent = collect(Http::recorded())->map(fn (array $pair): string => $pair[0]->url());

    expect($sent->all())->toBe(['https://tv.test/api/caster/imaging', 'https://tv.test/api/caster/imaging/files/'.sha1(casterOpus(2001)), 'https://tv.test/api/caster/imaging'])
        ->and(Http::recorded()[0][0]->data()['items'][0])->toMatchArray(['id' => 'station-01-a', 'category' => 'station', 'hash' => sha1(casterOpus(2000)), 'text' => 'TWENTY ONE ESPORTS RADIO.'])
        ->and(Http::recorded()[0][0]->header('Authorization')[0])->toBe('Bearer '.IMAGING_TOKEN);
});
