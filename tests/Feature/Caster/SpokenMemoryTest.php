<?php

use App\Models\CasterScript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\PatternScripts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpokenMemory;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceGuard;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: no sentence twice, AI only by default (user 2026-10-12)
|--------------------------------------------------------------------------
|
| "ALLE WIEDERHOLUNGEN RAUS!!!" and "KEINE SICH WIEDERHOLENDEN FÜLLSÄTZE
| MEHR: ALLES NUR NOCH MIT KI". Every script of every kind passes the
| no-repeat memory at ScriptQueue::enqueue(); template text and fillers are
| refused while the pattern fallback is off.
|
*/

beforeEach(function () {
    config(['tv.commentary.enabled' => true]);
});

const MEMORY_SENTENCE = 'Und gleich geht es hier bei uns weiter mit der Liga, also bleibt bitte dran.';

it('drops a sentence said within repeat_hours from any later script, and lets it back after the window', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);

    $first = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, MEMORY_SENTENCE."\nDie Lobby ist rund um die Uhr offen, und jeder kann jederzeit einsteigen.", 'a', 0, $now);
    $second = $queue->enqueue(CasterScript::NEWS, ScenePhase::BREAK, "Aus Berlin kommt heute eine Meldung, bei der man nur staunen kann.\n".MEMORY_SENTENCE, 'b', 0, $now->addMinutes(5));

    $third = $queue->enqueue(CasterScript::NEWS, ScenePhase::BREAK, "Aus Hamburg kommt heute eine Meldung, die man sich merken sollte.\nDie Stadt plant ein neues Amt nur für die Verwaltung der anderen Ämter.\n".MEMORY_SENTENCE, 'b2', 0, $now->addMinutes(5));

    // The script ends before the repeat (P8): one sentence left is a setup without its point and is not queued.
    expect($first?->text)->toContain(MEMORY_SENTENCE)
        ->and($second)->toBeNull()
        ->and($third?->text)->toBe("Aus Hamburg kommt heute eine Meldung, die man sich merken sollte.\nDie Stadt plant ein neues Amt nur für die Verwaltung der anderen Ämter.");

    // A script of nothing but repeats is not queued at all.
    expect($queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, MEMORY_SENTENCE, 'c', 50, $now->addMinutes(6)))->toBeNull();

    // After the window, from a fresh memory (the next run of caster:script), it may come back.
    $this->travel(25)->hours();
    expect(app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, MEMORY_SENTENCE, 'd', 0, CarbonImmutable::now())?->text)->toBe(MEMORY_SENTENCE);
});

it('reads its memory from the queue table, so a second run still knows what the first one queued', function () {
    $now = CarbonImmutable::now();
    app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, MEMORY_SENTENCE, 'a', 0, $now);

    expect((new SpokenMemory)->withoutRepeats(MEMORY_SENTENCE, $now->addHour()))->toBe('')
        ->and((new SpokenMemory)->withoutRepeats(MEMORY_SENTENCE, $now->addHours(25)))->toBe(MEMORY_SENTENCE);
});

it('drops a near copy: the same words with a different name or number, or the same first five words', function (string $copy) {
    $memory = new SpokenMemory;
    $now = CarbonImmutable::now();
    $memory->remember('Ada gewinnt die Partie gegen Bob, und das Ergebnis steht jetzt fest.', $now);

    expect($memory->withoutRepeats($copy, $now))->toBe('');
})->with([
    'another name' => ['Cleo gewinnt die Partie gegen Bob, und das Ergebnis steht jetzt fest.'],
    'a digit' => ['Ada gewinnt die Partie 3 gegen Bob, und das Ergebnis steht jetzt fest.'],
    'the same opening' => ['Ada gewinnt die Partie gegen alle Erwartungen und feiert ganz ausgelassen.'],
    'other case and punctuation' => ['ADA gewinnt die Partie gegen Bob und das Ergebnis steht jetzt fest!'],
]);

it('lets a different sentence through, and drops a sentence with a four-word phrase said twice already', function () {
    $memory = new SpokenMemory;
    $now = CarbonImmutable::now();
    $memory->remember('Ich frag mich wirklich, und wer zahlt das am Ende alles wieder für uns.', $now);

    expect($memory->withoutRepeats('Die Lobby ist rund um die Uhr offen, und jeder kann sofort einsteigen.', $now))->not->toBe('');

    // Once said, the phrase may come again once more; then it is worn out.
    $memory->remember('Bei der Steuer frag ich euch, und wer zahlt das am Ende, genau, ihr selbst.', $now);
    expect($memory->withoutRepeats('Beim neuen Gesetz ist die Frage halt, wer zahlt das am Ende tatsächlich.', $now))->toBe('');
});

it('refuses template text and fillers while the pattern fallback is off, and seeds no filler', function () {
    config(['tv.commentary.patterns.enabled' => false]);
    $queue = app(ScriptQueue::class);
    $now = CarbonImmutable::now();

    expect($queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Die Lobby ist rund um die Uhr offen, und jeder kann sofort einsteigen.', 'a', 0, $now, source: CasterScript::SOURCE_PATTERN))->toBeNull()
        ->and($queue->seedFillers($now))->toBe(0)
        ->and($queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Die Lobby ist rund um die Uhr offen, und jeder kann sofort einsteigen.', 'a', 0, $now, source: CasterScript::SOURCE_AI))->not->toBeNull()
        ->and(PatternScripts::enabled())->toBeFalse();
});

it('writes no pattern scene while the fallback is off: when the voice gives nothing, the music plays', function () {
    config(['tv.commentary.patterns.enabled' => false, 'tv.commentary.scripts.ahead_seconds' => 60]);
    VoiceAgent::fake(fn () => ['text' => "Hey!\nLäuft.", 'canon_id' => null, 'mood' => 'hype']);

    app(ScriptQueue::class)->fill(CarbonImmutable::now());

    expect(CasterScript::query()->count())->toBe(0);
});

it('speaks a short canon saying only inside a carrier sentence of at least seven words, its words kept for the fact guard', function () {
    $patterns = app(PatternScripts::class);
    $canon = app(VoiceCanon::class);
    $short = null;
    foreach ($patterns->canonPool() as $id) {
        if (count(preg_split('/\s+/u', trim($canon->find($id)['wording']))) <= 3) {
            $short = $id;
            break;
        }
    }

    expect($short)->not->toBeNull()
        ->and(count($patterns->carriers()))->toBeGreaterThanOrEqual(25);

    $wording = $canon->find((string) $short)['wording'];
    foreach (range(0, 30) as $turn) {
        $line = (string) $patterns->spokenCanon((string) $short, $turn);

        expect($line)->not->toBe(rtrim($wording, '.').'.')
            ->and(count(preg_split('/\s+/u', $line)))->toBeGreaterThanOrEqual(7)
            ->and(preg_match(VoiceGuard::wordingPattern($wording), $line))->toBe(1)
            ->and(app(CasterVoice::class)->guard()->check($line, [], 900, (string) $short))->toBe([], $line);
    }
});
