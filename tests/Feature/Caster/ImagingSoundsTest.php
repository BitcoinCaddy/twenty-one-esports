<?php

use App\Console\Commands\CasterJinglesCommand;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\JingleSynth;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Idents;
use App\Support\Caster\ScenePhase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio, P11: more stingers, more beds, longer Anchor imaging
|--------------------------------------------------------------------------
|
| User 2026-10-12: "aufregendere Jingles, die viel länger sind vom ANCHOR, und
| mehr unterschiedliche Jingles und Stinger und Betten." Own synthesized
| sounds (JingleSynth, CC0), measured with the real ffmpeg; the Anchor's
| imaging lines in English with ESPORTS spoken as "Eee. Sports.".
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
});

/**
 * Integrated loudness and true peak of a file.
 *
 * @return array{lufs: float, peak: float}
 */
function imagingSoundLevels(string $file): array
{
    $err = Process::run(['ffmpeg', '-nostdin', '-hide_banner', '-i', $file, '-af', 'ebur128=peak=true', '-f', 'null', '-'])->errorOutput();
    preg_match_all('/I:\s+(-?[\d.]+) LUFS/', $err, $lufs);
    preg_match_all('/Peak:\s+(-?[\d.]+) dBFS/', $err, $peak);

    return ['lufs' => (float) end($lufs[1]), 'peak' => (float) end($peak[1])];
}

it('ships at least 12 stingers and 6 loopable beds, each at -16 LUFS (plus or minus 1) under -1 dBTP, each licensed CC0', function () {
    $licenses = (string) file_get_contents(resource_path('audio/idents/LICENSES.md'));
    $files = [...array_map(fn (string $name): string => 'sfx-'.$name.'.opus', JingleSynth::STINGERS), ...array_map(fn (string $name): string => 'bed-'.$name.'.opus', JingleSynth::LOOP_BEDS)];

    expect(count(JingleSynth::STINGERS))->toBeGreaterThanOrEqual(12)
        ->and(count(JingleSynth::LOOP_BEDS))->toBeGreaterThanOrEqual(6);

    foreach ($files as $file) {
        $path = resource_path('audio/idents/'.$file);
        $levels = imagingSoundLevels($path);

        expect(is_file($path))->toBeTrue()
            ->and($levels['lufs'])->toBeGreaterThanOrEqual(-17.0)->toBeLessThanOrEqual(-15.0)
            ->and($levels['peak'])->toBeLessThanOrEqual(-1.0)
            ->and($licenses)->toMatch('/`'.preg_quote($file, '/').'`[^\n]*own work, CC0 1\.0/');
    }
});

it('makes every bed loop without a seam: past its loop length it starts over sample for sample', function () {
    foreach (JingleSynth::LOOP_BEDS as $bed) {
        $wav = storage_path('framework/testing/loop-'.$bed.'.wav');
        File::ensureDirectoryExists(dirname($wav));
        $arguments = JingleSynth::soundArguments('ffmpeg', 'bed:'.$bed, $wav, JingleSynth::LOOP_SECONDS + 1.0);
        expect(Process::run(array_slice($arguments, 3))->successful())->toBeTrue();

        $samples = unpack('g*', Process::run(['ffmpeg', '-nostdin', '-loglevel', 'error', '-i', $wav, '-f', 'f32le', '-'])->output()) ?: [];
        unlink($wav);
        $loop = JingleSynth::RATE * JingleSynth::LOOP_SECONDS;
        $worst = 0.0;

        for ($i = 1; $i <= JingleSynth::RATE; $i += 7) {
            $worst = max($worst, abs($samples[$i] - $samples[$i + $loop]));
        }

        expect(count($samples))->toBe($loop + JingleSynth::RATE)
            ->and($worst)->toBeLessThan(0.002, $bed);
    }
});

it('rotates the news stinger and the news bed, never the same twice in a row, and lays a format bed under its rubric', function () {
    config(['tv.commentary.idents.enabled' => true]);
    $idents = Idents::fromConfig();
    $stingers = array_map(fn (): string => basename((string) $idents?->stingerFor('news')), range(1, 40));

    expect(array_unique($stingers))->toHaveCount(4);
    foreach (array_slice($stingers, 1) as $index => $stinger) {
        expect($stinger)->not->toBe($stingers[$index]);
    }

    $day = CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin');
    $bulletin = app(ClipSpool::class)->store(casterScript(CasterScript::BULLETIN, ScenePhase::BREAK), casterOpus(), 10.0, [10.0], CarbonImmutable::now());
    $beds = array_map(fn (): array => (array) $idents?->bedUnder($bulletin, $day), range(1, 20));

    expect(array_values(array_unique(array_map(fn (array $bed): string => basename($bed['file']), $beds))))->toEqualCanonicalizing(['news-bed.opus', 'bed-news-pulse.opus', 'bed-news-drive.opus'])
        // The -16 LUFS variants sit 18 dB down, the -28 LUFS legacy bed 6 dB: both at -34 LUFS under the voice.
        ->and(collect($beds)->firstWhere('file', resource_path('audio/idents/bed-news-drive.opus'))['gain_db'] ?? null)->toBe(-18.0);

    $script = casterScript(CasterScript::RUBRIC, ScenePhase::BREAK);
    $script->forceFill(['topic' => 'tribunal'])->save();
    $tribunal = app(ClipSpool::class)->store($script, casterOpus(), 10.0, [10.0], CarbonImmutable::now());

    expect(basename((string) ($idents?->bedUnder($tribunal, $day)['file'] ?? '')))->toBe('bed-courtroom.opus')
        ->and($idents?->bedUnder($tribunal, CarbonImmutable::parse('2026-10-12 03:00:00', 'Europe/Berlin')))->toBeNull();
});

it('gives the Anchor at least 120 English imaging lines: every category, an opener and a promo for every format, ESPORTS spoken as "Eee. Sports."', function () {
    $lines = json_decode((string) file_get_contents(resource_path('data/voice/anchor-imaging.json')), true)['lines'];
    $categories = collect($lines)->countBy('category');
    $formats = ['tribunal', 'friedhof', 'jahrestag', 'meetups', 'partie', 'hotline', 'zentralbank', 'schwarzbuch', 'altcoin', 'pranger', 'phrasen', 'fundbuero', 'stacker', 'inflation', 'sport_wetter', 'nachrichten_talk'];

    expect(count($lines))->toBeGreaterThanOrEqual(120)
        ->and(collect($lines)->pluck('id')->unique()->count())->toBe(count($lines));

    foreach (['station-long', 'station-short', 'sweeper', 'liner', 'news-open', 'news-close', 'power-intro', 'promo'] as $category) {
        expect($categories[$category] ?? 0)->toBeGreaterThanOrEqual(4, $category);
    }

    foreach ($formats as $format) {
        expect($categories['opener-'.$format] ?? 0)->toBeGreaterThanOrEqual(1, $format)
            ->and($categories['promo-'.$format] ?? 0)->toBe(1, $format);
    }

    foreach ($lines as $line) {
        expect($line['language'])->toBe('en')
            ->and($line['text'])->not->toMatch('/[äöüß]|\b(?:die|der|das|und|nachrichten|gleich)\b/iu')
            ->and($line['spoken'])->not->toContain('ESPORTS')->not->toContain('..')
            ->and($line['text'])->not->toMatch('/\d/');

        if (str_contains($line['text'], 'ESPORTS')) {
            expect($line['spoken'])->toContain('TWENTY ONE. Eee. Sports. Radio.');
        }
    }
});

it('produces each imaging category to its length: voice length by tempo, stinger before, hit and tail after', function () {
    $lines = json_decode((string) file_get_contents(resource_path('data/voice/anchor-imaging.json')), true)['lines'];
    $ranges = ['station-long' => [12, 20], 'station-short' => [4, 6.5], 'sweeper' => [6, 10], 'liner' => [8, 15], 'opener' => [8, 15], 'news-open' => [10, 15], 'news-close' => [5, 8], 'promo' => [15, 25], 'power-intro' => [4.5, 8]];

    foreach ($lines as $line) {
        $recipe = JingleSynth::imagingRecipe($line['id'], $line['category'], (array) config('tv.commentary.imaging.format_beds'));
        $family = str_starts_with($line['category'], 'opener-') ? 'opener' : (str_starts_with($line['category'], 'promo') ? 'promo' : $line['category']);
        // The Anchor takes the length he is asked for (measured 2026-10-12: 7 s asked, 7 s spoken).
        $seconds = $recipe['intro'] + round(CasterJinglesCommand::duration($line['spoken']) * $recipe['tempo'], 1) + $recipe['tail'];

        expect($seconds)->toBeGreaterThanOrEqual($ranges[$family][0] - 0.6, $line['id'])->toBeLessThanOrEqual($ranges[$family][1] + 0.6, $line['id'])
            ->and($recipe)->toBe(JingleSynth::imagingRecipe($line['id'], $line['category'], (array) config('tv.commentary.imaging.format_beds')))
            ->and(in_array($recipe['stinger'], JingleSynth::STINGERS, true) && in_array($recipe['hit'], JingleSynth::STINGERS, true))->toBeTrue();
    }

    expect(JingleSynth::imagingRecipe('img-op-tribunal-1', 'opener-tribunal', (array) config('tv.commentary.imaging.format_beds'))['bed'])->toBe('courtroom')
        ->and(JingleSynth::imagingRecipe('img-pi-01', 'power-intro')['bed'])->toBeNull();
});
