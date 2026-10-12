<?php

use App\Models\CasterClip;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Models\ChatterLine;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: the news talk after the :00 bulletin (P5)
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Die News war zu kurz … Die Programme sollen aufregend
| und lang werden und alles Mögliche beleuchten und besprechen, so wie in
| einem echten Radiosender." Right after the full-hour bulletin Markus and
| Sirius discuss its most interesting stories for three to six minutes,
| the items and their descriptions as the only facts, chat questions
| taken up, a true teaser at the end; in parts, airing when the bulletin
| ends.
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
        'tv.commentary.programme.teasers.enabled' => true,
        'tv.commentary.programme.rubrics.enabled' => true,
        'tv.commentary.programme.rubrics.rotation' => ['geschichte'],
        'tv.commentary.programme.bulletin.sirius_chance' => 0.0,
        'tv.commentary.programme.bulletin.league' => false,
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.parts.sentences' => 5,
        'tv.commentary.spool.quiet_hours.from' => '00:00',
        'tv.commentary.spool.quiet_hours.to' => '00:00',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 13:53:00', 'Europe/Berlin'));
    WorldMemory::forget();
});

function newsTalkDesk(): void
{
    $rows = [
        ['Klingbeil will SPD-Führung neu wählen lassen', 'tagesschau', CasterNews::GERMANY, 'Der Rücktritt von Bärbel Bas hat die SPD erschüttert, nun soll die gesamte Parteiführung neu gewählt werden.'],
        ['Russlands Schattenflotte wird zum Risiko', 'Spiegel', CasterNews::WORLD, 'Mit immer neuen Tankern baut Russland eine Parallelstruktur für den Ölhandel auf, die maroden Schiffe werden zum Risiko.'],
        ['Miner verlagern Rechenleistung nach Skandinavien', 'BTC-Echo', CasterNews::BITCOIN, 'Günstiger Strom aus Wasserkraft lockt Bitcoin-Miner in den Norden.'],
        ['Wallet-Anbieter schließt Sicherheitslücke nach Hinweis', 'Blocktrainer', CasterNews::BITCOIN, null],
        ['Bahn kündigt Sanierung wichtiger Strecken im Westen an', 'Welt', CasterNews::GERMANY, 'Mehrere Hauptstrecken sollen über Monate gesperrt werden.'],
        ['Koalition legt Entwurf für Pflegereform vor', 'FAZ', CasterNews::GERMANY, null],
    ];

    foreach ($rows as $index => [$title, $source, $section, $summary]) {
        CasterNews::query()->create(['hash' => NewsFeeds::hash($title), 'section' => $section, 'feed' => 'test', 'source' => $source, 'language' => 'de',
            'title' => $title, 'summary' => $summary, 'link' => null, 'published_at' => now()->subMinutes(10 + $index)]);
    }

    ChatterLine::query()->create(['pubkey' => str_repeat('b', 64), 'name' => 'Bob', 'message' => 'Was bedeutet die Schattenflotte eigentlich für den Ölpreis?', 'said_at' => now()->subMinutes(30)->getTimestamp()]);
}

/** Thirty distinct long sentences, Markus and Sirius in turn: about four minutes spoken. @return list<string> */
function newsTalkLines(int $count = 30): array
{
    $topics = ['Parteiführung', 'Schattenflotte', 'Wasserkraft', 'Tanker', 'Ölhandel', 'Rücktritt', 'Strompreis', 'Norden', 'Sanktionen', 'Rechenleistung',
        'Bürokratie', 'Steuerzahler', 'Zentralbank', 'Inflation', 'Rechenzentrum', 'Wahlkampf', 'Schiffsversicherung', 'Energiewende', 'Ersparnis', 'Blitzzahlung',
        'Kanzleramt', 'Ostsee', 'Fiatgeld', 'Schuldenbremse', 'Bergbau', 'Tabelle', 'Schachbrett', 'Stammtisch', 'Altbier', 'Kölsch'];

    return array_map(fn (int $n): string => ($n % 2 === 0 ? 'Markus: ' : 'Sirius: ').'Beim Thema '.$topics[$n % count($topics)]
        .' muss ich ehrlich sagen, dass mich die ganze Sache mehr nach einem schlechten Witz klingt als nach ernsthafter Politik, und die wird auch nicht besser.', range(0, $count - 1));
}

/** The fake model: a bulletin reads its items; the talk is thirty lines and a true and a false teaser. */
function newsTalkAnswer(AgentPrompt|string $prompt): array
{
    $text = is_string($prompt) ? $prompt : $prompt->prompt;

    if (str_contains($text, 'Nachrichten-Talk')) {
        return ['text' => implode("\n", [...newsTalkLines(), 'Vorschau:', '[15] Um Viertel nach kommt die Geschichtsstunde mit einem Kapitel aus dem Bauernkrieg.', '[15] Um Viertel nach kommt außerdem die Podcast-Ecke mit allem Neuen.']), 'canon_id' => null, 'mood' => 'fakten'];
    }

    preg_match_all('/^(\d+)\. Rubrik: [^|]+\| Quelle: ([^|]+) \| Meldung: ([^|\n]+)/mu', $text, $items, PREG_SET_ORDER);
    $lines = array_map(fn (array $item): string => "{$item[1]}: Wie ".trim($item[2]).' heute berichtet, lautet die aktuelle Meldung so: '.trim($item[3]).'.', $items);
    $lines[] = 'Kommentar: Ich finde ja, dass die Regierung schon wieder viel zu lange berät und dabei am Ende gar nichts herauskommt.';

    return ['text' => implode("\n", $lines), 'canon_id' => null, 'mood' => 'fakten'];
}

it('writes the news talk after the full-hour bulletin: both speak, three to six minutes, airing when the bulletin ends', function () {
    newsTalkDesk();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => newsTalkAnswer($prompt));
    $queue = app(ScriptQueue::class);

    // No bulletin yet: no talk.
    expect($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(0)
        ->and($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(0);

    $bulletin = CasterScript::query()->where('kind', CasterScript::BULLETIN)->sole();
    $parts = CasterScript::query()->where('kind', CasterScript::RUBRIC)->where('topic', NewsTalk::TOPIC)->orderBy('part_index')->get();
    $text = $parts->pluck('text')->implode("\n");
    $speakers = $parts->flatMap(fn (CasterScript $part): array => $part->speakers)->all();
    $talk = implode("\n", array_slice(ScriptGuard::lines($text), 0, -1));

    expect($parts->count())->toBeGreaterThan(3)
        ->and($parts->first()->airs_at->getTimestamp())->toBe(CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin')->getTimestamp() + (int) ceil(ScriptQueue::estimateSeconds($bulletin->text) + 4))
        ->and(array_unique($speakers))->toEqualCanonicalizing([DuetDirector::MARKUS, DuetDirector::SIRIUS])
        ->and(ScriptQueue::estimateSeconds($talk))->toBeGreaterThanOrEqual(180.0)->toBeLessThanOrEqual(360.0)
        // The true teaser ends it; the rubric that does not follow never airs.
        ->and(last(ScriptGuard::lines($text)))->toBe('Um Viertel nach kommt die Geschichtsstunde mit einem Kapitel aus dem Bauernkrieg.')
        ->and($text)->not->toContain('Podcast-Ecke')
        // The :00 bulletin itself has no preview: the talk carries it.
        ->and($bulletin->text)->not->toContain('Viertel nach');
    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Programmvorschau'));

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Nachrichten-Talk')
        && str_contains($prompt->prompt, 'Hintergrund: Mit immer neuen Tankern')
        && str_contains($prompt->prompt, 'Was bedeutet die Schattenflotte eigentlich für den Ölpreis?')
        && ! str_contains($prompt->prompt, 'Bob'));
});

it('turns down a talk that is too short or where one of the two never speaks, and cuts one that runs too long', function () {
    $talk = app(NewsTalk::class);

    [$short, $why] = $talk->evaluate(implode("\n", newsTalkLines(6)), []);
    expect($short)->toBeNull()->and($why)->toBe([NewsTalk::SHORT]);

    [$alone, $why] = $talk->evaluate(implode("\n", array_map(fn (string $line): string => preg_replace('/^Sirius:/', 'Markus:', $line), newsTalkLines())), []);
    expect($alone)->toBeNull()->and($why)->toBe([NewsTalk::TURNS]);

    [$long] = $talk->evaluate(implode("\n", [...newsTalkLines(), ...newsTalkLines(30)]), []);
    expect(ScriptQueue::estimateSeconds($long['text']))->toBeLessThanOrEqual(360.0)
        ->and(count(ScriptGuard::lines($long['text'])))->toBeLessThanOrEqual(44);
});

it('writes no talk after the half-hour headlines', function () {
    newsTalkDesk();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => newsTalkAnswer($prompt));
    $queue = app(ScriptQueue::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 14:24:00', 'Europe/Berlin'));
    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(0);
});

it('writes no talk after the night bulletin', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:54:00', 'Europe/Berlin'));
    newsTalkDesk();
    VoiceAgent::fake(fn (AgentPrompt|string $prompt): array => newsTalkAnswer($prompt));
    $queue = app(ScriptQueue::class);
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 03:54:00', 'Europe/Berlin'));
    expect($queue->fillBulletin(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillNewsTalk(CarbonImmutable::now()))->toBe(0)
        ->and(CasterScript::query()->where('topic', NewsTalk::TOPIC)->count())->toBe(0);
});

function newsTalkClip(string $kind, int $seconds, CarbonImmutable $airsAt): CasterClip
{
    $script = casterScript($kind);
    $script->update(['airs_at' => $airsAt->utc(), 'expires_at' => $airsAt->utc()->addMinutes(15)]);

    return app(ClipSpool::class)->store($script->fresh(), casterOpus(1000 * $seconds), (float) $seconds, range(5.0, (float) $seconds, 5.0), CarbonImmutable::now());
}

it('plays the talk right after the bulletin, never before it ends', function () {
    Process::fake(['*' => fn (PendingProcess $process) => Process::result(output: str_repeat("\1\0", (int) (24000 * strlen((string) $process->input) / 1000)))]);
    $slot = CarbonImmutable::parse('2026-10-12 14:00:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(5));
    $bulletin = newsTalkClip(CasterScript::BULLETIN, 60, $slot);
    // Its estimated end is a little early: the talk still waits for the real end.
    $talk = newsTalkClip(CasterScript::RUBRIC, 40, $slot->addSeconds(50));
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $director = new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'long_max_hold_seconds' => ['bulletin' => 430], 'gap_seconds' => ['min' => 25, 'max' => 25], 'bulletin_late_seconds' => 120, 'rubric_late_seconds' => 240,
    ], clock: BroadcastClock::fromConfig());
    [$bulletinEnd, $talkAt] = [null, null];

    for ($t = (float) $slot->subSeconds(5)->getTimestamp(); $t <= $slot->getTimestamp() + 120; $t += 0.25) {
        $this->travelTo(CarbonImmutable::createFromTimestamp((int) $t));
        $director->tick($planner, $planner->at($t, []), $t);

        if ($director->clip()?->id === $bulletin->id) {
            $bulletinEnd = $t;
        }
        if ($director->clip()?->id === $talk->id && $talkAt === null) {
            $talkAt = $t;
        }
    }

    expect($bulletinEnd)->not->toBeNull()->and($talkAt)->not->toBeNull()
        ->and($talkAt)->toBeGreaterThan($bulletinEnd)
        ->and($talkAt - $bulletinEnd)->toBeLessThanOrEqual(1.5);
});

it('keeps the feed item\'s description with its headline, as plain words', function () {
    $xml = '<?xml version="1.0"?><rss version="2.0"><channel><item><title>Russlands Schattenflotte wird zum Risiko</title>'
        .'<description><![CDATA[<p>Mit immer neuen Tankern baut Russland eine <b>Parallelstruktur</b> auf &amp; die Schiffe werden zum Risiko. <a href="x">mehr</a></p>]]></description>'
        .'<pubDate>Mon, 12 Oct 2026 10:00:00 +0000</pubDate></item><item><title>Nur ein Titel</title><description>Nur ein Titel</description></item></channel></rss>';

    $items = NewsFeeds::parse($xml);

    expect($items[0]['summary'])->toBe('Mit immer neuen Tankern baut Russland eine Parallelstruktur auf & die Schiffe werden zum Risiko.')
        ->and($items[1]['summary'])->toBeNull()
        ->and(config('tv.commentary.news.feeds.tagesschau-ausland.section'))->toBe(CasterNews::WORLD);
});
