<?php

use App\Support\TwentyOne\Stream\LoudnessNormalizer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/*
 * The library's loudness (plan "Musik-Katalog"): the AAC encoder overshoots the limiter, by more than a fixed dB on
 * some songs (2026-10-16: "Journalists Are Cypherpunks - Richard Greaser" kept +0.2 dBTP at a -2.5 dB ceiling).
 */

/**
 * ffmpeg faked: loudnorm's first pass, encodes that write their output, and ebur128 reporting `$peaks` in turn.
 *
 * @param  list<float>  $peaks
 * @return ArrayObject<int, float> the limiter ceilings the encodes used, in dB
 */
function fakeLoudnessRuns(array $peaks, string $inputLoudness = '-20.0', string $inputPeak = '-3.0'): ArrayObject
{
    $limits = new ArrayObject;

    Process::fake(function (PendingProcess $process) use (&$peaks, $limits, $inputLoudness, $inputPeak) {
        $command = (array) $process->command;
        $line = implode(' ', $command);

        if (str_contains($line, 'print_format=json')) {
            return Process::result(errorOutput: "[Parsed_loudnorm_0]\n{\n\"input_i\" : \"{$inputLoudness}\",\n\"input_tp\" : \"{$inputPeak}\",\n\"input_lra\" : \"6.0\",\n\"input_thresh\" : \"-30.0\",\n\"target_offset\" : \"0.1\"\n}\n");
        }

        expect($line)->not->toContain('latency=true');

        if (str_contains($line, 'alimiter')) {
            preg_match('/alimiter=limit=([\d.]+)/', $line, $limit);
            $limits[] = round(20 * log10((float) $limit[1]), 1);
            touch((string) end($command));

            return Process::result();
        }

        return Process::result(errorOutput: sprintf("Summary:\n  Integrated loudness:\n    I:         -15.0 LUFS\n  True peak:\n    Peak:       %.1f dBFS\n", array_shift($peaks)));
    });

    return $limits;
}

test('a result above the true-peak ceiling is encoded again with the limiter lowered by the excess and half a dB more', function () {
    $dir = sys_get_temp_dir().'/normalize-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $limits = fakeLoudnessRuns([0.2, -1.2]);

    try {
        $result = (new LoudnessNormalizer)->normalize($dir.'/in.mp3', $dir.'/out__v1.m4a');

        // +0.2 dBTP is 1.2 dB above -1.0: the second encode limits at -1.5 - 1.2 - 0.5 = -3.2 dB.
        expect($limits->getArrayCopy())->toBe([-1.5, -3.2])
            ->and($result)->toBe(['lufs' => -15.0, 'true_peak' => -1.2, 'limit' => -3.2])
            ->and(is_file($dir.'/out__v1.m4a'))->toBeTrue();
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

test('a song whose own peak lies below the first ceiling is limited from its peak down, since a higher ceiling limits nothing', function () {
    $dir = sys_get_temp_dir().'/normalize-'.bin2hex(random_bytes(4));
    mkdir($dir);
    // "Journalists Are Cypherpunks - Richard Greaser": -15.6 LUFS, -3.71 dBTP, so -3.11 dBTP after +0.6 dB of loudnorm.
    $limits = fakeLoudnessRuns([0.2, -1.1], '-15.60', '-3.71');

    try {
        (new LoudnessNormalizer)->normalize($dir.'/in.mp3', $dir.'/out__v1.m4a');

        expect($limits->getArrayCopy())->toBe([-1.5, -4.8]);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

test('a song still above the ceiling after the last try is refused and leaves no file', function () {
    $dir = sys_get_temp_dir().'/normalize-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $limits = fakeLoudnessRuns([0.2, -0.5, -0.8]);

    try {
        expect(fn () => (new LoudnessNormalizer)->normalize($dir.'/in.mp3', $dir.'/out__v1.m4a'))
            ->toThrow(RuntimeException::class, 'true peak -0.8 dBTP after the limiter, above -1.0');

        expect($limits->getArrayCopy())->toHaveCount(LoudnessNormalizer::TRIES)
            ->and(glob($dir.'/{,.}*out*', GLOB_BRACE))->toBe([]);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});
