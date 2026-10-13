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
