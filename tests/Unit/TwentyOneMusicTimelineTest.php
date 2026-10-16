<?php

use App\Console\Commands\TwentyOneStreamCommand;
use App\Support\TwentyOne\Stream\MusicPlaylist;
use App\Support\TwentyOne\Stream\MusicTimeline;
use Random\Engine\Mt19937;
use Random\Randomizer;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/music-timeline-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/instrumental', 0777, true);
    $this->vocals = [];
    $this->instrumentals = [];

    foreach (range(1, 4) as $title) {
        foreach ([1, 2] as $version) {
            touch($this->vocals[] = "{$this->dir}/song-{$title}__v{$version}.m4a");
        }
    }

    foreach (range(1, 6) as $number) {
        touch($this->instrumentals[] = sprintf('%s/instrumental/%02d__v1.m4a', $this->dir, $number));
    }

    // Every file lasts 100 s; the probes are counted.
    $this->probes = 0;
    $this->timeline = fn (): MusicTimeline => new MusicTimeline($this->dir.'/timeline.json', function (): float {
        $this->probes++;

        return 100.0;
    });
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('a new encoder goes on with the playing track at its second, and ffprobe runs once per file', function () {
    $first = ($this->timeline)()->from($this->vocals, $this->instrumentals, 3, 1000.0, 1, new Randomizer(new Mt19937(1)));
    $again = ($this->timeline)()->from($this->vocals, $this->instrumentals, 3, 1000.0 + 250.5, 1, new Randomizer(new Mt19937(2)));

    expect($first['restarted'])->toBeTrue()
        ->and($first['inpoint'])->toBe(0.0)
        ->and($again['restarted'])->toBeFalse()
        // 250.5 s later: two tracks of 100 s played, the third is 50.5 s in.
        ->and($again['files'][0])->toBe($first['files'][2])
        ->and($again['inpoint'])->toBe(50.5)
        ->and(array_slice($again['files'], 0, 10))->toBe(array_slice($first['files'], 2, 10))
        ->and($this->probes)->toBe(count($this->vocals) + count($this->instrumentals))
        ->and(MusicPlaylist::ffconcat($again['files'], $again['inpoint']))->toStartWith("ffconcat version 1.0\nfile '{$again['files'][0]}'\ninpoint 50.500\nfile ");
});

test('a set plays every vocal once before any repeats, and the next set is a new shuffle that never repeats the last title', function () {
    $music = ($this->timeline)()->from($this->vocals, $this->instrumentals, 3, 0.0, 3, new Randomizer(new Mt19937(7)));
    // A set: 8 vocals, each followed by 3 instrumentals = 32 entries.
    $sets = array_chunk($music['files'], 32);
    $vocalsOf = fn (array $set): array => array_values(array_filter($set, fn (string $file): bool => ! str_contains($file, '/instrumental/')));

    expect(count($sets))->toBeGreaterThanOrEqual(3);

    foreach (array_slice($sets, 0, 3) as $index => $set) {
        $vocals = $vocalsOf($set);
        sort($vocals);
        $expected = $this->vocals;
        sort($expected);

        expect($vocals)->toBe($expected);

        if ($index > 0) {
            expect(MusicPlaylist::title($set[0]))->not->toBe(MusicPlaylist::title($sets[$index - 1][31]));
        }
    }

    expect($vocalsOf($sets[0]))->not->toBe($vocalsOf($sets[1]));
});

test('with PlebRap files the timeline plays them in runs of three to five with one other track between, and new PlebRap starts it anew', function () {
    mkdir($this->dir.'/plebrap');
    $plebrap = [];
    foreach (range(1, 12) as $number) {
        touch($plebrap[] = sprintf("%s/plebrap/%02d - Artist - Song 'x' %d__v1.m4a", $this->dir, $number, $number));
    }

    $timeline = ($this->timeline)();
    $plain = $timeline->from($this->vocals, $this->instrumentals, 3, 1000.0, 1);
    $music = $timeline->from($this->vocals, $this->instrumentals, 3, 1010.0, 2, new Randomizer(new Mt19937(3)), $plebrap, [3, 5]);
    $pattern = implode('', array_map(fn (string $file): string => str_contains($file, '/plebrap/') ? 'P' : 'o', $music['files']));

    // The first set of the new timeline: all twelve PlebRap tracks once, three to five in a row, one other after each run.
    $first = array_slice(array_values(array_filter($music['files'], fn (string $file): bool => str_contains($file, '/plebrap/'))), 0, 12);
    sort($first);

    expect($plain['restarted'])->toBeTrue()
        ->and($music['restarted'])->toBeTrue()
        ->and($first)->toBe($plebrap)
        ->and($pattern)->toMatch('/^(P{1,5}o)+$/')
        ->and(preg_match_all('/P+/', $pattern, $runs))->toBeGreaterThan(3)
        ->and(collect($runs[0])->every(fn (string $run): bool => strlen($run) <= 5))->toBeTrue()
        // The same files again: the timeline goes on.
        ->and($timeline->from($this->vocals, $this->instrumentals, 3, 1020.0, 2, new Randomizer, $plebrap, [3, 5])['restarted'])->toBeFalse();
});

test('added music, a corrupt file or a stream that was off for longer than the timeline start a new timeline', function () {
    $timeline = ($this->timeline)();
    $timeline->from($this->vocals, $this->instrumentals, 3, 1000.0, 1);
    touch($this->instrumentals[] = $this->dir.'/instrumental/07__v1.m4a');

    $added = $timeline->from($this->vocals, $this->instrumentals, 3, 1010.0, 1);
    file_put_contents($this->dir.'/timeline.json', '{"anchor": 1');
    $corrupt = $timeline->from($this->vocals, $this->instrumentals, 3, 1020.0, 1);
    $later = $timeline->from($this->vocals, $this->instrumentals, 3, 1020.0 + 3 * 86400, 1);

    expect([$added['restarted'], $corrupt['restarted']])->toBe([true, true])
        ->and($added['inpoint'])->toBe(0.0)
        ->and($later['restarted'])->toBeFalse()
        ->and($later['inpoint'])->toBe(0.0);
});

test('a track plays from the end of its silent lead to the end of its sound, so no dead air lies between two tracks', function () {
    // Live 2026-10-12: up to three seconds of digital silence at the track ends fell silent on air at every change.
    $timeline = fn (): MusicTimeline => new MusicTimeline($this->dir.'/timeline.json', fn (): array => ['seconds' => 100.0, 'lead' => 1.0, 'tail' => 2.5]);
    $first = $timeline()->from($this->vocals, $this->instrumentals, 3, 1000.0, 1, new Randomizer(new Mt19937(1)));
    // 96.5 s of sound per track: 200 s later the third track is 7 s into its sound.
    $later = $timeline()->from($this->vocals, $this->instrumentals, 3, 1200.0, 1, new Randomizer(new Mt19937(2)));

    expect($first['points'][0])->toBe(['in' => 1.0, 'out' => 97.5])
        ->and($later['files'][0])->toBe($first['files'][2])
        ->and($later['inpoint'])->toBe(7.0)
        ->and(MusicPlaylist::ffconcat($later['files'], $later['inpoint'], $later['points']))
        ->toStartWith("ffconcat version 1.0\nfile '{$later['files'][0]}'\ninpoint 8.000\noutpoint 97.500\nfile '{$later['files'][1]}'\ninpoint 1.000\noutpoint 97.500\n");
});

test('a track without silent edges, or too short to trim, plays whole', function () {
    $music = (new MusicTimeline($this->dir.'/timeline.json', fn (): array => ['seconds' => 12.0, 'lead' => 2.0, 'tail' => 1.0]))->from($this->vocals, $this->instrumentals, 3, 0.0, 1);

    expect($music['points'][0])->toBe(['in' => 0.0, 'out' => null])
        ->and(MusicPlaylist::ffconcat($music['files'], 0.0, $music['points']))->not->toContain('outpoint');
});

test('a library probed before the edges is caught up a few files per encoder start, and an old timeline plays on whole', function () {
    // The first start, as the code before P17 kept it: lengths only, entries without a lead.
    ($this->timeline)()->from($this->vocals, $this->instrumentals, 3, 1000.0, 1);
    $state = json_decode((string) file_get_contents($this->dir.'/timeline.json'), true);
    $state['durations'] = array_map(fn (array $probe): array => array_slice($probe, 0, 3), $state['durations']);
    $state['entries'] = array_map(fn (array $entry): array => array_slice($entry, 0, 2), $state['entries']);
    file_put_contents($this->dir.'/timeline.json', json_encode($state));
    $probes = 0;
    $timeline = new MusicTimeline($this->dir.'/timeline.json', function () use (&$probes): array {
        $probes++;

        return ['seconds' => 100.0, 'lead' => 0.5, 'tail' => 1.5];
    }, 180, 4);

    $music = $timeline->from($this->vocals, $this->instrumentals, 3, 1010.0, 1);

    expect($probes)->toBe(4)
        // The kept entries have no lead: they play whole, where they are.
        ->and($music['restarted'])->toBeFalse()
        ->and($music['points'][0])->toBe(['in' => 0.0, 'out' => null])
        ->and($music['inpoint'])->toBe(10.0);

    $timeline->from($this->vocals, $this->instrumentals, 3, 1020.0, 1);
    expect($probes)->toBe(8);
});

test('the silencedetect logs of a track\'s ends give its silent lead and tail, a tenth of a second kept', function () {
    $head = "[silencedetect @ 0x1] silence_start: 0\n[silencedetect @ 0x1] silence_end: 1.12472 | silence_duration: 1.12472";
    $tail = "[silencedetect @ 0x1] silence_start: 10.4249\n[silencedetect @ 0x1] silence_end: 12.012 | silence_duration: 1.5871";
    $inside = "[silencedetect @ 0x1] silence_start: 3.2\n[silencedetect @ 0x1] silence_end: 4.1 | silence_duration: 0.9";

    expect(TwentyOneStreamCommand::leadSilence($head))->toBe(1.025)
        ->and(TwentyOneStreamCommand::leadSilence($inside))->toBe(0.0)
        ->and(TwentyOneStreamCommand::tailSilence($tail, 12.0))->toBe(1.475)
        ->and(TwentyOneStreamCommand::tailSilence("[silencedetect @ 0x1] silence_start: 9.5\n", 12.0))->toBe(2.4)
        ->and(TwentyOneStreamCommand::tailSilence($inside, 12.0))->toBe(0.0)
        ->and(TwentyOneStreamCommand::tailSilence('', 12.0))->toBe(0.0);
});

/**
 * The artist cycle's library in `$dir`: A with three songs, B with two, C with one, and the six instrumentals of the
 * fixture as DJ Bitcoin's; the artist of a file is told by its name (`a-1` is A's).
 *
 * @param  list<string>  $instrumentals
 * @return array{0: list<list<string>>, 1: Closure(string): string}
 */
function cycleLibrary(string $dir, array $instrumentals, array $songs = ['a-1', 'a-2', 'a-3', 'b-1', 'b-2', 'c-1']): array
{
    $files = [];

    foreach ($songs as $song) {
        touch($files[] = "{$dir}/{$song}__v1.m4a");
    }

    return [[$files, $instrumentals], fn (string $file): string => str_contains($file, '/instrumental/') ? 'DJ Bitcoin' : strtoupper(explode('-', basename($file))[0])];
}

test('the artist cycle goes on across restarts, never starts over for a changed library, and plays nothing again before its turn', function () {
    [$sources, $artist] = cycleLibrary($this->dir, $this->instrumentals);
    $timeline = fn (): MusicTimeline => new MusicTimeline($this->dir.'/timeline.json', fn (): float => 100.0, 180, 25, 1, $artist);

    $first = $timeline()->from([], [], 1, 1000.0, 2, new Randomizer(new Mt19937(1)), [], [3, 5], $sources);
    // A restart 250.5 s later: the third track, 50.5 s in.
    $restart = $timeline()->from([], [], 1, 1250.5, 2, new Randomizer(new Mt19937(2)), [], [3, 5], $sources);
    // A new song for A and a changed setting of the older orders: nothing starts over.
    touch($sources[0][] = $this->dir.'/a-4__v1.m4a');
    $added = $timeline()->from([], [], 1, 1460.0, 2, new Randomizer(new Mt19937(3)), [], [3, 5], $sources);

    expect($first['restarted'])->toBeTrue()
        ->and([$restart['restarted'], $restart['files'][0], $restart['inpoint']])->toBe([false, $first['files'][2], 50.5])
        ->and([$added['restarted'], $added['files'][0], $added['inpoint']])->toBe([false, $restart['files'][2], 60.0]);

    // What played and what is queued, as the file keeps it: every round has every artist once, never one twice in a
    // row, and A's four songs all play before one of them again, the new one within that turn.
    $played = array_column(json_decode((string) file_get_contents($this->dir.'/timeline.json'), true)['entries'], 0);
    $artists = array_map($artist, $played);

    foreach (array_chunk($artists, 4) as $round) {
        if (count($round) === 4) {
            expect($round)->toEqualCanonicalizing(['A', 'B', 'C', 'DJ Bitcoin']);
        }
    }

    foreach (array_slice($artists, 1) as $index => $name) {
        expect($name)->not->toBe($artists[$index]);
    }

    expect(array_unique(array_slice(array_values(array_filter($played, fn (string $file): bool => $artist($file) === 'A')), 0, 4)))->toHaveCount(4);
});

test('a removed track leaves the timeline and the playing one gives way at once; a new artist joins the round', function () {
    [$sources, $artist] = cycleLibrary($this->dir, $this->instrumentals);
    $timeline = fn (): MusicTimeline => new MusicTimeline($this->dir.'/timeline.json', fn (): float => 100.0, 180, 25, 1, $artist);
    $first = $timeline()->from([], [], 1, 1000.0, 1, new Randomizer(new Mt19937(5)), [], [3, 5], $sources);

    // The playing track's file goes away, and D arrives while three artists are still to play in the round.
    $gone = $first['files'][0];
    unlink($gone);
    $sources = array_map(fn (array $files): array => array_values(array_diff($files, [$gone])), $sources);
    touch($sources[0][] = $this->dir.'/d-1__v1.m4a');
    $later = $timeline()->from([], [], 1, 1050.0, 1, new Randomizer(new Mt19937(6)), [], [3, 5], $sources);
    $names = array_map($artist, $later['files']);

    expect($later['restarted'])->toBeFalse()
        ->and($later['inpoint'])->toBe(0.0)
        ->and($later['files'])->not->toContain($gone)
        // The round had three artists left besides the one whose track went: D plays within it.
        ->and(array_slice($names, 0, 4))->toContain('D');

    foreach (array_slice($names, 1) as $index => $name) {
        expect($name)->not->toBe($names[$index]);
    }
});

test('the first start of the artist cycle counts the tracks of the old timeline as heard, and the playing one goes on', function () {
    [$sources, $artist] = cycleLibrary($this->dir, $this->instrumentals);
    // As the source rotation left it: three tracks played, the fourth 50 s in at 1000.
    file_put_contents($this->dir.'/timeline.json', json_encode(['fingerprint' => 'rotate', 'anchor' => 650.0, 'entries' => [
        ["{$this->dir}/a-1__v1.m4a", 100.0, 0.0, null], [$this->instrumentals[0], 100.0, 0.0, null], ["{$this->dir}/b-1__v1.m4a", 100.0, 0.0, null], ["{$this->dir}/a-2__v1.m4a", 100.0, 0.0, null],
    ], 'durations' => []]));

    $music = (new MusicTimeline($this->dir.'/timeline.json', fn (): float => 100.0, 180, 25, 1, $artist))->from([], [], 1, 1000.0, 1, new Randomizer(new Mt19937(7)), [], [3, 5], $sources);
    $next = fn (string $name): ?string => collect(array_slice($music['files'], 1))->first(fn (string $file): bool => $artist($file) === $name);

    expect([$music['restarted'], $music['files'][0], $music['inpoint']])->toBe([false, "{$this->dir}/a-2__v1.m4a", 50.0])
        // a-1 and a-2 were heard: A goes on with a-3; B with b-2; the house artist with another instrumental.
        ->and($next('A'))->toBe("{$this->dir}/a-3__v1.m4a")
        ->and($next('B'))->toBe("{$this->dir}/b-2__v1.m4a")
        ->and($next('DJ Bitcoin'))->not->toBe($this->instrumentals[0]);
});
