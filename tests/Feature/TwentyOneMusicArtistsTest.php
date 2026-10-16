<?php

use App\Support\TwentyOne\Stream\MusicCredits;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use App\Support\TwentyOne\Stream\MusicTimeline;
use Illuminate\Support\Facades\File;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * Who a track is by for the artist cycle (plan "Musik-Katalog"): the credited artist from the music catalog, the
 * house artist DJ Bitcoin as one artist like any other, and one shared turn for every track without a credit.
 */

afterEach(fn () => MusicCredits::flush());

test('a track is by its credited artist, a PlebRap collaboration by its first, a house track by DJ Bitcoin, a track without a credit by Unbekannt', function () {
    $dir = rtrim((string) config('twentyone.stream.music.dir'), '/');

    expect(MusicPlaylist::artist($dir."/wavlake/Can't Say I'm Not Trying - Ainsley Costello__v1.m4a"))->toBe('Ainsley Costello')
        ->and(MusicPlaylist::artist($dir.'/plebrap/01 - Feuer über Fiat - Too Bit to Fail, Hanspanzer__v1.m4a'))->toBe('Too Bit to Fail')
        ->and(MusicPlaylist::artist($dir.'/instrumental/01__v1.m4a'))->toBe(MusicCredits::HOUSE_ARTIST)
        ->and(MusicPlaylist::artist($dir.'/einundzwanzig-song__v1.m4a'))->toBe(MusicCredits::HOUSE_ARTIST)
        ->and(MusicPlaylist::artist($dir.'/wavlake/Unknown Song - Nobody__v1.m4a'))->toBe(MusicPlaylist::UNKNOWN_ARTIST);
});

test('the timeline reads its artist gap from the config, a changed gap starts nothing over, and credits that cannot be read leave the music playing', function () {
    $dir = sys_get_temp_dir().'/music-artists-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $songs = [];

    foreach (range(1, 6) as $number) {
        touch($songs[] = "{$dir}/song-{$number}__v1.m4a");
    }

    // Two artists: with a gap of 2 every round has to bend, never more than the two.
    $artist = fn (string $file): string => (int) explode('-', basename($file, '__v1.m4a'))[1] % 2 === 0 ? 'Even' : 'Odd';
    $timeline = fn (?Closure $artists): MusicTimeline => new MusicTimeline($dir.'/timeline.json', fn (): float => 100.0, 180, 25, null, $artists);

    try {
        config(['twentyone.stream.music.artist_gap' => 2]);
        $wide = $timeline($artist)->from([], [], 1, 1000.0, 1, new Randomizer(new Mt19937(2)), [], [3, 5], [$songs]);
        config(['twentyone.stream.music.artist_gap' => 1]);
        $narrow = $timeline($artist)->from([], [], 1, 1150.0, 1, new Randomizer(new Mt19937(2)), [], [3, 5], [$songs]);
        $broken = $timeline(fn (): string => throw new RuntimeException('catalog unreadable'))->from([], [], 1, 2000.0, 1, new Randomizer(new Mt19937(2)), [], [3, 5], [$songs]);
        $names = array_map($artist, $narrow['files']);

        expect($wide['restarted'])->toBeTrue()
            ->and([$narrow['restarted'], $narrow['files'][0]])->toBe([false, $wide['files'][1]])
            ->and(array_filter(array_slice($names, 1), fn (string $name, int $index): bool => $name === $names[$index], ARRAY_FILTER_USE_BOTH))->toBe([])
            ->and($broken['restarted'])->toBeFalse()
            ->and(count($broken['files']))->toBeGreaterThanOrEqual(30);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

test('an artist with fewer than min_artist_tracks songs sits out of the cycle and plays again once they have enough', function () {
    $dir = sys_get_temp_dir().'/music-min-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $make = function (string $artist, int $count) use ($dir): array {
        $files = [];

        foreach (range(1, $count) as $number) {
            touch($files[] = "{$dir}/{$artist}-{$number}__v1.m4a");
        }

        return $files;
    };
    $artist = fn (string $file): string => explode('-', basename($file))[0];
    $timeline = fn (): MusicTimeline => new MusicTimeline($dir.'/timeline.json', fn (): float => 100.0, 180, 25, 1, $artist);
    $pool = [...$make('A', 6), ...$make('B', 6), ...$make('Single', 1), ...$make('Few', 5)];

    try {
        config(['twentyone.stream.music.min_artist_tracks' => 6]);
        $artists = array_unique(array_map($artist, $timeline()->from([], [], 1, 1000.0, 2, new Randomizer(new Mt19937(1)), [], [3, 5], [$pool])['files']));

        // Few reaches six songs: it joins without anything starting over.
        $pool[] = $dir.'/Few-6__v1.m4a';
        touch($dir.'/Few-6__v1.m4a');
        $later = $timeline()->from([], [], 1, 1500.0, 3, new Randomizer(new Mt19937(2)), [], [3, 5], [$pool]);

        expect($artists)->toEqualCanonicalizing(['A', 'B'])
            ->and($later['restarted'])->toBeFalse()
            ->and(array_unique(array_map($artist, $later['files'])))->toContain('Few')->not->toContain('Single');
    } finally {
        File::deleteDirectory($dir);
    }
});
