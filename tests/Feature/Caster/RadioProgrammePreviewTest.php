<?php

use App\Models\CasterScript;
use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\PodcastReplay;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\ProgrammePreview;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the Programmvorschau (P14)
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Was ist mit einer Vorlesung des Programms, was in den
| nächsten 12 Stunden zu erwarten ist?" Markus and Sirius read what the
| real timetable holds, after the news block, every time and name checked
| against it; a rotation format only at a time the planner has fixed.
|
*/

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['*' => Http::response([], 404)]);
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        'tv.commentary.programme.roasts.enabled' => true,
        'tv.commentary.programme.shows.enabled' => true,
        // A curated show (always material), a match show without matches and a chat show without chatters.
        'tv.commentary.programme.shows.schedule' => ['12:45' => 'friedhof', '15:15' => 'partie', '17:15' => 'friedhof', '20:45' => 'tribunal'],
        'tv.commentary.programme.news_talk.enabled' => true,
        'tv.commentary.programme.sport_weather.enabled' => true,
        'tv.commentary.programme.preview.enabled' => true,
        'tv.commentary.podcast_replay.enabled' => true,
        'tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => true,
        'tv.commentary.podcast_replay.sources.resat.enabled' => false,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.parts.sentences' => 5,
        'tv.commentary.spool.quiet_hours.from' => '01:00',
        'tv.commentary.spool.quiet_hours.to' => '09:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:55:00', 'Europe/Berlin'));
});

/** The stored EINUNDZWANZIG episode (one chunk) the replay would air at 14:03. */
function programmePreviewEpisode(): void
{
    Storage::disk('local')->put('caster/podcasts/einundzwanzig/ep/chunk-001.opus', 'opus');
    Storage::disk('local')->put('caster/podcasts/einundzwanzig/episode.json', json_encode([
        'guid' => 'ep-335', 'title' => 'Folge 335: Der Staat und dein Geld', 'published_at' => now()->subDay()->getTimestamp(), 'seconds' => 300.0,
        'chunks' => [['path' => 'caster/podcasts/einundzwanzig/ep/chunk-001.opus', 'seconds' => 300.0, 'bytes' => 4, 'pauses' => [299.0]]],
    ]));
}

/** A script of `$kind` queued for the slot `$slot` (Berlin "Y-m-d H:i"), its facts_hash `$prefix:<key>`. */
function programmePreviewQueued(string $kind, string $prefix, string $slot, string $text, ?string $topic = null): CasterScript
{
    $at = CarbonImmutable::parse($slot, 'Europe/Berlin');

    return app(ScriptQueue::class)->enqueue($kind, ScenePhase::BREAK, $text, $prefix.':'.$at->getTimestamp(), 70, CarbonImmutable::now(),
        source: CasterScript::SOURCE_AI, topic: $topic, airsAt: $at, expiresAt: $at->addHour());
}

/** The day preview as Markus and Sirius would say it from the fixture timetable; `$extra` replaces the third line. @return list<string> */
function programmePreviewLines(?string $extra = null): array
{
    $lines = [
        'Markus: Um dreizehn Uhr fünfzehn kommt die Schwarzbuch-Stunde, und wieder hat jemand unser Steuergeld für einen goldenen Radweg verbrannt.',
        'Sirius: Wer danach noch nicht genug hat, bekommt um vierzehn Uhr drei die neueste Folge vom EINUNDZWANZIG Podcast in voller Länge.',
        'Markus: Die Folge heißt Der Staat und dein Geld, und schon der Titel klingt nach einer Beziehung mit sehr einseitiger Liebe.',
        'Sirius: Um siebzehn Uhr fünfzehn halten wir die Trauerfeier auf dem Shitcoin-Friedhof, mit Grabrede und ganz ohne Mitleid.',
        'Markus: Dazwischen laufen im Wechsel die Roast-Formate, und welches genau kommt, entscheidet sich erst kurz vorher.',
        'Sirius: Zu jeder vollen Stunde gibt es Nachrichten, gleich danach Sport und Wetter, und zur halben Stunde die Kurznachrichten.',
        'Markus: Ab ein Uhr ist Nachtruhe, dann kommen bis neun Uhr nur noch die Nachrichten zur vollen Stunde, Wiederholungen und Musik.',
        'Sirius: Wer also nachts wach liegt, hört wenigstens pünktlich, wie viel Kaufkraft das Fiatgeld seit gestern wieder verloren hat.',
        'Markus: Bis dahin bleibt dran, denn bei uns wird nichts versprochen, was nicht wirklich im Sendeplan steht, ganz anders als bei der Zentralbank.',
    ];

    if ($extra !== null) {
        $lines[2] = $extra;
    }

    return $lines;
}

/** The short night preview. @return list<string> */
function programmePreviewNightLines(): array
{
    return [
        'Markus: Ab ein Uhr ist bei uns Nachtruhe, dann laufen bis neun Uhr nur die Nachrichten zur vollen Stunde, Wiederholungen und Musik.',
        'Sirius: Für die Nachteulen gibt es nach den Nachrichten zur vollen Stunde wenigstens noch kurz das Wetter, sonst ist hier Ruhe.',
        'Markus: Morgen früh um zehn Uhr fünfzehn halten wir dann die Trauerfeier auf dem Shitcoin-Friedhof, mit einer Grabrede ohne jedes Mitleid.',
        'Sirius: Und dazwischen kommen die Roast-Formate im Wechsel, welches genau, steht erst kurz vorher fest, also schlaft lieber vorher.',
    ];
}

function programmePreviewAnswer(AgentPrompt|string $prompt): array
{
    $text = is_string($prompt) ? $prompt : $prompt->prompt;

    return ['text' => implode("\n", str_contains($text, 'heute Nacht und morgen früh') ? programmePreviewNightLines() : programmePreviewLines()), 'canon_id' => null, 'mood' => 'fakten'];
}

/** The material from the end of the 13:00 news block, with the 13:15 slot fixed to the Schwarzbuch-Stunde. @return array<string, mixed> */
function programmePreviewMaterial(): array
{
    programmePreviewEpisode();
    Cache::put('caster:rubric:plan:'.CarbonImmutable::parse('2026-10-12 13:15', 'Europe/Berlin')->getTimestamp(), 'schwarzbuch', 3600);

    return app(ProgrammePreview::class)->material(CarbonImmutable::parse('2026-10-12 13:06', 'Europe/Berlin'), CarbonImmutable::now(), false);
}

it('assembles the next twelve hours from the real timetable only', function () {
    $material = programmePreviewMaterial();

    expect($material['data'])
        // The fixed plan of a rotation slot, the podcast with its real title and a show whose source has material.
        ->toContain('- dreizehn Uhr fünfzehn: die Schwarzbuch-Stunde')
        ->toContain('- vierzehn Uhr drei: EINUNDZWANZIG Podcast, die neueste Folge in voller Länge, „Folge 335: Der Staat und dein Geld“')
        ->toContain('- siebzehn Uhr fünfzehn: die Trauerfeier auf dem Shitcoin-Friedhof')
        // A show without material (no match, no chatter), a past one and a source that is off are not there.
        ->not->toContain('Partie des Tages')->not->toContain('Chat-Tribunal')->not->toContain('zwölf Uhr fünfundvierzig')->not->toMatch('/re:?s[ae]t|blocktrainer/iu')
        ->toContain('Nachrichten zu jeder vollen Stunde, Kurznachrichten zu jeder halben Stunde')
        ->toContain('Gleich nach den Nachrichten zur vollen Stunde: Sport und Wetter')
        // The window reaches past one o'clock: the night is said as it is.
        ->toContain('Nachtruhe von ein Uhr bis neun Uhr: dann nur die Nachrichten zur vollen Stunde, dazwischen Wiederholungen und Musik')
        ->toContain('im Wechsel laufen Roast-Formate wie');

    // The rest of the rubrics: rotation roast formats only, never the fixed one again, never at a time.
    expect($material['generic'])->toHaveCount(3)->not->toContain('schwarzbuch')
        ->and(array_diff($material['generic'], (array) config('tv.commentary.programme.rubrics.rotation')))->toBe([])
        ->and(array_column($material['items'], 'at'))->toBe(['dreizehn Uhr fünfzehn', 'vierzehn Uhr drei', 'siebzehn Uhr fünfzehn'])
        ->and($material['times'])->toContain('vierzehn uhr', 'dreizehn uhr dreißig', 'ein uhr', 'neun uhr')
        // At night no headlines at the half hour.
        ->and($material['times'])->not->toContain('drei uhr dreißig');
});

it('names no rotation format at a time before the planner has fixed it, and the podcast not once it aired today', function () {
    programmePreviewEpisode();
    $material = app(ProgrammePreview::class)->material(CarbonImmutable::parse('2026-10-12 13:06', 'Europe/Berlin'), CarbonImmutable::now(), false);

    expect($material['data'])->not->toContain('dreizehn Uhr fünfzehn')->and($material['data'])->not->toContain('Schwarzbuch-Stunde,');

    Cache::put('caster:podcast_replay:aired:einundzwanzig:2026-10-12', 'ep-335', 3600);
    expect(app(ProgrammePreview::class)->material(CarbonImmutable::parse('2026-10-12 13:06', 'Europe/Berlin'), CarbonImmutable::now(), false)['data'])
        ->not->toContain('vierzehn Uhr drei');
});

it('turns down an invented show, an invented time, a digit and a format promised at a time it does not have', function (string $line, string $reason) {
    $material = programmePreviewMaterial();

    expect(ProgrammePreview::factual(implode("\n", programmePreviewLines()), $material))->toBe([])
        ->and(ProgrammePreview::factual(implode("\n", programmePreviewLines($line)), $material))->toContain($reason);
})->with([
    'a show that does not run' => ['Markus: Heute Abend kommt außerdem noch das Chat-Tribunal mit allen Chattern vor Gericht.', ProgrammePreview::NAME],
    'a time that is in no slot' => ['Markus: Um sechzehn Uhr zweiundvierzig gibt es dann noch eine ganz besondere Überraschung für euch.', ProgrammePreview::TIME],
    'a digit' => ['Markus: Die Folge läuft um 14:03 Uhr und dauert ziemlich genau so lange wie eine Bundestagsdebatte.', ProgrammePreview::NUMBER],
    'a rotation format at a time' => ['Markus: Um dreizehn Uhr fünfundvierzig kommt dann der Altcoin-Hype-Detektor mit dem neuesten Schwindel.', ProgrammePreview::WRONG_TIME],
    'a fixed show at another show\'s time' => ['Markus: Um vierzehn Uhr drei beginnt die Trauerfeier auf dem Shitcoin-Friedhof, also zieht euch schwarz an.', ProgrammePreview::WRONG_TIME],
    'a podcast that is off' => ['Markus: Und danach spielen wir noch den Blocktrainer-Podcast Reset in voller Länge für euch ab.', ProgrammePreview::NAME],
]);

it('airs after the news talk of the hour and waits while that talk can still be written', function () {
    programmePreviewEpisode();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => programmePreviewAnswer($prompt));
    Cache::put('caster:rubric:plan:'.CarbonImmutable::parse('2026-10-12 13:15', 'Europe/Berlin')->getTimestamp(), 'schwarzbuch', 3600);
    $queue = app(ScriptQueue::class);
    $bulletinText = "Die Nachrichten.\nErste Meldung des Tages mit allen Einzelheiten.\nZweite Meldung des Tages mit allen Einzelheiten.";

    // No bulletin yet, then a bulletin without its talk: the news block is still being written.
    expect($queue->fillPreview(CarbonImmutable::now()))->toBe(0);
    $bulletin = programmePreviewQueued(CasterScript::BULLETIN, 'bulletin', '2026-10-12 13:00', $bulletinText);
    $sport = programmePreviewQueued(CasterScript::RUBRIC, 'sport_weather', '2026-10-12 13:00', "Markus: Sport und Wetter mit allen Zahlen.\nSirius: Und noch mehr Zahlen dazu.", SportWeather::TOPIC);
    expect($queue->fillPreview(CarbonImmutable::now()))->toBe(0);

    $talk = programmePreviewQueued(CasterScript::RUBRIC, 'news_talk', '2026-10-12 13:00', "Markus: Der Talk zu den Nachrichten.\nSirius: Und der Widerspruch dazu.", NewsTalk::TOPIC);
    expect($queue->fillPreview(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillPreview(CarbonImmutable::now()))->toBe(0);

    $preview = CasterScript::query()->where('topic', ProgrammePreview::TOPIC)->orderBy('part_index')->get();
    $end = ScriptQueue::bulletinEnd(CarbonImmutable::parse('2026-10-12 13:00', 'Europe/Berlin'), $bulletin->text)->getTimestamp()
        + (int) ceil($sport->estimated_seconds + $talk->estimated_seconds);

    expect($preview->first()->kind)->toBe(CasterScript::RUBRIC)
        ->and($preview->first()->facts_hash)->toBe('programmvorschau:'.CarbonImmutable::parse('2026-10-12 13:03', 'Europe/Berlin')->getTimestamp())
        ->and($preview->first()->airs_at->getTimestamp())->toBe(max($end, CarbonImmutable::parse('2026-10-12 13:03', 'Europe/Berlin')->getTimestamp()))
        ->and($preview->first()->id)->toBeGreaterThan($talk->id)
        ->and(array_values(array_unique($preview->flatMap(fn (CasterScript $part): array => $part->speakers)->all())))->toEqualCanonicalizing([DuetDirector::MARKUS, DuetDirector::SIRIUS])
        ->and(ScriptQueue::estimateSeconds($preview->pluck('text')->implode("\n")))->toBeGreaterThanOrEqual(60.0)->toBeLessThanOrEqual(130.0)
        ->and(ScriptGuard::lines($preview->first()->text)[0])->toContain('Programmvorschau')->not->toMatch('/\buhr\b/iu');

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Schreib jetzt die Programmvorschau')
        && str_contains($prompt->prompt, '- vierzehn Uhr drei: EINUNDZWANZIG Podcast'));
});

it('writes after the sport once the talk can no longer come, and not before its own time', function () {
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => programmePreviewAnswer($prompt));
    programmePreviewEpisode();
    Cache::put('caster:rubric:plan:'.CarbonImmutable::parse('2026-10-12 13:15', 'Europe/Berlin')->getTimestamp(), 'schwarzbuch', 3600);
    programmePreviewQueued(CasterScript::BULLETIN, 'bulletin', '2026-10-12 13:00', 'Kurz.');

    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:59:30', 'Europe/Berlin'));
    expect(app(ScriptQueue::class)->fillPreview(CarbonImmutable::now()))->toBe(1)
        // A short bulletin ends before the preview's own time: it waits for thirteen oh three.
        ->and(CasterScript::query()->where('topic', ProgrammePreview::TOPIC)->orderBy('part_index')->first()->airs_at->getTimestamp())
        ->toBe(CarbonImmutable::parse('2026-10-12 13:03', 'Europe/Berlin')->getTimestamp());
});

it('leaves the preview out while the podcast replay is on', function () {
    // The 13:15 slot lies before this window: the draft leaves it out.
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => ['text' => implode("\n", array_slice(programmePreviewLines(), 1)), 'canon_id' => null, 'mood' => 'fakten']);
    programmePreviewEpisode();
    config(['tv.commentary.programme.preview.slots' => ['13:55' => 'day']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 13:50:00', 'Europe/Berlin'));

    expect(PodcastReplay::active(CarbonImmutable::now()))->toBeFalse();
    app(PodcastReplay::class)->schedule(CarbonImmutable::now());
    expect(PodcastReplay::active(CarbonImmutable::now()))->toBeTrue()
        ->and(app(ScriptQueue::class)->fillPreview(CarbonImmutable::now()))->toBe(0)
        ->and(app(ScriptQueue::class)->fill(CarbonImmutable::now()))->toBeInt()
        ->and(CasterScript::query()->where('topic', ProgrammePreview::TOPIC)->count())->toBe(0);
    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Programmvorschau'));

    // The same minute without the airing writes it.
    CasterScript::query()->where('kind', CasterScript::PODCAST)->delete();
    Cache::forget('caster:podcast_replay:aired:einundzwanzig:2026-10-12');
    expect(app(ScriptQueue::class)->fillPreview(CarbonImmutable::now()))->toBe(1);
});

it('brings the short night version at 00:35: the quiet hours said honestly, and the morning', function () {
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => programmePreviewAnswer($prompt));
    config(['tv.commentary.programme.shows.schedule' => ['10:15' => 'friedhof']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 00:25:00', 'Europe/Berlin'));
    programmePreviewQueued(CasterScript::BULLETIN, 'bulletin', '2026-10-13 00:30', 'Die Kurznachrichten in aller Kürze.');

    $target = ProgrammePreview::target(CarbonImmutable::now());
    $material = app(ProgrammePreview::class)->material(CarbonImmutable::parse('2026-10-13 00:35', 'Europe/Berlin'), CarbonImmutable::now(), true);

    expect($target['night'])->toBeTrue()
        ->and($material['data'])->toContain('heute Nacht und morgen früh')
        ->toContain('Nachtruhe von ein Uhr bis neun Uhr')
        ->toContain('- zehn Uhr fünfzehn: die Trauerfeier auf dem Shitcoin-Friedhof')
        // The podcast at 14:03 lies outside the twelve hours.
        ->not->toContain('vierzehn Uhr drei')
        ->and($material['times'])->not->toContain('zwei uhr dreißig');

    expect(app(ScriptQueue::class)->fillPreview(CarbonImmutable::now()))->toBe(1);
    $preview = CasterScript::query()->where('topic', ProgrammePreview::TOPIC)->orderBy('part_index')->get();

    expect(ScriptGuard::lines($preview->first()->text)[0])->toContain('heute Nacht und morgen früh')
        ->and(ScriptQueue::estimateSeconds($preview->pluck('text')->implode("\n")))->toBeGreaterThanOrEqual(30.0)->toBeLessThanOrEqual(75.0);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'ehrlich auch, dass nachts nur Nachrichten, Wiederholungen und Musik kommen'));
});

it('picks its slots from the config and never replays the preview', function () {
    $at = fn (string $time): ?array => ProgrammePreview::target(CarbonImmutable::parse($time, 'Europe/Berlin'));

    expect($at('2026-10-12 08:47'))->toBeNull()
        ->and($at('2026-10-12 08:48')['key'])->toBe(CarbonImmutable::parse('2026-10-12 09:03', 'Europe/Berlin')->getTimestamp())
        ->and($at('2026-10-12 21:13')['night'])->toBeFalse()
        ->and($at('2026-10-12 21:14'))->toBeNull()
        ->and($at('2026-10-13 00:20')['night'])->toBeTrue()
        ->and(ReplayArchive::NEVER_TOPICS)->toContain(ProgrammePreview::TOPIC);
});
