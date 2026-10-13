<?php

use App\Models\CasterScript;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\ShowScripts;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: long shows fill their window (P21)
|--------------------------------------------------------------------------
|
| User 2026-10-13: "wenn im Programm steht TalkShow von 19:00 bis 19:30, dann
| muss sie auch 30 min gehen!" A scheduled show starts at :03 right after
| the news and owns its window (`slot_minutes`); it is written segment by
| segment until it fills the window, one frame from the opener to the
| back-announce. With the laravel/ai fake, never the network.
|
*/

beforeEach(function () {
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        'tv.commentary.programme.rubrics.rotation' => ['chat'],
        'tv.commentary.programme.shows.enabled' => true,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
        'esports.voice.monthly_budget_usd' => 100,
        // A six-minute window for the fakes: the same code fills thirty.
        'tv.commentary.programme.shows.formats.tribunal.slot_minutes' => 6,
        'tv.commentary.programme.shows.formats.tribunal.seconds' => ['min' => 20, 'max' => 400],
        'tv.commentary.programme.shows.formats.tribunal.sentences' => ['min' => 6, 'max' => 40],
        'tv.commentary.programme.shows.formats.tribunal.segment_seconds_min' => 20,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 19:10:00', 'Europe/Berlin'));
});

/** One fake segment: eight whole German sentences, new ones on every call (a repeated line leaves a later segment). */
function longShowSegment(int $call): array
{
    $subjects = ['Der Angeklagte', 'Der Richter', 'Die Verteidigung', 'Der Chat', 'Die Anklage', 'Das Gericht', 'Der Zeuge', 'Die Jury'];
    $adverbs = ['gründlich', 'gewaltig', 'peinlich', 'herrlich', 'kolossal', 'gnadenlos', 'meisterhaft', 'vollständig', 'fürchterlich', 'grandios', 'maßlos', 'tragisch', 'spektakulär', 'schamlos'];
    $adverb = $adverbs[$call % count($adverbs)];
    $lines = array_map(fn (string $subject, int $index): string => ($index % 2 === 0 ? 'Markus' : 'Sirius').": {$subject} {$adverb} blamiert, so hat sich das in dieser Verhandlung vor dem versammelten Publikum im Saal angefühlt.", $subjects, array_keys($subjects));

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

it('lets a long show at :03 own its window: the headlines at :30 are skipped, the news at the full hour stays', function () {
    config(['tv.commentary.programme.shows.formats.tribunal.slot_minutes' => 30]);
    $clock = BroadcastClock::fromConfig();
    $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse('2026-10-12 '.$time, 'Europe/Berlin');

    expect($clock->slotAt($at('20:03'))['type'])->toBe(BroadcastClock::RUBRIC)
        ->and($clock->slotAt($at('20:31'))['starts_at']->equalTo($at('20:03')))->toBeTrue()
        ->and($clock->slotAt($at('20:33'))['type'])->toBe(BroadcastClock::TALK)
        ->and($clock->slotAt($at('21:00'))['type'])->toBe(BroadcastClock::NEWS)
        ->and(app(Shows::class)->scheduledFor($clock->slotAt($at('20:03'))))->toBe(Shows::TRIBUNAL)
        // Without a window the :03 slot stays talk.
        ->and($clock->slotAt($at('19:03'))['type'])->toBe(BroadcastClock::TALK);
});

it('writes a long show segment by segment until it fills its window, one opener, the back-announce only at the end', function () {
    $calls = 0;
    VoiceAgent::fake(function () use (&$calls): array {
        return longShowSegment($calls++);
    });
    $slot = BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse('2026-10-12 20:05', 'Europe/Berlin'));
    $material = Shows::build('Angeklagt: Fritzi', ['Fritzi'], [], 'x', []);

    $show = app(ShowScripts::class)->write($slot, Shows::TRIBUNAL, $material, CarbonImmutable::now());
    $lines = explode("\n", $show['text']);

    expect($calls)->toBeGreaterThan(2)
        ->and(ScriptQueue::estimateSeconds($show['text']))->toBeGreaterThanOrEqual(0.75 * 360)
        ->and(substr_count($show['text'], 'zwanzig Uhr drei'))->toBe(1)
        ->and(count($lines))->toBe(count($show['speakers']))
        ->and(count($lines))->toBe(count(array_unique($lines)));

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Abschnitt eins') && str_contains($prompt->prompt, 'Es ist der erste')
        && str_contains($prompt->prompt, 'keine Abmoderation') && ! str_contains($prompt->prompt, 'Bisher gesendet (nur'));
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Ihr macht nahtlos weiter') && str_contains($prompt->prompt, 'Bisher gesendet (nur')
        && str_contains($prompt->prompt, 'nächste Fall'));
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Es ist der letzte Abschnitt') && str_contains($prompt->prompt, 'Abmoderation: einer sagt'));
    // Over thirty minutes the personas' running gags came back from nowhere: a long show goes without them.
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->agent->instructions(), "Running Gags\n"));
});

it('drops a long show that stays short of its window, and records why', function () {
    VoiceAgent::fake(fn (): array => longShowSegment(0));
    $slot = BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse('2026-10-12 20:05', 'Europe/Berlin'));

    expect(app(ShowScripts::class)->write($slot, Shows::TRIBUNAL, Shows::build('Angeklagt: Fritzi', ['Fritzi'], [], 'x', []), CarbonImmutable::now()))->toBeNull()
        ->and(DB::table('voice_rejections')->where('channel', ShowScripts::CHANNEL)->pluck('reason')->all())->toContain(ShowScripts::SHORT);
});

it('writes a long show two hours ahead, not held up by the rubric written before it in the hour', function () {
    $calls = 0;
    VoiceAgent::fake(function () use (&$calls): array {
        return longShowSegment($calls++);
    });
    DB::table('chatter_profiles')->insert(['pubkey' => str_repeat('a', 64), 'name' => 'Fritzi', 'last_chat_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('chatter_lines')->insert(['pubkey' => str_repeat('a', 64), 'name' => 'Fritzi', 'message' => 'Ich kaufe ab jetzt nur noch Pizza mit Sats, ehrlich.', 'said_at' => now()->subMinutes(30)->getTimestamp(), 'created_at' => now(), 'updated_at' => now()]);
    config(['tv.commentary.programme.shows.formats.tribunal.write_minutes_ahead' => 120]);
    $queue = app(ScriptQueue::class);

    // 19:10: the rubric of 19:15 is already written; the tribunal of 20:03 does not wait for it to pass.
    CasterScript::query()->create(['kind' => CasterScript::RUBRIC, 'phase' => 'break', 'priority' => 70, 'text' => 'Schon geschrieben.', 'facts_hash' => 'chat:x', 'status' => CasterScript::QUEUED,
        'source' => CasterScript::SOURCE_AI, 'topic' => 'chat', 'estimated_seconds' => 5, 'airs_at' => CarbonImmutable::parse('2026-10-12 19:15', 'Europe/Berlin')->utc(), 'expires_at' => now()->addHour()]);

    expect($queue->fillRubric(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->where('topic', Shows::TRIBUNAL)->value('airs_at')->setTimezone('Europe/Berlin')->format('H:i'))->toBe('20:03');
});

it('buries several coins in a long Shitcoin-Friedhof, one grave per segment', function () {
    config(['tv.commentary.programme.shows.formats.friedhof.graves' => 5]);
    $material = app(Shows::class)->material(Shows::FRIEDHOF, CarbonImmutable::now());

    expect(substr_count($material['data'], 'der Verstorbene: '))->toBe(5)
        ->and($material['keys'])->toHaveCount(5)
        ->and($material['sources'])->toHaveCount(5);
});

it('turns down a segment that opens a third line with the same three words, or takes a tried case up again', function () {
    $material = Shows::build("Angeklagt: Fritzi\nAngeklagt: Hodlbert", ['Fritzi', 'Hodlbert'], [], 'x', []);
    $scripts = app(ShowScripts::class);
    $previous = ['Euer Ehren, das ist grausam, was Fritzi im Chat geschrieben hat.', 'Euer Ehren, das ist grausam, und zwar ohne jede Bewährung.'];
    $segment = fn (string $first): string => implode("\n", ['Markus: '.$first, ...array_slice(explode("\n", longShowSegment(3)['text']), 1)]);

    expect(ShowScripts::opener('[laughter] Euer Ehren, das ist grausam.'))->toBe('euer ehren das')
        ->and($scripts->evaluate(Shows::TRIBUNAL, $material, $segment('Euer Ehren, das ist schon wieder Fritzi, der hier jammert.'), false, $previous, true, false)[1])->toContain(ShowScripts::OPENER, ShowScripts::CASE_AGAIN)
        ->and($scripts->evaluate(Shows::TRIBUNAL, $material, $segment('Nächster Fall, Hodlbert, und der ist noch schlimmer als Fritzi.'), false, $previous, true, false)[1])->toBe([])
        // The closing segment may come back to every case.
        ->and($scripts->evaluate(Shows::TRIBUNAL, $material, $segment('Zum Schluss noch einmal Fritzi, der Held dieses Abends im Saal.'), false, $previous, true, true)[1])->not->toContain(ShowScripts::CASE_AGAIN);

    // The prompt names what is tried and used up.
    expect(ShowScripts::prompt(Shows::TRIBUNAL, $material, [], null, ['number' => 2, 'first' => false, 'final' => false, 'minutes' => 30, 'previous' => $previous]))
        ->toContain('Schon dran (nicht noch einmal als Fall, nur als kurzer Rückgriff): Fritzi')->toContain('„euer ehren das …“');
});
