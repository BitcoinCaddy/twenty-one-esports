<?php

use App\Support\Caster\Blocks\DayStock;
use App\Support\Caster\Blocks\StockScripts;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\Pcm;
use App\Support\Voice\StockCoherenceJudge;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: every stock block is one coherent programme (P21)
|--------------------------------------------------------------------------
|
| User 2026-10-13 about a stock block that glued a league explainer onto an
| unrelated history talk: "Was soll das sein? Das war total sinnlos!". One
| talk per block, the show's opener and back-announce, canon quotes with who
| and where, and the coherence judge before a draft is queued. With the
| laravel/ai, HTTP and process fakes, never the network or VoiceStudio.
|
*/

const STOCK_COHERENCE_TOKEN = 'coherence-token-0123456789abcdef0123456789abcd';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => true,
        'tv.commentary.fetch.token' => STOCK_COHERENCE_TOKEN,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.blocks.enabled' => true,
        'tv.commentary.blocks.stock.enabled' => true,
        'tv.commentary.blocks.stock.library.enabled' => false,
        'tv.commentary.blocks.stock.dir' => sys_get_temp_dir().'/stock-coherence-'.bin2hex(random_bytes(4)),
        'tv.commentary.blocks.jingles.dir' => sys_get_temp_dir().'/stock-coherence-'.bin2hex(random_bytes(4)),
        'tv.commentary.repeat_hours' => 0,
        'tv.commentary.spool.quiet_hours' => ['from' => '01:00', 'to' => '09:00', 'timezone' => 'Europe/Berlin', 'every_minutes' => 10, 'ahead_seconds' => 90],
        'tv.commentary.blocks.stock.window' => ['from' => '01:00', 'to' => '09:00'],
        'tv.commentary.blocks.stock.formats' => ['tutorial'],
        // A stock talk is a whole 40-80 turn programme since 692f9a9b ("One programme, one continuous text: the writer
        // writes it long"); these drafts are ten turns, and what is under test here is coherence, not length.
        'tv.commentary.blocks.stock.turns' => ['min' => 6, 'max' => 16],
        'esports.voice.monthly_budget_usd' => 100,
    ]);

    // The tutorial lives on the real discover feed since a83fb67e ("The timeless programmes live on real data"):
    // one article and its page, the same fixture DayStockTest uses; without them the tutorial has no material.
    Http::fake([
        'discover.einundzwanzig.space/*' => Http::response('<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>E</title><item><title>Deine Seed-Phrase sicher aufbewahren</title><link>https://media.einundzwanzig.space/seed</link><pubDate>Mon, 12 Oct 2026 10:00:00 +0000</pubDate><dc:creator>pleb</dc:creator><description>Volltext folgt</description></item></channel></rss>'),
        'media.einundzwanzig.space/*' => Http::response('<html><body><article><p>Die Seed-Phrase gehort aufs Papier oder auf Metall, nie als Foto in die Cloud.</p></article></body></html>'),
    ]);
});

/** A stock tutorial draft: ten turns of Markus and Sirius, the show named first and announced back last. */
function stockCoherenceDraft(): string
{
    return implode("\n", [
        'Markus: Das ist das Bitcoin-Tutorial, und es geht darum, wo eure Seed-Phrase hingehört.',
        'Sirius: Ich habe meine Wörter früher auf einen Bierdeckel geschrieben und den Deckel dann beim Aufräumen weggeworfen.',
        'Markus: Genau deshalb gehören die Wörter auf Papier oder besser noch auf Metall und an einen Ort ohne Internet.',
        'Sirius: Metall klingt nach Baumarkt und Hammer, aber ich gebe zu, dass ein Wohnungsbrand dem Papier nicht gut bekommt.',
        'Markus: Und niemand fragt dich je nach diesen Wörtern, weder der Support noch eine Bank und auch kein freundlicher Fremder.',
        'Sirius: Der freundliche Fremde im Chat wollte meine Wörter zur Sicherheit überprüfen und war dann plötzlich weg.',
        'Markus: Der war so freundlich wie eine Zentralbank, die dir verspricht, dass dein Geld nächstes Jahr noch genauso viel wert ist.',
        'Sirius: Dann schreibe ich die Wörter auf Metall und lege den Bierdeckel als Köder für die Gauner in die Schublade.',
        'Markus: Du alter Bierdeckel-Banker, wenigstens hast du jetzt ein Backup, das kein Datenleck der Welt erreicht.',
        'Sirius: Und das war das Bitcoin-Tutorial, zurück ins Programm, ich gehe jetzt Metall stanzen.',
    ]);
}

/** A judge answer: every score `$score`, `$weak` scores lower. */
function stockCoherenceVerdict(int $score = 5, array $weak = [], string $hint = ''): array
{
    $answer = array_fill_keys(StockCoherenceJudge::SCORES, $score);

    foreach ($weak as $name) {
        $answer[$name] = 2;
    }

    return [...$answer, 'nonsense' => $weak === [] ? [] : ['Der Euro ist ein Gummiband, das jedes Jahr länger wird.'], 'invented' => [], 'hint' => $hint];
}

/**
 * The workstation renders one stock block from the claims `$claims` hands out in order.
 *
 * @param  list<array<string, mixed>>  $claims
 * @return list<string> the URLs the fetcher called
 */
function stockCoherenceRender(array $claims): array
{
    $calls = [];
    Http::fake(function (Request $request) use (&$claims, &$calls) {
        $calls[] = $request->url();

        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim?only=stock') => Http::response(['script' => array_shift($claims), 'spool_seconds' => 0, 'pronunciation' => []]),
            str_contains($request->url(), '/api/caster/claim') => Http::response(['script' => null, 'full' => false, 'spool_seconds' => 300, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(Pcm::toWav(str_repeat(pack('v', 2000), 24000), 24000)),
            str_ends_with($request->url(), '/release') => Http::response(['released' => true]),
            str_ends_with($request->url(), '/api/caster/stock/stored') => Http::response(['stored' => 1]),
            str_ends_with($request->url(), '/profiles') => Http::response([['id' => 'p']]),
            default => Http::response([], 404),
        };
    });
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::STOCKED);

    return $calls;
}

/** A claimed stock script of `$topic`, optionally a part of the talk `$group`. */
function stockCoherenceScript(int $id, string $topic, ?string $group = null, int $index = 1, bool $continues = false): array
{
    return ['id' => $id, 'kind' => 'scene', 'scene' => 'stock', 'topic' => $topic, 'sentences' => ['Wer seine Seed-Phrase fotografiert, schenkt sie dem nächsten Datenleck.'],
        'speakers' => ['markus'], 'estimated_seconds' => 5, 'part' => $group === null ? null : ['group' => $group, 'index' => $index, 'continues' => $continues]];
}

it('renders ONE talk per stock block: all its parts, never a second talk glued on to reach a length', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));

    // The prod case of 2026-10-13: a short league talk, then an unrelated history talk waiting in the queue.
    $calls = stockCoherenceRender([stockCoherenceScript(11, 'stock:liga'), stockCoherenceScript(12, 'stock:geschichte')]);
    $meta = array_values(DayStock::fromConfig()->entries())[0];

    expect(array_column($meta['scripts'], 'id'))->toBe([11])
        ->and(count(array_filter($calls, fn (string $url): bool => str_ends_with($url, 'only=stock'))))->toBe(1);

});

it('renders a talk in parts whole, and the next talk stays in the queue for a block of its own', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));

    stockCoherenceRender([
        stockCoherenceScript(21, 'stock:talk', 'g1', 1, true),
        stockCoherenceScript(22, 'stock:talk', 'g1', 2, true),
        stockCoherenceScript(23, 'stock:talk', 'g1', 3, false),
        stockCoherenceScript(24, 'stock:tutorial'),
    ]);

    expect(array_column(array_values(DayStock::fromConfig()->entries())[0]['scripts'], 'id'))->toBe([21, 22, 23]);
});

it('hands a claim of another talk back to the queue instead of gluing it into the block', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));

    // Part 1 says more comes, but the queue hands out another talk (its part 2 not yet free): it goes back.
    $calls = stockCoherenceRender([stockCoherenceScript(31, 'stock:talk', 'g1', 1, true), stockCoherenceScript(41, 'stock:geschichte', 'g2', 1, false)]);

    expect(array_column(array_values(DayStock::fromConfig()->entries())[0]['scripts'], 'id'))->toBe([31])
        ->and($calls)->toContain('https://tv.test/api/caster/scripts/41/release')
        ->and($calls)->not->toContain('https://tv.test/api/caster/scripts/31/release');
});

it('never opens a block with an orphaned later part: it is told done without audio and the next talk starts the block', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));

    $calls = stockCoherenceRender([stockCoherenceScript(51, 'stock:talk', 'g9', 2, false), stockCoherenceScript(61, 'stock:tutorial')]);

    expect(array_column(array_values(DayStock::fromConfig()->entries())[0]['scripts'], 'id'))->toBe([61]);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/caster/stock/stored') && $request->header('X-Caster-Scripts')[0] === '51');
});

it('writes a draft the judge finds weak once more with its hint, records why, and queues the second', function () {
    VoiceAgent::fake(fn (): array => ['text' => stockCoherenceDraft(), 'canon_id' => null, 'mood' => 'fakten']);
    StockCoherenceJudge::fake([stockCoherenceVerdict(5, ['payoff'], 'Am Ende fehlt die Pointe.'), stockCoherenceVerdict()]);

    $stock = app(StockScripts::class);
    $talk = $stock->write();

    expect($talk)->not->toBeNull()
        ->and($talk['format'])->toBe('tutorial')
        ->and($stock->judgements)->toHaveCount(2);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Am Ende fehlt die Pointe.')
        && str_contains($prompt->prompt, 'Gummiband') && str_contains($prompt->prompt, 'Schluss: eine Pointe'));
    // The judge reads every line with its speaker: banter is half of what it scores.
    StockCoherenceJudge::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Markus: Das ist das Bitcoin-Tutorial')
        && str_contains($prompt->prompt, 'Sirius: Ich habe meine Wörter') && str_contains($prompt->prompt, 'Format: das Bitcoin-Tutorial'));

    expect(DB::table('voice_rejections')->where('channel', StockScripts::JUDGE_CHANNEL)->pluck('reason')->all())
        ->toEqualCanonicalizing([StockScripts::INCOHERENT, 'stock_weak_payoff']);
});

it('writes the stock with its own model and without the personas\' running gags, the live items keep them', function () {
    config(['esports.voice.model' => 'claude-haiku-5-5']);
    VoiceAgent::fake(fn (): array => ['text' => stockCoherenceDraft(), 'canon_id' => null, 'mood' => 'fakten']);

    expect(app(StockScripts::class)->write())->not->toBeNull();
    // The gag blocks (their header, the radio craft rule asking for them) and the gags themselves are gone.
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->model() === 'claude-sonnet-5-5'
        && ! str_contains($prompt->agent->instructions(), "Running Gags\n") && ! str_contains($prompt->agent->instructions(), 'Running Gags in Variation')
        && ! str_contains($prompt->agent->instructions(), 'Nachtzug') && str_contains($prompt->agent->instructions(), 'Persona: Markus Turm'));

    $duets = app(DuetScripts::class);
    expect($duets->systemPrompt())->toContain("Running Gags\n")->toContain('Running Gags in Variation')->toContain('Nachtzug')
        ->and($duets->systemPrompt(gags: false))->not->toContain("Running Gags\n")->not->toContain('Running Gags in Variation')->not->toMatch('/\b(?:Nachtzug|Kabel|Neustart|Bierdeckel)\b/u')
        ->and($duets->systemPrompt(gags: false))->toContain('Zweite Stimme: Sirius')->toContain('Grenzen');
});

it('rejects an invented fact hard, but airs sense 3 when nothing is invented (user bar 2026-10-13)', function () {
    VoiceAgent::fake(fn (): array => ['text' => stockCoherenceDraft(), 'canon_id' => null, 'mood' => 'fakten']);
    $invented = [...stockCoherenceVerdict(), 'invented' => ['Die Szene sagt, das Feuer wird kleiner, wenn man es lange anschaut.']];
    StockCoherenceJudge::fake([$invented, [...stockCoherenceVerdict(), 'sense' => 3]]);

    $stock = app(StockScripts::class);

    expect($stock->write())->not->toBeNull()
        ->and($stock->judgements)->toHaveCount(2);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Diese Sätze erfinden') && str_contains($prompt->prompt, 'das Feuer wird kleiner'));
    expect(DB::table('voice_rejections')->where('channel', StockScripts::JUDGE_CHANNEL)->pluck('reason')->all())->toEqualCanonicalizing([StockScripts::INCOHERENT, StockScripts::INVENTED]);

    // Sense 2 still fails, and an answer without the `invented` list is no clearance.
    StockCoherenceJudge::fake([[...stockCoherenceVerdict(), 'sense' => 2], array_diff_key(stockCoherenceVerdict(), ['invented' => 0])]);
    expect(app(StockScripts::class)->write())->toBeNull();
});

it('drops a draft the judge finds weak twice, and one whose judgement it cannot read', function (array $first, array $second) {
    VoiceAgent::fake(fn (): array => ['text' => stockCoherenceDraft(), 'canon_id' => null, 'mood' => 'fakten']);
    StockCoherenceJudge::fake([$first, $second]);

    $stock = app(StockScripts::class);

    expect($stock->write())->toBeNull()
        ->and($stock->judgements)->toHaveCount(2);
    VoiceAgent::assertPromptedTimes(2);
})->with([
    'weak twice' => [stockCoherenceVerdict(5, ['premise']), stockCoherenceVerdict(5, ['sense', 'liveliness'])],
    'a score missing' => [array_diff_key(stockCoherenceVerdict(), ['fit' => 0]), array_diff_key(stockCoherenceVerdict(), ['through_line' => 0])],
    'a score out of range' => [stockCoherenceVerdict(9), stockCoherenceVerdict(0)],
]);

it('gives a canon quote to the stock with who said it and where it is documented, and tells ONE history story', function () {
    $saylor = app(VoiceCanon::class)->find('tx-it-s-going-up-forever-laura');

    expect($saylor['speaker'])->toBe('Michael Saylor (Sample)')
        ->and($saylor['source'])->toStartWith('YouTube EINUNDZWANZIG')
        ->and(StockScripts::canonLine($saylor))->toContain('gesagt von: Michael Saylor (Sample)')->toContain('belegt bei: YouTube EINUNDZWANZIG')
        // The league usage of the entry is no stock material.
        ->and(StockScripts::canonLine($saylor))->not->toContain('Siegesserie');

    // An entry without a speaker is announced as a saying of the scene, never put into someone's mouth.
    $explained = app(VoiceCanon::class)->find('btc-laser-eyes');
    expect(StockScripts::canonLine($explained))->toContain('kein Sprecher belegt')->toContain('belegt bei: Bitcoin Magazine');

    config(['tv.commentary.blocks.stock.formats' => ['geschichte']]);
    [$format, $material] = app(StockScripts::class)->material();

    expect($format)->toBe('geschichte')
        ->and(substr_count($material, 'gesagt von:'))->toBe(1)
        ->and($material)->toContain('belegt bei:')
        ->and(StockScripts::prompt('geschichte', $material))->toContain('WER und WO')->toContain('„Michael Saylor“')->toContain('nie „ein Veteran“');
});

it('opens every stock format with its name and ritual and ends it with a back-announce, never a cold open alone', function (string $format, string $ritual) {
    $prompt = StockScripts::prompt($format, '- Material');

    expect($prompt)->toContain('die erste Zeile ist die Ansage mit dem Namen der Sendung ('.StockScripts::SHOW_NAMES[$format].')')
        ->and($prompt)->not->toContain('kalt und mitten hinein')
        ->and($prompt)->toContain($ritual)
        ->and($prompt)->toContain('Abmoderation: einer sagt, dass das '.StockScripts::SHOW_NAMES[$format].' war')
        ->and($prompt)->toContain('die kalte Prämisse')->toContain('genau drei Runden')->toContain('härteste Pointe')->toContain('Kauderwelsch')
        ->and($prompt)->not->toContain('keine Ansage wie');
})->with([
    'own ritual' => ['geschichte', 'klopft mit dem Zeigestock'],
    'ShowCraft ritual' => ['schwarzbuch', 'das Schwarzbuch wird aufgeschlagen'],
]);

it('keeps the live-air lines of the league basics out of the stock material', function () {
    config(['tv.commentary.blocks.stock.formats' => ['liga']]);
    [$format, $material] = app(StockScripts::class)->material();

    expect($format)->toBe('liga')
        ->and($material)->toContain('Elo')
        ->and($material)->not->toMatch('/\b(?:live|nachts|genau jetzt)\b/iu');
});

it('writes a draft again when a line follows the back-announce: the back-announce is the last line', function () {
    VoiceAgent::fake([
        ['text' => stockCoherenceDraft()."\nMarkus: Und dann rechnet euch das mal selbst aus mit eurem Bierdeckel.", 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => stockCoherenceDraft(), 'canon_id' => null, 'mood' => 'fakten'],
    ]);

    $talk = app(StockScripts::class)->write();

    expect($talk)->not->toBeNull()
        ->and(StockScripts::endsWithBackAnnounce('schwarzbuch', "Markus: Das war die Schwarzbuch-Stunde.\nSirius: Rechnet selbst."))->toBeFalse()
        ->and(StockScripts::endsWithBackAnnounce('schwarzbuch', 'Markus: Das Buch ist zu, das war die Schwarzbuch-Stunde.'))->toBeTrue();
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'danach kommt nichts mehr'));
    expect(DB::table('voice_rejections')->where('channel', StockScripts::CHANNEL)->pluck('reason')->all())->toBe([StockScripts::BACK_ANNOUNCE]);
});

it('turns down a draft whose opener or back-announce names the time, instead of airing it without its frame', function () {
    $lines = explode("\n", stockCoherenceDraft());
    $stock = app(StockScripts::class);

    expect($stock->evaluate(stockCoherenceDraft())[1])->toBe([]);

    $opener = $lines;
    $opener[0] = 'Markus: Heute im Bitcoin-Tutorial geht es darum, wo eure Seed-Phrase hingehört.';
    expect($stock->evaluate(implode("\n", $opener))[1])->toBe([StockScripts::FRAME_LOST]);

    $ending = $lines;
    $ending[count($ending) - 1] = 'Sirius: Und das war das Bitcoin-Tutorial für heute, zurück ins Programm.';
    expect($stock->evaluate(implode("\n", $ending))[1])->toBe([StockScripts::FRAME_LOST])
        ->and(StockScripts::prompt('tutorial', '- x', [StockScripts::FRAME_LOST]))->toContain('ohne „heute“');
});
