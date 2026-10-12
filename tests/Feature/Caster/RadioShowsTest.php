<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\CasterScript;
use App\Models\ChatterLine;
use App\Models\ChatterNote;
use App\Models\ChatterProfile;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\User;
use App\Support\Caster\Blocks\BlockComposer;
use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\Programme\Announcements;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\MatchOfTheDay;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\ShowScripts;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\WebLookupAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: six long formats (P9)
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Mehr Programm … damit es nicht langweilig wird, müssen
| wir roasten!" Chat-Tribunal, Shitcoin-Friedhof, Bitcoin-Zeitreise,
| Meetup-Radar, Partie des Tages and Satoshis Hotline, each on its slot of
| the clock, each from real data only: the prompt carries the data, the
| guard rejects a number, a year or a name that is not in it, and a source
| that is down or empty skips the format (the rotation takes the slot).
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
        'tv.commentary.programme.teasers.enabled' => true,
        'tv.commentary.programme.shows.enabled' => true,
        'tv.commentary.programme.shows.formats.jahrestag.wikipedia' => true,
        'tv.commentary.chat.web_search' => true,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.parts.sentences' => 5,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
        'esports.voice.monthly_budget_usd' => 100,
    ]);

    // Short formats for the fakes: the length rules are the same code as the rubrics'.
    foreach (array_keys(Shows::CATALOGUE) as $id) {
        config(["tv.commentary.programme.shows.formats.{$id}.seconds" => ['min' => 20, 'max' => 400], "tv.commentary.programme.shows.formats.{$id}.sentences" => ['min' => 6, 'max' => 40]]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-12 20:38:00', 'Europe/Berlin'));
    WorldMemory::forget();
    showsSources();
});

/**
 * The fake outside world: the portal's meetup dates and Wikipedia's "on this day"; `$down` names the ones that fail.
 *
 * @param  list<string>  $down
 */
function showsSources(array $down = []): void
{
    // Http::fake() stacks its stubs: the first callback stays in charge and reads the switch.
    $GLOBALS['showsDown'] = $down;

    if (($GLOBALS['showsFaked'] ?? null) === app()) {
        return;
    }

    $GLOBALS['showsFaked'] = app();
    Http::fake(function (Request $request) {
        $url = $request->url();
        $down = $GLOBALS['showsDown'];

        if (str_contains($url, 'portal.einundzwanzig.space')) {
            return in_array('portal', $down, true) ? Http::response('oops', 500) : Http::response([
                showsMeetup(1, 'Einundzwanzig Kempten', 'Kempten', 'DE', '2026-10-14T16:30:00+00:00', 3),
                showsMeetup(2, 'Einundzwanzig Wien', 'Wien', 'AT', '2026-10-16T17:00:00+00:00', 0, 'Stammtisch im Café'),
                showsMeetup(3, 'Einundzwanzig Hamburg', 'Hamburg', 'DE', '2026-10-25T17:00:00+00:00', 5),
                showsMeetup(4, 'Einundzwanzig Trier', 'Trier', 'DE', '2026-09-01T17:00:00+00:00', 1),
            ]);
        }

        if (str_contains($url, 'onthisday')) {
            return in_array('wikipedia', $down, true) ? Http::response([], 503) : Http::response(['events' => [
                ['text' => 'Ein Vulkan bricht aus.', 'year' => 1990, 'pages' => [['title' => 'Vulkan']]],
                ['text' => 'Eine Börse listet erstmals eine Bitcoin-Anleihe.', 'year' => 2019, 'pages' => [['title' => 'Bitcoin']]],
            ]]);
        }

        return Http::response([], 404);
    });
}

/** @return array<string, mixed> one portal meetup date */
function showsMeetup(int $id, string $name, string $city, string $country, string $start, int $attendees, ?string $title = null): array
{
    return ['id' => $id, 'title' => $title, 'start_iso' => $start, 'attendees' => $attendees, 'meetup.name' => $name, 'meetup.city' => $city, 'meetup.country' => $country];
}

function showsChatter(string $key, ?string $name, array $messages, array $notes = [], array $gamerTags = []): string
{
    $pubkey = str_repeat($key, 64);
    ChatterProfile::query()->create(['pubkey' => $pubkey, 'name' => $name, 'last_chat_at' => now()]);

    foreach ($messages as $index => [$message, $minutesAgo]) {
        ChatterLine::query()->create(['pubkey' => $pubkey, 'name' => $name, 'message' => $message, 'said_at' => now()->subMinutes($minutesAgo)->getTimestamp(), 'reply' => $index === 0 ? null : 'Markus sagte etwas']);
    }

    foreach ($notes as $index => $note) {
        ChatterNote::query()->create(['pubkey' => $pubkey, 'event_id' => $key.$index.str_repeat('0', 62), 'note_created_at' => now()->subHours($index + 1)->getTimestamp(), 'content' => $note]);
    }

    if ($gamerTags !== []) {
        User::factory()->create(['pubkey' => $pubkey, 'gamer_tags' => $gamerTags]);
    }

    return $pubkey;
}

function showsSlot(string $time): array
{
    return BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse($time, 'Europe/Berlin'));
}

/**
 * A model answer of `$lines` alternating between the speakers (or as given: "Name: text" lines are kept).
 *
 * @param  list<string>  $lines
 * @param  list<string>  $speakers
 */
function showsAnswer(array $lines, array $speakers = ['Markus', 'Sirius']): array
{
    return ['text' => implode("\n", array_map(fn (string $line, int $index): string => preg_match('/^\p{L}+: /u', $line) === 1 ? $line : $speakers[$index % count($speakers)].': '.$line, $lines, array_keys($lines))), 'canon_id' => null, 'mood' => 'fakten'];
}

/** @return list<string> long, whole German sentences without facts of their own */
function showsFiller(): array
{
    return [
        'Ich sage euch ehrlich, dass mich diese Mischung aus Größenwahn und Ahnungslosigkeit jedes Mal wieder richtig begeistert.',
        'Während die Zentralbanken weiter Geld drucken, sitzen wir hier und schauen uns die Trümmer in aller Ruhe genau an.',
        'Das ist die Art von Entschlossenheit, die ich mir von manchem Finanzminister wirklich auch einmal wünschen würde.',
        'Wer jetzt noch glaubt, dass dieses Thema langweilig ist, hat offenbar den ganzen Abend komplett verschlafen.',
        'Die Konkurrenz wird sich das genau anschauen, denn so etwas passiert selten ganz ohne eine lange Vorgeschichte.',
        'Ich bin gespannt, ob die nächste Runde genauso viel Drama bringt oder ob dann doch alle etwas vorsichtiger werden.',
    ];
}

it('puts each long format on its scheduled rubric slot, and the rotation takes a slot whose format has nothing', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00:00', 'Europe/Berlin'));
    showsChatter('a', 'Fritzi', [['Ist der Lightning-Kanal eigentlich wirklich so teuer wie alle immer behaupten?', 30]]);
    $rubrics = app(Rubrics::class);

    expect($rubrics->plannedFor(showsSlot('2026-10-31 10:15:00'), CarbonImmutable::now()))->toBe(Shows::JAHRESTAG)
        ->and($rubrics->plannedFor(showsSlot('2026-10-31 11:15:00'), CarbonImmutable::now()))->toBeIn([Shows::MEETUPS, 'chat'])
        ->and($rubrics->plannedFor(showsSlot('2026-10-31 12:45:00'), CarbonImmutable::now()))->toBe(Shows::FRIEDHOF)
        ->and($rubrics->plannedFor(showsSlot('2026-10-31 20:45:00'), CarbonImmutable::now()))->toBe(Shows::TRIBUNAL)
        ->and($rubrics->plannedFor(showsSlot('2026-10-31 21:15:00'), CarbonImmutable::now()))->toBe(Shows::HOTLINE)
        // No league match: the Partie des Tages has nothing, the rotation's rubric airs.
        ->and($rubrics->plannedFor(showsSlot('2026-10-31 15:15:00'), CarbonImmutable::now()))->toBe('chat')
        // A slot without a format keeps the rotation.
        ->and($rubrics->plannedFor(showsSlot('2026-10-31 14:15:00'), CarbonImmutable::now()))->toBe('chat');

    // Switched off, the schedule is ignored.
    config(['tv.commentary.programme.shows.enabled' => false]);
    expect(app(Shows::class)->scheduledFor(showsSlot('2026-11-01 20:45:00')))->toBeNull();
});

it('tries the Chat-Tribunal: the day\'s named chatters with their lines and own notes, never a gamer tag, a hidden or a nameless one', function () {
    showsChatter('a', 'Fritzi', [['Wer hat eigentlich den Stream-Ton heute wieder so laut gedreht?', 300], ['Ich kaufe ab jetzt nur noch Pizza mit Sats, ehrlich.', 200], ['Spielt gegen mich, ich bin Blitzkoenig auf dem Brett.', 150]],
        ['Heute wieder den ganzen Tag Nodes gebaut und keinen einzigen Kaffee getrunken.', 'Mein Gamertag ist geheim, aber ich bin der Blitzkoenig hier.'], ['games' => 'Blitzkoenig']);
    showsChatter('b', 'Hodlbert', [['Mein Schach ist besser als mein Timing beim Kaufen, das schwöre ich.', 100]]);
    showsChatter('c', null, [['Ich habe keinen Namen und schreibe trotzdem ganz viel hier.', 50]]);
    showsChatter('d', 'Gestern', [['Das war vor drei Tagen und zählt nicht mehr.', 60 * 30]]);

    $material = app(Shows::class)->material(Shows::TRIBUNAL, CarbonImmutable::now());

    expect($material['data'])->toContain('Angeklagt: Fritzi')->toContain('Pizza mit Sats')->toContain('Nodes gebaut')
        ->toContain('Angeklagt: Hodlbert')->not->toContain('keinen Namen')->not->toContain('Blitzkoenig')->not->toContain('vor drei Tagen')
        ->and($material['names'])->toEqualCanonicalizing(['Fritzi', 'Hodlbert'])
        // The gamer tag and the chatter who is not on trial are foreign: a draft naming them goes back.
        ->and($material['foreign'])->toContain('blitzkoenig')->toContain('Gestern');

    expect(ShowScripts::factual('Sirius: Der Angeklagte Blitzkoenig wird zu drei Wochen ohne Liquidität verurteilt.', $material))->toContain(ShowScripts::FOREIGN)
        ->and(ShowScripts::factual('Sirius: Auch Gestern sitzt heute auf der Anklagebank des Senders.', $material))->toContain(ShowScripts::FOREIGN)
        ->and(ShowScripts::factual('Sirius: Fritzi wird zu einem Monat Seed-Phrase auf dem Kühlschrank verurteilt.', $material))->toBe([]);

    // Nobody chatted: no tribunal.
    ChatterLine::query()->delete();
    expect(app(Shows::class)->material(Shows::TRIBUNAL, CarbonImmutable::now()))->toBeNull();
});

it('buries a dead coin of the curated list, each with a source, and none again within thirty days', function () {
    $coins = Shows::coins();
    expect(count($coins))->toBeGreaterThanOrEqual(9);
    foreach ($coins as $coin) {
        expect($coin['source'])->toStartWith('https://')->and($coin['facts'])->not->toBeEmpty();
    }

    $now = CarbonImmutable::now();
    $first = app(Shows::class)->material(Shows::FRIEDHOF, $now);
    expect($first['data'])->toStartWith('Der Verstorbene: ')->and($first['sources'][0])->toStartWith('https://');

    Shows::markUsed(Shows::FRIEDHOF, $first['keys'], $now);
    $second = app(Shows::class)->material(Shows::FRIEDHOF, $now->addDay());
    expect($second['keys'])->not->toBe($first['keys']);

    foreach ($coins as $coin) {
        Shows::markUsed(Shows::FRIEDHOF, ['friedhof:'.$coin['id']], $now);
    }
    expect(app(Shows::class)->material(Shows::FRIEDHOF, $now->addDays(29)))->toBeNull()
        ->and(app(Shows::class)->material(Shows::FRIEDHOF, $now->addDays(31)))->not->toBeNull();
});

it('checks a eulogy against its facts: no other year, no other amount, no other coin\'s people', function () {
    $terra = collect(Shows::coins())->firstWhere('id', 'terra-luna');
    $material = Shows::build("Der Verstorbene: {$terra['name']}\n".implode("\n", $terra['facts']), $terra['names'], ['Sam Bankman-Fried', 'Ruja Ignatova'], 'x', ['friedhof:terra-luna']);

    expect(ShowScripts::factual('Markus: Im Jahr zweitausendzweiundzwanzig verdampften fast fünfundvierzig Milliarden Dollar in einer einzigen Woche.', $material))->toBe([])
        ->and(ShowScripts::factual('Markus: Im Jahr zweitausendneunzehn verdampften fast fünfundvierzig Milliarden Dollar.', $material))->toBe([ShowScripts::YEAR])
        ->and(ShowScripts::factual('Markus: Dabei verdampften fast sechzig Milliarden Dollar in einer Woche.', $material))->toBe([ShowScripts::NUMBER])
        ->and(ShowScripts::factual('Markus: Dabei verdampften 60 Milliarden Dollar in einer Woche.', $material))->toBe([ShowScripts::NUMBER])
        // Common ground needs no data: a block every ten minutes (real Sonnet eulogy 2026-10-12 was turned down for it).
        ->and(ShowScripts::factual('Markus: Bitcoin findet dagegen ganz ohne Podium alle zehn Minuten einen neuen Block.', $material))->toBe([])
        ->and(ShowScripts::factual('Markus: Do Kwon ist ungefähr so vertrauenswürdig wie Sam Bankman-Fried an der Kasse.', $material))->toBe([ShowScripts::FOREIGN]);
});

it('travels back on a day with Bitcoin history, curated and Wikipedia\'s bitcoin events only, and skips a day without', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00:00', 'Europe/Berlin'));
    $material = app(Shows::class)->material(Shows::JAHRESTAG, CarbonImmutable::now());

    expect($material['data'])->toContain('Heute vor 18 Jahren, am 31. Oktober 2008')->toContain('Whitepaper')
        ->toContain('Heute vor 7 Jahren, am 31. Oktober 2019: Eine Börse listet erstmals eine Bitcoin-Anleihe')->not->toContain('Vulkan')
        ->and($material['years'])->toContain(2008)->toContain(2019)
        ->and(ShowScripts::factual('Markus: Heute vor achtzehn Jahren, im Jahr zweitausendacht, kam das Whitepaper.', $material))->toBe([])
        ->and(ShowScripts::factual('Markus: Heute vor zwanzig Jahren kam das Whitepaper.', $material))->toBe([ShowScripts::NUMBER]);

    // Wikipedia down: the curated list alone.
    Cache::flush();
    showsSources(['wikipedia']);
    expect(app(Shows::class)->material(Shows::JAHRESTAG, CarbonImmutable::now())['data'])->toContain('Whitepaper')->not->toContain('Anleihe');

    // 12 October: nothing curated, Wikipedia down: skipped.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'Europe/Berlin'));
    expect(app(Shows::class)->material(Shows::JAHRESTAG, CarbonImmutable::now()))->toBeNull();
});

it('sweeps the meetup radar over the next seven days from the portal, rejects a city without a date, and skips when the portal is down', function () {
    $material = app(Shows::class)->material(Shows::MEETUPS, CarbonImmutable::now());

    expect($material['data'])->toContain('Insgesamt 2 Meetup-Termine')->toContain('Einundzwanzig Kempten in Kempten (Deutschland)')->toContain('bisher 3 Zusagen')
        ->toContain('Mittwoch um achtzehn Uhr dreißig')->toContain('Einundzwanzig Wien in Wien (Österreich), Titel „Stammtisch im Café“')
        ->not->toContain('Hamburg')->not->toContain('Trier')
        ->and($material['foreign'])->toEqualCanonicalizing(['Hamburg', 'Trier'])
        ->and(ShowScripts::factual('Sirius: In Hamburg trinkt man dafür bestimmt nur Wasser.', $material))->toBe([ShowScripts::FOREIGN])
        ->and(ShowScripts::factual('Sirius: In Kempten haben schon drei Zusagen den Mut gefunden.', $material))->toBe([]);

    Cache::flush();
    showsSources(['portal']);
    expect(app(Shows::class)->material(Shows::MEETUPS, CarbonImmutable::now()))->toBeNull();
    Http::assertSentCount(2);
    // Remembered as down: the next minute does not ask again.
    expect(app(Shows::class)->material(Shows::MEETUPS, CarbonImmutable::now()))->toBeNull();
    Http::assertSentCount(2);
});

it('picks the most dramatic match of the day as the Partie des Tages, its moves in words, another one at the second airing', function () {
    $ada = User::factory()->create(['name' => 'Adalinde']);
    $bob = User::factory()->create(['name' => 'Bobbert']);
    $quick = ChessGame::factory()->create(['white_id' => $ada->id, 'black_id' => $bob->id, 'status' => ChessGameStatus::Finished, 'result' => '1-0', 'end_reason' => ChessEndReason::Checkmate,
        'mode' => 'blitz', 'ply' => 12, 'white_ms' => 120_000, 'black_ms' => 90_000, 'created_at' => now()->subMinutes(70), 'ended_at' => now()->subMinutes(60)]);
    $drama = ChessGame::factory()->create(['white_id' => $bob->id, 'black_id' => $ada->id, 'status' => ChessGameStatus::Finished, 'result' => '0-1', 'end_reason' => ChessEndReason::Timeout,
        'mode' => 'blitz', 'ply' => 81, 'white_ms' => 0, 'black_ms' => 4_000, 'created_at' => now()->subMinutes(200), 'ended_at' => now()->subMinutes(180)]);
    foreach (['e4', 'e5', 'Nf3', 'Nc6', 'Bb5', 'a6', 'Bxc6', 'dxc6'] as $index => $san) {
        ChessMove::query()->create(['chess_game_id' => $drama->id, 'ply' => $index + 1, 'uci' => 'e2e4', 'san' => $san, 'fen' => 'x', 'spent_ms' => 1000, 'clock_ms' => 1000]);
    }

    $material = app(Shows::class)->material(Shows::PARTIE, CarbonImmutable::now());
    expect($material['keys'])->toBe(['chess:'.$drama->id])
        ->and($material['data'])->toContain('Adalinde gewinnt mit Schwarz gegen Bobbert auf Zeit')->toContain('41 Züge')->toContain('Restzeit des Siegers am Ende: 4 Sekunden')
        ->toContain('e vier, e fünf, Springer f drei, Springer c sechs, Läufer b fünf, a sechs')->toContain('Läufer schlägt c sechs, Bauer d schlägt c sechs')
        ->and(MatchOfTheDay::spokenMove('O-O-O+'))->toBe('lange Rochade, Schach')
        ->and(MatchOfTheDay::spokenMove('Qxh7#'))->toBe('Dame schlägt h sieben, Matt')
        ->and(ShowScripts::factual('Markus: Nach einundvierzig Zügen fiel die Fahne mit vier Sekunden Restzeit.', $material))->toBe([])
        ->and(ShowScripts::factual('Markus: Nach fünfzig Zügen fiel die Fahne.', $material))->toBe([ShowScripts::NUMBER]);

    Shows::markUsed(Shows::PARTIE, $material['keys'], CarbonImmutable::now());
    expect(app(Shows::class)->material(Shows::PARTIE, CarbonImmutable::now())['keys'])->toBe(['chess:'.$quick->id]);
});

it('collects the hotline\'s questions, unanswered first, looks each one up and goes on air only with something found', function () {
    showsChatter('a', 'Fritzi', [['Wie viele Bitcoin wird es am Ende eigentlich wirklich geben?', 40], ['Was ist eigentlich ein Mempool und warum ist der manchmal so voll?', 30]]);
    showsChatter('b', 'Hodlbert', [['Warum heißt das Ding eigentlich Lightning und nicht Donner?', 20]]);
    showsChatter('c', 'Kurz', [['Echt jetzt?', 10]]);

    $material = app(Shows::class)->material(Shows::HOTLINE, CarbonImmutable::now());
    expect($material['questions'])->toHaveCount(3)
        ->and($material['data'])->toContain('Frage von Fritzi (noch unbeantwortet): „Wie viele Bitcoin')->not->toContain('Echt jetzt');

    WebLookupAgent::fake(['Es wird höchstens 21 Millionen Bitcoin geben. Danach leben Miner von Gebühren, laut https://bitcoin.test/faq.']);
    $looked = app(Shows::class)->withLookups($material, app(CasterVoice::class));
    expect($looked['data'])->toContain('Recherche dazu: Es wird höchstens 21 Millionen Bitcoin geben')->not->toContain('https://')
        // A word the voice never says (a fee) leaves the data with its sentence: it would come back in every draft.
        ->and($looked['data'])->not->toContain('Gebühren')
        ->and($looked['digits'])->toContain('21')
        ->and(ShowScripts::factual('Sirius: Es gibt höchstens einundzwanzig Millionen Bitcoin, Fritzi, das steht sogar auf jedem Sticker.', $looked))->toBe([])
        ->and(ShowScripts::factual('Sirius: Es gibt höchstens zweiundzwanzig Millionen Bitcoin.', $looked))->toBe([ShowScripts::NUMBER]);
    WebLookupAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Wie viele Bitcoin wird es am Ende'));

    // Nothing found for any question: no hotline (never answered from thin air).
    Cache::flush();
    WebLookupAgent::fake([WebLookupAgent::NOTHING, WebLookupAgent::NOTHING, WebLookupAgent::NOTHING]);
    expect(app(Shows::class)->withLookups($material, app(CasterVoice::class)))->toBeNull();
});

it('writes a format: the time and its name first, the cast in turns, the data in the prompt, a rejected draft tried once more', function () {
    showsChatter('a', 'Fritzi', [['Ich kaufe ab jetzt nur noch Pizza mit Sats, ehrlich.', 200]]);
    showsChatter('b', 'Hodlbert', [['Mein Schach ist besser als mein Timing beim Kaufen, das schwöre ich.', 100]]);
    $material = app(Shows::class)->material(Shows::TRIBUNAL, CarbonImmutable::now());
    $calls = 0;
    VoiceAgent::fake(function () use (&$calls): array {
        $calls++;
        $lines = [...showsFiller(), 'Fritzi wird zu drei Wochen Lightning-Kanal ohne jede Liquidität verurteilt, und zwar ohne Bewährung.'];

        // The first draft drags in a chatter who is not on trial.
        return showsAnswer($calls === 1 ? [...$lines, 'Und Gestern bekommt gleich dieselbe Strafe, weil er auch immer so viel schreibt.'] : $lines);
    });
    showsChatter('c', 'Gestern', [['Alt.', 60 * 40]]);
    $material = app(Shows::class)->material(Shows::TRIBUNAL, CarbonImmutable::now());

    $show = app(ShowScripts::class)->write(showsSlot('2026-10-12 20:45:00'), Shows::TRIBUNAL, $material, CarbonImmutable::now());
    $lines = ScriptGuard::lines($show['text']);

    expect($calls)->toBe(2)
        ->and($lines[0])->toContain('zwanzig Uhr fünfundvierzig')->toContain('das Chat-Tribunal')
        ->and($show['text'])->not->toContain('Gestern bekommt')
        ->and($show['speakers'])->toHaveCount(count($lines))
        ->and(array_values(array_unique($show['speakers'])))->toEqualCanonicalizing([DuetDirector::MARKUS, DuetDirector::SIRIUS]);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Pizza mit Sats') && str_contains($prompt->prompt, 'Sirius ist der Richter')
        && str_contains($prompt->prompt, 'Markus ist der Verteidiger'));
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'einen Namen, der nicht ins Material gehört'));
});

it('turns down a draft in which one of the cast hardly speaks, and a voice outside the cast is left out', function () {
    $terra = collect(Shows::coins())->firstWhere('id', 'terra-luna');
    $material = Shows::build("Der Verstorbene: {$terra['name']}\n".implode("\n", $terra['facts']), $terra['names'], [], 'x', ['friedhof:terra-luna']);

    [$solo, $why] = app(ShowScripts::class)->evaluate(Shows::FRIEDHOF, $material, showsAnswer(showsFiller(), ['Markus'])['text']);
    expect($solo)->toBeNull()->and($why)->toContain(ShowScripts::TURNS);

    config(['tv.commentary.voicestudio.profiles.anna' => 'abcd1234']);
    [$duet] = app(ShowScripts::class)->evaluate(Shows::FRIEDHOF, $material, showsAnswer([...showsFiller(), 'Anna: Ich bin gar nicht eingeladen und rede trotzdem einfach mit, weil ich das so will.'])['text']);
    expect($duet['text'])->not->toContain('nicht eingeladen');
});

it('parses three speakers once a third voice has a profile, and a format can cast it', function () {
    // Without a profile "Anna:" is text of the turn it stands in.
    expect(collect(DuetScripts::parse("Markus: Eins zwei.\nAnna: Drei vier.\nSirius: Fünf sechs."))->pluck('speaker')->all())->toBe(['markus', 'sirius']);

    config(['tv.commentary.voicestudio.profiles.anna' => 'abcd1234', 'tv.commentary.programme.shows.formats.tribunal.speakers' => ['markus', 'sirius', 'anna']]);
    expect(collect(DuetScripts::parse("Markus: Eins zwei.\nAnna: Drei vier.\nSirius: Fünf sechs. Anna: Sieben acht."))->pluck('speaker')->all())->toBe(['markus', 'anna', 'sirius', 'anna'])
        ->and(Shows::cast(Shows::TRIBUNAL))->toBe(['markus', 'sirius', 'anna'])
        ->and(ShowScripts::prompt(Shows::TRIBUNAL, Shows::build('Angeklagt: Fritzi', ['Fritzi'], [], 'x', [])))->toContain('Anna ist der Ankläger')->toContain('Markus, Sirius und Anna wechseln sich ab');

    // An unknown voice in the config falls back to the two.
    config(['tv.commentary.programme.shows.formats.hotline.speakers' => ['markus', 'nobody']]);
    expect(Shows::cast(Shows::HOTLINE))->toBe([DuetDirector::MARKUS, DuetDirector::SIRIUS]);
});

it('queues a scheduled format once, in parts, at its slot; the hotline starts writing earlier; nothing when its source is down', function () {
    showsChatter('a', 'Fritzi', [['Ich kaufe ab jetzt nur noch Pizza mit Sats, ehrlich.', 200]]);
    VoiceAgent::fake(fn (): array => showsAnswer([...showsFiller(), ...showsFiller(), 'Fritzi wird zu drei Wochen Lightning-Kanal ohne jede Liquidität verurteilt, und zwar ohne Bewährung.']));
    $queue = app(ScriptQueue::class);

    expect($queue->fillRubric(CarbonImmutable::now()))->toBe(1)->and($queue->fillRubric(CarbonImmutable::now()))->toBe(0);
    $parts = CasterScript::query()->where('kind', CasterScript::RUBRIC)->orderBy('part_index')->get();
    expect($parts->count())->toBeGreaterThan(1)
        ->and($parts->pluck('topic')->unique()->all())->toBe([Shows::TRIBUNAL])
        ->and($parts->first()->airs_at->setTimezone('Europe/Berlin')->format('H:i'))->toBe('20:45');

    // 21:01: fourteen minutes before the hotline, inside its own window (a rubric would wait until 21:07).
    showsChatter('b', 'Hodlbert', [['Warum heißt das Ding eigentlich Lightning und nicht Donner?', 20]]);
    WebLookupAgent::fake(['Der Name Lightning steht für schnelle Zahlungen über Kanäle neben der Blockchain.']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 21:01:00', 'Europe/Berlin'));
    expect($queue->fillRubric(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->where('topic', Shows::HOTLINE)->value('airs_at')->setTimezone('Europe/Berlin')->format('H:i'))->toBe('21:15');

    // The meetup slot with the portal down: the rotation's rubric, not an invented radar.
    Cache::flush();
    showsSources(['portal']);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 11:08:00', 'Europe/Berlin'));
    showsChatter('e', 'Neugier', [['Kann mir jemand erklären, wie ein Halving genau funktioniert?', 20]]);
    expect(app(Rubrics::class)->plannedFor(showsSlot('2026-10-13 11:15:00'), CarbonImmutable::now()))->toBe('chat');
});

it('never replays the day-bound formats, brings an Anchor jingle line per format, and teases a format only on its slot', function () {
    foreach (Shows::DAY_BOUND as $topic) {
        expect(ReplayArchive::evergreen([['kind' => 'rubric', 'topic' => $topic, 'sentences' => ['Ein zeitloser Satz ohne jede Zeitangabe.']]], ['script:1']))->toBeFalse();
    }
    expect(ReplayArchive::evergreen([['kind' => 'rubric', 'topic' => Shows::FRIEDHOF, 'sentences' => ['Ein zeitloser Satz ohne jede Zeitangabe.']]], ['script:1']))->toBeTrue();

    $jingles = json_decode((string) file_get_contents(resource_path('data/voice/anchor-jingles.json')), true)['lines'];
    foreach (array_keys(Shows::CATALOGUE) as $id) {
        $lines = array_values(array_filter($jingles, fn (array $line): bool => $line['category'] === 'rubric-'.$id));
        expect(count($lines))->toBeGreaterThanOrEqual(2);
        foreach ($lines as $line) {
            expect($line['language'])->toBe('en')
                ->and(str_contains($line['text'], 'ESPORTS') ? $line['spoken'] : 'Eee. Sports.')->toContain('Eee. Sports.');
        }
        expect(BlockComposer::plan([['kind' => 'rubric', 'topic' => $id]], CarbonImmutable::now())[0]['categories'])->toBe(['rubric-'.$id, 'sweeper']);
    }

    // At 20:38 the tribunal is planned for 20:45: a teaser may name it; the hotline (21:15) is not in this window's rundown.
    showsChatter('a', 'Fritzi', [['Ich kaufe ab jetzt nur noch Pizza mit Sats, ehrlich.', 200]]);
    $entries = app(Announcements::class)->entries(CarbonImmutable::now(), 10);
    expect(array_column($entries, 'name'))->toContain('das Chat-Tribunal')
        ->and(Announcements::verify('Gleich um Viertel vor im Chat-Tribunal stehen die Chatter des Tages vor Gericht.', 45, $entries))->toBeTrue()
        ->and(Announcements::verify('Gleich um Viertel vor kommt Satoshis Hotline mit euren Fragen.', 45, $entries))->toBeFalse();
});
