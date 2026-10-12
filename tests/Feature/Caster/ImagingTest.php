<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Imaging;
use App\Support\Caster\Pcm;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScenePhase;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio, P11: radio imaging on the server
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Mehr typische Radio-Zwischenfüller, wie man sie von einem
| Radiosender kennt. Es ist noch nicht lebhaft genug." The Anchor's jingles,
| uploaded by the workstation, play between clips by rules: station ID at
| least every 15 minutes, sweepers between talk clips, format openers, news
| open and close, never back to back, never a line twice in 45 minutes, never
| on a chat reply, at night station IDs only.
|
*/

beforeEach(function () {
    Storage::fake('local');
    $this->imagingDir = storage_path('framework/testing/imaging-'.uniqid());
    config(['tv.commentary.enabled' => true, 'tv.commentary.repeat_hours' => 0, 'tv.commentary.spool.filler_below_seconds' => 10, 'tv.commentary.parts.sentences' => 5,
        'tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00',
        'tv.commentary.imaging.dir' => $this->imagingDir]);
});

afterEach(function () {
    File::deleteDirectory($this->imagingDir);
});

/**
 * A library in the imaging dir: `$lines` per category, each line one item whose file is `$source` (or a stub).
 *
 * @param  array<string, int>  $lines
 */
function imagingLibrary(string $dir, array $lines, ?string $source = null): void
{
    File::ensureDirectoryExists($dir.'/files');
    $items = [];

    foreach ($lines as $category => $count) {
        for ($n = 1; $n <= $count; $n++) {
            $id = $category.'-'.$n;
            $source === null ? file_put_contents($dir.'/files/'.$id.'.opus', 'stub') : copy($source, $dir.'/files/'.$id.'.opus');
            $items[] = ['id' => $id.'-a', 'line' => $id, 'category' => $category, 'file' => 'files/'.$id.'.opus', 'seconds' => 4.0];
        }
    }

    file_put_contents($dir.'/manifest.json', json_encode(['items' => $items]));
}

function imagingClip(string $kind, ?string $topic = null, ?CarbonImmutable $airsAt = null, float $seconds = 10.0): CasterClip
{
    $script = casterScript($kind, ScenePhase::BREAK);
    $script->forceFill(['topic' => $topic, 'airs_at' => $airsAt])->save();

    return app(ClipSpool::class)->store($script, casterOpus(), $seconds, [$seconds / 2, $seconds], CarbonImmutable::now());
}

/** Imaging of the config with a fixed dice roll. */
function imagingRules(float $roll = 0.0): Imaging
{
    config(['tv.commentary.imaging.enabled' => true]);

    return new Imaging((array) config('tv.commentary.imaging'), 24000, fn (): float => $roll);
}

/** The categories chosen for a clip: [opener, closer]. */
function imagingChoice(Imaging $imaging, CasterClip $clip, CarbonImmutable $at, bool $continuation = false): array
{
    $choice = $imaging->choose($clip, $continuation, $at);

    return [$choice['before']['category'] ?? null, $choice['after']['category'] ?? null];
}

it('opens with a station ID, then not again before 15 minutes have passed, then again', function () {
    imagingLibrary($this->imagingDir, ['station' => 6, 'sweeper' => 6]);
    $imaging = imagingRules(0.99);
    $at = CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC');

    expect(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at)[0])->toBe('station')
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at->addMinutes(14))[0])->toBeNull()
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at->addMinutes(15))[0])->toBe('station');
});

it('puts a sweeper only between two talk clips, on the configured share, never after a chat reply', function () {
    imagingLibrary($this->imagingDir, ['station' => 3, 'sweeper' => 8]);
    $at = CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC');
    config(['tv.commentary.imaging.sweeper_share' => 0.35]);

    $imaging = imagingRules(0.3);
    imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at);
    expect(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at->addMinute())[0])->toBe('sweeper')
        // A chat reply between two talk clips: no imaging on it, and the next talk clip is no "between two talk clips".
        ->and(imagingChoice($imaging, imagingClip(CasterScript::CHAT), $at->addMinutes(2))[0])->toBeNull()
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at->addMinutes(3))[0])->toBeNull();

    // A roll above the share: no sweeper.
    $imaging = imagingRules(0.4);
    imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at);
    expect(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at->addMinute())[0])->toBeNull();
});

it('never dresses a chat reply, a live moment, a later part of a story or a block, even with a station ID due', function () {
    imagingLibrary($this->imagingDir, ['station' => 3, 'sweeper' => 3]);
    $imaging = imagingRules(0.0);
    $at = CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC');
    $block = imagingClip(CasterScript::SCENE);
    $block->forceFill(['block' => ['dressed' => true, 'scripts' => [$block->caster_script_id]]])->save();

    expect(imagingChoice($imaging, imagingClip(CasterScript::CHAT), $at))->toBe([null, null])
        ->and(imagingChoice($imaging, imagingClip(CasterScript::LIVE), $at))->toBe([null, null])
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at, continuation: true))->toBe([null, null])
        ->and(imagingChoice($imaging, $block, $at))->toBe([null, null])
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $at)[0])->toBe('station');
});

it('wraps a bulletin in news open and news close, the headlines at half past, and the next clip opens without a jingle', function () {
    imagingLibrary($this->imagingDir, ['station' => 3, 'news' => 2, 'headlines' => 2, 'back-announce' => 2, 'rubric-liga' => 2]);
    $imaging = imagingRules(0.0);
    $at = CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC');

    expect(imagingChoice($imaging, imagingClip(CasterScript::BULLETIN, airsAt: $at), $at))->toBe(['news', 'back-announce'])
        // Never back to back: the rubric right after the news close (10 s of speech, 4 + 4 s of jingles) gets no opener.
        ->and(imagingChoice($imaging, imagingClip(CasterScript::RUBRIC, 'liga'), $at->addSeconds(20))[0])->toBeNull()
        ->and(imagingChoice($imaging, imagingClip(CasterScript::BULLETIN, airsAt: $at->addMinutes(30)), $at->addMinutes(30)))->toBe(['headlines', 'back-announce']);
});

it('opens a rubric with its format opener where the library has one, else with the normal rules', function () {
    imagingLibrary($this->imagingDir, ['station' => 3, 'sweeper' => 3, 'rubric-liga' => 2, 'opener-tribunal' => 2, 'coming-up' => 2]);
    $imaging = imagingRules(0.99);
    $at = CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC');

    expect(imagingChoice($imaging, imagingClip(CasterScript::RUBRIC, 'liga'), $at)[0])->toBe('rubric-liga')
        ->and(imagingChoice($imaging, imagingClip(CasterScript::RUBRIC, 'tribunal'), $at->addMinutes(5))[0])->toBe('opener-tribunal')
        ->and(imagingChoice($imaging, imagingClip(CasterScript::TEASER), $at->addMinutes(6))[0])->toBe('coming-up')
        // No opener for this format: a station ID is due (the format opener at +5 named the station), so it opens with one.
        ->and(imagingChoice($imaging, imagingClip(CasterScript::RUBRIC, 'friedhof'), $at->addMinutes(19))[0])->toBeNull()
        ->and(imagingChoice($imaging, imagingClip(CasterScript::RUBRIC, 'friedhof'), $at->addMinutes(21))[0])->toBe('station');
});

it('plays only station IDs in the quiet hours, no news open, no sweeper', function () {
    imagingLibrary($this->imagingDir, ['station' => 4, 'sweeper' => 4, 'news' => 2, 'back-announce' => 2]);
    $imaging = imagingRules(0.0);
    $night = CarbonImmutable::parse('2026-10-12 03:00:00', 'Europe/Berlin');

    expect(imagingChoice($imaging, imagingClip(CasterScript::BULLETIN, airsAt: $night), $night))->toBe(['station', null])
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $night->addMinutes(5)))->toBe([null, null])
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $night->addMinutes(10)))->toBe([null, null])
        ->and(imagingChoice($imaging, imagingClip(CasterScript::SCENE), $night->addMinutes(16)))->toBe(['station', null]);
});

it('never plays the same line twice within 45 minutes: a category used up gives nothing', function () {
    imagingLibrary($this->imagingDir, ['sweeper' => 3]);
    $imaging = imagingRules(0.0);
    $at = CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC');
    $played = [];

    for ($minute = 0; $minute < 44; $minute += 2) {
        $choice = $imaging->choose(imagingClip(CasterScript::SCENE), false, $at->addMinutes($minute));
        if ($choice['before'] !== null) {
            $played[] = [$choice['before']['line'], $minute];
        }
    }

    expect(array_column($played, 0))->toHaveCount(3)->toEqual(array_unique(array_column($played, 0)));

    $later = $imaging->choose(imagingClip(CasterScript::SCENE), false, $at->addMinutes(48));
    expect($later['before']['line'] ?? null)->toBe($played[0][0]);
});

it('keeps every rule over a simulated hour of programme', function () {
    imagingLibrary($this->imagingDir, ['station-long' => 12, 'station-short' => 12, 'station' => 14, 'sweeper' => 28, 'liner' => 14, 'news-open' => 6, 'headlines' => 4,
        'news-close' => 6, 'power-intro' => 10, 'promo-tribunal' => 1, 'promo-friedhof' => 1, 'promo' => 4, 'coming-up' => 6,
        'opener-tribunal' => 2, 'opener-friedhof' => 2, 'opener-nachrichten_talk' => 2, 'time-day' => 4]);
    config(['tv.commentary.spool.max_seconds' => 100_000, 'tv.commentary.spool.max_clip_seconds' => 600, 'tv.commentary.spool.long_clip_seconds' => []]);
    mt_srand(21);
    $imaging = new Imaging((array) config('tv.commentary.imaging'), 24000, fn (): float => mt_rand(0, 999) / 1000);
    $start = CarbonImmutable::parse('2026-10-12 12:00:00', 'Europe/Berlin');
    // The broadcast clock: bulletins at :00 and :30, the news talk right after the :00 news, rubrics at :15 and :45,
    // a teaser five minutes before each rubric and before the :30 headlines; between them talk, every fifth clip a chat reply.
    $slots = [0 => [CasterScript::BULLETIN, null, 150], 15 * 60 => [CasterScript::RUBRIC, 'tribunal', 180], 30 * 60 => [CasterScript::BULLETIN, null, 90], 45 * 60 => [CasterScript::RUBRIC, 'friedhof', 180]];
    $teasers = [10 * 60 => 'tribunal', 25 * 60 => 'headlines', 40 * 60 => 'friedhof'];
    $jingles = [];
    $log = [];
    $t = 0;
    $n = 0;

    while ($t < 3600) {
        $slotAt = collect(array_keys($slots))->first(fn (int $at): bool => $at <= $t);
        $teaserAt = collect(array_keys($teasers))->first(fn (int $at): bool => $at <= $t);
        if ($slotAt !== null) {
            [$kind, $topic, $seconds] = $slots[$slotAt];
            unset($slots[$slotAt]);
        } elseif ($teaserAt !== null) {
            [$kind, $topic, $seconds] = [CasterScript::TEASER, $teasers[$teaserAt], 15];
            unset($teasers[$teaserAt]);
        } else {
            [$kind, $topic, $seconds] = ++$n % 5 === 0 ? [CasterScript::CHAT, null, 15] : [CasterScript::SCENE, null, 40];
        }

        $now = $start->addSeconds($t);
        $clip = imagingClip($kind, $topic, $kind === CasterScript::BULLETIN ? $now : null, $seconds);
        $choice = $imaging->choose($clip, false, $now);
        $end = $t + $seconds + ($choice['before']['seconds'] ?? 0) + ($choice['after']['seconds'] ?? 0);
        $log[] = ['t' => $t, 'kind' => $kind, 'topic' => $topic, 'before' => $choice['before'], 'after' => $choice['after']];

        if ($choice['before'] !== null) {
            $jingles[] = ['from' => $t, 'to' => $t + $choice['before']['seconds'], 'item' => $choice['before']];
        }
        if ($choice['after'] !== null) {
            $jingles[] = ['from' => $end - $choice['after']['seconds'], 'to' => $end, 'item' => $choice['after']];
        }

        $intro = $imaging->powerIntro($clip, $start->addSeconds($end));
        if ($intro !== null) {
            $jingles[] = ['from' => $end, 'to' => $end + $intro['seconds'], 'item' => $intro];
        }

        // The news talk follows the :00 news three seconds later; everything else after a music gap of 25 s.
        if ($kind === CasterScript::BULLETIN && $t < 60) {
            $slots = [$end + 3 => [CasterScript::RUBRIC, 'nachrichten_talk', 240]] + $slots;
            ksort($slots);
        }
        $t = $end + ($kind === CasterScript::BULLETIN && $t < 60 ? 3 : 25);
    }

    $categories = array_count_values(array_map(fn (array $jingle): string => $jingle['item']['category'], $jingles));
    $stations = array_values(array_map(fn (array $jingle): int => $jingle['from'], array_filter($jingles, fn (array $jingle): bool => Imaging::namesStation($jingle['item']['category']))));
    $lastLine = [];

    foreach ($jingles as $index => $jingle) {
        // Never two jingles back to back, never a line twice within 45 minutes.
        if ($index > 0) {
            expect($jingle['from'] - $jingles[$index - 1]['to'])->toBeGreaterThanOrEqual(12);
        }
        if (isset($lastLine[$jingle['item']['line']])) {
            expect($jingle['from'] - $lastLine[$jingle['item']['line']])->toBeGreaterThanOrEqual(45 * 60);
        }
        $lastLine[$jingle['item']['line']] = $jingle['from'];
    }

    foreach ($log as $entry) {
        match ($entry['kind']) {
            CasterScript::CHAT => expect([$entry['before'], $entry['after']])->toBe([null, null]),
            CasterScript::BULLETIN => expect([$entry['before']['category'] ?? null, $entry['after']['category'] ?? null])->toBeIn([['news-open', 'news-close'], ['headlines', 'news-close']]),
            CasterScript::RUBRIC => expect($entry['before']['category'] ?? null)->toBe($entry['topic'] === 'nachrichten_talk' ? null : 'opener-'.$entry['topic']),
            CasterScript::TEASER => expect($entry['before']['category'] ?? null)->toBe($entry['topic'] === 'headlines' ? 'coming-up' : 'promo-'.$entry['topic']),
            default => null,
        };
    }

    $stationGaps = array_map(fn (int $a, int $b): int => $b - $a, array_slice($stations, 0, -1), array_slice($stations, 1));

    expect(max($stationGaps))->toBeLessThanOrEqual(16 * 60)
        ->and($categories['station-long'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and(($categories['sweeper'] ?? 0) + ($categories['liner'] ?? 0))->toBeGreaterThanOrEqual(5)
        ->and($categories['power-intro'] ?? 0)->toBeGreaterThanOrEqual(2);

    $this->imagingHour = ['categories' => $categories, 'stations_at_minute' => array_map(fn (int $at): float => round($at / 60, 1), $stations), 'clips' => count($log)];
});

it('puts the jingle in front of a scene clip on the timeline in place of the stinger, the speech and its sentence ends behind it', function () {
    $jingle = resource_path('audio/idents/station-ident.opus');
    imagingLibrary($this->imagingDir, ['station' => 2], $jingle);
    config(['tv.commentary.imaging.enabled' => true, 'tv.commentary.idents.enabled' => true, 'tv.commentary.idents.kinds' => ['scene' => 'ident']]);
    $clip = imagingClip(CasterScript::SCENE);
    Process::fake(["*'pipe:0' '-ac'*" => Process::result(output: str_repeat("\1\0", 24000 * 10))]);

    $director = SceneDirector::fromConfig(app(ClipSpool::class));
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    for ($t = $start; $t <= $start + 30 && $director->clip() === null; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);
    }

    $jingleSeconds = Pcm::seconds((string) Process::run(['ffmpeg', '-nostdin', '-loglevel', 'error', '-i', $jingle, '-ac', '1', '-ar', '24000', '-f', 's16le', 'pipe:1'])->output(), 24000);
    $timeline = $director->timeline;

    expect($director->clip()?->is($clip))->toBeTrue()
        ->and($director->imagingOnAir())->toBe(['station'])
        ->and($director->ident())->toBeNull()
        ->and((float) $timeline->endsAt() - (float) $timeline->startsAt())->toEqualWithDelta($jingleSeconds + 0.3 + 10.0, 0.05);
});

it('leaves a chat reply on the timeline exactly as it came, with a library uploaded', function () {
    imagingLibrary($this->imagingDir, ['station' => 2], resource_path('audio/idents/station-ident.opus'));
    config(['tv.commentary.imaging.enabled' => true, 'tv.commentary.idents.enabled' => false]);
    imagingClip(CasterScript::CHAT);
    Process::fake(["*'pipe:0' '-ac'*" => Process::result(output: str_repeat("\1\0", 24000 * 10))]);

    $director = SceneDirector::fromConfig(app(ClipSpool::class));
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    for ($t = $start; $t <= $start + 30 && $director->clip() === null; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);
    }

    expect($director->clip()?->kind)->toBe(CasterScript::CHAT)
        ->and($director->imagingOnAir())->toBe([])
        ->and((float) $director->timeline->endsAt() - (float) $director->timeline->startsAt())->toEqualWithDelta(10.0, 0.01);
});
