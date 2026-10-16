<?php

use App\Support\TwentyOne\Stream\ArtistCycle;
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

test('the list is written over again the given passes with the inpoint once only, as ffmpeg cannot loop a trimmed concat list', function () {
    expect(MusicPlaylist::ffconcat(['/music/a__v1.m4a', '/music/b__v1.m4a'], 12.5, [['in' => 0.2, 'out' => 180.0], ['in' => 0.0, 'out' => null]], 2))
        ->toBe("ffconcat version 1.0\nfile '/music/a__v1.m4a'\ninpoint 12.700\noutpoint 180.000\nfile '/music/b__v1.m4a'\n"
            ."file '/music/a__v1.m4a'\ninpoint 0.200\noutpoint 180.000\nfile '/music/b__v1.m4a'\n")
        ->and(MusicPlaylist::ffconcat(['/music/a__v1.m4a'], 0.0, [], 0))->toBe("ffconcat version 1.0\nfile '/music/a__v1.m4a'\n");
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

/**
 * Five artists with one to five tracks each, every title its own.
 *
 * @return array<string, list<string>>
 */
function cycleArtists(): array
{
    $byArtist = [];

    foreach (['A' => 5, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 1] as $artist => $count) {
        foreach (range(1, $count) as $number) {
            $byArtist[$artist][] = "/music/{$artist}-{$number}__v1.m4a";
        }
    }

    return $byArtist;
}

test('the artist cycle plays every artist exactly once a round, each round in a fresh order that never opens with the closing artist, and an artist repeats a track only after all its tracks', function () {
    $byArtist = cycleArtists();
    $artistOf = fn (string $file): string => explode('-', basename($file))[0];
    $cycle = new ArtistCycle($byArtist);
    $random = new Randomizer(new Mt19937(11));
    $bends = 0;
    $played = [];

    foreach (range(1, 5 * 6) as $pick) {
        $played[] = $cycle->next($random, $bends);
    }

    $rounds = array_chunk(array_map($artistOf, $played), 5);

    foreach ($rounds as $round) {
        expect($round)->toEqualCanonicalizing(['A', 'B', 'C', 'D', 'E']);
    }

    foreach (array_slice($played, 1) as $index => $file) {
        expect($artistOf($file))->not->toBe($artistOf($played[$index]))
            ->and(MusicPlaylist::title($file))->not->toBe(MusicPlaylist::title($played[$index]));
    }

    // A's five tracks over the first five rounds are five different ones, and its bag starts over with another than
    // the last; C's two take turns.
    $of = fn (string $artist): array => array_values(array_filter($played, fn (string $file): bool => $artistOf($file) === $artist));
    expect(array_unique(array_slice($of('A'), 0, 5)))->toHaveCount(5)
        ->and($of('A')[5])->not->toBe($of('A')[4])
        ->and(collect(array_chunk($of('C'), 2))->every(fn (array $pair): bool => count(array_unique($pair)) === count($pair)))->toBeTrue()
        ->and(count(array_unique(array_map(fn (array $round): string => implode('', $round), $rounds))))->toBeGreaterThan(1)
        ->and($bends)->toBe(0);
});

test('a kept cycle goes on where it was: a new track joins the open part of its artist\'s bag, a new artist joins the round while three are left, a removed track drops out', function () {
    $byArtist = cycleArtists();
    $cycle = new ArtistCycle($byArtist);
    $random = new Randomizer(new Mt19937(4));
    $first = $cycle->next($random);
    $second = $cycle->next($random);
    $state = $cycle->toState();

    // Two of five played: three artists left in the round, so a new one joins it.
    $more = [...$byArtist, 'F' => ['/music/F-1__v1.m4a']];
    $more['A'][] = '/music/A-6__v1.m4a';
    $kept = ArtistCycle::fromState($more, json_decode((string) json_encode($state), true), 1);
    $left = $kept?->toState()['pending'];

    expect($kept)->not->toBeNull()
        ->and($left)->toHaveCount(4)->toContain('F')
        ->and($kept?->toState()['played'])->toMatchArray(array_filter($state['played']))
        ->and($kept?->toState()['recent'])->toBe($state['recent']);

    // The rest of the round: the four left, never the two that played; the new track waits in A's bag, unplayed.
    $rest = array_map(fn (): string => $kept->next($random), range(1, 4));
    expect(array_map(fn (string $file): string => explode('-', basename($file))[0], $rest))->toEqualCanonicalizing($left)
        ->and(array_intersect($rest, [$first, $second]))->toBe([]);

    // One artist left in the round: a new artist waits for the next round. A removed track leaves its bag.
    $late = new ArtistCycle($byArtist);
    foreach (range(1, 4) as $pick) {
        $late->next($random);
    }
    $playedA = $late->toState()['played']['A'];
    $fewer = [...$byArtist, 'F' => ['/music/F-1__v1.m4a'], 'A' => array_values(array_diff($byArtist['A'], $playedA))];
    $later = ArtistCycle::fromState($fewer, $late->toState(), 1);

    expect($later?->toState()['pending'])->toHaveCount(1)->not->toContain('F')
        ->and($later?->toState()['played']['A'])->toBe([])
        ->and(ArtistCycle::fromState($byArtist, ['version' => 0], 1))->toBeNull();
});

test('with too few artists for the gap the cycle bends and counts it, and an artist whose only title was just played still plays', function () {
    $bends = 0;
    $cycle = new ArtistCycle(['A' => ['/music/same__v1.m4a', '/music/a-2__v1.m4a'], 'B' => ['/music/same__v2.m4a']], gap: 2);
    $random = new Randomizer(new Mt19937(3));
    $played = [];

    foreach (range(1, 6) as $pick) {
        $played[] = $cycle->next($random, $bends);
    }

    // Two artists cannot keep two tracks between the same artist: every round's first pick bends.
    expect($played)->toHaveCount(6)
        ->and($bends)->toBeGreaterThan(0);

    foreach (array_slice($played, 1) as $index => $file) {
        if (MusicPlaylist::title($file) === MusicPlaylist::title($played[$index])) {
            // Only B, with that single title, may follow it.
            expect($file)->toBe('/music/same__v2.m4a');
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
