<?php

use App\Support\TwentyOne\Stream\EncoderRun;
use App\Support\TwentyOne\Stream\SegmentTracks;
use Illuminate\Contracts\Process\InvokedProcess;

function mp4Box(string $type, string $payload): string
{
    return pack('N', 8 + strlen($payload)).$type.$payload;
}

/** An init declaring `$tracks` tracks, as ffmpeg's fMP4 muxer writes it: one trex per track in moov/mvex. */
function fakeInit(int $tracks): string
{
    return mp4Box('ftyp', 'iso5').mp4Box('moov', mp4Box('mvhd', str_repeat("\0", 100)).mp4Box('mvex', implode('', array_map(
        fn (int $id): string => mp4Box('trex', pack('N6', 0, $id, 1, 0, 0, 0)), $tracks > 0 ? range(1, $tracks) : [],
    ))));
}

/** A media segment with one traf per entry of `$samples` (the sample count of its trun), then the mdat. */
function fakeSegment(array $samples): string
{
    $trafs = '';

    foreach ($samples as $index => $count) {
        $trafs .= mp4Box('traf', mp4Box('tfhd', pack('N2', 0, $index + 1)).mp4Box('tfdt', pack('N2', 0, 0)).mp4Box('trun', pack('N2', 0, $count)));
    }

    return mp4Box('styp', 'msdh').mp4Box('moof', mp4Box('mfhd', pack('N2', 0, 1)).$trafs).mp4Box('mdat', str_repeat('x', 64));
}

test('an init counts its declared tracks, a segment the tracks it carries samples for, and anything else counts nothing', function () {
    $dir = sys_get_temp_dir().'/segment-tracks-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/init.mp4', fakeInit(2));
    file_put_contents($dir.'/both.m4s', fakeSegment([6, 259]));
    file_put_contents($dir.'/video-only.m4s', fakeSegment([6]));
    file_put_contents($dir.'/empty-audio.m4s', fakeSegment([6, 0]));
    file_put_contents($dir.'/placeholder.m4s', 'segment');

    expect(SegmentTracks::count($dir.'/init.mp4'))->toBe(2)
        ->and(SegmentTracks::count($dir.'/both.m4s'))->toBe(2)
        ->and(SegmentTracks::count($dir.'/video-only.m4s'))->toBe(1)
        ->and(SegmentTracks::count($dir.'/empty-audio.m4s'))->toBe(1)
        ->and(SegmentTracks::count($dir.'/placeholder.m4s'))->toBeNull()
        ->and(SegmentTracks::count($dir.'/missing.m4s'))->toBeNull();

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

test('a run counts the segments in a row that carry fewer tracks than its init declares, each listed segment once', function () {
    $hlsDir = sys_get_temp_dir().'/encoder-run-'.uniqid();
    mkdir($hlsDir.'/scene', 0777, true);
    $run = new EncoderRun('scene', 'abc', Mockery::mock(InvokedProcess::class), 0.0);
    $list = function (array $segments) use ($hlsDir): void {
        file_put_contents($hlsDir.'/scene/index.m3u8', "#EXTM3U\n#EXT-X-MAP:URI=\"abc-init.mp4\"\n".implode('', array_map(fn (string $s): string => "#EXTINF:6.0,\n$s\n", $segments)));
    };
    file_put_contents($hlsDir.'/scene/abc-init.mp4', fakeInit(2));
    file_put_contents($hlsDir.'/scene/abc-seg-000000001.m4s', fakeSegment([6, 258]));
    file_put_contents($hlsDir.'/scene/abc-seg-000000002.m4s', fakeSegment([6]));
    file_put_contents($hlsDir.'/scene/abc-seg-000000003.m4s', fakeSegment([6]));
    file_put_contents($hlsDir.'/scene/abc-seg-000000004.m4s', fakeSegment([6]));
    file_put_contents($hlsDir.'/scene/abc-seg-000000005.m4s', fakeSegment([6, 259]));

    expect($run->segmentsWithoutAudio($hlsDir))->toBe(0, 'no playlist yet');
    $list(['abc-seg-000000001.m4s', 'abc-seg-000000002.m4s']);
    expect($run->segmentsWithoutAudio($hlsDir))->toBe(1)
        ->and($run->segmentsWithoutAudio($hlsDir))->toBe(1, 'the same playlist counts nothing twice');
    $list(['abc-seg-000000002.m4s', 'abc-seg-000000003.m4s', 'abc-seg-000000004.m4s']);
    expect($run->segmentsWithoutAudio($hlsDir))->toBe(3);
    $list(['abc-seg-000000004.m4s', 'abc-seg-000000005.m4s']);
    expect($run->segmentsWithoutAudio($hlsDir))->toBe(0, 'a segment with the audio back ends the row');

    array_map('unlink', glob($hlsDir.'/scene/*'));
    rmdir($hlsDir.'/scene');
    rmdir($hlsDir);
});
