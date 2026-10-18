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
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: "Markus erzählt vom Bauernkrieg" and the segments on air
|--------------------------------------------------------------------------
|
| User 2026-10-11: "Ein Gag wäre es, wenn er ab und an mal etwas Längeres
| über die Bauernkriege erzählen würde." Since 2026-10-12 the league voice
| narrates each episode freely from its facts (every fact with its source);
| the pattern episode (facts and a twist, no fixed intro, aside or outro)
| stands in only when the voice gives nothing. Every 45 to 90 minutes, no
| episode twice a day. On air a segment comes after the live clips, on calm
| slots only, one at a time.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.history.enabled' => true, 'tv.commentary.news.enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 15:00:00', 'Europe/Berlin'));
});

/**
 * Every spoken line of the fact file (facts and twists).
 *
 * @return list<string>
 */
function casterHistoryKnownLines(): array
{
    $data = HistoryScripts::data();
    $lines = [];

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

/**
 * Two drafts the voice turns down, so it "gives nothing" and the pattern episode may stand in. A fragment no longer
 * does: since 3b76ec2c ("No style verdict refuses a text any more") only what breaks the medium is refused, here a link
 * the voice would read out literally (VoiceGuard::URL).
 *
 * @return list<array{text: string, canon_id: null, mood: string}>
 */
function historyRejectedDrafts(): array
{
    $draft = ['text' => "Die ganze Geschichte steht unter https://example.com in aller Ausführlichkeit für euch bereit.\nLest sie dort nach, sie lohnt sich wirklich sehr.", 'canon_id' => null, 'mood' => 'hype'];

    return [$draft, $draft];
}

it('gives every fact a source and keeps every pattern episode within the spoken limits, 6 to 12 sentences and 50 to 120 seconds', function () {
    $data = HistoryScripts::data();
    $guard = new ScriptGuard;

    expect($data['episodes'])->toHaveCount(8)
        ->and(array_keys($data))->not->toContain('intros')->not->toContain('outros')->not->toContain('asides');

    foreach ($data['episodes'] as $episode) {
        foreach ($episode['facts'] as $fact) {
            expect($fact['source'])->toStartWith('https://de.wikipedia.org/wiki/');
        }

        // No stock announcement in front of a twist any more (user 2026-10-12: "Er wiederholt Phrasen 'mal mit einem Augenzwinkern'").
        foreach ($episode['twists'] as $twist) {
            expect($twist)->not->toMatch('/Scherz am Rande|als Witz gemeint|Augenzwinkern/u');
        }

        foreach (range(0, 39) as $turn) {
            $script = HistoryScripts::build($episode, $turn);

            expect($guard->check($script, 6, 12, 32))->toBe([])
                ->and(ScriptQueue::estimateSeconds($script))->toBeGreaterThanOrEqual(50.0)->toBeLessThanOrEqual(120.0);
        }
    }
});

it('lets the league voice narrate the episode freely from its facts, sources as context, no fixed intro or outro', function () {
    $story = implode("\n", [
        'Stellt euch mal das Jahr fünfzehnhundertfünfundzwanzig vor, als die Bauern in Memmingen endgültig genug hatten.',
        'Die haben sich zusammengesetzt und ihre Forderungen aufgeschrieben, und das ganz ohne Formular vom Amt.',
        'Zwölf Artikel sind dabei herausgekommen, und die waren damals so etwas wie ein Freiheitsmanifest.',
        'Heute würde man so etwas wohl erst einmal von drei Ausschüssen prüfen lassen, bevor es überhaupt jemand lesen darf.',
        'Die Obrigkeit fand das natürlich überhaupt nicht lustig, und die Steuern blieben trotzdem fällig.',
        'Und genau deshalb erzähl ich euch das, weil sich manche Dinge in fünfhundert Jahren kaum verändert haben.',
    ]);
    VoiceAgent::fake([['text' => $story, 'canon_id' => null, 'mood' => 'zitat']]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->whole()->where('kind', CasterScript::HISTORY)->sole();
    $episode = HistoryScripts::data()['episodes'][0];
    expect($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->facts_hash)->toBe('history:memmingen')
        ->and(casterWholeSentences($script))->toBe(ScriptGuard::lines($story));
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, str_replace("\n", ' ', $episode['facts'][0]['text']))
        && str_contains($prompt->prompt, 'de.wikipedia.org')
        && str_contains($prompt->prompt, $episode['title'])
        && str_contains($prompt->prompt, 'kein „gleich geht es weiter“')
        && str_contains($prompt->prompt, 'Dein Kniff für die Pointe diesmal:'));
});

it('writes the pattern episode when the voice gives nothing: three to five facts in order and a twist, nothing fixed around it', function () {
    VoiceAgent::fake(historyRejectedDrafts());

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
        // A history episode does not expire (user 2026-10-18: only the news, Sport und Wetter are bound to their time).
        ->and($script->expires_at->greaterThan(CarbonImmutable::now()->addYears(9)))->toBeTrue();
});

it('tells a fragment take as it comes: no style verdict refuses it any more, the pattern episode does not stand in (user 2026-10-15)', function () {
    VoiceAgent::fake([['text' => "Hey!\nLäuft.", 'canon_id' => null, 'mood' => 'hype']]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->whole()->where('kind', CasterScript::HISTORY)->sole();
    expect($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->text)->toBe("Hey!\nLäuft.");
    VoiceAgent::assertPromptedTimes(1);
});

it('queues no pattern episode while the pattern fallback is off (the default): the music plays', function () {
    config(['tv.commentary.patterns.enabled' => false]);
    VoiceAgent::fake(historyRejectedDrafts());

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0)
        ->and(CasterScript::query()->count())->toBe(0);
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
    config(['tv.commentary.spool.max_clip_seconds' => 90, 'tv.commentary.spool.max_clip_bytes' => 3 * 1024 * 1024, 'tv.commentary.spool.long_clip_seconds' => ['scene' => 130, 'news' => 150, 'history' => 280, 'bulletin' => 420, 'rubric' => 130]]);
    expect(casterHistoryClip(CasterScript::HISTORY, 278.0)->duration_seconds)->toBe(278.0)
        ->and(casterHistoryClip(CasterScript::SCENE, 118.0)->duration_seconds)->toBe(118.0)
        ->and(casterHistoryClip(CasterScript::NEWS, 148.0)->duration_seconds)->toBe(148.0)
        ->and(fn () => casterHistoryClip(CasterScript::SCENE, 131.0))->toThrow(InvalidClip::class)
        ->and(fn () => casterHistoryClip(CasterScript::NEWS, 151.0))->toThrow(InvalidClip::class)
        ->and(fn () => casterHistoryClip(CasterScript::CHAT, 91.0))->toThrow(InvalidClip::class)
        ->and(fn () => casterHistoryClip(CasterScript::HISTORY, 281.0))->toThrow(InvalidClip::class);
});

it('plays a segment in any slot after the live clips, and never two within segment_spacing_minutes (user 2026-10-15)', function () {
    $now = CarbonImmutable::now();
    $spool = app(ClipSpool::class);
    $scene = casterHistoryClip(CasterScript::SCENE, 40.0);
    $history = casterHistoryClip(CasterScript::HISTORY, 70.0);
    $news = casterHistoryClip(CasterScript::NEWS, 25.0);
    $live = casterHistoryClip(CasterScript::LIVE, 5.0);

    expect($spool->next(ScenePhase::BREAK, $now)['clip']?->id)->toBe($live->id);
    $spool->played($live);

    // A long segment no longer waits for a calm slot (user 2026-10-15: "was generiert wurde, geht auf Sendung"):
    // the history segment plays over a busy match slot as over the night, before any scene clip.
    expect($spool->next(ScenePhase::LIVE_MATCH, $now))->toMatchArray(['source' => 'segment'])
        ->and($spool->next(ScenePhase::LIVE_MATCH, $now)['clip']?->id)->toBe($history->id)
        ->and($spool->next(ScenePhase::NIGHT, $now)['clip']?->id)->toBe($history->id);

    $spool->playing($history);
    $spool->played($history);

    // Within the spacing the scene clip plays; after it, the news segment.
    expect($spool->next(ScenePhase::BREAK, CarbonImmutable::now())['clip']?->id)->toBe($scene->id);
    $this->travel(5)->minutes();
    expect($spool->next(ScenePhase::BREAK, CarbonImmutable::now())['clip']?->id)->toBe($news->id);
});

it('tells every pattern episode long: its facts in order, the other twist between them, 15 to 30 sentences, at least one and a half minutes', function () {
    $data = HistoryScripts::data();
    $guard = new ScriptGuard;
    $known = casterHistoryKnownLines();

    foreach ($data['episodes'] as $episode) {
        $factLines = array_merge(...array_map(fn (array $fact): array => ScriptGuard::lines($fact['text']), $episode['facts']));
        expect(count($episode['facts']))->toBeGreaterThanOrEqual(10);

        foreach (range(0, 19) as $turn) {
            $script = HistoryScripts::buildLong($episode, $turn);
            $lines = ScriptGuard::lines($script);
            $spokenFacts = array_values(array_intersect($lines, $factLines));

            expect($guard->check($script, 15, 30, 32))->toBe([], "{$episode['id']} turn {$turn}")
                ->and(ScriptQueue::estimateSeconds($script))->toBeGreaterThanOrEqual(85.0)->toBeLessThanOrEqual(240.0)
                ->and(array_diff($lines, $known))->toBe([])
                ->and($spokenFacts)->toBe(array_slice($factLines, 0, count($spokenFacts)))
                ->and(count($spokenFacts))->toBeGreaterThanOrEqual(12)
                // Both twists: one in the middle, one at the end.
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
