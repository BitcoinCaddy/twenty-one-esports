<?php

use App\Models\CasterClip;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\ReplayArchive;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\SportResults;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\Programme\WeatherReport;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: Sport und Wetter after the full-hour news
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Wetter und Sport im Radio Programm aus echten Daten …
| sehr sarkastisch und toxisch darüber sprechen. Sport und Wetter immer im
| Anschluss der News zur vollen Stunde." Real results, fixtures and tables
| (OpenLigaDB) and real weather (Open-Meteo) as data, every score and
| temperature checked against it; bulletin, then Sport und Wetter, then the
| news talk; a source that is down leaves its half out, never guessed.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.programme.news_talk.enabled' => true,
        'tv.commentary.programme.teasers.enabled' => false,
        'tv.commentary.programme.rubrics.enabled' => false,
        'tv.commentary.programme.bulletin.sirius_chance' => 0.0,
        'tv.commentary.programme.bulletin.league' => false,
        'tv.commentary.programme.sport_weather.enabled' => true,
        'tv.commentary.programme.sport_weather.duet' => true,
        'tv.commentary.programme.sport_weather.weather.enabled' => true,
        'tv.commentary.programme.sport_weather.weather.cities' => [['name' => 'Berlin', 'lat' => 52.52, 'lon' => 13.41], ['name' => 'Kempten', 'lat' => 47.73, 'lon' => 10.31]],
        'tv.commentary.programme.sport_weather.sport.enabled' => true,
        'tv.commentary.programme.sport_weather.sport.leagues' => [['shortcut' => 'bl1', 'name' => 'Bundesliga']],
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.parts.sentences' => 5,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 13:53:00', 'Europe/Berlin'));
    WorldMemory::forget();
});

/** @return array<string, mixed> one OpenLigaDB match */
function sportWeatherMatch(int $group, string $home, string $away, string $kickoffUtc, ?array $score): array
{
    return [
        'leagueSeason' => 2026, 'leagueShortcut' => 'bl1', 'matchDateTimeUTC' => $kickoffUtc, 'group' => ['groupName' => "{$group}. Spieltag", 'groupOrderID' => $group],
        'team1' => ['teamName' => $home, 'shortName' => $home], 'team2' => ['teamName' => $away, 'shortName' => $away],
        'matchIsFinished' => $score !== null,
        'matchResults' => $score === null ? [] : [
            ['resultName' => 'Halbzeit', 'pointsTeam1' => 0, 'pointsTeam2' => 0, 'resultOrderID' => 1, 'resultTypeID' => 1],
            ['resultName' => 'Endergebnis', 'pointsTeam1' => $score[0], 'pointsTeam2' => $score[1], 'resultOrderID' => 2, 'resultTypeID' => 2],
        ],
    ];
}

/** @return array<string, mixed> the Open-Meteo answer for one city */
function sportWeatherCity(float $now, int $code, array $min, array $max, array $rain): array
{
    return [
        'current' => ['time' => '2026-10-12T13:45', 'temperature_2m' => $now, 'weather_code' => $code, 'wind_speed_10m' => 12.0],
        'daily' => ['time' => ['2026-10-12', '2026-10-13'], 'weather_code' => [3, 61], 'temperature_2m_max' => $max, 'temperature_2m_min' => $min,
            'precipitation_sum' => $rain, 'wind_speed_10m_max' => [14.1, 13.6]],
    ];
}

/**
 * The fake sources: OpenLigaDB (matchday five finished, six to come, a table of eighteen) and Open-Meteo (Berlin,
 * Kempten); `$down` names the ones that answer 500.
 *
 * @param  list<string>  $down
 */
function sportWeatherSources(array $down = []): void
{
    $table = array_map(fn (int $place): array => ['teamName' => ['SC Freiburg', 'RB Leipzig', 'FC Bayern München'][$place] ?? "Verein {$place}", 'points' => 20 - $place], range(0, 17));
    $table[16]['teamName'] = '1. FC Heidenheim 1846';
    $table[17]['teamName'] = 'FC St. Pauli';

    Http::fake(function (Request $request) use ($down, $table) {
        $url = $request->url();

        if (str_contains($url, 'openligadb')) {
            // Down: a 500, even with a body that would read as data.
            return in_array('sport', $down, true) ? Http::response([sportWeatherMatch(5, 'Kaputt FC', 'Fehler SV', '2026-10-10T13:30:00Z', [9, 9])], 500) : match (true) {
                str_ends_with($url, '/getmatchdata/bl1') => Http::response([
                    sportWeatherMatch(5, 'Borussia Dortmund', 'SV Werder Bremen', '2026-10-09T18:30:00Z', [2, 2]),
                    sportWeatherMatch(5, 'FC Augsburg', 'FC Bayern München', '2026-10-10T13:30:00Z', [2, 2]),
                    sportWeatherMatch(5, 'RB Leipzig', 'Eintracht Frankfurt', '2026-10-10T16:30:00Z', [6, 0]),
                    sportWeatherMatch(5, '1. FC Köln', 'FC Schalke 04', '2026-10-11T13:30:00Z', [2, 1]),
                    // Postponed and played long ago: old news.
                    sportWeatherMatch(5, 'Hamburger SV', 'VfL Wolfsburg', '2026-09-30T18:30:00Z', [1, 0]),
                ]),
                str_ends_with($url, '/getmatchdata/bl1/2026/6') => Http::response([
                    sportWeatherMatch(6, 'FC Bayern München', 'Borussia Dortmund', '2026-10-17T16:30:00Z', null),
                ]),
                str_ends_with($url, '/getbltable/bl1/2026') => Http::response($table),
                default => Http::response([], 404),
            };
        }

        if (str_contains($url, 'open-meteo')) {
            return in_array('weather', $down, true) ? Http::response([sportWeatherCity(30.0, 0, [29.0, 29.0], [31.0, 31.0], [0.0, 0.0])], 500) : Http::response([
                sportWeatherCity(13.7, 1, [6.8, 5.4], [13.7, 16.0], [0.0, 0.7]),
                sportWeatherCity(-2.6, 45, [-3.4, -1.0], [9.2, 11.4], [0.0, 2.3]),
            ]);
        }

        return Http::response([], 404);
    });
}

function sportWeatherDesk(): void
{
    $rows = [
        ['Klingbeil will SPD-Führung neu wählen lassen', 'tagesschau', CasterNews::GERMANY, 'Die gesamte Parteiführung soll neu gewählt werden.'],
        ['Russlands Schattenflotte wird zum Risiko', 'Spiegel', CasterNews::WORLD, 'Die maroden Schiffe werden zum Risiko.'],
        ['Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 'Günstiger Strom lockt Miner in den Norden.'],
        ['Wallet-Anbieter schließt Sicherheitslücke nach Hinweis', 'Blocktrainer', CasterNews::BITCOIN, null],
        ['Bahn kündigt Sanierung wichtiger Strecken im Westen an', 'Welt', CasterNews::GERMANY, 'Mehrere Hauptstrecken werden gesperrt.'],
        ['Koalition legt Entwurf für Pflegereform vor', 'FAZ', CasterNews::GERMANY, null],
    ];

    foreach ($rows as $index => [$title, $source, $section, $summary]) {
        CasterNews::query()->create(['hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => 'test', 'source' => $source, 'language' => 'de',
            'title' => $title, 'summary' => $summary, 'link' => null, 'published_at' => now()->subMinutes(10 + $index)]);
    }
}

/** Ten long sentences about the data, Markus and Sirius in turn; `$extra` replaces the third. @return list<string> */
function sportWeatherLines(?string $extra = null): array
{
    $lines = [
        'Markus: Borussia Dortmund und Werder Bremen trennen sich zwei zu zwei, und beide Vereine verkaufen dieses Unentschieden jetzt als großen moralischen Sieg.',
        'Sirius: Der FC Bayern München kommt in Augsburg auch nur zu einem zwei zu zwei, und die Funktionäre reden trotzdem schon wieder von der Meisterschaft.',
        'Markus: Leipzig schießt Frankfurt mit sechs zu null aus dem Stadion, und die Eintracht wird das bestimmt wieder als Lernprozess für die Zukunft bezeichnen.',
        'Sirius: Der FC Köln schlägt Schalke mit zwei zu eins, und die Gelsenkirchener suchen die Schuld vermutlich wieder beim Rasen oder beim Schiedsrichter.',
        'Markus: Oben steht der SC Freiburg mit zwanzig Punkten, während Sankt Pauli unten mit drei Punkten auf ein Wunder oder eine Steuerreform hofft.',
        'Sirius: Am Samstag empfängt der FC Bayern München dann Borussia Dortmund, und der DFB verkauft uns das sicher wieder als Spiel des Jahrhunderts.',
        'Markus: In Berlin haben wir gerade vierzehn Grad bei überwiegend klarem Himmel, und morgen steigt es auf sechzehn Grad mit leichtem Regen.',
        'Sirius: In Kempten sind es jetzt minus drei Grad im Nebel, und die Klimapolitik nennt das bestimmt wieder einen ganz normalen Übergangszeitraum.',
        'Markus: Morgen fallen in Kempten zwei Komma drei Millimeter Regen, und die Bürokratie wird dafür garantiert noch ein eigenes Formular erfinden.',
        'Sirius: Wer bei dem Wetter Fiatgeld für ein Stadionticket ausgibt, hat ohnehin schon verloren, egal wie das Spiel am Ende ausgeht.',
    ];

    if ($extra !== null) {
        $lines[2] = $extra;
    }

    return $lines;
}

/** The fake model: a bulletin reads its items, Sport und Wetter its ten lines, the news talk thirty lines. */
function sportWeatherAnswer(AgentPrompt|string $prompt): array
{
    $text = is_string($prompt) ? $prompt : $prompt->prompt;

    if (str_contains($text, 'Schreib jetzt Sport und Wetter')) {
        return ['text' => implode("\n", sportWeatherLines()), 'canon_id' => null, 'mood' => 'fakten'];
    }

    if (str_contains($text, 'Nachrichten-Talk')) {
        $topics = ['Parteiführung', 'Schattenflotte', 'Wasserkraft', 'Tanker', 'Ölhandel', 'Rücktritt', 'Strompreis', 'Norden', 'Sanktionen', 'Rechenleistung',
            'Bürokratie', 'Steuerzahler', 'Zentralbank', 'Inflation', 'Rechenzentrum', 'Wahlkampf', 'Schiffsversicherung', 'Energiewende', 'Ersparnis', 'Blitzzahlung',
            'Kanzleramt', 'Ostsee', 'Fiatgeld', 'Schuldenbremse', 'Bergbau', 'Tabelle', 'Schachbrett', 'Stammtisch', 'Altbier', 'Kölsch'];

        return ['text' => implode("\n", array_map(fn (int $n): string => ($n % 2 === 0 ? 'Markus: ' : 'Sirius: ').'Beim Thema '.$topics[$n]
            .' muss ich ehrlich sagen, dass mich die ganze Sache mehr nach einem schlechten Witz klingt als nach ernsthafter Politik, und die wird auch nicht besser.', range(0, 29))), 'canon_id' => null, 'mood' => 'fakten'];
    }

    preg_match_all('/^(\d+)\. Rubrik: [^|]+\| Quelle: ([^|]+) \| Meldung: ([^|\n]+)/mu', $text, $items, PREG_SET_ORDER);
    $lines = array_map(fn (array $item): string => "{$item[1]}: Wie ".trim($item[2]).' heute berichtet, lautet die aktuelle Meldung so: '.trim($item[3]).'.', $items);
    $lines[] = 'Kommentar: Ich finde ja, dass die Regierung schon wieder viel zu lange berät und dabei am Ende gar nichts herauskommt.';

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

/** The material as SportWeather builds it from the fake sources. @return array<string, mixed> */
function sportWeatherMaterial(): array
{
    sportWeatherSources();
    $slot = BroadcastClock::fromConfig()->nextBulletin(CarbonImmutable::now());

    return app(SportWeather::class)->material($slot, CarbonImmutable::now());
}

it('airs Sport und Wetter right after the full-hour bulletin and before the news talk, from the real numbers', function () {
    sportWeatherDesk();
    sportWeatherSources();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => sportWeatherAnswer($prompt));
    $queue = app(ScriptQueue::class);

    // No bulletin yet: nothing to follow.
    expect($queue->fillSportWeather(CarbonImmutable::now()))->toBe(0)
        ->and($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillSportWeather(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillSportWeather(CarbonImmutable::now()))->toBe(0)
        ->and($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(1);

    $slot = CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin');
    $bulletin = CasterScript::query()->where('kind', CasterScript::BULLETIN)->sole();
    $segment = CasterScript::query()->where('topic', SportWeather::TOPIC)->orderBy('part_index')->get();
    $talk = CasterScript::query()->where('topic', NewsTalk::TOPIC)->orderBy('part_index')->get();
    $bulletinEnd = $slot->getTimestamp() + (int) ceil(ScriptQueue::estimateSeconds($bulletin->text) + 4);

    expect($segment->first()->kind)->toBe(CasterScript::RUBRIC)
        ->and($segment->first()->airs_at->getTimestamp())->toBe($bulletinEnd)
        // The news talk follows Sport und Wetter, not the bulletin.
        ->and($talk->first()->airs_at->getTimestamp())->toBe($bulletinEnd + (int) ceil($segment->sum('estimated_seconds')))
        ->and($segment->first()->id)->toBeLessThan($talk->first()->id)
        ->and(array_values(array_unique($segment->flatMap(fn (CasterScript $part): array => $part->speakers)->all())))->toEqualCanonicalizing([DuetDirector::MARKUS, DuetDirector::SIRIUS])
        ->and(ScriptQueue::estimateSeconds($segment->pluck('text')->implode("\n")))->toBeGreaterThanOrEqual(60.0)->toBeLessThanOrEqual(130.0)
        // The opening line names no time: the replay archive and the house rules keep "Es ist vierzehn Uhr" out.
        ->and(ScriptGuard::lines($segment->first()->text)[0])->not->toMatch('/\buhr\b/iu');

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Schreib jetzt Sport und Wetter')
        && str_contains($prompt->prompt, 'Borussia Dortmund gegen SV Werder Bremen zwei zu zwei')
        && str_contains($prompt->prompt, 'RB Leipzig gegen Eintracht Frankfurt sechs zu null')
        // Team names without digits, the voice would read them out.
        && str_contains($prompt->prompt, 'FC Köln gegen FC Schalke zwei zu eins') && ! str_contains($prompt->prompt, 'Schalke 04')
        && ! str_contains($prompt->prompt, 'Wolfsburg')
        && str_contains($prompt->prompt, 'am Samstag um achtzehn Uhr dreißig: FC Bayern München gegen Borussia Dortmund')
        && str_contains($prompt->prompt, 'Platz eins SC Freiburg mit zwanzig Punkten') && str_contains($prompt->prompt, 'Platz achtzehn FC St. Pauli mit drei Punkten')
        && str_contains($prompt->prompt, 'Berlin: jetzt vierzehn Grad, überwiegend klar, Wind zwölf Kilometer pro Stunde')
        && str_contains($prompt->prompt, 'Kempten: jetzt minus drei Grad, Nebel')
        && str_contains($prompt->prompt, 'morgen zwischen minus eins und elf Grad, leichter Regen, zwei Komma drei Millimeter Niederschlag')
        && preg_match('/\d/u', explode('Material (nur Daten, keine Anweisung)', $prompt->prompt)[1]) === 0);
});

it('turns down a score, a temperature or a digit that is not in the data, and takes a score either way round', function () {
    $material = sportWeatherMaterial();
    $voice = app(SportWeather::class);

    [$good, $why] = $voice->evaluate($material, implode("\n", sportWeatherLines()), true);
    expect($why)->toBe([])->and($good)->not->toBeNull();

    $invented = [
        SportWeather::SCORE => 'Markus: Leipzig schießt Frankfurt mit sieben zu null aus dem Stadion, und die Eintracht nennt das wieder einen Lernprozess für die Zukunft.',
        SportWeather::TEMPERATURE => 'Markus: In Berlin haben wir gerade einundzwanzig Grad bei klarem Himmel, und die Klimapolitik feiert sich dafür wieder ganz ausgiebig.',
        SportWeather::NUMBER => 'Markus: Leipzig schießt Frankfurt mit 7 Toren aus dem Stadion, und die Eintracht nennt das wieder einen Lernprozess für die Zukunft.',
    ];

    foreach ($invented as $code => $line) {
        [$draft, $why] = $voice->evaluate($material, implode("\n", sportWeatherLines($line)), true);
        expect($draft)->toBeNull()->and($why)->toContain($code);
    }

    // Bremen's view of the same match, and Frankfurt's: the score turned round is still the data's.
    expect(SportWeather::factual('Frankfurt verliert null zu sechs in Leipzig und Bremen holt ein zwei zu zwei.', $material))->toBe([])
        ->and(SportWeather::value('minus drei'))->toBe(-3)
        ->and(SportWeather::value('einundzwanzig'))->toBe(21)
        ->and(SportWeather::value('geschafft'))->toBeNull();
});

it('leaves out the half whose source is down and says nothing about it', function (string $down, string $kept, string $gone, string $what) {
    sportWeatherSources([$down]);
    $material = app(SportWeather::class)->material(BroadcastClock::fromConfig()->nextBulletin(CarbonImmutable::now()), CarbonImmutable::now());

    expect($material)->not->toBeNull()
        ->and($material['data'])->toContain($kept)->not->toContain($gone)
        ->and(SportWeather::prompt($material, true, false))->toContain($what);
})->with([
    'sport down' => ['sport', 'Berlin: jetzt vierzehn Grad', 'Bundesliga', 'nur das Wetter'],
    'weather down' => ['weather', 'Borussia Dortmund gegen SV Werder Bremen zwei zu zwei', 'Kempten', 'nur der Sport'],
]);

it('skips the segment when both sources are down, and the news talk then follows the bulletin', function () {
    sportWeatherDesk();
    sportWeatherSources(['sport', 'weather']);
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => sportWeatherAnswer($prompt));
    $queue = app(ScriptQueue::class);

    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillSportWeather(CarbonImmutable::now()))->toBe(0)
        ->and($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->where('topic', SportWeather::TOPIC)->count())->toBe(0);

    $bulletin = CasterScript::query()->where('kind', CasterScript::BULLETIN)->sole();
    $talk = CasterScript::query()->where('topic', NewsTalk::TOPIC)->orderBy('part_index')->first();
    expect($talk->airs_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin')->getTimestamp() + (int) ceil(ScriptQueue::estimateSeconds($bulletin->text) + 4));
    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Sport und Wetter'));
});

it('caches the sources: weather half an hour, sport a quarter of an hour', function () {
    sportWeatherSources();
    $slot = BroadcastClock::fromConfig()->nextBulletin(CarbonImmutable::now());
    app(SportWeather::class)->material($slot, CarbonImmutable::now());
    app(SportWeather::class)->material($slot, CarbonImmutable::now());
    Http::assertSentCount(4);

    $this->travel(10)->minutes();
    app(SportWeather::class)->material($slot, CarbonImmutable::now());
    Http::assertSentCount(4);

    $this->travel(6)->minutes();
    app(SportWeather::class)->material($slot, CarbonImmutable::now());
    Http::assertSentCount(7);

    $this->travel(15)->minutes();
    app(SportWeather::class)->material($slot, CarbonImmutable::now());
    Http::assertSentCount(11);
});

it('reads no table for a cup', function () {
    config(['tv.commentary.programme.sport_weather.sport.leagues' => [['shortcut' => 'bl1', 'name' => 'DFB-Pokal', 'round' => 'Runde', 'table' => false]]]);
    sportWeatherSources();

    $material = app(SportResults::class)->material(CarbonImmutable::now());

    expect($material['data'])->toContain('DFB-Pokal, Runde fünf, Endergebnisse:')->not->toContain('Tabelle');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'getbltable'));
});

it('writes no Sport und Wetter after the half-hour headlines', function () {
    sportWeatherDesk();
    sportWeatherSources();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => sportWeatherAnswer($prompt));
    $queue = app(ScriptQueue::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:24:00', 'Europe/Berlin'));
    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillSportWeather(CarbonImmutable::now()))->toBe(0)
        ->and(CasterScript::query()->where('topic', SportWeather::TOPIC)->count())->toBe(0);
});

it('leaves Sport und Wetter out once the news talk is written: the talk keeps its place', function () {
    sportWeatherDesk();
    sportWeatherSources();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => sportWeatherAnswer($prompt));
    $queue = app(ScriptQueue::class);

    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillSportWeather(CarbonImmutable::now()))->toBe(0);
});

it('in the quiet hours brings only the weather, short and by Markus alone, and nothing when switched off', function () {
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:54:00', 'Europe/Berlin'));
    sportWeatherDesk();
    sportWeatherSources();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => str_contains(is_string($prompt) ? $prompt : $prompt->prompt, 'Schreib jetzt Sport und Wetter')
        ? ['text' => implode("\n", [
            'Markus: In Berlin haben wir gerade vierzehn Grad bei überwiegend klarem Himmel, und morgen steigt es auf sechzehn Grad mit leichtem Regen.',
            'Sirius: Das hier sagt Sirius, obwohl er nachts gar nicht dabei sein soll, und deshalb fliegt dieser Satz auch wieder raus.',
            'Markus: In Kempten sind es jetzt minus drei Grad im Nebel, und die Klimapolitik nennt das bestimmt wieder einen ganz normalen Übergangszeitraum.',
            'Markus: Morgen fallen in Kempten zwei Komma drei Millimeter Regen, und die Bürokratie wird dafür garantiert noch ein eigenes Formular erfinden.',
        ]), 'canon_id' => null, 'mood' => 'fakten']
        : sportWeatherAnswer($prompt));
    $queue = app(ScriptQueue::class);

    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillSportWeather(CarbonImmutable::now()))->toBe(1);

    $segment = CasterScript::query()->where('topic', SportWeather::TOPIC)->get();
    expect(array_values(array_unique($segment->flatMap(fn (CasterScript $part): array => $part->speakers)->all())))->toBe([DuetDirector::MARKUS])
        ->and(ScriptQueue::estimateSeconds($segment->pluck('text')->implode("\n")))->toBeLessThanOrEqual(55.0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'openligadb'));
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'nur das Wetter') && str_contains($prompt->prompt, 'Nur Markus spricht')
        && ! str_contains($prompt->prompt, 'Bundesliga'));

    // Off at night: nothing for the same bulletin.
    CasterScript::query()->where('topic', SportWeather::TOPIC)->delete();
    config(['tv.commentary.programme.sport_weather.quiet' => 'off']);
    expect($queue->fillSportWeather(CarbonImmutable::now()))->toBe(0)
        ->and(CasterScript::query()->where('topic', SportWeather::TOPIC)->count())->toBe(0);
});

it('lets the news talk wait for the end of Sport und Wetter when that runs longer than estimated', function () {
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(output: str_repeat("\1\0", (int) (24000 * strlen((string) $process->input) / 1000)))]);
    $at = CarbonImmutable::parse('2026-10-12 14:01:00', 'Europe/Berlin');
    $this->travelTo($at->subSeconds(2));
    $clip = function (string $topic, int $seconds, CarbonImmutable $airsAt): CasterClip {
        $script = casterScript(CasterScript::RUBRIC);
        $script->update(['topic' => $topic, 'airs_at' => $airsAt->utc(), 'expires_at' => $airsAt->utc()->addMinutes(15)]);

        return app(ClipSpool::class)->store($script->fresh(), casterOpus(1000 * $seconds), (float) $seconds, range(5.0, (float) $seconds, 5.0), CarbonImmutable::now());
    };
    // Sport und Wetter runs 60 s; the talk was due after 40.
    $segment = $clip(SportWeather::TOPIC, 60, $at);
    $talk = $clip(NewsTalk::TOPIC, 40, $at->addSeconds(40));
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'long_max_hold_seconds' => ['rubric' => 140], 'gap_seconds' => ['min' => 25, 'max' => 25], 'bulletin_late_seconds' => 120, 'rubric_late_seconds' => 240,
    ], clock: BroadcastClock::fromConfig());
    [$segmentEnd, $talkAt] = [null, null];

    for ($t = (float) $at->subSeconds(2)->getTimestamp(); $t <= $at->getTimestamp() + 130; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id === $segment->id) {
            $segmentEnd = $t;
        }
        if ($director->clip()?->id === $talk->id && $talkAt === null) {
            $talkAt = $t;
        }
    }

    expect($segmentEnd)->not->toBeNull()->and($talkAt)->not->toBeNull()
        ->and($segmentEnd - $at->getTimestamp())->toBeGreaterThanOrEqual(59.0)
        ->and($talkAt)->toBeGreaterThan($segmentEnd)
        ->and($talkAt - $segmentEnd)->toBeLessThanOrEqual(1.5);
});

it('never replays Sport und Wetter in the night archive', function () {
    expect(ReplayArchive::evergreen([['kind' => 'rubric', 'topic' => SportWeather::TOPIC, 'sentences' => ['Leipzig schießt Frankfurt aus dem Stadion, und die Eintracht lernt wieder etwas.']]], []))->toBeFalse()
        ->and(ReplayArchive::evergreen([['kind' => 'rubric', 'topic' => 'geschichte', 'sentences' => ['Der Bauernkrieg begann mit einem Streit um Abgaben und Rechte.']]], []))->toBeTrue();
});

it('reads a city and a score from the source shapes, and leaves out what is missing', function () {
    expect(WeatherReport::city('Berlin', ['current' => ['temperature_2m' => 13.7]]))->toBeNull()
        ->and(WeatherReport::city('Berlin', sportWeatherCity(13.7, 1, [6.8, 5.4], [13.7, 16.0], [0.0, 0.7]))['days'][1])
        ->toBe(['label' => 'morgen', 'min' => 5, 'max' => 16, 'code' => 61, 'rain' => 0.7, 'wind' => 14])
        ->and(SportResults::score(sportWeatherMatch(5, 'A', 'B', '2026-10-10T13:30:00Z', [3, 1])))->toBe([3, 1])
        // A running match has no final score.
        ->and(SportResults::score(['matchIsFinished' => false, 'matchResults' => [['pointsTeam1' => 1, 'pointsTeam2' => 0, 'resultTypeID' => 1, 'resultOrderID' => 1]]]))->toBeNull()
        ->and(SportResults::team(['teamName' => 'TSG 1899 Hoffenheim']))->toBe('TSG Hoffenheim')
        ->and(SportResults::team(['teamName' => '1. FSV Mainz 05']))->toBe('FSV Mainz');
});
