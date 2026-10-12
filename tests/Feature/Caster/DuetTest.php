<?php

use App\Models\CasterScript;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\CasterPersonas;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\TournamentSlides;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Prompts\AgentPrompt;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: duets of Markus and Sirius (user 2026-10-12)
|--------------------------------------------------------------------------
|
| Sirius as side commentator: the turn grammar over seeded draws (at most
| two turns in a row, two to five turns, Sirius never opens twice in a row,
| Markus opens about four in five), the rolling duet share, Sirius' cooldown,
| no duet in the quiet hours, the speaker-tagged model answer parsed and kept
| line by line through the queue and the claim API, and the fetcher voicing
| each line with its speaker's profile. With the laravel/ai and HTTP fakes,
| never the network; the clock stands at a weekday afternoon.
|
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'Europe/Berlin'));
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.duet.share' => 0.35,
        'tv.commentary.spool.quiet_hours.from' => '01:00',
        'tv.commentary.spool.quiet_hours.to' => '09:00',
    ]);
});

/** A seeded random source in [0, 1) for the director. */
function duetRandom(int $seed): Closure
{
    $generator = new Randomizer(new Mt19937($seed));

    return fn (): float => $generator->getFloat(0, 1);
}

/** @param  list<string>  $sequence */
function duetSymbols(array $sequence): string
{
    return implode('', array_map(fn (string $speaker): string => $speaker === DuetDirector::SIRIUS ? 'S' : 'M', $sequence));
}

/** A queue whose facts know the league's size only, with the director given. */
function duetQueue(?DuetDirector $director = null): ScriptQueue
{
    $facts = Mockery::mock(SceneFacts::class, [app(TournamentSlides::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $facts->shouldReceive('league')->andReturn(['players' => 21, 'clans' => 3]);

    return new ScriptQueue(app(CasterVoice::class), $facts, duets: $director);
}

/** A model answer for M·S·M: every sentence led by its speaker. */
const DUET_GOOD_ANSWER = "Markus: Die Liga läuft heute wieder rund um die Uhr, und ich sitze hier im Allgäu mit einem Kaffee vor dem Bildschirm.\n"
    ."Sirius: Kaffee um diese Uhrzeit ist ja fast schon Verrat, denn bei mir steht längst ein schönes kühles Altbier aus Düsseldorf auf dem Tisch.\n"
    ."Sirius: Und bevor jetzt einer aus Köln im Chat aufjault, ich habe zur Sicherheit auch noch ein Kölsch danebengestellt.\n"
    .'Markus: Du bist halt ein echter Diplomat, und genau deshalb lassen wir dich heute Abend auch die Turniere moderieren.';

it('draws only sequences the grammar allows over 10 000 seeded draws', function () {
    $director = new DuetDirector(duetRandom(21));
    $last = null;
    [$markusOpens, $seen] = [0, []];

    for ($i = 0; $i < 10_000; $i++) {
        $symbols = duetSymbols($director->sequence($last, null));

        expect(strlen($symbols))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(5)
            ->and($symbols)->not->toMatch('/MMM|SSS/');

        if ($last === DuetDirector::SIRIUS) {
            expect($symbols[0])->toBe('M');
        }

        $markusOpens += $symbols[0] === 'M' ? 1 : 0;
        $seen[$symbols] = true;
        $last = $symbols[0] === 'S' ? DuetDirector::SIRIUS : DuetDirector::MARKUS;
    }

    // "mal hin und her, dann mal einmal Markus mit zweimal Sirius": every pattern of the table turns up.
    expect($markusOpens / 10_000)->toBeGreaterThan(0.75)->toBeLessThan(0.85)
        ->and(array_keys($seen))->toContain('MS', 'MSM', 'MSS', 'SM', 'MSMS', 'MMS');
});

it('ignores a configured pattern that breaks the run or turn limits', function () {
    config(['tv.commentary.duet.patterns' => ['MSSS' => 100, 'MMMS' => 100, 'MSMSMS' => 100, 'M' => 100, 'MS' => 1]]);

    expect(array_keys((new DuetDirector)->weights(null, null)))->toBe(['MS']);
});

it('keeps the rolling duet share between 30 and 40 percent over 10 000 segments', function () {
    $random = duetRandom(7);
    [$recent, $duets] = [[], 0];

    for ($i = 0; $i < 10_000; $i++) {
        $duet = DuetDirector::decide($recent, $random(), 0.35, 0.05, 20);
        array_unshift($recent, $duet);
        $recent = array_slice($recent, 0, 20);
        $duets += $duet ? 1 : 0;

        // Rolling, not only on average: no window of twenty drifts past the band once it is full.
        if ($i >= 20) {
            expect(count(array_filter($recent)) / 20)->toBeGreaterThanOrEqual(0.25)->toBeLessThanOrEqual(0.45);
        }
    }

    expect($duets / 10_000)->toBeGreaterThanOrEqual(0.30)->toBeLessThanOrEqual(0.40)
        ->and(DuetDirector::decide([], 0.0, 0.0, 0.05, 20))->toBeFalse();
});

it('steers Sirius share of the duet seconds into 25 to 35 percent with the cooldown', function () {
    // Model: a Markus turn takes 2.5 sentences, a Sirius turn 1.5 (his range is smaller), 5 s each; the
    // last twelve duets stand for the rolling hour.
    $director = new DuetDirector(duetRandom(99));
    [$window, $last, $total] = [[], null, ['M' => 0.0, 'S' => 0.0]];

    for ($i = 0; $i < 10_000; $i++) {
        $seconds = array_reduce($window, fn (array $sum, array $duet): array => ['M' => $sum['M'] + $duet['M'], 'S' => $sum['S'] + $duet['S']], ['M' => 0.0, 'S' => 0.0]);
        $share = $seconds['M'] + $seconds['S'] > 0 ? $seconds['S'] / ($seconds['M'] + $seconds['S']) : null;
        $symbols = duetSymbols($director->sequence($last, $share));
        $duet = ['M' => substr_count($symbols, 'M') * 12.5, 'S' => substr_count($symbols, 'S') * 7.5];
        $window = array_slice([...$window, $duet], -12);
        $total = ['M' => $total['M'] + $duet['M'], 'S' => $total['S'] + $duet['S']];
        $last = $symbols[0] === 'S' ? DuetDirector::SIRIUS : DuetDirector::MARKUS;
    }

    expect($total['S'] / ($total['M'] + $total['S']))->toBeGreaterThanOrEqual(0.25)->toBeLessThanOrEqual(0.35);
});

it('gives Sirius fewer turns while his share is above the target', function () {
    $turns = function (?float $share): float {
        $director = new DuetDirector(duetRandom(5));
        $count = 0;

        for ($i = 0; $i < 2_000; $i++) {
            $count += substr_count(duetSymbols($director->sequence(DuetDirector::MARKUS, $share)), 'S');
        }

        return $count / 2_000;
    };

    expect($turns(0.6))->toBeLessThan($turns(0.3) - 0.2)
        ->and($turns(0.1))->toBeGreaterThan($turns(0.3));
});

it('measures Sirius share from the duets of the last hour, expired ones left out', function () {
    $queue = app(ScriptQueue::class);
    $now = CarbonImmutable::now();
    $line = 'Das ist ein ganz normaler Satz mit genau zehn Wörtern drin.';
    $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, "{$line}\nSirius sagt dazu auch noch einen Satz mit genau zehn Wörtern.", 'a', 0, $now, speakers: [DuetDirector::MARKUS, DuetDirector::SIRIUS]);
    $old = $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, 'Ein alter Satz von Sirius, der vor zwei Stunden schon gelaufen ist.', 'b', 0, $now, speakers: [DuetDirector::SIRIUS]);
    $old->forceFill(['created_at' => $now->subHours(2)])->save();
    $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, 'Ein verfallener Satz von Sirius, den niemand jemals gehört hat.', 'c', 0, $now, speakers: [DuetDirector::SIRIUS])
        ->update(['status' => CasterScript::EXPIRED]);

    expect((new DuetDirector)->siriusShare($now))->toBe(0.5)
        ->and((new DuetDirector)->siriusShare($now->addHours(3)))->toBeNull();
});

it('never makes a duet in the quiet hours or with the share at zero, and follows the rolling share', function () {
    config(['tv.commentary.duet.share' => 1.0]);
    $always = new DuetDirector(fn (): float => 0.0);

    expect($always->isDue(CarbonImmutable::parse('2026-10-12 03:00', 'Europe/Berlin')))->toBeFalse()
        ->and($always->isDue(CarbonImmutable::now()))->toBeTrue();

    config(['tv.commentary.duet.share' => 0.0]);
    expect($always->isDue(CarbonImmutable::now()))->toBeFalse();

    // Twenty duets in a row: the next one stays Markus alone even with the luckiest draw.
    config(['tv.commentary.duet.share' => 0.35]);
    foreach (range(1, 20) as $i) {
        CasterScript::query()->create(['kind' => CasterScript::SCENE, 'phase' => ScenePhase::BREAK, 'source' => CasterScript::SOURCE_AI, 'priority' => 0,
            'text' => 'x', 'facts_hash' => 'h', 'status' => CasterScript::VOICED, 'estimated_seconds' => 1, 'expires_at' => now()->addHour(),
            'speakers' => [DuetDirector::MARKUS, DuetDirector::SIRIUS]]);
    }
    expect($always->isDue(CarbonImmutable::now()))->toBeFalse();
});

it('writes no duet in the quiet hours, Markus speaks alone', function () {
    config(['tv.commentary.duet.share' => 1.0, 'tv.commentary.scripts.ai_calls_per_fill' => 1, 'tv.commentary.patterns.enabled' => false]);
    VoiceAgent::fake([['text' => "Die Liga läuft heute Nacht ganz ruhig weiter, und die Bretter warten auf euch.\nWer nicht schlafen kann, der schaut einfach in der Lobby vorbei und spielt eine Runde.", 'canon_id' => null, 'mood' => 'fakten']]);

    duetQueue()->fill(CarbonImmutable::parse('2026-10-12 03:00', 'Europe/Berlin'));

    expect(CasterScript::query()->whereNotNull('speakers')->count())->toBe(0)
        ->and(CasterScript::query()->where('source', CasterScript::SOURCE_AI)->count())->toBe(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, 'Wortwechsel'));
});

it('parses speaker-tagged lines into turns, continuation lines included', function () {
    $turns = DuetScripts::parse("**Markus:** Erster Satz von Markus.\nNoch ein Satz ohne Namen davor.\nSIRIUS: Sirius antwortet.\n- Sirius Turm: falsch, aber Sirius.\nMarkus Turm: Markus schließt.");

    expect($turns)->toBe([
        ['speaker' => 'markus', 'text' => 'Erster Satz von Markus. Noch ein Satz ohne Namen davor.'],
        ['speaker' => 'sirius', 'text' => 'Sirius antwortet. falsch, aber Sirius.'],
        ['speaker' => 'markus', 'text' => 'Markus schließt.'],
    ]);
});

it('writes a duet in one call and turns down a draft whose turns break the drawn sequence', function () {
    VoiceAgent::fake([
        ['text' => "Markus: Die Liga läuft heute wieder rund um die Uhr, und ich sitze hier mit einem Kaffee.\nMarkus: Und gleich geht es weiter mit der nächsten Runde für alle, die noch wach sind.", 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => DUET_GOOD_ANSWER, 'canon_id' => null, 'mood' => 'fakten'],
    ]);
    $sequence = [DuetDirector::MARKUS, DuetDirector::SIRIUS, DuetDirector::MARKUS];

    $duet = app(DuetScripts::class)->write($sequence, ScenePhase::BREAK, ['players' => 21]);

    expect($duet['speakers'])->toBe(['markus', 'sirius', 'sirius', 'markus'])
        ->and(explode("\n", $duet['text'])[1])->toStartWith('Kaffee um diese Uhrzeit')
        ->and($duet['text'])->not->toContain('Sirius:');
    VoiceAgent::assertPromptedTimes(2);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "1. Markus: zwei bis vier Sätze\n2. Sirius: zwei bis vier Sätze, reagiert auf die Wortmeldung davor\n3. Markus")
        && str_contains($prompt->prompt, 'folgten nicht der Reihenfolge'));
});

it('puts Markus persona into every TV prompt and Sirius behind it into the duets, every phrase with its source', function () {
    $fixed = app(CasterVoice::class)->fixedPart();
    $system = app(DuetScripts::class)->systemPrompt();

    expect($fixed)->toContain(CasterPersonas::markus())
        ->and($system)->toStartWith($fixed)
        ->and($system)->toContain(CasterPersonas::sirius())
        ->and(CasterPersonas::phrases(CasterPersonas::SIRIUS))->toHaveCount(20)
        // A prompt line with "#" is dropped (CasterVoice::withoutHashes()): the personas carry none.
        ->and(CasterPersonas::markus().CasterPersonas::sirius())->not->toContain('#');

    foreach ([CasterPersonas::MARKUS, CasterPersonas::SIRIUS] as $speaker) {
        expect(count(CasterPersonas::topics($speaker)))->toBeGreaterThanOrEqual(6);

        foreach ([...CasterPersonas::phrases($speaker), ...CasterPersonas::topics($speaker)] as $item) {
            expect($item['source'])->toMatch('/Folge \\d+|YouTube|RSS|Kanon|Soundboard/');
        }
    }
});

it('queues a duet scene with one speaker per line, through its parts and the claim API', function () {
    $token = 'test-token-0123456789abcdef0123456789abcdef';
    config(['tv.commentary.duet.share' => 1.0, 'tv.commentary.duet.patterns' => ['MSMSM' => 1], 'tv.commentary.scripts.ai_calls_per_fill' => 1,
        'tv.commentary.patterns.enabled' => false, 'tv.commentary.parts.sentences' => 3, 'tv.commentary.fetch.token' => $token]);
    VoiceAgent::fake([['text' => "Markus: Die Liga läuft heute wieder rund um die Uhr, und ich sitze hier im Allgäu mit einem Kaffee vor dem Bildschirm.\n"
        ."Sirius: Kaffee um diese Uhrzeit ist ja fast schon Verrat, denn bei mir steht längst ein schönes kühles Altbier aus Düsseldorf auf dem Tisch.\n"
        ."Markus: Na gut, dann trink halt dein Altbier, aber ich bleib heute beim Kaffee, weil ich noch ein paar Turniere moderieren muss.\n"
        ."Sirius: Und bevor jetzt einer aus Köln im Chat aufjault, ich habe zur Sicherheit auch noch ein Kölsch danebengestellt.\n"
        ."Sirius: Man muss ja als Rheinländer beide Seiten bedienen, sonst gibt es am nächsten Stammtisch richtig Ärger mit den Leuten.\n"
        .'Markus: Du bist halt ein echter Diplomat, und genau deshalb lassen wir dich heute Abend auch die Turniere moderieren.', 'canon_id' => null, 'mood' => 'fakten']]);

    duetQueue(new DuetDirector(fn (): float => 0.0))->fill(CarbonImmutable::now());

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "1. Markus: zwei bis vier Sätze\n2. Sirius: zwei bis vier Sätze, reagiert auf die Wortmeldung davor\n3. Markus")
        && str_contains($prompt->prompt, '5. Markus'));
    $parts = CasterScript::query()->whereNotNull('speakers')->orderBy('part_index')->get();

    expect($parts)->toHaveCount(2)
        ->and($parts->pluck('speakers')->all())->toBe([['markus', 'sirius', 'markus'], ['sirius', 'sirius', 'markus']])
        ->and($parts[1]->sentences()[0])->toStartWith('Und bevor jetzt einer aus Köln');

    $this->postJson('/api/caster/claim', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk()->assertJsonPath('script.speakers', ['markus', 'sirius', 'markus']);

    // A follow-up duet sees the last two turns of this one, nothing older.
    expect(array_column((new DuetDirector)->lastTurns(CarbonImmutable::now()), 'speaker'))->toBe(['sirius', 'markus'])
        ->and((new DuetDirector)->lastOpener())->toBe('markus');
});

it('drops a repeated or farewell line of a duet together with its speaker', function () {
    $queue = app(ScriptQueue::class);
    $said = 'Kaffee um diese Uhrzeit ist ja fast schon Verrat, denn bei mir steht längst ein Altbier auf dem Tisch.';
    $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, $said, 'a', 0, CarbonImmutable::now());

    $script = $queue->enqueue(CasterScript::LIVE, ScenePhase::BREAK, implode("\n", [
        'Die Liga läuft heute wieder rund um die Uhr, und ich sitze hier im Allgäu vor dem Bildschirm.',
        $said,
        'Und dann sag ich euch allen für heute ciao und macht es gut da draußen an den Bildschirmen.',
        'Du bist halt ein echter Diplomat, und genau deshalb lassen wir dich heute auch moderieren.',
    ]), 'b', 0, CarbonImmutable::now(), speakers: ['markus', 'sirius', 'sirius', 'markus']);

    expect($script->speakers)->toBe(['markus', 'markus'])
        ->and($script->sentences())->toHaveCount(2)
        ->and($script->sentences()[1])->toStartWith('Du bist halt');
});

it('voices every line with its speaker profile and breathes on a speaker change', function () {
    config([
        'tv.commentary.fetch.token' => 'test-token-0123456789abcdef0123456789abcdef',
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.pause.jitter' => 0.0,
        'tv.commentary.voicestudio.chunk.max_sentences' => 3,
        'tv.commentary.voicestudio.profiles' => ['markus' => 'mmmm1111', 'sirius' => 'ssss2222'],
        'tv.commentary.voicestudio.speaker_pause' => ['min' => 0.3, 'max' => 0.3],
    ]);
    $wav = function (): string {
        $pcm = '';
        for ($i = 0; $i < 12000; $i++) {
            $pcm .= pack('v', ((int) (8000 * sin($i / 8))) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    };
    Http::fake([
        'https://tv.test/api/caster/claim' => Http::response(['script' => ['id' => 9, 'kind' => 'scene',
            'sentences' => ['Markus eins.', 'Markus zwei.', 'Sirius eins.', 'Sirius zwei.', 'Markus drei.'],
            'speakers' => ['markus', 'markus', 'sirius', 'sirius', 'markus']], 'full' => false, 'pronunciation' => []]),
        'https://tv.test/api/caster/scripts/9/clip' => Http::response(['clip' => 1], 201),
        'http://voicestudio.test/generate' => Http::response($wav(), 200, ['Content-Type' => 'audio/wav']),
    ]);
    Process::fake(['*' => Process::result(output: 'OggS-fake')]);

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED);

    $calls = [];
    Http::assertSent(function (Request $request) use (&$calls): bool {
        if ($request->url() === 'http://voicestudio.test/generate') {
            $fields = collect($request->data())->pluck('contents', 'name');
            $calls[] = $fields['profile_id'].' '.$fields['text'];
        }

        return true;
    });

    // Three chunks may hold three sentences, yet a chunk never spans two speakers.
    expect($calls)->toBe(['mmmm1111 Markus eins. Markus zwei.', 'ssss2222 Sirius eins. Sirius zwei.', 'mmmm1111 Markus drei.']);

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://tv.test/api/caster/scripts/9/clip') {
            return false;
        }

        $ends = array_map('floatval', explode(',', $request->header('X-Caster-Sentence-Ends')[0]));

        // Each chunk 0.5 s of tone; the speaker-change breath (0.3 s) between every two of them.
        return count($ends) === 3 && abs($ends[1] - $ends[0] - 0.8) < 0.03 && abs($ends[2] - $ends[1] - 0.8) < 0.03;
    });
});
