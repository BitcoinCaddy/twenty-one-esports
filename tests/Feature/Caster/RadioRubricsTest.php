<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\CasterClip;
use App\Models\CasterNews;
use App\Models\CasterPodcastTranscript;
use App\Models\CasterScript;
use App\Models\ChatterLine;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\HistoryScripts;
use App\Support\Caster\Imaging;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\PodcastTranscripts;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\RubricScripts;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the rubrics (plan "TWENTY ONE Radio", P5)
|--------------------------------------------------------------------------
|
| At :15 and :45 a rubric with real material, in turn over the day: the
| Liga-Rubrik (the league's last 24 hours from its own rows), the
| Presseschau (three outlets, one story first), the Podcast-Ecke (the
| newest episode's digest), the Geschichtsstunde (Bauernkrieg) and the
| Chat-Stunde (questions from the chat). Each opens with the ident and a
| line that names it, then 60 to 150 s of content, written ahead and
| started at its slot.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        // The rotation alone; the long formats on their scheduled slots are RadioShowsTest's.
        'tv.commentary.programme.shows.enabled' => false,
        'tv.commentary.programme.rubrics.sirius_chance' => 0.0,
        'tv.commentary.programme.teasers.enabled' => false,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:05:00', 'Europe/Berlin'));
    WorldMemory::forget();
});

/** A league player joined long before the window. */
function rubricsPlayer(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->forceFill(['created_at' => now()->subDays(10)])->save();

    return $user;
}

function rubricsHeadline(string $title, string $source, int $minutesAgo = 20, string $section = CasterNews::GERMANY): CasterNews
{
    return CasterNews::query()->create([
        'hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => 'test', 'source' => $source, 'language' => 'de',
        'title' => $title, 'link' => null, 'published_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
    ]);
}

/** Real rows for every rubric: a finished game, one story from three outlets, a podcast digest, a chat question. */
function rubricsDesk(): void
{
    $ada = rubricsPlayer('Adalinde');
    $bob = rubricsPlayer('Bobbert');
    ChessGame::factory()->create(['white_id' => $ada->id, 'black_id' => $bob->id, 'status' => ChessGameStatus::Finished, 'result' => '1-0', 'end_reason' => ChessEndReason::Resignation, 'ply' => 40, 'ended_at' => now()->subHours(3)]);

    rubricsHeadline('Klingbeil will SPD-Führung neu wählen lassen', 'tagesschau', 10);
    rubricsHeadline('Klingbeil kündigt Neuwahl der Parteispitze an', 'Spiegel', 15);
    rubricsHeadline('Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', 25, CasterNews::BITCOIN);
    rubricsHeadline('Länder streiten über Finanzierung neuer Schulgebäude', 'FAZ', 30);

    app(PodcastTranscripts::class)->store(['guid' => 'ep-336', 'title' => '#336 - Proof of Bier', 'episode_number' => 336, 'published_at' => now()->subDays(2)->getTimestamp(), 'transcript' => 'Heute reden wir über Bier.']);
    CasterPodcastTranscript::query()->update(['digest' => 'Themen: warum Kölsch kein Bier ist und was Lightning im Biergarten kann']);

    ChatterLine::query()->create(['pubkey' => $ada->pubkey, 'name' => 'Adalinde', 'message' => 'Wann startet eigentlich der nächste Schach-Cup hier?', 'said_at' => now()->subMinutes(40)->getTimestamp()]);
}

/** Twelve long, distinct sentences: about ninety seconds spoken. @return list<string> */
function rubricsSentences(): array
{
    return [
        'Gestern Abend ging es in der Liga richtig zur Sache, und die Partien hatten mehr Spannung als jede Haushaltsdebatte.',
        'Adalinde hat ihre Partie mit Schwarz souverän nach Hause gebracht, weil ihr Gegner irgendwann einfach aufgegeben hat.',
        'Das ist die Art von Entschlossenheit, die ich mir von manchem Finanzminister wirklich auch einmal wünschen würde.',
        'Die Tabelle wackelt dadurch ordentlich, und oben wird es langsam so eng wie in einer Düsseldorfer Altbierkneipe.',
        'Wer jetzt noch glaubt, dass Schach langweilig ist, hat offenbar die letzten vierundzwanzig Stunden komplett verschlafen.',
        'Ich sage euch ehrlich, dass mich diese Mischung aus Taktik und Nervenstärke jedes Mal wieder richtig begeistert.',
        'Und während die Zentralbanken weiter Geld drucken, spielt man hier ehrlich um Sats und um die eigene Ehre.',
        'Die Konkurrenz wird sich das Ergebnis genau anschauen, denn so eine Aufgabe passiert selten ganz ohne Vorgeschichte.',
        'Ich bin gespannt, ob die nächste Runde genauso viel Drama bringt oder ob dann doch alle vorsichtiger spielen.',
        'Wer mitspielen will, findet die offenen Turniere direkt auf der Seite und braucht dafür keinen einzigen Antrag.',
        'Kein Formular, kein Amt und keine Wartenummer, sondern einfach ein Brett, zwei Köpfe und ein bisschen Mut.',
        'So stelle ich mir eine Liga vor, in der jeder zeigen kann, was er draufhat, ganz ohne Erlaubnis von oben.',
    ];
}

/** A model answer for a rubric prompt: twelve sentences by Markus; a Presseschau names the outlets of its material. */
function rubricsAnswer(AgentPrompt|string $prompt): array
{
    $text = is_string($prompt) ? $prompt : $prompt->prompt;
    $lines = rubricsSentences();

    if (preg_match_all('/^- ([^:\n]+): „/mu', $text, $outlets) > 0 && str_contains($text, 'Presseschau')) {
        foreach ($outlets[1] as $index => $outlet) {
            $lines[$index] = 'Bei '.trim($outlet).' liest sich die Geschichte heute so, als hätte jemand den Pressetext nur leicht umgerührt.';
        }
    }

    return ['text' => implode("\n", array_map(fn (string $line): string => 'Markus: '.$line, $lines)), 'canon_id' => null, 'mood' => 'fakten'];
}

/** The rubric slot at :15 of the current hour. @return array<string, mixed> */
function rubricsSlot(string $time = '2026-10-12 14:15:00'): array
{
    return BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse($time, 'Europe/Berlin'));
}

it('brings real material for five rubrics: league, press, podcast, history and chat', function () {
    rubricsDesk();
    $rubrics = app(Rubrics::class);
    $now = CarbonImmutable::now();

    $league = $rubrics->material(Rubrics::LIGA, $now);
    $press = $rubrics->material(Rubrics::PRESS, $now);
    $podcast = $rubrics->material(Rubrics::PODCAST, $now);
    $history = $rubrics->material(Rubrics::HISTORY, $now);
    $chat = $rubrics->material(Rubrics::CHAT, $now);

    expect($league['data'])->toContain('Adalinde gewinnt gegen die Gegenseite')->not->toContain('Bobbert')
        ->and($league['names'])->toContain('Adalinde')->toContain('Bobbert')
        ->and($press['sources'])->toHaveCount(3)
        ->and(array_unique($press['sources']))->toHaveCount(3)
        // The story two outlets tell comes first: the comparison.
        ->and(array_slice($press['sources'], 0, 2))->toEqualCanonicalizing(['tagesschau', 'Spiegel'])
        ->and($press['same_story'])->toBeTrue()
        ->and($press['data'])->toContain('tagesschau: „Klingbeil will SPD-Führung neu wählen lassen“')
        ->and($podcast['data'])->toContain('warum Kölsch kein Bier ist')
        ->and($history['data'])->toContain(HistoryScripts::data()['episodes'][0]['title'])
        ->and($chat['data'])->toContain('Wann startet eigentlich der nächste Schach-Cup hier?')
        ->and($chat['data'])->not->toContain('Adalinde');
});

it('has no material without real rows, and none twice within its repeat window', function () {
    $rubrics = app(Rubrics::class);
    $now = CarbonImmutable::now();

    expect($rubrics->material(Rubrics::LIGA, $now))->toBeNull()
        ->and($rubrics->material(Rubrics::PRESS, $now))->toBeNull()
        ->and($rubrics->material(Rubrics::PODCAST, $now))->toBeNull()
        ->and($rubrics->material(Rubrics::CHAT, $now))->toBeNull();

    rubricsDesk();
    WorldMemory::forget();
    $league = $rubrics->material(Rubrics::LIGA, $now);
    $rubrics->aired(Rubrics::LIGA, $league, $now);

    expect($rubrics->material(Rubrics::LIGA, $now))->toBeNull();
    $this->travel(2)->hours();
    WorldMemory::forget();
    expect($rubrics->material(Rubrics::LIGA, CarbonImmutable::now()))->not->toBeNull();
});

it('takes the rubrics in turn over the day, skips one without material and keeps a plan once made', function () {
    config(['tv.commentary.programme.rubrics.rotation' => ['liga', 'presseschau', 'podcast', 'geschichte', 'chat']]);
    $rubrics = app(Rubrics::class);
    $first = $rubrics->rotationFor(rubricsSlot('2026-10-12 14:15:00'));
    $second = $rubrics->rotationFor(rubricsSlot('2026-10-12 14:45:00'));
    $next = $rubrics->rotationFor(rubricsSlot('2026-10-12 15:15:00'));

    expect($second[0])->toBe($first[1])->and($next[0])->toBe($first[2])
        ->and($first)->toEqualCanonicalizing(['liga', 'presseschau', 'podcast', 'geschichte', 'chat']);

    // A slot whose rotation starts with the Liga-Rubrik: without league rows the Geschichtsstunde is planned.
    config(['tv.commentary.programme.rubrics.rotation' => ['liga', 'geschichte']]);
    $slot = $rubrics->rotationFor(rubricsSlot('2026-10-12 14:15:00'))[0] === Rubrics::LIGA ? rubricsSlot('2026-10-12 14:15:00') : rubricsSlot('2026-10-12 14:45:00');
    $other = $slot['minute'] === 15 ? rubricsSlot('2026-10-12 14:45:00') : rubricsSlot('2026-10-12 14:15:00');
    expect($rubrics->plannedFor($slot, CarbonImmutable::now()))->toBe(Rubrics::HISTORY);

    // League rows that come later do not change a plan a teaser may already have named; a slot not planned yet takes them.
    rubricsDesk();
    WorldMemory::forget();
    expect($rubrics->plannedFor($slot, CarbonImmutable::now()))->toBe(Rubrics::HISTORY)
        ->and($rubrics->plannedFor(rubricsSlot('2026-10-12 15:15:00')['minute'] === $slot['minute'] ? rubricsSlot('2026-10-12 15:15:00') : rubricsSlot('2026-10-12 15:45:00'), CarbonImmutable::now()))->toBe(Rubrics::LIGA)
        ->and($rubrics->plannedFor($other, CarbonImmutable::now()))->toBe(Rubrics::HISTORY)
        // Not a rubric slot: nothing.
        ->and($rubrics->plannedFor(rubricsSlot('2026-10-12 14:05:00'), CarbonImmutable::now()))->toBeNull();
});

it('writes a rubric: the time and its name first, then sixty to one hundred fifty seconds of content', function () {
    rubricsDesk();
    VoiceAgent::fake(fn (string $prompt): array => rubricsAnswer($prompt));
    $slot = rubricsSlot();
    $material = app(Rubrics::class)->material(Rubrics::LIGA, CarbonImmutable::now());

    $rubric = app(RubricScripts::class)->write($slot, Rubrics::LIGA, $material, CarbonImmutable::now());
    $lines = ScriptGuard::lines($rubric['text']);
    $content = implode("\n", array_slice($lines, 1));

    expect($lines[0])->toBe(RubricScripts::opener('die Liga-Rubrik', $slot['key']))
        // No clock time (user, 2026-10-25): a rubric that misses its slot airs late.
        ->and($lines[0])->not->toContain('Uhr')->toContain('Liga-Rubrik')
        ->and(ScriptQueue::estimateSeconds($content))->toBeGreaterThanOrEqual(60.0)->toBeLessThanOrEqual(150.0)
        ->and($rubric['speakers'])->toHaveCount(count($lines))
        ->and(array_unique($rubric['speakers']))->toBe([DuetDirector::MARKUS]);

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Adalinde gewinnt gegen die Gegenseite') && str_contains($prompt->prompt, 'höchstens einen Spielernamen'));
});

it('turns down a short draft, drops a league sentence with two players and a Sirius line nobody asked for, and wants every outlet of a Presseschau', function () {
    rubricsDesk();
    $scripts = app(RubricScripts::class);
    $league = app(Rubrics::class)->material(Rubrics::LIGA, CarbonImmutable::now());
    $press = app(Rubrics::class)->material(Rubrics::PRESS, CarbonImmutable::now());
    $sentences = rubricsSentences();

    [$short, $reasons] = $scripts->evaluate(Rubrics::LIGA, $league, 'Markus: '.$sentences[0]."\nMarkus: ".$sentences[1], false);
    expect($short)->toBeNull()->and($reasons)->toBe([RubricScripts::SHORT]);

    $two = 'Markus: Adalinde hat Bobbert in einer langen Partie mit viel Geduld und noch mehr Kaffee niedergerungen.';
    [$rubric] = $scripts->evaluate(Rubrics::LIGA, $league, $two."\nSirius: Ich hätte da noch eine ganz eigene Geschichte zu diesem Ergebnis im Angebot.\n"
        .implode("\n", array_map(fn (string $line): string => 'Markus: '.$line, $sentences)), false);
    expect($rubric['text'])->not->toContain('Bobbert')->not->toContain('eigene Geschichte')
        ->and(ScriptGuard::lines($rubric['text']))->toHaveCount(12);

    // A Presseschau that leaves out an outlet goes again; with all three it passes.
    [$missing, $why] = $scripts->evaluate(Rubrics::PRESS, $press, implode("\n", array_map(fn (string $line): string => 'Markus: '.$line, $sentences)), false);
    expect($missing)->toBeNull()->and($why)->toBe([RubricScripts::SOURCES]);

    VoiceAgent::fake(fn (string $prompt): array => rubricsAnswer($prompt));
    $written = $scripts->write(rubricsSlot(), Rubrics::PRESS, $press, CarbonImmutable::now());
    foreach ($press['sources'] as $source) {
        expect($written['text'])->toContain($source);
    }
});

it('tries a rejected rubric once more with the reason, then airs the second draft and counts what it broke', function () {
    rubricsDesk();
    $calls = 0;
    VoiceAgent::fake(function () use (&$calls): array {
        $calls++;

        return ['text' => 'Markus: '.rubricsSentences()[0], 'canon_id' => null, 'mood' => 'fakten'];
    });

    $material = app(Rubrics::class)->material(Rubrics::LIGA, CarbonImmutable::now());
    $rubric = app(RubricScripts::class)->write(rubricsSlot(), Rubrics::LIGA, $material, CarbonImmutable::now());

    // The second draft airs all the same (user 2026-10-18: "Nie mehr verwerfen"): the opener and the short draft.
    expect($rubric['text'] ?? null)->toEndWith(rubricsSentences()[0])
        ->and($rubric['speakers'] ?? null)->toBe(['markus', 'markus'])
        ->and($calls)->toBe(2)
        ->and(DB::table('voice_rejections')->where('channel', RubricScripts::CHANNEL)->where('reason', RubricScripts::SHORT)->value('count'))->toBe(2)
        ->and(DB::table('voice_usage')->where('channel', RubricScripts::CHANNEL)->value('accepted'))->toBe(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Er war zu kurz'));
});

it('queues the planned rubric once within its writing window, in parts, waiting for its slot', function () {
    rubricsDesk();
    config(['tv.commentary.programme.rubrics.rotation' => ['liga'], 'tv.commentary.parts.sentences' => 5]);
    VoiceAgent::fake(fn (string $prompt): array => rubricsAnswer($prompt));
    $queue = app(ScriptQueue::class);

    // 14:05: ten minutes ahead, too early.
    expect($queue->fillRubric(CarbonImmutable::now()))->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:08:00', 'Europe/Berlin'));
    expect($queue->fillRubric(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillRubric(CarbonImmutable::now()))->toBe(0);

    $parts = CasterScript::query()->where('kind', CasterScript::RUBRIC)->orderBy('part_index')->get();
    expect($parts->count())->toBeGreaterThan(1)
        ->and($parts->pluck('topic')->unique()->all())->toBe([Rubrics::LIGA])
        ->and($parts->first()->airs_at->setTimezone('Europe/Berlin')->format('H:i'))->toBe('14:15')
        ->and($parts->first()->priority)->toBe(70)
        ->and($parts->first()->sentences()[0])->toContain('Liga-Rubrik')
        ->and(config('tv.commentary.idents.kinds.rubric'))->toBe('ident')
        // Not counted as airtime ahead: the scenes are written as before.
        ->and($queue->airtimeAhead())->toBe(0.0);

    // 14:14: the slot is a minute away, past the last writing minute; the next rubric slot is :45.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:14:00', 'Europe/Berlin'));
    expect($queue->fillRubric(CarbonImmutable::now()))->toBe(0);
});

it('writes no rubric in the quiet hours, when the clock has no rubric slots', function () {
    rubricsDesk();
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    VoiceAgent::fake(fn (string $prompt): array => rubricsAnswer($prompt));
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:09:00', 'Europe/Berlin'));

    expect(app(ScriptQueue::class)->fillRubric(CarbonImmutable::now()))->toBe(0);
    VoiceAgent::assertNeverPrompted();
});

it('counts a Geschichtsstunde as told, so the next history segment tells another episode', function () {
    config(['tv.commentary.programme.rubrics.rotation' => ['geschichte']]);
    VoiceAgent::fake(fn (string $prompt): array => rubricsAnswer($prompt));
    $first = app(HistoryScripts::class)->nextEpisode(CarbonImmutable::now());
    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:08:00', 'Europe/Berlin'));

    expect(app(ScriptQueue::class)->fillRubric(CarbonImmutable::now()))->toBe(1)
        ->and(app(HistoryScripts::class)->nextEpisode(CarbonImmutable::now())['id'])->not->toBe($first['id']);
});

/** Decoded PCM by the byte length of the Opus file: one second per thousand bytes. */
function rubricsFakeDecoding(): void
{
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(output: str_repeat("\1\0", (int) (24000 * strlen((string) $process->input) / 1000)))]);
}

function rubricsClip(string $kind, int $seconds, ?CarbonImmutable $airsAt = null): CasterClip
{
    $script = casterScript($kind);
    $script->update(['airs_at' => $airsAt?->utc(), 'expires_at' => ($airsAt ?? CarbonImmutable::now())->utc()->addMinutes(10)]);

    return app(ClipSpool::class)->store($script->fresh(), casterOpus(1000 * $seconds), (float) $seconds, range(5.0, (float) $seconds, 5.0), CarbonImmutable::now());
}

it('starts the rubric at its slot, never before, the scene on air cut at the end of its sentence', function () {
    $slot = CarbonImmutable::parse('2026-10-12 14:15:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(20));
    rubricsFakeDecoding();
    $rubric = rubricsClip(CasterScript::RUBRIC, 60, $slot);
    rubricsClip(CasterScript::SCENE, 60);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'gap_seconds' => ['min' => 25, 'max' => 25], 'rubric_late_seconds' => 180,
    ], clock: BroadcastClock::fromConfig());
    $startedAt = null;

    for ($t = (float) $slot->subSeconds(20)->getTimestamp(); $t <= $slot->getTimestamp() + 30; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id === $rubric->id && $startedAt === null) {
            $startedAt = $t;
        }
    }

    expect($startedAt)->not->toBeNull()
        ->and($startedAt - $slot->getTimestamp())->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(6.0)
        ->and($director->bulletinOnAir())->toBeNull();
});

it('drops Sport und Wetter that missed its slot by more than late_seconds, but airs a late show (user 2026-10-18)', function (string $topic, bool $airs) {
    $slot = CarbonImmutable::parse('2026-10-12 14:15:00', 'Europe/Berlin');
    $this->travelTo($slot->addSeconds(200));
    rubricsFakeDecoding();
    $rubric = rubricsClip(CasterScript::RUBRIC, 60, $slot);
    $rubric->script->update(['topic' => $topic]);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), ['gap_seconds' => ['min' => 0, 'max' => 0], 'rubric_late_seconds' => 180], clock: BroadcastClock::fromConfig());
    $played = false;

    for ($t = (float) $slot->addSeconds(200)->getTimestamp(); $t <= $slot->getTimestamp() + 240; $t += 0.5) {
        $director->tick($planner, $planner->at($t, []), $t);
        $played = $played || $director->clip()?->id === $rubric->id;
    }

    expect($played)->toBe($airs);
})->with([
    'Sport und Wetter, bound to the news' => [SportWeather::TOPIC, false],
    'a show, late is fine' => [Shows::TRIBUNAL, true],
]);

it('hands out the due rubric with the earliest slot first, by its script\'s airs_at (prod 2026-10-18: ordering by a column the clips lack broke every tick on Postgres)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00:00', 'Europe/Berlin'));
    rubricsFakeDecoding();
    $later = rubricsClip(CasterScript::RUBRIC, 30, CarbonImmutable::parse('2026-10-12 14:45:00', 'Europe/Berlin'));
    $earlier = rubricsClip(CasterScript::RUBRIC, 30, CarbonImmutable::parse('2026-10-12 14:15:00', 'Europe/Berlin'));
    collect([$later, $earlier])->each(fn (CasterClip $clip) => $clip->script->update(['topic' => Shows::TRIBUNAL, 'expires_at' => now()->addYears(9)]));
    collect([$later, $earlier])->each(fn (CasterClip $clip) => $clip->update(['expires_at' => now()->addYears(9)]));

    expect(app(ClipSpool::class)->rubric(CarbonImmutable::now(), 180)?->id)->toBe($earlier->id);
});

it('airs a due show while the track-change jingle watches the calm air (prod 2026-10-18: the 20:03 show never aired)', function () {
    $slot = CarbonImmutable::parse('2026-10-12 20:03:00', 'Europe/Berlin');
    $this->travelTo($slot->addHours(4));
    rubricsFakeDecoding();
    $rubric = rubricsClip(CasterScript::RUBRIC, 60, $slot);
    $rubric->script->update(['topic' => Shows::TRIBUNAL]);
    $rubric->update(['expires_at' => now()->addYears(9)]);
    $dir = storage_path('framework/testing/rubrics-imaging-'.getmypid().'-'.bin2hex(random_bytes(4)));
    @mkdir($dir, 0775, true);
    file_put_contents($dir.'/manifest.json', json_encode(['items' => []]));
    $clock = BroadcastClock::fromConfig();
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), ['gap_seconds' => ['min' => 0, 'max' => 0], 'rubric_late_seconds' => 180],
        clock: $clock, imaging: new Imaging(['dir' => $dir], 24000, fn (): float => 0.0, $clock), musicTracks: fn (float $from, float $to): array => []);
    $played = false;

    for ($t = (float) now()->getTimestamp(); $t <= now()->getTimestamp() + 10; $t += 0.25) {
        $director->tick($planner, $planner->at($t, []), $t);
        $played = $played || $director->clip()?->id === $rubric->id;
    }

    File::deleteDirectory($dir);

    expect($played)->toBeTrue();
});

it('frames a show without a clock time: the opener names no hour and a content line that does leaves with its speaker (user, 2026-10-25)', function () {
    $show = RubricScripts::framed('Satoshis Hotline', 0, [
        'text' => "Erste Frage aus dem Chat.\nUm einundzwanzig Uhr geht es weiter.\nZur vollen Stunde gibt es Nachrichten.\nZweite Frage, danke fürs Zuhören.",
        'speakers' => ['sirius', 'markus', 'sirius', 'markus'],
    ], 'markus');

    expect(ScriptGuard::lines($show['text']))->toBe(['Ihr hört TWENTY ONE TV, und jetzt ist Zeit für Satoshis Hotline.', 'Erste Frage aus dem Chat.', 'Zweite Frage, danke fürs Zuhören.'])
        ->and($show['speakers'])->toBe(['markus', 'sirius', 'markus']);
});
