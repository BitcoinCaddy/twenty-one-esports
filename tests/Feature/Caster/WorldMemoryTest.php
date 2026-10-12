<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Models\ChatterLine;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ChatWishes;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\ScriptQueue;
use App\Support\Caster\WorldMemory;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the rolling world memory, the material mix, the callbacks
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Wir sind immer noch zu monoton und nicht reaktiv genug
| auf echte Geschehnisse." The world memory is built from rows the league
| and the caster keep (only what really aired or happened), newest first,
| with the time in words, and goes into every TV prompt as data. A scene
| draws its material at random instead of reading the slide's facts; a
| comment on a running game names its match first. A "Rückblick-Spitze"
| calls one or two items back, none twice within six hours. With the
| laravel/ai fakes, never the network.
|
*/

const WORLD_MEMORY_SCRIPT = "Vorhin lief ja eine Schlagzeile über den Bundestag, und ich muss immer noch darüber lachen.\nDer Staat druckt sich die Welt, wie sie ihm gefällt, und wir stapeln einfach weiter Sats.\nDas ist der Unterschied zwischen Fiatgeld und echtem Geld, den jeder irgendwann versteht.";

beforeEach(function () {
    config(['tv.commentary.enabled' => true, 'tv.commentary.ai' => true]);
    // Daytime in Berlin (no quiet hours, no night topics), at a fixed clock so the time words are known.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'Europe/Berlin'));
    WorldMemory::forget();
});

/** A user with a league name, joined long before the window (so no "Neu in der Liga" line). */
function worldMemoryPlayer(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->forceFill(['created_at' => now()->subDays(10)])->save();

    return $user;
}

/** A headline: aired `$hoursAgo` hours ago unless `$skipped` or `$aired` false. */
function worldMemoryNews(string $title, float $hoursAgo, bool $aired = true, ?string $skipped = null): CasterNews
{
    return CasterNews::query()->create([
        'hash' => hash('sha256', $title), 'section' => CasterNews::GERMANY, 'feed' => 'tagesschau-inland', 'source' => 'tagesschau', 'language' => 'de',
        'title' => $title, 'link' => null, 'published_at' => now()->subHours(30),
        'used_at' => $aired ? now()->subMinutes((int) ($hoursAgo * 60)) : null, 'skipped' => $skipped,
    ]);
}

it('builds the digest from real rows only, newest first, with the time in words', function () {
    $ada = worldMemoryPlayer('Ada');
    $bob = worldMemoryPlayer('Bob');
    worldMemoryNews('Bundestag beschließt neues Heizungsgesetz', 2);
    worldMemoryNews('Schreckliches Unglück', 1, skipped: 'model');
    worldMemoryNews('Noch nicht gesendet', 0, aired: false);
    worldMemoryNews('Schon zu alt für das Fenster', 30);
    ChatterLine::query()->create(['pubkey' => $ada->pubkey, 'name' => 'Ada', 'message' => 'Wer gewinnt heute den Cup?', 'said_at' => now()->subMinutes(30)->getTimestamp()]);
    ChessGame::factory()->create(['white_id' => $ada->id, 'black_id' => $bob->id, 'status' => ChessGameStatus::Finished, 'result' => '1-0', 'end_reason' => ChessEndReason::Resignation, 'ply' => 40, 'ended_at' => now()->subHours(3)]);
    ChessGame::factory()->create(['white_id' => $ada->id, 'black_id' => $bob->id, 'status' => ChessGameStatus::Finished, 'result' => '0-1', 'end_reason' => ChessEndReason::Aborted, 'ply' => 1, 'ended_at' => now()->subHour()]);

    $digest = app(WorldMemory::class)->digest(CarbonImmutable::now());

    expect($digest)->toBe(implode("\n", [
        '- vor dreißig Minuten: Ada schrieb im Chat: „Wer gewinnt heute den Cup?“',
        '- vor zwei Stunden (heute Mittag): Schlagzeile von tagesschau lief bei dir: „Bundestag beschließt neues Heizungsgesetz“',
        '- vor drei Stunden (heute Mittag): Schach (Blitz): Ada gewinnt gegen Bob durch Aufgabe',
    ]));
});

it('trims the digest to its token budget from the newest line on', function () {
    worldMemoryNews('Die neueste Schlagzeile des Tages', 1);
    worldMemoryNews('Eine ältere Schlagzeile von heute Morgen', 5);

    // 30 tokens are 120 characters: the newest line (115) fits, the second does not.
    expect(app(WorldMemory::class)->digest(CarbonImmutable::now(), 30))->toBe('- vor einer Stunde (heute Nachmittag): Schlagzeile von tagesschau lief bei dir: „Die neueste Schlagzeile des Tages“')
        ->and(app(WorldMemory::class)->digest(CarbonImmutable::now()))->toContain('Eine ältere Schlagzeile');
});

it('says when something happened in words', function (int $minutesAgo, string $words) {
    expect(WorldMemory::relative(now()->subMinutes($minutesAgo)->getTimestamp(), CarbonImmutable::now()))->toBe($words);
})->with([
    'just now' => [1, 'gerade eben'],
    'minutes, rounded to five' => [23, 'vor fünfundzwanzig Minuten'],
    'an hour, at lunchtime' => [65, 'vor einer Stunde (heute Mittag)'],
    'hours, in the morning' => [6 * 60, 'vor sechs Stunden (heute Morgen)'],
    'yesterday evening' => [19 * 60, 'vor neunzehn Stunden (gestern Abend)'],
    'two days back, no part of the day' => [50 * 60, 'vor fünfzig Stunden'],
]);

it('caches the items for two minutes as ints and strings only', function () {
    worldMemoryNews('Erste Schlagzeile', 1);
    app(WorldMemory::class)->items(CarbonImmutable::now());
    worldMemoryNews('Zweite Schlagzeile', 0.5);

    $stored = Cache::get('caster:world:items');
    $scalars = collect($stored)->flatten()->every(fn (mixed $value): bool => is_int($value) || is_string($value));

    expect($scalars)->toBeTrue()
        ->and(app(WorldMemory::class)->digest(CarbonImmutable::now()))->not->toContain('Zweite Schlagzeile');

    $this->travel(121)->seconds();
    expect(app(WorldMemory::class)->digest(CarbonImmutable::now()))->toContain('Zweite Schlagzeile');
});

it('puts the digest into every kind of TV prompt as data, and the chat reply without the chat lines it has already', function () {
    $ada = worldMemoryPlayer('Ada');
    worldMemoryNews('Bundestag beschließt neues Heizungsgesetz', 2);
    ChatterLine::query()->create(['pubkey' => $ada->pubkey, 'name' => 'Ada', 'message' => 'Hallo aus dem Allgäu', 'said_at' => now()->subMinutes(30)->getTimestamp()]);
    $title = 'Was in den letzten 24 Stunden wirklich passiert ist';
    $headline = 'Bundestag beschließt neues Heizungsgesetz';
    $voice = app(CasterVoice::class);
    $item = ['key' => 'news:1', 'at' => now()->subHours(2)->getTimestamp(), 'kind' => WorldMemory::NEWS, 'line' => 'Schlagzeile von tagesschau lief bei dir: „'.$headline.'“'];

    $static = [
        'news' => CasterVoice::newsPrompt('Eine andere Schlagzeile', 'Spiegel', 'germany', 1, 3),
        'followed' => CasterVoice::followedPrompt('Ein Nostr-Post', 'Ben', true, 3, 7),
        'chat' => CasterVoice::chatReplyPrompt('Wie geht es dir?', 'Ada', 1, 3),
        'callback' => CasterVoice::callbackPrompt([$item], 2, 4, CarbonImmutable::now()),
        'scene' => $voice->variablePart('scene', ScenePhase::PRE_SHOW, ['players' => 3], 2, 8, [], [], [], [], material: CasterVoice::LEAGUE),
        'live' => $voice->variablePart('live', ScenePhase::BREAK, ['players' => 3], 1, 3, [], [], [], []),
    ];
    foreach ($static as $kind => $prompt) {
        expect(str_contains($prompt, $title) && str_contains($prompt, $headline))->toBeTrue("the {$kind} prompt has no world memory");
    }

    expect($static['chat'])->not->toContain('Hallo aus dem Allgäu')
        ->and($static['news'])->toContain('Hallo aus dem Allgäu');

    // The model-written kinds that build their prompt inside: a Bauernkrieg episode and a chat wish's riff.
    VoiceAgent::fake([['text' => WORLD_MEMORY_SCRIPT, 'canon_id' => null, 'mood' => 'witz'], ['text' => WORLD_MEMORY_SCRIPT."\nUnd noch ein Satz, der den Witz zu einem schönen Ende bringt.", 'canon_id' => null, 'mood' => 'witz']]);
    $voice->history(['id' => 'e1', 'title' => 'Der Anfang', 'facts' => [['text' => 'Im Jahr 1525 erhoben sich die Bauern.', 'source' => 'Wikipedia']], 'twists' => []]);
    $voice->riff(ChatWishes::JOKE, [['wording' => 'Stay humble, stack sats', 'meaning' => 'Bescheiden bleiben und sparen']]);

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Markus erzählt vom Bauernkrieg') && str_contains($prompt->prompt, $headline));
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Anregung aus dem Kanon') && str_contains($prompt->prompt, $headline));
});

it('builds a scene from a drawn material and leaves the slide facts out unless it is the league material', function () {
    worldMemoryNews('Bundestag beschließt neues Heizungsgesetz', 2);
    config(['tv.commentary.scripts.materials' => ['world' => 1]]);
    VoiceAgent::fake(array_fill(0, 2, ['text' => WORLD_MEMORY_SCRIPT, 'canon_id' => null, 'mood' => 'witz']));
    $facts = ['sender' => 'TWENTY ONE TV', 'players' => 34, 'tournaments_open' => [['name' => 'Herbst Cup']]];

    app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, $facts, 2, 8);

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Dein Stoff diesmal: '.CasterVoice::MATERIALS['world'])
        && ! str_contains($prompt->prompt, 'Fakten (die einzige Quelle') && ! str_contains($prompt->prompt, 'Herbst Cup'));

    // Without one of Markus' own notes the `nostr` material is not drawn: the league material stands in, with its facts.
    config(['tv.commentary.scripts.materials' => ['nostr' => 1]]);
    app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, $facts, 2, 8);

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Fakten (die einzige Quelle') && str_contains($prompt->prompt, 'Herbst Cup'));
});

it('keeps the facts for a caller that names the league material (a chat wish for the game)', function () {
    worldMemoryNews('Bundestag beschließt neues Heizungsgesetz', 2);
    config(['tv.commentary.scripts.materials' => ['world' => 1]]);
    VoiceAgent::fake([['text' => WORLD_MEMORY_SCRIPT, 'canon_id' => null, 'mood' => 'witz']]);

    app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, ['players' => 34, 'tournaments_open' => [['name' => 'Herbst Cup']]], 2, 8, material: CasterVoice::LEAGUE);

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Herbst Cup') && ! str_contains($prompt->prompt, 'Dein Stoff diesmal'));
});

it('draws the materials by their weights and never one without data', function () {
    config(['tv.commentary.scripts.materials' => ['podcast' => 5, 'nostr' => 5, 'chat' => 5, 'canon' => 1, 'invite' => 1]]);
    $drawn = [];

    for ($i = 0; $i < 60; $i++) {
        $drawn[app(CasterVoice::class)->material(ScenePhase::PRE_SHOW, ['players' => 3])[0]] = true;
    }

    // No podcast digest, no own note, no chat line: only canon and invite are left.
    expect(array_keys($drawn))->toEqualCanonicalizing(['canon', 'invite']);
});

it('anchors a live chess moment in its match: the facts carry it and the prompt asks for it first, with one name at most', function () {
    $ada = worldMemoryPlayer('Ada');
    $bob = worldMemoryPlayer('Bob');
    ChessGame::factory()->create(['white_id' => $ada->id, 'black_id' => $bob->id, 'rated' => true, 'number' => 412, 'ply' => 2,
        'fen' => 'rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2']);
    // A material draw that would leave the facts out: a game moment keeps them anyway.
    config(['tv.commentary.scripts.materials' => ['canon' => 1]]);
    $facts = app(SceneFacts::class)->forTopic(SceneTopic::LIVE_GAMES);
    VoiceAgent::fake([['text' => WORLD_MEMORY_SCRIPT, 'canon_id' => null, 'mood' => 'hype']]);

    app(CasterVoice::class)->write('scene', SceneTopic::phase(SceneTopic::LIVE_GAMES, CarbonImmutable::now()), $facts, 2, 8);

    $anchor = 'Blitz-Schachpartie gewertet in der Ladder, Match Nummer 412';
    expect($facts['live_games'][0]['match'] ?? null)->toBe($anchor)
        ->and(collect($facts['moments'] ?? [])->pluck('match')->unique()->all())->toBe([$anchor]);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '"match": "'.$anchor.'"')
        && str_contains($prompt->prompt, 'Spielbezug zuerst') && str_contains($prompt->prompt, 'Höchstens EIN Spielername im ganzen Skript, nie eine Liste von Namen, nie beide Namen samt Farben'));
});

it('writes a callback when it is due, with its items as data, and not again before callback_every_minutes', function () {
    config(['tv.commentary.world.callback_every_minutes' => 12]);
    worldMemoryNews('Bundestag beschließt neues Heizungsgesetz', 2);
    worldMemoryNews('Bahn streicht schon wieder Verbindungen', 3);
    // A third item: the first callback takes one or two, so one is left for the next.
    worldMemoryNews('Zentralbank denkt über digitalen Euro nach', 4);
    VoiceAgent::fake([['text' => WORLD_MEMORY_SCRIPT, 'canon_id' => null, 'mood' => 'witz'], ['text' => "Heute Mittag hat die Bahn mal wieder bewiesen, dass Pünktlichkeit bei ihr ein Fremdwort bleibt.\nIch frag mich ernsthaft, ob die ihre Fahrpläne inzwischen auch mit frisch gedrucktem Geld bezahlen.", 'canon_id' => null, 'mood' => 'witz']]);
    $queue = new ScriptQueue(app(CasterVoice::class), app(SceneFacts::class));

    expect($queue->fillCallback(CarbonImmutable::now()))->toBe(1)
        ->and($queue->fillCallback(CarbonImmutable::now()))->toBe(0);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => preg_match('/<<<rueckblick\n- vor (zwei|drei|vier) Stunden \(heute (Vormittag|Mittag)\): Schlagzeile von tagesschau lief bei dir: „(Bundestag|Bahn|Zentralbank)/u', $prompt->prompt) === 1);
    expect(CasterScript::query()->where('topic', WorldMemory::CALLBACK_TOPIC)->value('kind'))->toBe(CasterScript::SCENE);

    $this->travel(13)->minutes();
    WorldMemory::forget();
    expect($queue->fillCallback(CarbonImmutable::now()))->toBe(1);
});

it('calls no item back twice within six hours', function () {
    config(['tv.commentary.world.callback_every_minutes' => 12]);
    worldMemoryNews('Bundestag beschließt neues Heizungsgesetz', 2);
    $world = app(WorldMemory::class);

    $first = $world->pickForCallback(CarbonImmutable::now(), 2);
    $world->markCalledBack(array_column($first, 'key'), CarbonImmutable::now());

    expect(array_column($first, 'kind'))->toBe([WorldMemory::NEWS])
        ->and($world->pickForCallback(CarbonImmutable::now(), 2))->toBe([]);

    $this->travel(5)->hours();
    WorldMemory::forget();
    expect($world->pickForCallback(CarbonImmutable::now(), 2))->toBe([]);

    $this->travel(61)->minutes();
    WorldMemory::forget();
    worldMemoryNews('Noch eine Schlagzeile, damit das Fenster nicht leer ist', 1);
    expect(array_column($world->pickForCallback(CarbonImmutable::now(), 5), 'key'))->toContain($first[0]['key']);
});

it('calls back nothing younger than a quarter of an hour, no game on now, and nothing in the quiet hours', function () {
    worldMemoryNews('Ganz frische Schlagzeile', 0.1);

    expect(app(WorldMemory::class)->pickForCallback(CarbonImmutable::now(), 2))->toBe([]);

    worldMemoryNews('Bundestag beschließt neues Heizungsgesetz', 2);
    WorldMemory::forget();
    config(['tv.commentary.spool.quiet_hours.from' => '01:00', 'tv.commentary.spool.quiet_hours.to' => '09:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00', 'Europe/Berlin'));
    VoiceAgent::fake([['text' => WORLD_MEMORY_SCRIPT, 'canon_id' => null, 'mood' => 'witz']]);
    config(['tv.commentary.scripts.ai_calls_per_fill' => 0]);

    (new ScriptQueue(app(CasterVoice::class), app(SceneFacts::class)))->fill(CarbonImmutable::now());

    expect(CasterScript::query()->where('topic', WorldMemory::CALLBACK_TOPIC)->exists())->toBeFalse();
});
