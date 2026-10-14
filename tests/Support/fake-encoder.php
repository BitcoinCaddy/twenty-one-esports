<?php

/*
 * An ffmpeg stand-in for supervisor tests: takes the HLS arguments that
 * FfmpegCommands builds, writes the init file, a few segments and the
 * encoder playlist like ffmpeg's HLS muxer would, then waits for SIGTERM.
 *
 * Usage (via a shell wrapper named ffmpeg): <ffmpeg args…>
 * FAKE_ENCODER_SEGMENTS sets how many segments (default 3);
 * FAKE_ENCODER_CAPTURE, if set, is a path the public playlist (two levels
 * up: hls_dir/stream.m3u8) is copied to 1.5 s after start, so a test can
 * read what the supervisor published while it was running.
 * Per mode (LOOP or SCENE, the scene is the run reading image2pipe):
 * FAKE_ENCODER_<MODE>_SEGMENTS, _DELAY (seconds before the first segment)
 * and _EXIT_AFTER (seconds, then exit 1 as a crash).
 * FAKE_ENCODER_TRACKS, "<declared>:<carried>", writes an init declaring
 * that many tracks (moov/mvex/trex) and segments carrying that many (moof/traf
 * with one sample each), as ffmpeg does, instead of placeholder bytes.
 * Called as the YouTube relay (FLV output) it plays that instead, see below.
 */

$arguments = array_slice($argv, 1);
$option = function (string $name) use ($arguments): string {
    $index = array_search($name, $arguments, true);

    return $index === false ? '' : $arguments[$index + 1];
};

// The YouTube relay (FfmpegCommands::relay, FLV out): appends the playlist it reads to FAKE_RELAY_LOG, names its
// target on stderr the way ffmpeg's errors do, then exits 1 after FAKE_RELAY_EXIT_AFTER seconds or waits for SIGTERM.
if (in_array('flv', $arguments, true)) {
    if (($log = getenv('FAKE_RELAY_LOG')) !== false && $log !== '') {
        file_put_contents($log, $option('-i')."\n", FILE_APPEND);
    }

    fwrite(STDERR, 'Error opening output '.end($arguments).": Connection timed out\n");
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn () => exit(255));
    $relayExitAfter = (float) getenv('FAKE_RELAY_EXIT_AFTER');

    if ($relayExitAfter > 0) {
        usleep((int) (1_000_000 * $relayExitAfter));
        exit(1);
    }

    sleep(30);
    exit(0);
}

$playlist = end($arguments);
$directory = dirname($playlist);
$segmentPattern = basename($option('-hls_segment_filename'));
$init = $option('-hls_fmp4_init_filename');
$mode = in_array('image2pipe', $arguments, true) ? 'SCENE' : 'LOOP';
// Unset or empty falls through; "0" is a value (e.g. no segments at all).
$setting = function (string $name, string $default) use ($mode): string {
    foreach (["FAKE_ENCODER_{$mode}_{$name}", "FAKE_ENCODER_{$name}"] as $variable) {
        $value = getenv($variable);

        if ($value !== false && $value !== '') {
            return $value;
        }
    }

    return $default;
};
$count = (int) $setting('SEGMENTS', '3');
$exitAfter = (float) $setting('EXIT_AFTER', '0');
usleep((int) (1_000_000 * (float) $setting('DELAY', '0')));

$box = fn (string $type, string $payload): string => pack('N', 8 + strlen($payload)).$type.$payload;
$tracks = (string) $setting('TRACKS', '');
[$declared, $carried] = $tracks === '' ? [null, null] : array_map('intval', explode(':', $tracks));
$initBytes = $declared === null ? 'init' : $box('moov', $box('mvex', implode('', array_map(fn (int $id): string => $box('trex', pack('N6', 0, $id, 1, 0, 0, 0)), range(1, max(1, $declared))))));
$segmentBytes = $carried === null ? 'segment' : $box('moof', $box('mfhd', pack('N2', 0, 1)).implode('', array_map(
    fn (int $id): string => $box('traf', $box('tfhd', pack('N2', 0, $id)).$box('trun', pack('N2', 0, 1))),
    $carried > 0 ? range(1, $carried) : [],
))).$box('mdat', 'x');

file_put_contents($directory.'/'.$init, $initBytes);
$lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-TARGETDURATION:6', '#EXT-X-MEDIA-SEQUENCE:0', '#EXT-X-MAP:URI="'.$init.'"'];

for ($i = 0; $i < $count; $i++) {
    $segment = sprintf($segmentPattern, $i);
    file_put_contents($directory.'/'.$segment, $segmentBytes);
    $lines[] = '#EXTINF:6.000000,';
    $lines[] = $segment;
}

file_put_contents($playlist.'.tmp', implode("\n", $lines)."\n");
rename($playlist.'.tmp', $playlist);

pcntl_async_signals(true);
pcntl_signal(SIGTERM, fn () => exit(255));

if (($capture = getenv('FAKE_ENCODER_CAPTURE')) !== false && $capture !== '') {
    usleep(1_500_000);
    @copy(dirname($directory).'/stream.m3u8', $capture);
}

if ($exitAfter > 0) {
    usleep((int) (1_000_000 * $exitAfter));
    exit(1);
}

sleep(30);
