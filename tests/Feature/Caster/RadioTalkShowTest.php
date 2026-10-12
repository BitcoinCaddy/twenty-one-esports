<?php

use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\BlockRenderer;
use App\Support\Caster\Blocks\JingleLibrary;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\Pcm;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\ShowScripts;
use App\Support\Caster\Programme\TalkShow;
use App\Support\Caster\Programme\TalkShowScripts;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: Turm und Genossin, der Talk (P16)
|--------------------------------------------------------------------------
|
| User 2026-10-12/13: a talk show of three like a real TV talk show: Sirius
| hosts as the parody of an interrupting talk show host, Markus Turm and the
| fictional guest Genossin Gisela (MMT, sourced positions only) fight. Every
| speaker path knows the third voice: parse, the fetcher's profile, tempo and
| level, the block renderer's batches, blips and the quicker turn gap.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        'tv.commentary.programme.rubrics.rotation' => ['chat'],
        'tv.commentary.programme.shows.enabled' => true,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.parts.sentences' => 5,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.voicestudio.pause.jitter' => 0.0,
        'esports.voice.monthly_budget_usd' => 100,
        // Short shows for the fakes: the length rules are the same code as the real ones.
        'tv.commentary.programme.shows.formats.stammtisch.seconds' => ['min' => 20, 'max' => 600],
        'tv.commentary.programme.shows.formats.stammtisch.sentences' => ['min' => 10, 'max' => 110],
        'tv.commentary.programme.shows.formats.stammtisch.min_turns' => 3,
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 17:58:00', 'Europe/Berlin'));
    WorldMemory::forget();
    Cache::flush();
});

/** A money headline of the last hours in the stored news. */
function talkHeadline(string $title = 'EZB lässt den Leitzins unverändert und warnt vor Inflation', string $summary = 'Lagarde sagt: „Wir bleiben wachsam bei den Preisen.“'): CasterNews
{
    return CasterNews::query()->create(['hash' => hash('sha256', $title), 'section' => CasterNews::GERMANY, 'feed' => 'tagesschau', 'source' => 'tagesschau', 'language' => 'de',
        'title' => $title, 'summary' => $summary, 'link' => 'https://www.tagesschau.de/wirtschaft/ezb-123.html', 'published_at' => now()->subHours(2)]);
}

/**
 * A talk show answer in the shape the show asks for: the guest's cold open, the host's welcome, crossfire, the fact
 * check, the closing round, the host last. `$lines` replaces lines by index.
 *
 * @param  array<int, string>  $lines
 * @return array<string, mixed>
 */
function talkAnswer(array $lines = []): array
{
    $talk = [
        'Genossin: Der Staat kann in seiner eigenen Währung niemals pleitegehen, das ist reine Buchhaltung!',
        'Markus: Nein, nein, nein, so einfach ist das wirklich nicht!',
        'Sirius: Moment, Moment, Moment, da muss ich jetzt mal dazwischen.',
        'Sirius: Willkommen zum Talk, heute streiten Herr Turm und Genossin Gisela über die Zinsen der Notenbank.',
        'Genossin: Wie Stephanie Kelton argumentiert, wirkt eine Zinserhöhung eigentlich wie ein Konjunkturprogramm.',
        'Markus: Das ist ungefähr so logisch, wie Feuer mit Benzin zu löschen und sich über die Wärme zu freuen.',
        'Sirius: Ja oder nein, mehr will ich von Ihnen gar nicht wissen.',
        'Genossin: Ja, und zwar weil der Staat dann mehr Zinsen zahlt!',
        'Markus: Und wer bezahlt diese Zinsen am Ende, wenn nicht die Sparer selbst?',
        'Sirius: Dann machen wir doch jetzt mal einen kleinen Faktencheck.',
        'Sirius: Die Positionsliste sagt genau das, ob es stimmt, ist eine ganz andere Frage.',
        'Genossin: Danke, endlich sagt das mal jemand deutlich hier im Studio.',
        'Markus: Gutes Geld braucht keine Erlaubnis von irgendeinem Ministerium oder einer Notenbank.',
        'Sirius: Ein Satz, Herr Turm, ein einziger Satz bitte!',
        'Genossin: Geld ist ein Werkzeug der Gesellschaft und kein Spielzeug für Spekulanten.',
        'Markus: Wer das Geld kontrolliert, kontrolliert die Menschen, und das sollte niemand dürfen.',
        'Sirius: Ich halte fest, beide haben recht, nur nicht gleichzeitig, und beim nächsten Talk geht es weiter.',
    ];

    foreach ($lines as $index => $line) {
        $talk[$index] = $line;
    }

    return ['text' => implode("\n", array_filter($talk, fn (?string $line): bool => $line !== null)), 'canon_id' => null, 'mood' => 'fakten'];
}

function talkSlot(string $time): array
{
    return BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse($time, 'Europe/Berlin'));
}

/** A VoiceStudio answer of `$seconds` of tone at 24 kHz and amplitude `$amplitude`. */
function talkWav(float $seconds, int $amplitude = 8000): string
{
    $pcm = '';
    for ($i = 0; $i < (int) (24000 * $seconds); $i++) {
        $pcm .= pack('v', ((int) ($amplitude * sin($i / 8))) & 0xFFFF);
    }

    return Pcm::toWav($pcm, 24000);
}

function talkRms(string $pcm): float
{
    $samples = unpack('s*', $pcm) ?: [];
    $sum = 0.0;
    foreach ($samples as $sample) {
        $sum += $sample * $sample;
    }

    return sqrt($sum / max(1, count($samples)));
}

/** A jingle directory with blips whose manifest names their texts. */
function talkJingleDir(): string
{
    $dir = sys_get_temp_dir().'/talk-jingles-'.bin2hex(random_bytes(4));
    $blips = ['markus' => ['Stimmt.', '[laughter]'], 'sirius' => ['Genau.', 'Hm.'], 'genossin' => ['Pff.']];
    $manifest = [];

    foreach ($blips as $speaker => $texts) {
        mkdir($dir.'/blips/'.$speaker, 0755, true);
        foreach ($texts as $index => $text) {
            $file = 'blips/'.$speaker.'/'.sprintf('%02d', $index + 1).'.wav';
            file_put_contents($dir.'/'.$file, Pcm::toWav(str_repeat(pack('v', 4), 12000), 24000));
            $manifest[] = ['speaker' => $speaker, 'text' => $text, 'file' => $file];
        }
    }

    file_put_contents($dir.'/manifest.json', json_encode(['items' => [], 'blips' => $manifest]));

    return $dir;
}

it('keeps a sourced sheet: twenty positions and more, each with its source and evidence, topics on real positions, the dramaturgy from real shows', function () {
    $positions = TalkShow::positions();
    $ids = array_column($positions, 'id');

    expect(count($positions))->toBeGreaterThanOrEqual(20)
        ->and(count(array_unique($ids)))->toBe(count($ids));

    foreach ([...$positions, ...TalkShow::positions(true)] as $row) {
        expect($row['source'])->toStartWith('https://')->and($row['evidence'])->not->toBeEmpty()->and($row['position'])->not->toContain('#');
    }

    foreach (TalkShow::topics() as $topic) {
        expect(array_diff($topic['positions'], $ids))->toBe([]);
    }

    $authors = array_unique(array_column($positions, 'author'));
    expect($authors)->toContain('Stephanie Kelton', 'Warren Mosler', 'Randall Wray', 'Dirk Ehnts', 'Maurice Höfgen');

    $dramaturgy = json_decode((string) file_get_contents(resource_path('data/voice/talkshow.json')), true);
    $urls = array_unique(array_merge(...array_map(fn (array $rule): array => array_column($rule['sources'], 'url'), $dramaturgy['rules'])));
    expect(count($urls))->toBeGreaterThanOrEqual(3)
        // The host is a parody of a type: no rule the prompt carries names a real host.
        ->and(TalkShow::dramaturgyBlock())->not->toContain('Lanz')->toContain('Faktencheck');
});

it('parses three speakers, the guest also as "Gisela:", bold or inline after a sentence end', function () {
    $turns = DuetScripts::parse("**Genossin Gisela:** Geld ist nie knapp!\nSirius: Moment, Moment! Gisela: Doch, wirklich. Markus: Nein!\nGenossin: Doch.");

    expect(array_column($turns, 'speaker'))->toBe(['genossin', 'sirius', 'genossin', 'markus', 'genossin'])
        ->and($turns[0]['text'])->toBe('Geld ist nie knapp!')
        ->and(DuetScripts::speakerNames())->toContain('genossin');

    // Without her profile she is no speaker: her lines stay with the speaker before.
    config(['tv.commentary.voicestudio.profiles.genossin' => '']);
    expect(array_column(DuetScripts::parse("Markus: Hallo zusammen.\nGisela: Hallo."), 'speaker'))->toBe(['markus']);
});

it('voices the guest with her profile, her tempo, faster when heated, and at her level', function () {
    config(['tv.commentary.voicestudio.levels' => ['markus' => 0, 'genossin' => -6], 'tv.commentary.voicestudio.profiles.markus' => 'ee7a2a18', 'tv.commentary.voicestudio.speed' => 1.05,
        'tv.commentary.voicestudio.speeds.genossin' => 1.0, 'tv.commentary.voicestudio.heated_speeds.genossin' => 1.08]);
    $calls = [];
    Http::fake(function (Request $request) use (&$calls) {
        if (str_ends_with($request->url(), '/generate')) {
            $data = collect($request->data());
            $calls[] = [$data->firstWhere('name', 'profile_id')['contents'] ?? null, $data->firstWhere('name', 'speed')['contents'] ?? null];

            return Http::response(talkWav(1.0));
        }

        return Http::response([]);
    });
    $fetcher = new CasterFetcher(fn () => null);

    $calm = $fetcher->voiceChunk(['Das ist reine Buchhaltung, Herr Turm.'], 'genossin');
    $fetcher->voiceChunk(['Das stimmt doch einfach nicht!'], 'genossin');
    $markus = $fetcher->voiceChunk(['Das ist ganz einfach falsch!'], 'markus');

    expect($calls)->toBe([['6575625b', '1'], ['6575625b', '1.08'], ['ee7a2a18', '1.05']])
        // Six dB under Markus: about half the amplitude.
        ->and(talkRms($calm['pcm']) / talkRms($markus['pcm']))->toBeGreaterThan(0.4)->toBeLessThan(0.6);
});

it('renders a talk show block: one batch per speaker, quicker turns, and across the sides only a laugh or a doubt', function () {
    config(['tv.commentary.voicestudio.profiles' => ['markus' => 'mmmm', 'sirius' => 'ssss', 'genossin' => 'gggg'],
        'tv.commentary.blocks.blips.share' => 1.0, 'tv.commentary.blocks.blips.min_gap_seconds' => 0,
        'tv.commentary.voicestudio.speaker_pause' => ['min' => 0.6, 'max' => 0.6],
        'tv.commentary.programme.shows.formats.stammtisch.turn_gap_ms' => ['min' => 150, 'max' => 150]]);
    $profiles = [];
    Http::fake(function (Request $request) use (&$profiles) {
        if (str_ends_with($request->url(), '/generate')) {
            $profiles[] = collect($request->data())->firstWhere('name', 'profile_id')['contents'] ?? null;

            return Http::response(talkWav(4.0));
        }

        return Http::response([]);
    });
    Process::fake(fn () => Process::result(output: str_repeat(pack('v', 9), 24000)));
    $library = new JingleLibrary(talkJingleDir(), 30);
    $renderer = new BlockRenderer(new CasterFetcher(fn () => null), $library, null, fn (): float => 0.0);
    $script = fn (?string $topic): array => ['id' => 1, 'kind' => 'rubric', 'topic' => $topic,
        'sentences' => ['Markus redet über gutes Geld.', 'Die Genossin widerspricht laut.', 'Sirius fährt dazwischen.', 'Markus kontert sofort.'],
        'speakers' => ['markus', 'genossin', 'sirius', 'markus']];

    $talk = $renderer->render([$script('stammtisch')], [], CarbonImmutable::parse('2026-10-13 18:15'));
    $duet = $renderer->render([$script('liga')], [], CarbonImmutable::parse('2026-10-13 18:15'));

    $files = array_map(fn (array $blip): string => $blip['speaker'].'/'.basename($blip['file']), $talk['blips']);

    expect($profiles)->toBe(['ssss', 'mmmm', 'mmmm', 'gggg', 'ssss', 'mmmm', 'mmmm', 'gggg'])
        ->and($talk['batches'])->toBe(['sirius', 'markus', 'genossin'])
        // Three speaker changes, each 0.45 s quicker than a duet's breath.
        ->and(Pcm::seconds($duet['pcm'], 24000) - Pcm::seconds($talk['pcm'], 24000))->toEqualWithDelta(3 * 0.45, 0.02)
        // Under Markus: the guest who answers him, her doubt; under the guest: Markus, whom she answers, laughing, never
        // "Stimmt."; under Sirius: the guest before him; under Markus again: Sirius, his own side, any blip.
        ->and($files)->toBe(['genossin/01.wav', 'markus/02.wav', 'genossin/01.wav', 'sirius/01.wav']);
});

it('rejects a quote a real person never said, and lets documented lines, paraphrases and the headline\'s own quotes through', function () {
    expect(TalkShow::quoteViolations('Genossin: Stephanie Kelton sagt: „Defizite sind immer gut und machen alle reich.“'))->toBe([TalkShow::QUOTE])
        ->and(TalkShow::quoteViolations('Genossin: Höffgen hat mal gesagt, „Schulden sind das neue Gold der Arbeiterklasse“.'))->toBe([TalkShow::QUOTE])
        ->and(TalkShow::quoteViolations('Markus: Mosler sagt: Steuern sind nur Deko für die Steuerberater.'))->toBe([TalkShow::QUOTE])
        // A documented verbatim line of the sheet, a paraphrase, a term in quotes, a lowercase "knapp".
        ->and(TalkShow::quoteViolations('Genossin: Maurice Höfgen fordert „ein Jahrzehnt der Investitionen, um die eigene Energieversorgung unabhängig von schwankenden Weltmarktpreisen zu machen“.'))->toBe([])
        ->and(TalkShow::quoteViolations('Genossin: Wie Stephanie Kelton argumentiert, ist der Staatshaushalt kein Haushaltsbuch einer Familie.'))->toBe([])
        ->and(TalkShow::quoteViolations('Markus: Die sogenannte „Modern Monetary Theory“ ist Voodoo, und Geld ist knapp: „sagt doch jeder hier am Tisch“.'))->toBe([])
        ->and(TalkShow::quoteViolations('Sirius: Lagarde sagt: „Wir bleiben wachsam bei den Preisen.“', 'Schlagzeile … Lagarde sagt: „Wir bleiben wachsam bei den Preisen.“'))->toBe([])
        ->and(TalkShow::quoteViolations('Sirius: Lagarde sagt: „Wir drucken jetzt einfach mehr Geld für alle.“', 'Schlagzeile … Lagarde sagt: „Wir bleiben wachsam bei den Preisen.“'))->toBe([TalkShow::QUOTE]);
});

it('checks a talk: the guest opens, the host closes after the fact check, every voice often, short turns, no self-label', function () {
    $writer = app(TalkShowScripts::class);
    talkHeadline();
    $material = app(TalkShow::class)->material(CarbonImmutable::now());

    [$talk, $violations] = $writer->evaluate($material, talkAnswer()['text']);
    expect($violations)->toBe([])
        ->and($talk['speakers'][0])->toBe('genossin')
        ->and($talk['speakers'][count($talk['speakers']) - 1])->toBe('sirius')
        // The crossfire's one-liner stays (five words, under the seven of a sentence elsewhere).
        ->and($talk['text'])->toContain('Nein, nein, nein, so einfach ist das wirklich nicht!');

    // A turn of five sentences keeps its first three.
    [$long] = $writer->evaluate($material, talkAnswer([7 => 'Genossin: Ja, und zwar weil der Staat dann mehr Zinsen zahlt! Das ist der erste Grund dafür. Das ist der zweite Grund dafür. Das ist der dritte Grund dafür. Das ist der vierte Grund dafür.'])['text']);
    expect($long['text'])->toContain('Das ist der zweite Grund dafür.')->not->toContain('Das ist der dritte Grund dafür.');

    expect($writer->evaluate($material, talkAnswer([0 => null, 1 => null])['text'])[1])->toContain(TalkShowScripts::SHAPE)
        ->and($writer->evaluate($material, talkAnswer([9 => 'Sirius: Dann schauen wir uns das jetzt einmal ganz genau gemeinsam an.'])['text'])[1])->toContain(TalkShowScripts::SHAPE)
        ->and($writer->evaluate($material, talkAnswer([16 => null])['text'])[1])->toContain(TalkShowScripts::SHAPE)
        ->and($writer->evaluate($material, talkAnswer([12 => 'Markus: Als Anarchokapitalist sage ich, gutes Geld braucht keine Erlaubnis.'])['text'])[1])->toContain(TalkShowScripts::LABEL)
        ->and($writer->evaluate($material, talkAnswer([5 => 'Markus: Gnädigste, das ist ungefähr so logisch wie Feuer mit Benzin zu löschen.'])['text'])[1])->toContain(TalkShowScripts::GENDERED)
        ->and($writer->evaluate($material, talkAnswer([4 => 'Genossin: Stephanie Kelton sagt: „Defizite machen jeden Menschen sofort reich.“'])['text'])[1])->toContain(TalkShow::QUOTE)
        ->and($writer->evaluate($material, talkAnswer([5 => 'Markus: Seit dreiundvierzig Jahren erzählt ihr Linken dieselbe Geschichte vom Gelddrucken.'])['text'])[1])->toContain(ShowScripts::NUMBER);

    config(['tv.commentary.programme.shows.formats.stammtisch.min_turns' => 6]);
    expect($writer->evaluate($material, talkAnswer()['text'])[1])->toContain(ShowScripts::TURNS);
});

it('cuts an overlong talk from the middle, so the cold open and the closing round stay', function () {
    $lines = ['Genossin: Erster Satz der kalten Eröffnung.', 'Markus: Zweiter Satz als Antwort darauf.'];
    $speakers = ['genossin', 'markus'];
    for ($i = 0; $i < 20; $i++) {
        $lines[] = 'Mittlerer Satz Nummer '.$i.' im Kreuzfeuer der beiden.';
        $speakers[] = $i % 2 === 0 ? 'sirius' : 'genossin';
    }
    array_push($lines, 'Schlusssatz der Genossin hier.', 'Schlusssatz des Gastgebers hier.');
    array_push($speakers, 'genossin', 'sirius');

    [$kept, $keptSpeakers] = TalkShowScripts::withinLength($lines, $speakers, 30.0, 110);

    expect(ScriptQueue::estimateSeconds(implode("\n", $kept)))->toBeLessThanOrEqual(30.0)
        ->and(array_slice($kept, 0, 2))->toBe(array_slice($lines, 0, 2))
        ->and(array_slice($kept, -2))->toBe(array_slice($lines, -2))
        ->and(count($kept))->toBe(count($keptSpeakers));
});

it('takes its slots at 18:15 and 22:15 on a real money headline, and leaves the slot to the rotation without one', function () {
    $rubrics = app(Rubrics::class);

    expect(app(Shows::class)->scheduledFor(talkSlot('2026-10-13 18:15:00')))->toBe(Shows::STAMMTISCH)
        ->and(app(Shows::class)->scheduledFor(talkSlot('2026-10-13 22:15:00')))->toBe(Shows::STAMMTISCH)
        // No money headline: the rotation's rubric airs.
        ->and($rubrics->plannedFor(talkSlot('2026-10-13 18:15:00'), CarbonImmutable::now()))->not->toBe(Shows::STAMMTISCH);

    talkHeadline('Bayern gewinnt gegen Dortmund', 'Ein Spiel ohne jede Bedeutung für das Geld.');
    expect(app(TalkShow::class)->material(CarbonImmutable::now()))->toBeNull();

    talkHeadline();
    $material = app(TalkShow::class)->material(CarbonImmutable::now());
    expect($material['data'])->toContain('EZB lässt den Leitzins unverändert')->toContain('Positionsliste der Genossin')->toContain('kelton-haushalt')
        ->and($material['names'])->toContain('Genossin Gisela', 'Stephanie Kelton')
        ->and($rubrics->plannedFor(talkSlot('2026-10-13 22:15:00'), CarbonImmutable::now()))->toBe(Shows::STAMMTISCH);

    // The same headline does not come again within repeat_days.
    Shows::markUsed(Shows::STAMMTISCH, $material['keys'], CarbonImmutable::now());
    expect(app(TalkShow::class)->material(CarbonImmutable::now()))->toBeNull();

    // Without the guest's voice there is no talk show.
    Cache::flush();
    config(['tv.commentary.voicestudio.profiles.genossin' => '']);
    expect(app(TalkShow::class)->material(CarbonImmutable::now()))->toBeNull();
});

it('writes the live talk into the 18:15 slot: the host reads the opener, three voices follow, the prompt carries the guest, the sheet and the host moves', function () {
    talkHeadline();
    $prompts = [];
    VoiceAgent::fake(function (string $prompt) use (&$prompts): array {
        $prompts[] = $prompt;

        return talkAnswer();
    });
    $this->travelTo(CarbonImmutable::parse('2026-10-13 18:05:00', 'Europe/Berlin'));

    expect(app(ScriptQueue::class)->fillRubric(CarbonImmutable::now()))->toBe(1);

    $rows = CasterScript::query()->where('topic', Shows::STAMMTISCH)->orderBy('id')->get();
    $speakers = $rows->flatMap(fn (CasterScript $row): array => $row->lineSpeakers() ?? [])->all();

    expect($rows->first()->sentences()[0])->toContain('Turm und Genossin, der Talk')->toContain('achtzehn Uhr fünfzehn')
        ->and($speakers[0])->toBe('sirius')
        ->and(array_values(array_unique($speakers)))->toEqualCanonicalizing(['sirius', 'genossin', 'markus'])
        ->and($prompts)->toHaveCount(1)
        ->and($prompts[0])->toContain('Dritte Stimme: Genossin Gisela')->toContain('Kalter Einstieg')->toContain('Wendungen des Gastgebers')
        ->toContain('EZB lässt den Leitzins unverändert')->toContain('Wörtlich belegt')->not->toContain('Lanz');
});

it('writes an evergreen talk for the night stock at most stock_per_day times a day, without a headline and without a time of day', function () {
    config(['tv.commentary.blocks.stock.formats' => ['stammtisch'], 'tv.commentary.blocks.stock.max_age_minutes' => 600,
        'tv.commentary.programme.shows.formats.stammtisch.stock_per_day' => 2]);
    $prompts = [];
    VoiceAgent::fake(function (string $prompt) use (&$prompts): array {
        $prompts[] = $prompt;

        return talkAnswer([11 => 'Genossin: Heute Abend um acht Uhr sage ich das noch einmal ganz deutlich.']);
    });
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00:00', 'Europe/Berlin'));
    $queue = app(ScriptQueue::class);

    // Claim every part of every talk the stock writes: after the second talk of the day nothing new comes.
    $claimed = 0;
    while ($claimed < 60 && $queue->claimStock(CarbonImmutable::now()) !== null) {
        $claimed++;
    }
    expect($claimed)->toBeLessThan(60)->and(count($prompts))->toBe(2);

    $talks = CasterScript::query()->where('topic', StockScripts::TOPIC_PREFIX.Shows::STAMMTISCH)->orderBy('id')->get();
    $text = $talks->pluck('text')->implode("\n");

    expect(TalkShow::stockedToday(CarbonImmutable::now()))->toBe(2)
        ->and($text)->not->toContain('Heute Abend')
        ->and($prompts[0])->toContain('zeitlos')->not->toContain('Die Schlagzeile für den Talk')
        // The third talk of the day: the format has no material, nothing is written.
        ->and(app(TalkShow::class)->evergreen(CarbonImmutable::now()))->toBeNull()
        ->and(TalkShow::turnGap(StockScripts::TOPIC_PREFIX.Shows::STAMMTISCH))->not->toBeNull()
        ->and(TalkShow::turnGap('liga'))->toBeNull();
});

it('offers ten host moves per episode from every group, rotating, and never one said on air within the repeat window', function () {
    config(['tv.commentary.repeat_hours' => 24, 'tv.commentary.repeat_phrase_hours' => 6]);
    $groups = TalkShow::hostMoveGroups();
    $all = array_merge(...array_values($groups));

    expect(count($all))->toBeGreaterThanOrEqual(80)->and(count($groups))->toBeGreaterThanOrEqual(10)
        ->and(count(array_unique($all)))->toBe(count($all));

    $first = TalkShow::hostMoves(CarbonImmutable::now());
    $second = TalkShow::hostMoves(CarbonImmutable::now());
    $groupOf = fn (string $move): string => (string) collect($groups)->search(fn (array $moves): bool => in_array($move, $moves, true));

    expect($first)->toHaveCount(10)->and($second)->toHaveCount(10)
        ->and(count(array_unique(array_map($groupOf, $first))))->toBe(10)
        // Offered longest ago first: the next episode gets ten others.
        ->and(array_intersect($first, $second))->toBe([]);

    // A move that went on air within the day is not offered again, from any of its groups.
    $said = $groups['beruhigen'][0];
    CasterScript::query()->create(['kind' => CasterScript::RUBRIC, 'phase' => 'break', 'source' => CasterScript::SOURCE_AI, 'topic' => Shows::STAMMTISCH, 'priority' => 70,
        'text' => $said, 'facts_hash' => 'x', 'status' => CasterScript::QUEUED, 'estimated_seconds' => 3, 'expires_at' => now()->addHour()]);

    for ($episode = 0; $episode < 30; $episode++) {
        expect(TalkShow::hostMoves(CarbonImmutable::now()))->not->toContain($said);
    }

    // A day later it comes back into the rotation.
    $this->travelTo(now()->addHours(25));
    $offered = [];
    for ($episode = 0; $episode < 30; $episode++) {
        $offered = [...$offered, ...TalkShow::hostMoves(CarbonImmutable::now())];
    }
    expect($offered)->toContain($said);
});
