<?php

use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use App\Support\TwentyOne\Stream\MusicTimeline;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Who a track is by for the playlist's artist spacing (plan "Musik-Katalog" P5): the credited artist from the music
 * credits, the house artist for the uncredited instrumentals and songs, nobody known for a track without a credit.
 */

afterEach(fn () => MusicCredits::flush());

test('a track is by its credited artist, a PlebRap track also by the co-artists its name lists, an uncredited instrumental or song by DJ Bitcoin, anything else by nobody known', function () {
    $dir = rtrim((string) config('twentyone.stream.music.dir'), '/');

    expect(MusicPlaylist::artists($dir."/wavlake/Can't Say I'm Not Trying - Ainsley Costello__v1.m4a"))->toBe(['Ainsley Costello'])
        ->and(MusicPlaylist::artists($dir.'/plebrap/01 - Feuer über Fiat - Too Bit to Fail, Hanspanzer__v1.m4a'))->toBe(['Too Bit to Fail', 'Hanspanzer'])
        ->and(MusicPlaylist::artists($dir.'/plebrap/25 - Hit the Road FED - Too Bit to Fail feat. Fr. Le Blanc__v1.m4a'))->toBe(['Too Bit to Fail', 'Fr. Le Blanc'])
        // A name whose last part is not the credited artist adds nobody.
        ->and(MusicPlaylist::artists($dir.'/plebrap/23 - Das Geldsystem ist krank - Too Bit to Fail (beat by Meidi_BTC Beatz)__v1.m4a'))->toBe(['Too Bit to Fail'])
        ->and(MusicPlaylist::artists($dir.'/instrumental/07 - Something__v1.m4a'))->toBe([MusicCredits::HOUSE_ARTIST])
        ->and(MusicPlaylist::artists($dir.'/einundzwanzig-song__v1.m4a'))->toBe([MusicCredits::HOUSE_ARTIST])
        ->and(MusicPlaylist::artists($dir.'/wavlake/Unknown Song - Nobody__v1.m4a'))->toBe([]);
});

test('the timeline reads its artist gap from the config, and credits that cannot be read leave the music playing unspaced', function () {
    $dir = sys_get_temp_dir().'/music-artists-'.bin2hex(random_bytes(4));
    mkdir($dir.'/instrumental', 0777, true);
    $songs = [];
    $instrumentals = [];

    foreach (range(1, 4) as $number) {
        touch($songs[] = "{$dir}/song-{$number}__v1.m4a");
        touch($instrumentals[] = "{$dir}/instrumental/{$number}__v1.m4a");
    }

    $sources = [$songs, $instrumentals];
    $timeline = fn (?Closure $artists): MusicTimeline => new MusicTimeline($dir.'/timeline.json', fn (): float => 100.0, 180, 25, null, $artists);

    try {
        // Every file here is the house artist's: with the gap from the config the rule has to bend, with 0 it is off.
        config(['twentyone.stream.music.artist_gap' => 0]);
        $off = $timeline(fn (): array => [MusicCredits::HOUSE_ARTIST])->from([], [], 1, 1000.0, 1, new Randomizer(new Mt19937(2)), [], [3, 5], $sources);
        config(['twentyone.stream.music.artist_gap' => 1]);
        $on = $timeline(fn (): array => [MusicCredits::HOUSE_ARTIST])->from([], [], 1, 1000.0, 1, new Randomizer(new Mt19937(2)), [], [3, 5], $sources);
        $fingerprint = json_decode((string) file_get_contents($dir.'/timeline.json'), true)['fingerprint'];
        $broken = $timeline(fn (): array => throw new RuntimeException('catalog unreadable'))->from([], [], 1, 2000.0, 1, new Randomizer(new Mt19937(2)), [], [3, 5], $sources);

        // Another gap starts a new timeline; the default keeps the fingerprint of the rotation before P5, so its deploy
        // goes on with the running timeline.
        expect($on['restarted'])->toBeTrue()
            ->and($fingerprint)->toBe(sha1(json_encode(['rotate', $sources], JSON_THROW_ON_ERROR)))
            ->and($broken['restarted'])->toBeFalse()
            ->and(count($off['files']))->toBeGreaterThanOrEqual(36)
            ->and(count($broken['files']))->toBeGreaterThanOrEqual(36);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});
