<?php

use App\Support\TwentyOne\Stream\MusicPlaylist;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The shape of the real music folder: 14 titles with 2 versions, one with 4.
 *
 * @return list<string>
 */
function musicFiles(): array
{
    $files = ['/music/shitcoin-shuffle__v1.m4a', '/music/shitcoin-shuffle__v2.m4a', '/music/shitcoin-shuffle__v3.m4a', '/music/shitcoin-shuffle__v4.m4a'];

    foreach (range(1, 14) as $title) {
        $files[] = "/music/title-{$title}__v1.m4a";
        $files[] = "/music/title-{$title}__v2.m4a";
    }

    return $files;
}

test('no title plays twice in a row, across passes and the wrap, and every pass plays every file', function () {
    $files = musicFiles();
    sort($files);
    $violations = [];

    foreach (range(1, 200) as $seed) {
        $order = MusicPlaylist::order($files, 8, new Randomizer(new Mt19937($seed)));
        $titles = array_map(MusicPlaylist::title(...), $order);

        foreach ($titles as $index => $title) {
            if ($title === $titles[($index + 1) % count($titles)]) {
                $violations[] = "seed {$seed}: {$title} at {$index}";
            }
        }

        foreach (array_chunk($order, count($files)) as $number => $pass) {
            sort($pass);

            if ($pass !== $files) {
                $violations[] = "seed {$seed}: pass {$number} is not every file once";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('a small set boxed in at the wrap is still ordered', function () {
    // Half the files share one title: every pass must alternate, and the list wraps.
    foreach (range(1, 50) as $seed) {
        $titles = array_map(MusicPlaylist::title(...), MusicPlaylist::order(['/m/a__v1.m4a', '/m/a__v2.m4a', '/m/b__v1.m4a', '/m/c__v1.m4a'], 8, new Randomizer(new Mt19937($seed))));

        foreach ($titles as $index => $title) {
            expect($title)->not->toBe($titles[($index + 1) % count($titles)]);
        }
    }
});

test('the order differs between seeds and passes', function () {
    $first = MusicPlaylist::order(musicFiles(), 2, new Randomizer(new Mt19937(1)));
    $second = MusicPlaylist::order(musicFiles(), 2, new Randomizer(new Mt19937(2)));

    expect($first)->not->toBe($second)
        ->and(array_slice($first, 0, 32))->not->toBe(array_slice($first, 32));
});

test('a set that cannot be separated is refused instead of looping forever', function () {
    MusicPlaylist::order(['/m/a__v1.m4a', '/m/a__v2.m4a', '/m/a__v3.m4a', '/m/b__v1.m4a'], 1);
})->throws(InvalidArgumentException::class);

test('the ffconcat list quotes every path for the concat demuxer', function () {
    expect(MusicPlaylist::ffconcat(['/music/a__v1.m4a', "/music/it's__v1.m4a"]))
        ->toBe("ffconcat version 1.0\nfile '/music/a__v1.m4a'\nfile '/music/it'\\''s__v1.m4a'\n")
        ->and(MusicPlaylist::title('/music/not-your-keys-not-your-coins-dnb-remix__v2.m4a'))->toBe('not-your-keys-not-your-coins-dnb-remix');
});

test('a music path with a control character never reaches the ffconcat list', function () {
    MusicPlaylist::ffconcat(["/music/a__v1.m4a\nfile '/etc/passwd'"]);
})->throws(InvalidArgumentException::class);

test('instrumentals alternate with the vocal tracks, never next to the same title, and all come round', function () {
    $instrumentals = ['/music/instrumental/drumnbass__v1.m4a', '/music/instrumental/drumnbass__v2.m4a', '/music/instrumental/drumnbass__v3.m4a', '/music/instrumental/drumnbass__v4.m4a'];

    foreach (['shitcoin-shuffle', 'title-1', 'title-2', 'title-3'] as $title) {
        $instrumentals[] = "/music/instrumental/{$title}__v1.m4a";
        $instrumentals[] = "/music/instrumental/{$title}__v2.m4a";
    }

    foreach (range(1, 100) as $seed) {
        $random = new Randomizer(new Mt19937($seed));
        $vocals = MusicPlaylist::order(musicFiles(), 4, $random);
        $list = MusicPlaylist::interleave($vocals, $instrumentals, $random);
        $count = count($list);

        expect($count)->toBe(2 * count($vocals))
            ->and(array_values(array_filter($list, fn (string $file): bool => ! str_contains($file, '/instrumental/'))))->toBe($vocals);

        $played = [];

        foreach ($list as $index => $file) {
            expect(str_contains($file, '/instrumental/'))->toBe($index % 2 === 1)
                ->and(MusicPlaylist::title($file))->not->toBe(MusicPlaylist::title($list[($index + 1) % $count]));

            if ($index % 2 === 1) {
                if ($index >= 3) {
                    expect(MusicPlaylist::title($file))->not->toBe(MusicPlaylist::title($list[$index - 2]));
                }

                $played[$file] = ($played[$file] ?? 0) + 1;
            }
        }

        expect(count($played))->toBe(count($instrumentals))
            ->and(max($played) - min($played))->toBeLessThanOrEqual(2);
    }
});

test('without instrumentals the vocal order stays as it is', function () {
    $vocals = MusicPlaylist::order(musicFiles(), 1);

    expect(MusicPlaylist::interleave($vocals, []))->toBe($vocals);
});

test('instrumentals that are all also sung leave a slot empty instead of failing', function () {
    $instrumentals = ['/music/instrumental/title-1__v1.m4a', '/music/instrumental/title-2__v1.m4a'];

    foreach (range(1, 100) as $seed) {
        $random = new Randomizer(new Mt19937($seed));
        $vocals = MusicPlaylist::order(musicFiles(), 4, $random);
        $list = MusicPlaylist::interleave($vocals, $instrumentals, $random);

        expect(array_values(array_filter($list, fn (string $file): bool => ! str_contains($file, '/instrumental/'))))->toBe($vocals);

        foreach ($list as $index => $file) {
            expect(MusicPlaylist::title($file))->not->toBe(MusicPlaylist::title($list[($index + 1) % count($list)]));
        }
    }
});

test('three instrumentals play between two vocal tracks, never next to the same title, and all come round', function () {
    $instrumentals = [];

    foreach (['dnb' => 10, 'drumnbass' => 4, 'shitcoin-shuffle' => 2, 'title-1' => 2, 'title-2' => 2, 'title-3' => 2] as $title => $versions) {
        foreach (range(1, $versions) as $version) {
            $instrumentals[] = "/music/instrumental/{$title}__v{$version}.m4a";
        }
    }

    foreach (range(1, 100) as $seed) {
        $random = new Randomizer(new Mt19937($seed));
        $vocals = MusicPlaylist::order(musicFiles(), 4, $random);
        $list = MusicPlaylist::interleave($vocals, $instrumentals, $random, 3);
        $count = count($list);
        $played = [];

        expect($count)->toBe(4 * count($vocals))
            ->and(array_values(array_filter($list, fn (string $file): bool => ! str_contains($file, '/instrumental/'))))->toBe($vocals);

        foreach ($list as $index => $file) {
            expect(str_contains($file, '/instrumental/'))->toBe($index % 4 !== 0)
                ->and(MusicPlaylist::title($file))->not->toBe(MusicPlaylist::title($list[($index + 1) % $count]));

            if ($index % 4 === 1) {
                // One run of three: three different titles.
                expect(count(array_unique(array_map(MusicPlaylist::title(...), array_slice($list, $index, 3)))))->toBe(3);
            }

            if ($index % 4 !== 0) {
                $played[$file] = ($played[$file] ?? 0) + 1;
            }
        }

        // Every file plays; a title with many versions (DnB) is rationed to one per run, so its
        // versions come round less often than the others: variety of titles wins over evenness.
        expect(count($played))->toBe(count($instrumentals));
    }
});

test('PlebRap plays in runs of three to five, each followed by exactly one other track, every PlebRap track once a pass', function () {
    $plebrap = array_map(fn (int $n): string => sprintf("/music/plebrap/%02d - Pleb %d - Song 'Nr' %d (Grüße)__v1.m4a", $n, $n, $n), range(1, 38));
    $rest = [...array_map(fn (int $n): string => "/music/song-{$n}__v1.m4a", range(1, 32)), ...array_map(fn (int $n): string => sprintf('/music/instrumental/%02d__v1.m4a', $n), range(1, 130))];

    foreach ([1, 2, 3, 4, 5] as $seed) {
        $list = MusicPlaylist::priorityRuns($plebrap, $rest, 3, 5, new Randomizer(new Mt19937($seed)));
        // The pattern as letters: P for PlebRap, o for one of the others.
        $pattern = implode('', array_map(fn (string $file): string => str_contains($file, '/plebrap/') ? 'P' : 'o', $list));
        $played = array_values(array_filter($list, fn (string $file): bool => str_contains($file, '/plebrap/')));
        sort($played);
        $sorted = $plebrap;
        sort($sorted);

        // Runs of three to five, a single other track after each (the last run may be shorter: 38 is not a multiple).
        expect($played)->toBe($sorted)
            ->and($pattern)->toMatch('/^(P{3,5}o)*P{1,5}o$/');

        foreach (array_slice($list, 1) as $index => $file) {
            expect(MusicPlaylist::title($file))->not->toBe(MusicPlaylist::title($list[$index]));
        }
    }
});

test('a PlebRap name with spaces, apostrophes, umlauts and parentheses is quoted for the concat demuxer', function () {
    $file = "/srv/music/plebrap/20 - Orange Pill 'o' Sophie - Too Bit to Fail (Grüße)__v1.m4a";

    expect(MusicPlaylist::ffconcat([$file]))
        ->toBe("ffconcat version 1.0\nfile '/srv/music/plebrap/20 - Orange Pill '\\''o'\\'' Sophie - Too Bit to Fail (Grüße)__v1.m4a'\n")
        ->and(MusicPlaylist::isSafePath($file))->toBeTrue()
        ->and(MusicPlaylist::title($file))->toBe("20 - Orange Pill 'o' Sophie - Too Bit to Fail (Grüße)");
});
