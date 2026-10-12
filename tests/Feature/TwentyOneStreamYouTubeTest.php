<?php

use App\Support\TwentyOne\Stream\FfmpegCommands;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// The YouTube push of the stream daemon (YouTubeRelay); helpers in tests/Support/twentyone_stream.php.
pest()->group('nostr');

const TEST_YOUTUBE_KEY = 'abcd-SECRET-1234-wxyz';

beforeEach(function () {
    twentyOneStreamUp($this);
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);
    setChildEnv('FAKE_RELAY_LOG', $this->dir.'/relay.log');
    config(['twentyone.stream.youtube.url' => 'rtmp://ingest.test/live2/']);
});

afterEach(fn () => twentyOneStreamDown($this));

test('without a stream key no relay runs and keyframes stay at one per segment', function () {
    config(['twentyone.stream.youtube.key' => '']);

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 1.5]);

    expect(Artisan::output())->toContain('ffmpeg started mode=loop')->not->toContain('youtube')
        ->and(File::exists($this->dir.'/relay.log'))->toBeFalse()
        ->and(FfmpegCommands::fromConfig()->keyframeSeconds())->toBe(6);
});

test('with a stream key the scene and the prepared loop get a keyframe every 2 s', function () {
    config(['twentyone.stream.youtube.key' => TEST_YOUTUBE_KEY]);
    $ffmpeg = FfmpegCommands::fromConfig();
    $scene = $ffmpeg->scene('/srv/hls/scene', 'run', '/srv/music.ffconcat', 35);
    $prepare = $ffmpeg->prepare('in.mp4', 'out.mp4', 262.1);

    expect($ffmpeg->keyframeSeconds())->toBe(2)
        ->and(array_slice($scene, array_search('-g', $scene), 4))->toBe(['-g', '2', '-keyint_min', '2'])
        ->and(array_slice($prepare, array_search('-g', $prepare), 4))->toBe(['-g', '60', '-keyint_min', '60']);
});

test('the relay reads the run on air, never logs the key, and starts again after a backoff when it exits', function () {
    config(['twentyone.stream.youtube.key' => TEST_YOUTUBE_KEY, 'twentyone.stream.youtube.backoff.initial_seconds' => 1]);
    setChildEnv('FAKE_RELAY_EXIT_AFTER', '0.3');

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 3.5]);
    $output = Artisan::output();
    $reads = file($this->dir.'/relay.log', FILE_IGNORE_NEW_LINES);

    expect($output)->toContain(
        'youtube relay on, to rtmp://ingest.test/live2/***, keyframes every 2 s',
        'to rtmp://ingest.test/live2/***',
        'youtube relay exited code=1',
        // ffmpeg's error names the URL; the key in it is replaced before the line is logged.
        'youtube relay: Error opening output rtmp://ingest.test/live2/***: Connection timed out',
        'restarting the youtube relay in 1 s',
    )
        ->and($output)->not->toContain('SECRET')
        // 1 s, then 2 s between the starts: two, at most three in 3.5 s (without the backoff one every tick).
        ->and(substr_count($output, 'youtube relay started'))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(3)
        ->and($reads)->each->toBe(config('twentyone.stream.hls_dir').'/loop/index.m3u8');
});

test('a relay that keeps running is replaced when another encoder run goes on air', function () {
    config(['twentyone.stream.youtube.key' => TEST_YOUTUBE_KEY, 'twentyone.stream.backoff.initial_seconds' => 1]);
    // The encoder crashes after 1 s and is restarted after 1 s: a new run, a new playlist under the same path.
    setChildEnv('FAKE_ENCODER_LOOP_EXIT_AFTER', '1');

    Artisan::call('twentyone:stream', ['--no-publish' => true, '--stop-after' => 3.5]);
    $output = Artisan::output();
    preg_match_all('/youtube relay started pid=\d+ run=(\w+)/', $output, $started);
    preg_match_all('/ffmpeg started mode=loop pid=\d+ run=(\w+)/', $output, $encoders);

    expect($started[1])->toHaveCount(2)
        // Each relay reads its own run, in the order the runs went on air.
        ->and($started[1])->toBe(array_slice($encoders[1], 0, 2))
        ->and($output)->toContain('youtube relay stopped run='.$started[1][0])
        ->and(strpos($output, 'youtube relay stopped run='.$started[1][0]))->toBeLessThan(strpos($output, 'run='.$started[1][1].' to'))
        ->and($output)->not->toContain('SECRET');
});
