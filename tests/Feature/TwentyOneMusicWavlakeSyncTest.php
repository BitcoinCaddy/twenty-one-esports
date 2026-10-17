<?php

use App\Console\Commands\TwentyOneMusicWavlakeSyncCommand;
use App\Support\TwentyOne\Stream\MusicCatalog;
use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
 * The nightly Wavlake sync (plan "Wavlake-Sync", user 2026-10-17: "Automatisches Abholen von neuen Tracks von Wavlake
 * von unseren Artists ... jede Nacht"): new tracks of the listed artists go into the overlay catalog under storage,
 * normalised and without duplicates; the repo catalog stays as it was. Real ffmpeg and fpcalc on generated audio.
 */

/** Twelve seconds of a two-tone melody; another `$seed` is another song, the same seed the same recording. */
function wavlakeFixture(string $path, int $seed): void
{
    File::ensureDirectoryExists(dirname($path));
    $source = "aevalsrc='0.5*sin(2*PI*(200+97*mod(floor(t*{$seed}),9))*t)*sin(PI*t*{$seed})+0.3*sin(2*PI*(500+61*mod(floor(t*{$seed}*1.7),7))*t)':s=44100:d=12";
    Process::run(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', $source, '-ac', '2', '-c:a', 'libmp3lame', '-b:a', '128k', $path])->throw();
}

beforeEach(function () {
    $this->root = storage_path('framework/testing/wavlake-sync-'.getmypid());
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root.'/music');
    config([
        'twentyone.stream.music.dir' => $this->root.'/music',
        'twentyone.stream.music.catalog' => $this->root.'/data/catalog.json',
        'twentyone.stream.music.overlay' => $this->root.'/sync/catalog.json',
        'twentyone.stream.music.wavlake_sync.artists' => ['test-artist' => 'Test Artist'],
        'twentyone.stream.music.wavlake_sync.state' => $this->root.'/sync/state.json',
        'twentyone.stream.music.wavlake_sync.per_run' => 10,
        // Never a real pkill from a test: the test that checks the restart fakes it and switches it on.
        'twentyone.stream.music.wavlake_sync.restart_stream' => false,
    ]);
    MusicCredits::flush();
});

afterEach(function () {
    File::deleteDirectory($this->root);
    MusicCredits::flush();
});

test('readers see the repo catalog and the overlay, writers keep them apart', function () {
    MusicCatalog::write(['base.m4a' => ['id' => 'aaa', 'artists' => ['test-artist'], 'title' => 'Base', 'credit_source' => 'manual']], ['test-artist' => ['name' => 'Test Artist', 'lud16' => 'theben@getalby.com']], ['aaa' => [1, 2, 3]]);
    MusicCatalog::writeOverlay(['night.m4a' => ['id' => 'bbb', 'artists' => ['test-artist'], 'title' => 'Night', 'credit_source' => 'manual'], 'base.m4a' => ['id' => 'zzz']], ['night-artist' => ['name' => 'Night Artist'], 'test-artist' => ['name' => 'Overridden']], ['bbb' => [4, 5, 6]]);

    expect(array_keys(MusicCatalog::tracks()))->toBe(['base.m4a', 'night.m4a'])
        // The repo catalog wins: the overlay neither renamed the artist nor took over a repo file.
        ->and(MusicCatalog::artists()['test-artist']['name'])->toBe('Test Artist')
        ->and(MusicCatalog::track('base.m4a')['id'])->toBe('aaa')
        ->and(array_keys(MusicCatalog::fingerprints()))->toEqualCanonicalizing(['aaa', 'bbb'])
        ->and(MusicCredits::forFile('night.m4a'))->toMatchArray(['artist' => 'Test Artist', 'kind' => 'lud16']);

    // A repo writer handed everything the readers merged writes back only its own.
    MusicCatalog::write(MusicCatalog::tracks(), MusicCatalog::artists(), MusicCatalog::fingerprints());
    $repo = json_decode((string) file_get_contents($this->root.'/data/catalog.json'), true);

    expect(array_column($repo['tracks'], 'file'))->toBe(['base.m4a'])
        ->and(array_keys($repo['artists']))->toBe(['test-artist'])
        ->and(array_keys(MusicCatalog::tracks()))->toBe(['base.m4a', 'night.m4a']);
});

test('the sync adds new tracks of a listed artist to the overlay, skips known ids, refuses duplicates and foreign hosts, and restarts the stream once', function () {
    $music = $this->root.'/music';
    wavlakeFixture($this->root.'/src/known.mp3', 3);
    wavlakeFixture($this->root.'/src/new.mp3', 5);
    wavlakeFixture($this->root.'/src/older.mp3', 9);

    // The repo catalog already holds seed 3, under another Wavlake id than the one it is listed with now.
    $this->artisan('twentyone:music-add', ['file' => $this->root.'/src/known.mp3', '--artist' => 'Test Artist', '--lud16' => 'theben@getalby.com', '--title' => 'Known', '--dir' => $music])->assertSuccessful();
    $repoBefore = file_get_contents($this->root.'/data/catalog.json');
    $id = fn (string $n): string => sprintf('%08d-0000-4000-8000-000000000000', $n);
    $cdn = fn (string $n): string => 'https://op3.dev/e,pg=x/https://d12wklypp119aj.cloudfront.net/track/'.$id($n).'.mp3';
    $tracks = [
        ['id' => $id('1'), 'title' => 'Brand New', 'mediaUrl' => $cdn('1'), 'releaseDate' => '2026-10-16T00:00:00Z'],
        ['id' => $id('2'), 'title' => 'Same Song Again', 'mediaUrl' => $cdn('2'), 'releaseDate' => '2026-10-15T00:00:00Z'],
        ['id' => $id('3'), 'title' => 'Elsewhere', 'mediaUrl' => 'https://evil.example/track.mp3', 'releaseDate' => '2026-10-14T00:00:00Z'],
        ['id' => $id('4'), 'title' => 'Older/One`', 'mediaUrl' => $cdn('4'), 'releaseDate' => '2025-01-01T00:00:00Z'],
    ];
    Http::fake([
        'catalog.wavlake.com/v1/artists/test-artist/url' => Http::response(['data' => ['id' => $id('9')]]),
        'wavlake.com/api/v1/content/artist/*' => Http::response(['data' => ['albums' => [['id' => $id('8')]]]]),
        'wavlake.com/api/v1/content/album/*' => Http::response(['data' => ['tracks' => $tracks]]),
        'op3.dev/*'.$id('1').'*' => Http::response((string) file_get_contents($this->root.'/src/new.mp3')),
        'op3.dev/*'.$id('2').'*' => Http::response((string) file_get_contents($this->root.'/src/known.mp3')),
        'op3.dev/*'.$id('4').'*' => Http::response((string) file_get_contents($this->root.'/src/older.mp3')),
        '*' => Http::response('nope', 404),
    ]);
    Process::fake(['*pkill*' => Process::result()]);
    config(['twentyone.stream.music.wavlake_sync.restart_stream' => true]);

    // Newest first, one download a night: only "Brand New" tonight.
    expect(Artisan::call('twentyone:music-wavlake-sync', ['--limit' => 1]))->toBe(0)->and(Artisan::output())->toContain('added 1, 0 duplicates, 0 failed, 3 waiting');
    expect(Artisan::call('twentyone:music-wavlake-sync'))->toBe(0)->and(Artisan::output())->toContain('added 1, 1 duplicates, 1 failed, 0 waiting');

    MusicCredits::flush();
    $overlay = MusicCatalog::overlay()['tracks'];

    expect(array_keys($overlay))->toEqualCanonicalizing(['Brand New - Test Artist__v1.m4a', 'OlderOne - Test Artist__v1.m4a'])
        ->and($overlay['Brand New - Test Artist__v1.m4a'])->toMatchArray(['artists' => ['test-artist'], 'credit_source' => 'manual', 'title' => 'Brand New', 'wavlake_track_id' => $id('1')])
        ->and($overlay['Brand New - Test Artist__v1.m4a']['lufs'])->toEqualWithDelta(-15.0, 1.0)
        ->and(is_file($music.'/Brand New - Test Artist__v1.m4a'))->toBeTrue()
        ->and(MusicCredits::forFile('Brand New - Test Artist__v1.m4a'))->toMatchArray(['artist' => 'Test Artist', 'kind' => 'lud16'])
        ->and(MusicPlaylist::artist($music.'/Brand New - Test Artist__v1.m4a'))->toBe('Test Artist')
        // The repo catalog is untouched; the duplicate and the foreign host left no file.
        ->and(file_get_contents($this->root.'/data/catalog.json'))->toBe($repoBefore)
        ->and(collect(File::files($music))->map->getFilename()->sort()->values()->all())->toBe(['Brand New - Test Artist__v1.m4a', 'OlderOne - Test Artist__v1.m4a', 'known__v1.m4a'])
        ->and(collect(File::files($this->root.'/sync/tmp'))->all())->toBe([]);

    $state = json_decode((string) file_get_contents($this->root.'/sync/state.json'), true);
    expect($state['tracks'][$id('2')]['status'])->toBe('duplicate')
        ->and($state['tracks'][$id('3')])->toMatchArray(['status' => 'failed', 'tries' => 1])
        ->and($state['last_run']['added'])->toBe(['Test Artist: Older/One`']);
    Process::assertRanTimes(fn ($process): bool => $process->command === ['pkill', '-TERM', '-f', 'artisan twentyone:stream$'], 2);

    // A night without anything new downloads nothing and restarts nothing; the foreign host is tried until max_tries.
    Http::assertSentCount(9);
    expect(Artisan::call('twentyone:music-wavlake-sync'))->toBe(0)->and(Artisan::output())->toContain('added 0, 0 duplicates, 1 failed');
    expect(Artisan::call('twentyone:music-wavlake-sync'))->toBe(0)->and(Artisan::output())->toContain('added 0, 0 duplicates, 1 failed');
    expect(Artisan::call('twentyone:music-wavlake-sync'))->toBe(0)->and(Artisan::output())->toContain('added 0, 0 duplicates, 0 failed, 0 waiting');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.example'));
    Process::assertRanTimes(fn ($process): bool => $process->command === ['pkill', '-TERM', '-f', 'artisan twentyone:stream$'], 2);
});

test('a download over max_bytes is refused and leaves nothing behind', function () {
    config(['twentyone.stream.music.wavlake_sync.max_bytes' => 1000]);
    $id = '00000001-0000-4000-8000-000000000000';
    Http::fake([
        'catalog.wavlake.com/*' => Http::response(['data' => ['id' => $id]]),
        'wavlake.com/api/v1/content/artist/*' => Http::response(['data' => ['albums' => [['id' => $id]]]]),
        'wavlake.com/api/v1/content/album/*' => Http::response(['data' => ['tracks' => [['id' => $id, 'title' => 'Huge', 'mediaUrl' => 'https://d12wklypp119aj.cloudfront.net/track/x.mp3', 'releaseDate' => '2026-10-16']]]]),
        'd12wklypp119aj.cloudfront.net/*' => Http::response(str_repeat('x', 5000)),
    ]);

    expect(Artisan::call('twentyone:music-wavlake-sync'))->toBe(0)->and(Artisan::output())->toContain('added 0, 0 duplicates, 1 failed');

    expect(json_decode((string) file_get_contents($this->root.'/sync/state.json'), true)['tracks'][$id]['message'])->toContain('larger than 1000 bytes')
        ->and(File::files($this->root.'/music'))->toBe([])
        ->and(File::files($this->root.'/sync/tmp'))->toBe([]);
});

test('file names keep only what the stream can play safely', function () {
    expect(TwentyOneMusicWavlakeSyncCommand::fileName("Older/One` \"x\"\n", 'Test Artist'))->toBe('OlderOne x - Test Artist')
        ->and(TwentyOneMusicWavlakeSyncCommand::fileName('Ends with dots...', 'sabu'))->toBe('Ends with dots - sabu');
});
