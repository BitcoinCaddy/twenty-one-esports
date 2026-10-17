<?php

use App\Support\TwentyOne\Stream\AudioProbe;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/*
 * The server has no fpcalc (no chromaprint tools, no root to add them; 2026-10-17): the fingerprint then comes from
 * ffmpeg's chromaprint muxer with fpcalc's defaults, its raw output read as fpcalc -raw prints it.
 */

test('without fpcalc the fingerprint comes from ffmpeg\'s chromaprint muxer with fpcalc\'s algorithm and length', function () {
    $items = [6193600, 4290151872, 0, 123456789];
    Process::fake(function (PendingProcess $process) use ($items) {
        $command = (array) $process->command;

        return match (true) {
            in_array('ffprobe', $command, true) => Process::result(output: json_encode(['format' => ['duration' => '12.5', 'bit_rate' => '128000'], 'streams' => [['codec_type' => 'audio', 'bit_rate' => '128000']]])),
            in_array('chromaprint', $command, true) => Process::result(output: pack('V*', ...$items)),
            default => Process::result(exitCode: 1),
        };
    });

    $measured = (new AudioProbe(fpcalc: '/nonexistent/fpcalc'))->measure(['song' => '/music/song.m4a'], loudness: false)['song'];

    expect($measured['fingerprint'])->toBe($items)
        ->and($measured['duration'])->toBe(12.5);
    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 3) === ['ffmpeg', '-v', 'error', '-i', '/music/song.m4a', '-t', '120', '-f', 'chromaprint', '-fp_format', 'raw', '-algorithm', '1', '-']);
    Process::assertNotRan(fn (PendingProcess $process): bool => in_array('/nonexistent/fpcalc', (array) $process->command, true));
});

test('raw chromaprint bytes are read as unsigned little-endian items, a torn tail ignored', function () {
    expect(AudioProbe::rawItems(pack('V*', 1, 4294967295).'xy'))->toBe([1, 4294967295])
        ->and(AudioProbe::rawItems(''))->toBe([])
        ->and(AudioProbe::fpcalcItems('{"fingerprint":[5,4290151872]}'))->toBe([5, 4290151872]);
});
