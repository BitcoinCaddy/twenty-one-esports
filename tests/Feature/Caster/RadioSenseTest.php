<?php

use App\Models\CasterScript;
use App\Support\Caster\CasterPersonas;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\SpokenMemory;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio P8: sentences that make sense (user 2026-10-12)
|--------------------------------------------------------------------------
|
| "Es sind immer noch viel zu viele Sätze, die gar keinen Sinn ergeben."
| Measured on 60 prod scripts: 140 of 288 sentences senseless, most from our
| own code: the queue cut repeats out of the middle (277 of 746 solo scenes
| left with one sentence), the drawn angle turned whole scripts into a
| running metaphor, persona gags and a "rest of the script" list hopped
| topics. These cases pin the fixes.
|
*/

beforeEach(function () {
    config(['tv.commentary.enabled' => true, 'tv.commentary.scripts.materials' => ['league' => 1], 'tv.commentary.repeat_hours' => 24, 'tv.commentary.repeat_phrase_hours' => 6]);
});

const RADIO_SAID = 'Und gleich geht es hier bei uns weiter mit der Liga, also bleibt bitte dran.';

it('ends a script before its first repeated sentence instead of cutting the repeat out of the middle', function () {
    $now = CarbonImmutable::now();
    $memory = new SpokenMemory;
    $memory->remember(RADIO_SAID, $now);
    $script = "Heute Abend sitzen erstaunlich viele Leute in der Lobby, und das an einem Montag.\n".RADIO_SAID."\nDenn wer so treu zuschaut, verdient wirklich jeden einzelnen Block.";

    expect($memory->coherent($script, $now, 1, $cut))->toBe('Heute Abend sitzen erstaunlich viele Leute in der Lobby, und das an einem Montag.')
        ->and($cut)->toBeTrue()
        // Too little left before the repeat: nothing, not a setup without its point.
        ->and($memory->coherent($script, $now, 2))->toBe('')
        ->and($memory->repeated($script, $now))->toBe([RADIO_SAID])
        // The old filter, kept for its own callers, still drops it from the middle.
        ->and($memory->withoutRepeats($script, $now))->toContain('Denn wer so treu');
});

it('queues a news segment as written, its repeated sentence included, and one that would have lost all but one sentence too', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, RADIO_SAID, 'a', 0, $now);

    $cut = $queue->enqueue(CasterScript::NEWS, ScenePhase::BREAK, "Aus Hamburg kommt heute eine Meldung, die man sich merken sollte.\nDie Stadt plant ein neues Amt nur für die Verwaltung der anderen Ämter.\n".RADIO_SAID."\nDas nenne ich mal effiziente Bürokratie, liebe Freunde.", 'b', 0, $now);
    $lost = $queue->enqueue(CasterScript::NEWS, ScenePhase::BREAK, "Aus Bremen kommt heute eine Meldung, bei der man nur staunen kann.\n".RADIO_SAID."\nDas nenne ich mal effiziente Verwaltung, liebe Freunde.", 'c', 0, $now);

    // Nothing a writer produced is cut any more (user 2026-10-18: "Nie mehr verwerfen").
    expect($cut?->text)->toBe("Aus Hamburg kommt heute eine Meldung, die man sich merken sollte.\nDie Stadt plant ein neues Amt nur für die Verwaltung der anderen Ämter.\n".RADIO_SAID."\nDas nenne ich mal effiziente Bürokratie, liebe Freunde.")
        ->and($lost?->sentences())->toHaveCount(3);
});

it('queues a template script as written, its repeated line included', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, RADIO_SAID, 'a', 0, $now);

    $run = $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, RADIO_SAID."\nDer erste Witz handelt von einem Banker, der sein Geld im Kopfkissen sucht.", 'b', 0, $now, source: CasterScript::SOURCE_PATTERN);

    // No repeat filter for any source any more (user 2026-10-18: "Nie mehr verwerfen").
    expect($run?->text)->toBe(RADIO_SAID."\nDer erste Witz handelt von einem Banker, der sein Geld im Kopfkissen sucht.");
});

it('keeps a repeated call of a name in a chat reply', function () {
    $now = CarbonImmutable::now();
    $queue = app(ScriptQueue::class);
    $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, 'El Presidento Ben! Du hast heute schon wieder die beste Frage im ganzen Chat gestellt.', 'a', 0, $now);

    $reply = $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, "El Presidento Ben! Ben, du glaubst also, ein Klima Coin rettet die Welt, und ich glaube an den Weihnachtsmann.\nDer Strom steckt bei Bitcoin im Block, und der hält danach für immer.", 'b', 0, $now, speakers: ['markus', 'sirius']);

    // Queued as written (user 2026-10-18: "Nie mehr verwerfen").
    expect($reply?->speakers)->toBe(['markus', 'sirius'])
        ->and($reply?->text)->toStartWith('El Presidento Ben! Ben, du glaubst also');
});

it('ends a script at its first goodbye, and only skips a goodbye before anything was said', function () {
    expect(ScriptQueue::beforeSignOff("Die Lobby ist rund um die Uhr offen, und jeder kann einsteigen.\nDann sag ich euch allen ciao für heute.\nDenn morgen geht es weiter mit dem nächsten Turnier.", $cut))
        ->toBe('Die Lobby ist rund um die Uhr offen, und jeder kann einsteigen.')
        ->and($cut)->toBeTrue()
        ->and(ScriptQueue::beforeSignOff("Gute Nacht an alle Nachteulen.\nDie Lobby ist rund um die Uhr offen, und jeder kann einsteigen.", $cut))
        ->toBe('Die Lobby ist rund um die Uhr offen, und jeder kann einsteigen.')
        ->and($cut)->toBeFalse();
});

it('counts a phrase as worn only with two words of content and only within repeat_phrase_hours', function () {
    $now = CarbonImmutable::now();
    $memory = new SpokenMemory;
    // "für mich ist das" twice: function words only, no listener hears it as a repeat.
    $memory->remember('Für mich ist das ein ganz normaler Abend in der Lobby unserer Liga.', $now);
    $memory->remember('Für mich ist das schon wieder eine dieser Nachrichten aus Berlin heute.', $now);
    expect($memory->repeated('Für mich ist das die schönste Partie, die ich seit Wochen gesehen habe.', $now))->toBe([]);

    // "zahlt das am Ende" (two words of content) twice within the window: worn.
    $memory->remember('Ich frag mich wirklich, und wer zahlt das am Ende alles wieder für uns.', $now);
    $memory->remember('Bei der Steuer frag ich euch, und wer zahlt das am Ende, genau, ihr selbst.', $now);
    $line = 'Beim neuen Gesetz ist die Frage halt, wer zahlt das am Ende tatsächlich.';
    expect($memory->repeated($line, $now))->toBe([$line])
        // Seven hours later the phrase is free again, while the whole sentence would still count for a day.
        ->and($memory->repeated($line, $now->addHours(7)))->toBe([]);
});

it('asks once more with the repeated sentences when a first scene draft repeats the station, and keeps the second draft', function () {
    app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, RADIO_SAID, 'a', 0, CarbonImmutable::now());
    $fresh = "Die Lobby ist heute Abend voller als jede Behörde am Montagmorgen, und das ganz ohne Wartemarke.\nWer hier ein Spiel sucht, bekommt einen Gegner schneller als einen Termin beim Amt.\nUnd das Beste daran ist, dass niemand dafür auch nur einen einzigen Sat bezahlen muss.";
    VoiceAgent::fake([
        ['punkt' => 'p', 'pointe' => 'q', 'text' => "Die Lobby ist heute Abend voller als jede Behörde am Montagmorgen, und das ganz ohne Wartemarke.\n".RADIO_SAID."\nUnd das Beste daran ist, dass niemand dafür auch nur einen einzigen Sat bezahlen muss.", 'canon_id' => null, 'mood' => 'deadpan'],
        ['punkt' => 'p', 'pointe' => 'q', 'text' => $fresh, 'canon_id' => null, 'mood' => 'deadpan'],
    ]);

    expect(app(CasterVoice::class)->write('scene', ScenePhase::BREAK, ['players' => 34], 3, 8)['text'] ?? null)->toBe($fresh);
    VoiceAgent::assertPromptedTimes(2);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Diese Sätze deines ersten Entwurfs liefen heute schon')
        && str_contains($prompt->prompt, '- '.RADIO_SAID));
});

it('asks a duet once more with the repeated lines when its first draft repeats the station', function () {
    config(['tv.commentary.duet.sentences' => ['markus' => ['min' => 1, 'max' => 3], 'sirius' => ['min' => 1, 'max' => 3]]]);
    app(ScriptQueue::class)->enqueue(CasterScript::SCENE, ScenePhase::BREAK, RADIO_SAID, 'a', 0, CarbonImmutable::now());
    $fresh = "Markus: Die Lobby ist heute Abend voller als jede Behörde am Montagmorgen, und das ganz ohne Wartemarke.\nSirius: Und trotzdem jammerst du, weil du für deinen Kaffee noch immer keinen Antrag stellen musstest.";
    VoiceAgent::fake([
        ['punkt' => 'p', 'pointe' => 'q', 'text' => "Markus: Die Lobby ist heute Abend voller als jede Behörde am Montagmorgen, und das ganz ohne Wartemarke.\nSirius: ".RADIO_SAID, 'canon_id' => null, 'mood' => 'deadpan'],
        ['punkt' => 'p', 'pointe' => 'q', 'text' => $fresh, 'canon_id' => null, 'mood' => 'deadpan'],
    ]);

    $duet = app(DuetScripts::class)->write([DuetDirector::MARKUS, DuetDirector::SIRIUS], ScenePhase::BREAK, ['players' => 21]);

    expect($duet['speakers'] ?? null)->toBe(['markus', 'sirius'])
        ->and($duet['text'] ?? null)->toContain('keinen Antrag');
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '- '.RADIO_SAID));
});

it('plans a TV bit in fields before its text, and leaves the posts\' schema as it was', function () {
    $tv = array_keys((new VoiceAgent('', null, null, plan: true))->schema(new JsonSchemaTypeFactory));
    $post = array_keys((new VoiceAgent(''))->schema(new JsonSchemaTypeFactory));

    expect($tv)->toBe([...VoiceAgent::PLAN_FIELDS, 'text', 'canon_id', 'mood'])
        ->and($post)->toBe(['text', 'canon_id', 'mood']);
});

it('builds the TV prompt around one point and a punchline, without the posts\' style guide or image-domain angles', function () {
    $system = app(CasterVoice::class)->fixedPart();

    expect($system)->toContain(CasterVoice::BIT)
        ->not->toContain('Stil-Leitfaden')
        ->not->toContain('Der Rest des Skripts ist Unterhaltung')
        ->not->toContain('gegen Spieler und Zuschauer wird nie gestichelt')
        ->and(CasterPersonas::sirius())->not->toContain('beginnt gern mit „Genau“');

    foreach (CasterVoice::ANGLES as $angle) {
        expect($angle)->not->toMatch('/Wetter|Fußball|Bergsteigen|Küche|Bahn|Mining|Allgäu|Naturdoku/u');
    }
});
