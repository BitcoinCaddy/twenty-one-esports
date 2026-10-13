<?php

use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\StockLibrary;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\TalkShow;
use App\Support\Caster\Programme\TalkShowScripts;
use App\Support\Caster\WorldMemory;
use App\Support\TwentyOne\Stream\ProgrammeSlides;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\AgentPrompted;

/*
 * The thirty-minute talk (plan "TWENTY ONE Radio", P20; user 2026-10-13: "wenn im Programm steht TalkShow von 19:00 bis
 * 19:30, dann muss die TalkShow auch 30 min gehen!"): the show owns its window on the clock, it is written in acts until
 * it fills the window, and every cap on the way takes a block of that length.
 */

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        'tv.commentary.programme.shows.enabled' => true,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours.from' => '01:00',
        'tv.commentary.spool.quiet_hours.to' => '09:00',
        'esports.voice.monthly_budget_usd' => 100,
        'tv.commentary.programme.shows.formats.stammtisch.studio_seconds' => 0,
        // Small parts for the short fake acts: one call per act unless a test asks for more.
        'tv.commentary.programme.shows.formats.stammtisch.part_seconds' => 40,
        'tv.commentary.programme.shows.formats.stammtisch.max_parts_per_act' => 1,
        'tv.commentary.programme.shows.formats.stammtisch.act_short_share' => 0.5,
        'tv.commentary.programme.shows.formats.stammtisch.acts' => [
            ['id' => 'opening', 'brief' => 'Akt eins: Einstieg.', 'min_turns' => 2],
            ['id' => 'middle', 'brief' => 'Akt zwei: Kreuzfeuer.', 'min_turns' => 2],
            ['id' => 'closing', 'brief' => 'Letzter Akt: Schlussrunde.', 'min_turns' => 1],
        ],
        'tv.commentary.programme.shows.formats.stammtisch.extra_act' => ['id' => 'extra', 'brief' => 'Zusatzrunde: noch eine Runde.', 'min_turns' => 2],
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 17:58:00', 'Europe/Berlin'));
    WorldMemory::forget();
    Cache::flush();
});

function actsHeadline(): CasterNews
{
    return CasterNews::query()->create(['hash' => hash('sha256', 'acts'), 'section' => CasterNews::GERMANY, 'feed' => 'tagesschau', 'source' => 'tagesschau', 'language' => 'de',
        'title' => 'EZB lässt den Leitzins unverändert und warnt vor Inflation', 'summary' => 'Die Notenbank bleibt vorsichtig.', 'link' => 'https://www.tagesschau.de/wirtschaft/ezb-456.html', 'published_at' => now()->subHours(2)]);
}

/** The acts the fake model writes, by the brief it is asked for. */
function actsTexts(): array
{
    return [
        'opening' => [
            'Genossin: Der Staat kann in seiner eigenen Währung gar nicht pleitegehen, Herr Turm!',
            'Markus: Das sagen alle, kurz bevor das Brot doppelt so teuer ist.',
            'Sirius: Willkommen zum Talk, Genossin, warum sollte man Ihnen das glauben?',
            'Genossin: Weil der Staat sein eigenes Geld immer bezahlen kann, das ist Buchhaltung.',
            'Markus: [sigh] Buchhaltung ist auch das, was der Konkursverwalter am Ende macht.',
            'Sirius: Herr Turm, ist das ein Argument oder nur ein Spruch für den Chat?',
            'Markus: Das ist ein Argument, und zwar eins, das jeder Sparer am eigenen Konto merkt.',
            'Genossin: Ihr Konto ist nicht die Volkswirtschaft, das verwechseln Sie ständig.',
            'Sirius: Genossin, was heißt das denn für die Rentnerin an der Kasse?',
            'Genossin: Für sie heißt das sichere Arbeitsplätze und eine Rente, die nicht gekürzt wird.',
        ],
        'middle' => [
            'Sirius: Herr Turm, die Genossin verspricht sichere Renten, was antworten Sie ihr?',
            'Markus: Ich antworte, dass ein Versprechen aus der Druckerpresse nichts wert ist.',
            'Genossin: Moment mal, Sie halten jede staatliche Leistung für Diebstahl!',
            'Markus: Nicht jede, nur die, die man mit frisch gedrucktem Geld bezahlt.',
            'Sirius: Mal angenommen, die Notenbank druckt morgen viel mehr, wer zahlt dann?',
            'Genossin: Niemand zahlt, solange es genug Arbeit und genug Waren gibt.',
            'Markus: [laughter] Und wenn nicht, dann zahlt eben jeder an der Supermarktkasse.',
            'Sirius: Genossin, ist das nicht genau der Punkt, den Sie gerne weglassen?',
            'Genossin: Nein, das ist der Punkt, den Herr Turm seit Jahren falsch erklärt.',
        ],
        'extra' => [
            'Sirius: Noch eine Runde, Herr Turm, warum sollte man der Notenbank nicht trauen?',
            'Markus: Weil sie ständig ein bisschen von meinem Ersparten wegnimmt, ganz ohne Brief.',
            'Genossin: Sie nimmt gar nichts weg, sie sorgt dafür, dass die Wirtschaft läuft.',
            'Sirius: Genossin, läuft die Wirtschaft denn gerade, oder stolpert sie eher?',
            'Genossin: Sie würde laufen, wenn man den Staat endlich investieren ließe.',
            'Markus: [sigh] Investieren nennt sie das, ich nenne es Ausgeben mit gutem Gewissen.',
        ],
        'closing' => [
            'Markus: Noch etwas, Genossin, Ihre Druckerpresse hat noch nie einen Bäcker gerettet.',
            'Genossin: Und Ihr Bitcoin hat noch nie einen Kindergarten gebaut, Herr Turm!',
            'Sirius: [laughter] Das hat gesessen, Herr Turm, wo ist Ihr Konter?',
            'Markus: Mein Konter ist, dass der Kindergarten mit gutem Geld billiger wäre.',
            'Genossin: Das glauben Sie doch selbst nicht, Herr Turm.',
            'Sirius: Wir müssen zum Ende kommen, die Schlussrunde, Genossin, Ihr Satz?',
            'Genossin: Geld ist ein Werkzeug der Gesellschaft und kein Spielzeug für Spekulanten.',
            'Sirius: Herr Turm, und Ihr letzter Satz für heute Abend?',
            'Markus: Wer das Geld kontrolliert, kontrolliert die Menschen, und das sollte niemand dürfen.',
            'Sirius: Halten wir fest, Sie sind sich in gar nichts einig, und genau so mögen wir es.',
        ],
    ];
}

/** Fakes the model: each call answers with the act its prompt asks for; the prompts are collected. */
function actsFake(array &$prompts, array $override = []): void
{
    $calls = [];
    VoiceAgent::fake(function (string $prompt) use (&$prompts, &$calls, $override): array {
        $prompts[] = $prompt;
        $act = match (true) {
            str_contains($prompt, 'Zusatzrunde') => 'extra',
            str_contains($prompt, 'Letzter Akt') => 'closing',
            str_contains($prompt, 'Akt zwei') => 'middle',
            default => 'opening',
        };
        $calls[$act] = ($calls[$act] ?? 0) + 1;
        $lines = $override[$act][$calls[$act] - 1] ?? actsTexts()[$act];

        return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
    });
}

/** How long acts air together. */
function actsSeconds(string ...$acts): float
{
    $turns = DuetScripts::parse(implode("\n", array_merge(...array_map(fn (string $act): array => actsTexts()[$act], $acts))));
    [$lines, $speakers] = [[], []];
    foreach ($turns as $turn) {
        foreach (preg_split('/(?<=[.!?])\s+/u', $turn['text']) as $line) {
            $lines[] = $line;
            $speakers[] = $turn['speaker'];
        }
    }

    return TalkShow::airSeconds($lines, $speakers);
}

it('lets a show with slot_minutes own its window: no headlines inside, the news at the full hour stays', function () {
    $clock = BroadcastClock::fromConfig();
    $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse('2026-10-13 '.$time, 'Europe/Berlin');

    expect(Shows::windows())->toBe(['18:15' => 30, '22:15' => 30])
        ->and($clock->slotAt($at('18:31'))['type'])->toBe(BroadcastClock::RUBRIC)
        ->and($clock->slotAt($at('18:31'))['starts_at']->equalTo($at('18:15')))->toBeTrue()
        ->and($clock->slotAt($at('18:44:59'))['key'])->toBe($at('18:15')->getTimestamp())
        ->and($clock->slotAt($at('18:45'))['starts_at']->equalTo($at('18:45')))->toBeTrue()
        ->and($clock->nextBulletin($at('18:16'))['starts_at']->equalTo($at('19:00')))->toBeTrue()
        // Elsewhere the clock is as it was.
        ->and($clock->slotAt($at('17:31'))['type'])->toBe(BroadcastClock::HEADLINES)
        ->and($clock->slotAt($at('18:14'))['type'])->toBe(BroadcastClock::TALK);

    // Never past the full hour: a window from 18:45 for thirty minutes ends at 19:00 and the news is on.
    config(['tv.commentary.programme.shows.schedule' => ['18:45' => Shows::STAMMTISCH]]);
    $clock = BroadcastClock::fromConfig();
    expect($clock->slotAt($at('18:59'))['starts_at']->equalTo($at('18:45')))->toBeTrue()
        ->and($clock->slotAt($at('19:01'))['type'])->toBe(BroadcastClock::NEWS);

    // Without slot_minutes, or with the shows off, the headlines are back.
    config(['tv.commentary.programme.shows.schedule' => ['18:15' => Shows::STAMMTISCH], 'tv.commentary.programme.shows.enabled' => false]);
    expect(BroadcastClock::fromConfig()->slotAt($at('18:31'))['type'])->toBe(BroadcastClock::HEADLINES);
});

it('measures a talk on air: words at the talk pace, the turn gaps, the sentence pauses and the studio', function () {
    config(['tv.commentary.programme.shows.formats.stammtisch.studio_seconds' => 30, 'tv.commentary.voicestudio.pause.sentence' => 0.2]);
    // Twelve words, two changes of speaker (0.625 s each), one sentence pause, thirty seconds of studio.
    $seconds = TalkShow::airSeconds(['Eins zwei drei vier.', 'Fünf sechs sieben acht.', 'Neun zehn elf.', 'Zwölf.'], ['markus', 'genossin', 'genossin', 'sirius']);

    expect($seconds)->toEqualWithDelta(12 / 2.9 + 2 * 0.625 + 0.2 + 30, 0.001)
        ->and(TalkShow::airWindow())->toBe([1740.0, 1800.0]);
});

it('writes the talk in acts that continue each other, the guest opening the first and the host closing the last', function () {
    actsHeadline();
    $total = actsSeconds('opening', 'middle', 'closing');
    config(['tv.commentary.programme.shows.formats.stammtisch.slot_minutes' => (int) ceil($total / 60), 'tv.commentary.programme.shows.formats.stammtisch.slot_margin_seconds' => ceil($total / 60) * 60 - $total + 5]);
    $prompts = [];
    actsFake($prompts);

    $talk = app(TalkShowScripts::class)->evergreen(app(TalkShow::class)->evergreen(CarbonImmutable::now()));

    expect($prompts)->toHaveCount(3)
        ->and($prompts[0])->toContain('Dieser Teil: Akt eins von drei')->toContain('spricht als Erste')->not->toContain('So weit lief der Talk')
        ->and($prompts[1])->toContain('Akt zwei')->toContain('So weit lief der Talk')->toContain('Sirius: Genossin, was heißt das denn für die Rentnerin an der Kasse?')
        ->toContain('Schon gestellte Fragen des Gastgebers')->toContain('Kein Abschluss, keine Schlussrunde')
        ->and($prompts[2])->toContain('Sirius spricht zuletzt')
        ->and($talk)->not->toBeNull()
        ->and($talk['speakers'][0])->toBe(TalkShow::GUEST)
        ->and($talk['speakers'][count($talk['speakers']) - 1])->toBe(TalkShow::HOST)
        ->and($talk['text'])->toContain('pleitegehen')->toContain('Druckerpresse nichts wert')->toContain('genau so mögen wir es');
});

it('writes another act before the closing round while the talk is short, and gives up when it stays short', function () {
    actsHeadline();
    config(['tv.commentary.programme.shows.formats.stammtisch.slot_minutes' => 6, 'tv.commentary.programme.shows.formats.stammtisch.slot_margin_seconds' => 10,
        'tv.commentary.programme.shows.formats.stammtisch.max_extra_acts' => 1]);
    $prompts = [];
    actsFake($prompts);

    $talk = app(TalkShowScripts::class)->evergreen(app(TalkShow::class)->evergreen(CarbonImmutable::now()));

    // Opening, middle, the extra round, the closing round: still far under six minutes, so nothing airs.
    expect($prompts)->toHaveCount(4)
        ->and($prompts[2])->toContain('Zusatzrunde')
        ->and($prompts[3])->toContain('Letzter Akt')
        ->and(actsSeconds('opening', 'middle', 'extra', 'closing'))->toBeLessThan(350.0)
        ->and($talk)->toBeNull();
});

it('cuts whole turns from the middle when the acts run over the window', function () {
    actsHeadline();
    $total = actsSeconds('opening', 'middle', 'closing');
    config(['tv.commentary.programme.shows.formats.stammtisch.slot_minutes' => 2, 'tv.commentary.programme.shows.formats.stammtisch.slot_margin_seconds' => 119]);
    $prompts = [];
    actsFake($prompts);

    $talk = app(TalkShowScripts::class)->evergreen(app(TalkShow::class)->evergreen(CarbonImmutable::now()));
    $turns = DuetScripts::parse(implode("\n", array_map(fn (string $line, string $speaker): string => ucfirst($speaker).': '.$line, explode("\n", $talk['text']), $talk['speakers'])));

    expect($total)->toBeGreaterThan(120.0)
        ->and(TalkShow::airSeconds(explode("\n", $talk['text']), $talk['speakers']))->toBeLessThanOrEqual(120.0)
        ->and($turns[0]['speaker'])->toBe(TalkShow::GUEST)
        ->and($talk['text'])->toContain('genau so mögen wir es');
});

it('drops a line an act repeats from an earlier act and keeps the rest of the act', function () {
    actsHeadline();
    $total = actsSeconds('opening', 'middle', 'closing');
    config(['tv.commentary.programme.shows.formats.stammtisch.slot_minutes' => (int) ceil($total / 60), 'tv.commentary.programme.shows.formats.stammtisch.slot_margin_seconds' => ceil($total / 60) * 60 - $total + 5]);
    // The middle act opens by writing the last line of the opening once more, as the model did in the real runs.
    $repeating = ['Genossin: Für sie heißt das sichere Arbeitsplätze und eine Rente, die nicht gekürzt wird.', ...actsTexts()['middle']];
    $prompts = [];
    actsFake($prompts, ['middle' => [$repeating]]);

    $talk = app(TalkShowScripts::class)->evergreen(app(TalkShow::class)->evergreen(CarbonImmutable::now()));

    expect($prompts)->toHaveCount(3)
        ->and($prompts[1])->toContain('dein Teil beginnt direkt danach')
        ->and($talk)->not->toBeNull()
        ->and(substr_count($talk['text'], 'eine Rente, die nicht gekürzt wird'))->toBe(1)
        ->and($talk['text'])->toContain('Druckerpresse nichts wert');
});

it('takes a thirty-minute block everywhere on its way: the spool, the stock library, and the programme shows the window', function () {
    $lead = casterScript(CasterScript::RUBRIC);
    $lead->forceFill(['topic' => Shows::STAMMTISCH])->save();
    $clip = app(ClipSpool::class)->storeBlock([$lead], casterOpus(11 * 1024 * 1024), 1795.0, [1795.0], [], 0.0, CarbonImmutable::now());
    $dir = sys_get_temp_dir().'/acts-library-'.bin2hex(random_bytes(4));
    config(['tv.commentary.blocks.stock.library.dir' => $dir]);
    $opus = casterOpus(5000);

    expect($clip->duration_seconds)->toBe(1795.0)
        ->and(StockLibrary::fromConfig()->store(sha1($opus), $opus, ['format' => Shows::STAMMTISCH, 'seconds' => 1795.0, 'evergreen' => true], CarbonImmutable::now()))->toBe(StockLibrary::STORED);

    $method = new ReflectionMethod(ProgrammeSlides::class, 'formatItem');
    expect($method->invoke(null, 'k', Shows::STAMMTISCH, 0)['seconds'])->toBe(1800.0)
        ->and($method->invoke(null, 'k', Shows::TRIBUNAL, 0)['seconds'])->toBe(0.0)
        ->and(CasterVoice::SPOKEN_REPEAT)->toBeString();
});

it('fills an act part by part while it stays under its share of the slot, each part continuing the one before', function () {
    actsHeadline();
    $total = actsSeconds('opening', 'middle', 'extra', 'closing');
    // Two parts for the opening act: its share is reached only with the second.
    config(['tv.commentary.programme.shows.formats.stammtisch.slot_minutes' => (int) ceil($total / 60), 'tv.commentary.programme.shows.formats.stammtisch.slot_margin_seconds' => ceil($total / 60) * 60 - $total + 5,
        'tv.commentary.programme.shows.formats.stammtisch.max_parts_per_act' => 2, 'tv.commentary.programme.shows.formats.stammtisch.max_extra_acts' => 0]);
    $prompts = [];
    actsFake($prompts, ['opening' => [actsTexts()['opening'], actsTexts()['extra']]]);

    $talk = app(TalkShowScripts::class)->evergreen(app(TalkShow::class)->evergreen(CarbonImmutable::now()));

    expect($prompts[1])->toContain('Akt eins von drei, Fortsetzung zwei')->toContain('Das ist die Fortsetzung dieses Akts')->not->toContain('spricht als Erste')
        ->and($prompts[2])->toContain('Akt zwei')
        ->and($talk)->not->toBeNull()
        ->and($talk['text'])->toContain('Noch eine Runde, Herr Turm');
});

it('writes the talk with its own model, whatever the global voice model is', function () {
    config(['esports.voice.model' => 'claude-haiku-5-5']);

    expect(TalkShow::model())->toBe('claude-sonnet-5-5')
        ->and((new VoiceAgent('', null, 9000, modelOverride: TalkShow::model()))->model())->toBe('claude-sonnet-5-5')
        ->and((new VoiceAgent)->model())->toBe('claude-haiku-5-5');

    // The writer's calls go out with it.
    actsHeadline();
    $total = actsSeconds('opening', 'middle', 'closing');
    config(['tv.commentary.programme.shows.formats.stammtisch.slot_minutes' => (int) ceil($total / 60), 'tv.commentary.programme.shows.formats.stammtisch.slot_margin_seconds' => ceil($total / 60) * 60 - $total + 5]);
    $models = [];
    Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$models): void {
        $models[] = $event->prompt->model;
    });
    $prompts = [];
    actsFake($prompts);
    app(TalkShowScripts::class)->evergreen(app(TalkShow::class)->evergreen(CarbonImmutable::now()));
    expect($models)->not->toBe([])->and(array_unique($models))->toBe(['claude-sonnet-5-5']);

    config(['tv.commentary.programme.shows.formats.stammtisch.model' => '']);
    expect(TalkShow::model())->toBeNull();
});
