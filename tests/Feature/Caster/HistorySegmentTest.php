<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\HistoryScripts;
use App\Support\Caster\InvalidClip;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: "Markus erzählt vom Bauernkrieg" and the segments on air
|--------------------------------------------------------------------------
|
| User 2026-10-11: "Ein Gag wäre es, wenn er ab und an mal etwas Längeres
| über die Bauernkriege erzählen würde." Built from the fact file alone (every
| fact with its source, no model): 6 to 12 sentences, 60 to 120 seconds, every
| 45 to 90 minutes, no episode twice a day. On air a segment comes after the
| live clips, on calm slots only, one at a time.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.history.enabled' => true, 'tv.commentary.news.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 15:00:00', 'Europe/Berlin'));
});

/**
 * Every spoken line of the fact file (facts, intros, outros, twists).
 *
 * @return list<string>
 */
function casterHistoryKnownLines(): array
{
    $data = HistoryScripts::data();
    $lines = [];

    foreach ([...$data['intros'], ...$data['outros']] as $text) {
        array_push($lines, ...ScriptGuard::lines($text));
    }

    foreach ($data['episodes'] as $episode) {
        foreach ($episode['facts'] as $fact) {
            array_push($lines, ...ScriptGuard::lines($fact['text']));
        }
        foreach ($episode['twists'] as $twist) {
            array_push($lines, ...ScriptGuard::lines($twist));
        }
    }

    return $lines;
}

it('gives every fact a source and keeps every episode within the spoken limits, 6 to 12 sentences and 55 to 120 seconds', function () {
    $data = HistoryScripts::data();
    $guard = new ScriptGuard;

    expect($data['episodes'])->toHaveCount(8);

    foreach ($data['episodes'] as $episode) {
        foreach ($episode['facts'] as $fact) {
            expect($fact['source'])->toStartWith('https://de.wikipedia.org/wiki/');
        }

        // Every twist is announced as a joke.
        foreach ($episode['twists'] as $twist) {
            expect($twist)->toMatch('/^(Kleiner Scherz am Rande|Nur so als Witz gemeint|Nur als Witz gemeint|Mal mit einem Augenzwinkern):/u');
        }

        foreach (range(0, 39) as $turn) {
            $script = HistoryScripts::build($episode, $turn, $data);

            expect($guard->check($script, 6, 12, 16))->toBe([])
                ->and(ScriptQueue::estimateSeconds($script))->toBeGreaterThanOrEqual(55.0)->toBeLessThanOrEqual(120.0);
        }
    }
});

it('writes a history segment from the fact file alone: intro, three to five facts in order, a joke, an outro', function () {
    VoiceAgent::fake();

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->whole()->where('kind', CasterScript::HISTORY)->sole();
    $episode = HistoryScripts::data()['episodes'][0];
    $factLines = array_merge(...array_map(fn (array $fact): array => ScriptGuard::lines($fact['text']), $episode['facts']));
    $spokenFacts = array_values(array_intersect(casterWholeSentences($script), $factLines));

    expect($script->facts_hash)->toBe('history:memmingen')
        ->and($script->source)->toBe(CasterScript::SOURCE_PATTERN)
        ->and($script->priority)->toBe(0)
        ->and(array_diff(casterWholeSentences($script), casterHistoryKnownLines()))->toBe([])
        ->and($spokenFacts)->toBe(array_slice($factLines, 0, count($spokenFacts)))
        ->and(count($spokenFacts))->toBeGreaterThanOrEqual(6)
        ->and($script->expires_at->equalTo(CarbonImmutable::now()->addMinutes(120)))->toBeTrue();
    VoiceAgent::assertNeverPrompted();
});

it('tells the next history segment 45 to 90 minutes later, and no episode twice within a day', function () {
    $queue = app(ScriptQueue::class);
    $queue->fillSegments(CarbonImmutable::now());
    $this->travel(44)->minutes();

    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(0);

    $this->travel(47)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(1);

    // Every episode once, then nothing until a day after the first.
    foreach (range(1, 6) as $ignored) {
        $this->travel(91)->minutes();
        $queue->fillSegments(CarbonImmutable::now());
    }
    $this->travel(91)->minutes();

    expect(CasterScript::query()->where('kind', CasterScript::HISTORY)->distinct()->count('facts_hash'))->toBe(8)
        ->and(CasterScript::query()->whole()->where('kind', CasterScript::HISTORY)->count())->toBe(8)
        ->and($queue->fillSegments(CarbonImmutable::now()))->toBe(0);

    $this->travel(24)->hours();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->where('kind', CasterScript::HISTORY)->latest('id')->value('facts_hash'))->toBe('history:memmingen');
});

it('writes no history segment while the switch is off', function () {
    config(['tv.commentary.history.enabled' => false]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0);
});

/**
 * A ready clip of `$kind`, `$seconds` long.
 */
function casterHistoryClip(string $kind, float $seconds = 10.0): CasterClip
{
    $script = casterScript($kind, ScenePhase::BREAK);

    return app(ClipSpool::class)->store($script, casterOpus(), $seconds, [$seconds], CarbonImmutable::now());
}

it('takes a clip of up to long_clip_seconds of its kind, any other kind only up to max_clip_seconds', function () {
    expect(casterHistoryClip(CasterScript::HISTORY, 278.0)->duration_seconds)->toBe(278.0)
        ->and(casterHistoryClip(CasterScript::SCENE, 118.0)->duration_seconds)->toBe(118.0)
        ->and(casterHistoryClip(CasterScript::NEWS, 148.0)->duration_seconds)->toBe(148.0)
        ->and(fn () => casterHistoryClip(CasterScript::SCENE, 131.0))->toThrow(InvalidClip::class)
        ->and(fn () => casterHistoryClip(CasterScript::NEWS, 151.0))->toThrow(InvalidClip::class)
        ->and(fn () => casterHistoryClip(CasterScript::CHAT, 91.0))->toThrow(InvalidClip::class)
        ->and(fn () => casterHistoryClip(CasterScript::HISTORY, 281.0))->toThrow(InvalidClip::class);
});

it('plays a segment after the live clips, on calm slots only, and never two within segment_spacing_minutes', function () {
    $now = CarbonImmutable::now();
    $spool = app(ClipSpool::class);
    $scene = casterHistoryClip(CasterScript::SCENE, 40.0);
    $history = casterHistoryClip(CasterScript::HISTORY, 70.0);
    $news = casterHistoryClip(CasterScript::NEWS, 25.0);
    $live = casterHistoryClip(CasterScript::LIVE, 5.0);

    expect($spool->next(ScenePhase::BREAK, $now)['clip']?->id)->toBe($live->id);
    $spool->played($live);

    // Segments stay on calm slots; a busy slot without a clip of its own takes the scene clip instead of music.
    expect($spool->next(ScenePhase::LIVE_MATCH, $now)['clip']?->id)->toBe($scene->id)
        ->and($spool->next(ScenePhase::AFTER_RESULT, $now)['clip']?->id)->toBe($scene->id)
        ->and($spool->next(ScenePhase::BREAK, $now))->toMatchArray(['source' => 'segment'])
        ->and($spool->next(ScenePhase::NIGHT, $now)['clip']?->id)->toBe($history->id);

    $spool->playing($history);
    $spool->played($history);

    // Within the spacing the scene clip plays; after it, the news segment.
    expect($spool->next(ScenePhase::BREAK, CarbonImmutable::now())['clip']?->id)->toBe($scene->id);
    $this->travel(5)->minutes();
    expect($spool->next(ScenePhase::BREAK, CarbonImmutable::now())['clip']?->id)->toBe($news->id);
});

it('tells every episode long: its facts in order, asides and the other twist between them, 15 to 30 sentences, two to four minutes', function () {
    $data = HistoryScripts::data();
    $guard = new ScriptGuard;
    $known = [...casterHistoryKnownLines(), ...$data['asides']];

    expect(count($data['asides']))->toBeGreaterThanOrEqual(8);

    foreach ($data['asides'] as $aside) {
        expect(preg_match('/\d/', $aside))->toBe(0)
            ->and($guard->check($aside, 1, 1, 16))->toBe([]);
    }

    foreach ($data['episodes'] as $episode) {
        $factLines = array_merge(...array_map(fn (array $fact): array => ScriptGuard::lines($fact['text']), $episode['facts']));
        expect(count($episode['facts']))->toBeGreaterThanOrEqual(10);

        foreach (range(0, 19) as $turn) {
            $script = HistoryScripts::buildLong($episode, $turn, $data);
            $lines = ScriptGuard::lines($script);
            $spokenFacts = array_values(array_intersect($lines, $factLines));

            expect($guard->check($script, 15, 30, 16))->toBe([], "{$episode['id']} turn {$turn}")
                ->and(ScriptQueue::estimateSeconds($script))->toBeGreaterThanOrEqual(120.0)->toBeLessThanOrEqual(240.0)
                ->and(array_diff($lines, $known))->toBe([])
                ->and($spokenFacts)->toBe(array_slice($factLines, 0, count($spokenFacts)))
                ->and(count($spokenFacts))->toBeGreaterThanOrEqual(12)
                ->and(array_intersect($lines, $data['asides']))->not->toBe([])
                // Both twists: one in the middle, one before the outro.
                ->and(count(array_intersect($lines, array_merge(...array_map(ScriptGuard::lines(...), $episode['twists'])))))->toBe(4);
        }
    }
});

it('writes every second history segment long while the share is 0.5, and every one at 1', function () {
    $queue = app(ScriptQueue::class);
    $lengths = [];

    foreach (range(1, 4) as $ignored) {
        $queue->fillSegments(CarbonImmutable::now());
        $lengths[] = count(casterWholeSentences(CasterScript::query()->whole()->where('kind', CasterScript::HISTORY)->latest('id')->firstOrFail()));
        $this->travel(91)->minutes();
    }

    config(['tv.commentary.length.shares.history' => 1.0]);
    $queue->fillSegments(CarbonImmutable::now());
    $lengths[] = count(casterWholeSentences(CasterScript::query()->whole()->where('kind', CasterScript::HISTORY)->latest('id')->firstOrFail()));

    expect(array_map(fn (int $count): bool => $count >= 15, $lengths))->toBe([false, true, false, true, true])
        ->and(max($lengths))->toBeLessThanOrEqual(30);
});

it('counts no segment as airtime ahead or spool target, so a waiting long story never starves the scene clips', function () {
    $queue = app(ScriptQueue::class);
    casterHistoryClip(CasterScript::HISTORY, 240.0);
    $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, str_repeat("Ein langer Satz über den Bauernkrieg.\n", 25), 'history:x', 0, CarbonImmutable::now());
    casterHistoryClip(CasterScript::SCENE, 20.0);

    // 240 s spooled and about 80 s queued of segments count for nothing: the spool stays below its 90 s target.
    expect(app(ClipSpool::class)->readySeconds())->toBe(20.0)
        ->and($queue->airtimeAhead())->toBe(20.0);
});
