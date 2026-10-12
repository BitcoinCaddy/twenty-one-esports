<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio, P4: stingers, idents and the news bed
|--------------------------------------------------------------------------
|
| Plan "TWENTY ONE Radio": the station sounds like a news/talk radio. Own
| synthesized idents (scripts/make-idents.sh, CC0) open a segment or a news
| block; the news bed lies under a bulletin's speech, never at night. These
| tests decode the real files with the real ffmpeg (no fake for the idents),
| only the spooled speech clip is faked.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.spool.filler_below_seconds' => 10, 'tv.commentary.parts.sentences' => 5, 'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00', 'tv.commentary.idents.bed_kinds' => ['bulletin'],
        // The P4 sound alone: one stinger per slot, the legacy bed (the P11 rotation has its own tests, ImagingSoundsTest).
        'tv.commentary.idents.stingers' => ['news' => 'news-stinger.opus', 'ident' => 'station-ident.opus', 'breaking' => 'breaking-sting.opus'],
        'tv.commentary.idents.beds' => [], 'tv.commentary.idents.format_beds' => []]);
});

/** The idents of the config, switched on. */
function identsFromConfig(): Idents
{
    config(['tv.commentary.idents.enabled' => true]);

    return Idents::fromConfig() ?? throw new RuntimeException('idents off');
}

/** Integrated loudness (LUFS) of an audio file, measured by ffmpeg's ebur128. */
function identsLoudness(string $file): float
{
    $result = Process::run(['ffmpeg', '-nostdin', '-hide_banner', '-i', $file, '-af', 'ebur128', '-f', 'null', '-']);
    preg_match_all('/I:\s+(-?[\d.]+) LUFS/', $result->errorOutput(), $matches);

    return (float) end($matches[1]);
}

/** A ready clip of `$kind` with `$seconds` of speech; only the speech decode (stdin) is faked, the idents decode for real. */
function identsSpooledClip(string $kind, float $seconds = 10.0): CasterClip
{
    $clip = app(ClipSpool::class)->store(casterScript($kind, ScenePhase::BREAK), casterOpus(), $seconds, range(5.0, $seconds, 5.0), CarbonImmutable::now());
    Process::fake(["*'pipe:0' '-ac'*" => Process::result(output: str_repeat("\1\0", (int) (24000 * $seconds)))]);

    return $clip;
}

/**
 * Runs a director built from the config until a clip is on air; the clip, its stinger slot and the timeline's start.
 *
 * @return array{clip: CasterClip|null, ident: string|null, director: SceneDirector}
 */
function identsFirstClip(SceneDirector $director): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    for ($t = $start; $t <= $start + 30 && $director->clip() === null; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);
    }

    return ['clip' => $director->clip(), 'ident' => $director->ident(), 'director' => $director];
}

it('ships at least three stingers of 2 to 4 s and a loopable bed of 60 to 180 s, each listed with its CC0 license', function () {
    $idents = identsFromConfig();
    $licenses = (string) file_get_contents(resource_path('audio/idents/LICENSES.md'));
    $stingers = (array) config('tv.commentary.idents.stingers');

    expect($stingers)->toHaveCount(3);

    foreach (array_keys($stingers) as $slot) {
        $file = $idents->stingerFor((string) $slot);
        $pcm = $idents->stingerPcm((string) $slot);

        expect($file)->not->toBeNull()
            ->and(Pcm::seconds((string) $pcm, 24000))->toBeGreaterThanOrEqual(2.0)->toBeLessThanOrEqual(4.0)
            ->and($licenses)->toMatch('/`'.preg_quote(basename((string) $file), '/').'`[^\n]*own work, CC0 1\.0/');
    }

    $bed = (string) $idents->bed();
    $seconds = (float) Process::run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $bed])->output();

    expect($seconds)->toBeGreaterThanOrEqual(60.0)->toBeLessThanOrEqual(180.0)
        ->and($licenses)->toMatch('/`news-bed\.opus`[^\n]*own work, CC0 1\.0/')
        ->and(file_exists(base_path('scripts/make-idents.sh')))->toBeTrue();
});

it('keeps the stingers at -16 LUFS (plus or minus 1) and the bed at -28 LUFS, the levels of the voice and the music bed', function () {
    $idents = identsFromConfig();

    foreach (array_keys((array) config('tv.commentary.idents.stingers')) as $slot) {
        expect(identsLoudness((string) $idents->stingerFor((string) $slot)))->toBeGreaterThanOrEqual(-17.0)->toBeLessThanOrEqual(-15.0);
    }

    expect(identsLoudness((string) $idents->bed()))->toBeGreaterThanOrEqual(-29.0)->toBeLessThanOrEqual(-27.0);
});

it('opens a news segment with the news stinger on the timeline: speech and sentence ends move behind it', function () {
    $clip = identsSpooledClip(CasterScript::NEWS);
    $stinger = (string) identsFromConfig()->stingerPcm('news');
    $offset = Pcm::seconds($stinger, 24000) + 0.3;

    ['clip' => $onAir, 'ident' => $ident, 'director' => $director] = identsFirstClip(SceneDirector::fromConfig(app(ClipSpool::class)));
    $timeline = $director->timeline;
    $at = (float) $timeline->startsAt();
    $sample = fn (float $second): string => $timeline->samples((int) round(($at + $second) * 24000), (int) round(($at + $second) * 24000) + 4);

    expect($onAir?->id)->toBe($clip->id)
        ->and($ident)->toBe('news')
        // The stinger's own samples first, the speech ("\1\0") only after stinger and pause.
        ->and($timeline->samples((int) round($at * 24000), (int) round($at * 24000) + intdiv(strlen($stinger), 2)))->toBe($stinger)
        ->and($sample($offset - 0.2))->toBe(str_repeat("\0\0", 4))
        ->and($sample($offset + 0.1))->toBe(str_repeat("\1\0", 4))
        ->and((float) $timeline->endsAt() - $at)->toEqualWithDelta($offset + 10.0, 0.01)
        ->and($timeline->nextSentenceEnd($at))->toEqualWithDelta($at + $offset + 5.0, 0.01);
});

it('opens a history segment with the station ident, and a scene clip with nothing', function () {
    identsSpooledClip(CasterScript::HISTORY);
    expect(identsFirstClip(SceneDirector::fromConfig(app(ClipSpool::class)))['ident'])->toBe('ident');

    CasterClip::query()->delete();
    identsSpooledClip(CasterScript::SCENE);
    ['clip' => $scene, 'ident' => $ident, 'director' => $director] = identsFirstClip(SceneDirector::fromConfig(app(ClipSpool::class)));

    expect($scene)->not->toBeNull()
        ->and($ident)->toBeNull()
        ->and((float) $director->timeline->endsAt() - (float) $director->timeline->startsAt())->toEqualWithDelta(10.0, 0.01);
});

it('plays no stinger before a later part of a long segment, only before its first part', function () {
    $text = implode("\n", array_map(fn (int $number): string => "Satz Nummer {$number} ist hier.", range(1, 10)));
    $first = app(ScriptQueue::class)->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, $text, 'history:idents', 0, CarbonImmutable::now());
    $parts = CasterScript::query()->where('part_group', $first->part_group)->orderBy('part_index')->get()->all();
    expect($parts)->toHaveCount(2);

    foreach ($parts as $part) {
        $part->update(['status' => CasterScript::CLAIMED, 'claimed_at' => now()]);
        app(ClipSpool::class)->store($part->refresh(), casterOpus(), 6.0, [3.0, 6.0], CarbonImmutable::now());
    }
    Process::fake(["*'pipe:0' '-ac'*" => Process::result(output: str_repeat("\1\0", 24000 * 6))]);

    $director = SceneDirector::fromConfig(app(ClipSpool::class));
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $start = (float) CarbonImmutable::now()->getTimestamp();
    $seen = [];

    for ($t = $start; $t <= $start + 40; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip() !== null) {
            $seen[$director->clip()->caster_script_id] ??= $director->ident();
        }
    }

    expect($seen)->toBe([$parts[0]->id => 'ident', $parts[1]->id => null]);
});

it('lays the news bed under a bulletin only, as long as its speech, and keeps the stinger in front', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin'));
    $idents = identsFromConfig();
    $speech = str_repeat("\0\0", 24000 * 6);

    $bulletin = $idents->dress(new CasterClip(['kind' => 'bulletin']), $speech, [3.0, 6.0], CarbonImmutable::now());
    $news = $idents->dress(new CasterClip(['kind' => CasterScript::NEWS]), $speech, [3.0, 6.0], CarbonImmutable::now());
    $offset = Pcm::seconds((string) $idents->stingerPcm('news'), 24000) + 0.3;

    expect($bulletin['bed'])->toBeTrue()
        ->and($bulletin['stinger'])->toBe('news')
        ->and(strlen($bulletin['pcm']))->toBe(strlen($news['pcm']))
        // Under the silent speech the bulletin carries the bed, the news segment silence.
        ->and(substr($bulletin['pcm'], -strlen($speech) + 24000 * 4, 4800))->not->toBe(str_repeat("\0", 4800))
        ->and($news['bed'])->toBeFalse()
        ->and(substr($news['pcm'], -strlen($speech)))->toBe($speech)
        ->and($bulletin['ends'])->toBe([round(3.0 + $offset, 3), round(6.0 + $offset, 3)]);
});

it('leaves the bed out in quiet hours, the stinger stays', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00', 'Europe/Berlin'));
    $idents = identsFromConfig();
    $speech = str_repeat("\0\0", 24000 * 6);

    $night = $idents->dress(new CasterClip(['kind' => 'bulletin']), $speech, [6.0], CarbonImmutable::now());

    expect($night['bed'])->toBeFalse()
        ->and($night['stinger'])->toBe('news')
        ->and(substr($night['pcm'], -strlen($speech)))->toBe($speech);

    config(['tv.commentary.idents.bed_at_night' => true]);
    expect(identsFromConfig()->dress(new CasterClip(['kind' => 'bulletin']), $speech, [6.0], CarbonImmutable::now())['bed'])->toBeTrue();
});

it('fails open towards the speech: a missing ident or bed leaves the clip as it was', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin'));
    config(['tv.commentary.idents.dir' => storage_path('framework/testing/no-idents')]);
    $speech = str_repeat("\1\0", 24000 * 2);

    $dressed = identsFromConfig()->dress(new CasterClip(['kind' => 'bulletin']), $speech, [2.0], CarbonImmutable::now());

    expect($dressed)->toBe(['pcm' => $speech, 'ends' => [2.0], 'stinger' => null, 'bed' => false]);
});

it('builds no idents while they are switched off, and the director then plays the clip bare', function () {
    config(['tv.commentary.idents.enabled' => false]);
    identsSpooledClip(CasterScript::NEWS);

    expect(Idents::fromConfig())->toBeNull()
        ->and(identsFirstClip(SceneDirector::fromConfig(app(ClipSpool::class)))['ident'])->toBeNull();
});
