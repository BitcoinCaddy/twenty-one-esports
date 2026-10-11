<?php

use App\Support\Caster\CasterVoice;
use App\Support\Caster\PatternScripts;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\ScriptGuard;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceGuard;
use App\Support\Voice\VoiceUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the pattern scripts, airtime without the model (P6)
|--------------------------------------------------------------------------
|
| The real Haiku passed 0 of 5 scene scripts against a prod snapshot
| (2026-10-11); the stream must not wait for it. A pattern script is
| assembled from resources/data/voice/caster.json and filled only with the
| scene's facts: no slot left open, no number the facts do not carry, no
| line again within the last scripts, a line that fails the guard dropped.
|
*/

beforeEach(function () {
    config(['tv.commentary.enabled' => true]);
});

/**
 * Facts of every topic, as SceneFacts::forTopic() shapes them.
 *
 * @return array<string, array<string, mixed>>
 */
function patternFactsByTopic(): array
{
    $basics = ['sender' => 'TWENTY ONE TV', 'website_spoken' => 'esports punkt einundzwanzig punkt space', 'players' => 34, 'clans' => 8];

    return [
        SceneTopic::LIVE_GAMES => [...$basics, 'live_games' => [['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'mode' => 'Blitz', 'move' => 14], ['game' => 'Rocket League', 'a' => 'Cleo', 'b' => 'Dirk']], 'live_now' => 5],
        SceneTopic::RESULTS => [...$basics, 'results' => [['winner' => 'Ada', 'loser' => 'Bob', 'game' => 'Schach', 'mode' => 'Fernschach', 'moves' => 31, 'how' => 'Aufgabe'], ['draw' => ['Cleo', 'Dirk'], 'game' => 'Schach', 'mode' => 'Blitz']], 'games_today' => 17],
        SceneTopic::TOURNAMENTS => [...$basics, 'tournaments_open' => [['name' => 'Chess Cup Nummer 2', 'game' => 'Schach', 'mode' => 'Blitz 5+3', 'start' => 'am Samstag den 17. Oktober um 20 Uhr', 'places' => 8, 'taken' => 3, 'spots_left' => 5, 'pot_sats' => 21000, 'roster' => ['Ada']]]],
        SceneTopic::LIVE_TOURNAMENT => [...$basics, 'tournaments_under_way' => [['name' => 'Pong Cup', 'state' => 'im Gange', 'game' => 'Proof of Pong', 'entrants' => 6, 'played' => 4, 'total' => 9, 'table' => [['rank' => 1, 'name' => 'Ada', 'points' => 3], ['rank' => 2, 'name' => 'Bob', 'points' => 2]], 'results' => [['winner' => 'Ada', 'loser' => 'Cleo', 'score' => '2 zu 1', 'round' => 2]]]]],
        SceneTopic::LADDERS => [...$basics, 'ladders' => [['ladder' => 'Schach Blitz 5+3', 'top' => [['rank' => 1, 'name' => 'Ada', 'elo' => 1210, 'wins' => 9, 'losses' => 2], ['rank' => 2, 'name' => 'Bob', 'elo' => 1188, 'wins' => 7, 'losses' => 3]], 'gap_first_second' => 22]]],
        SceneTopic::CLANS => [...$basics, 'clan_spotlight' => ['name' => 'Einundzwanzig Bern', 'tag' => 'BRN', 'members' => 4, 'games' => 12]],
        SceneTopic::TMNF => [...$basics, 'tmnf_week' => ['track' => 'A07-Race', 'author_time' => '24,5 Sekunden', 'drivers' => 6, 'board' => [['place' => 1, 'name' => 'Ada', 'time' => '24,38 Sekunden', 'under_author_time' => true], ['place' => 2, 'name' => 'Bob', 'time' => '24,9 Sekunden', 'under_author_time' => false]]]],
        SceneTopic::LEAGUE => [...$basics, 'games_played' => 245, 'games_today' => 17],
    ];
}

function patternScripts(string $dataPath = ''): PatternScripts
{
    return new PatternScripts(app(VoiceCanon::class), app(VoiceUsage::class), app(CasterVoice::class), new ScriptGuard, $dataPath);
}

it('fills every slot from the scene facts and says no number they do not carry', function () {
    foreach (patternFactsByTopic() as $topic => $facts) {
        $phase = SceneTopic::phase($topic, now()->setTimezone('Europe/Berlin')->setTime(15, 0)->toImmutable());
        preg_match_all('/\d+/', implode(' ', VoiceGuard::factStrings($facts)), $factNumbers);

        for ($i = 0; $i < 2; $i++) {
            $script = patternScripts()->write($topic, $phase, $facts);

            expect($script)->not->toBeNull("{$topic}: no script")
                ->and($script['text'])->not->toContain('{')
                ->and(count(ScriptGuard::lines($script['text'])))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6)
                ->and(app(CasterVoice::class)->guard()->check(ScriptGuard::withoutMarkers(str_replace("\n", ' ', $script['text'])), $facts, 900, $script['canon_id']))->toBe([]);

            preg_match_all('/\d+/', $script['text'], $spoken);
            expect(array_diff($spoken[0], $factNumbers[0]))->toBe([], "{$topic}: invented number in {$script['text']}");
        }
    }
});

it('talks about one item per script, so a line never borrows the name of another game', function () {
    $facts = patternFactsByTopic()[SceneTopic::RESULTS];

    for ($i = 0; $i < 4; $i++) {
        $text = patternScripts()->write(SceneTopic::RESULTS, ScenePhase::AFTER_RESULT, $facts)['text'] ?? '';
        $aboutAda = str_contains($text, 'Ada') || str_contains($text, 'Bob');
        $aboutCleo = str_contains($text, 'Cleo') || str_contains($text, 'Dirk');

        expect($aboutAda && $aboutCleo)->toBeFalse($text);
    }
});

it('repeats no line within the last scripts, about the topic or in all', function () {
    config(['tv.commentary.patterns.recent' => 2]);
    $facts = patternFactsByTopic()[SceneTopic::LADDERS];
    $written = [];

    for ($i = 0; $i < 4; $i++) {
        $script = patternScripts()->write(SceneTopic::LADDERS, ScenePhase::BREAK, $facts);
        $written[] = $script === null ? null : $script['lines'];
    }

    foreach ($written as $index => $lines) {
        foreach (array_slice($written, max(0, $index - 2), min(2, $index)) as $before) {
            if ($lines !== null && $before !== null) {
                expect(array_intersect($lines, $before))->toBe([]);
            }
        }
    }
    expect($written[0])->not->toBeNull()->and($written[1])->not->toBeNull();
});

it('gives up rather than say a line again when the pool is used up', function () {
    config(['tv.commentary.patterns.recent' => 10]);
    $facts = ['sender' => 'TWENTY ONE TV', 'players' => 34];
    $scripts = [];

    for ($i = 0; $i < 12; $i++) {
        $scripts[] = patternScripts()->write(SceneTopic::LEAGUE, ScenePhase::BREAK, $facts);
    }

    $said = [];
    foreach (array_filter($scripts) as $script) {
        $facts = array_values(array_filter($script['lines'], fn (string $id): bool => str_starts_with($id, 'f-')));
        expect(array_intersect($facts, $said))->toBe([]);
        $said = [...$said, ...$facts];
    }

    expect($scripts[0])->not->toBeNull()
        ->and(end($scripts))->toBeNull();
});

it('uses no line with a slot the facts leave open', function () {
    expect(PatternScripts::fill('{winner} gewinnt nach {moves} Zügen.', ['winner' => 'Ada']))->toBeNull()
        ->and(PatternScripts::fill('{winner} gewinnt nach {moves} Zügen.', ['winner' => 'Ada', 'moves' => '31']))->toBe('Ada gewinnt nach 31 Zügen.');

    // A result without moves or how: no line about them, and no gap where a slot stood.
    $facts = [...patternFactsByTopic()[SceneTopic::RESULTS], 'results' => [['winner' => 'Ada', 'loser' => 'Bob', 'game' => 'Schach']]];
    for ($i = 0; $i < 3; $i++) {
        $text = patternScripts()->write(SceneTopic::RESULTS, ScenePhase::AFTER_RESULT, $facts)['text'] ?? '';

        expect($text)->not->toBe('')->not->toMatch('/ {2,}|\s[.,:!?]|Zügen|durch\s*\./u');
    }
});

it('names nobody the leader whose row is not ranked first', function () {
    $facts = [...patternFactsByTopic()[SceneTopic::LADDERS], 'ladders' => [['ladder' => 'Schach Daily', 'top' => [['rank' => 2, 'name' => 'Bob', 'elo' => 1072, 'wins' => 5, 'losses' => 1], ['rank' => 3, 'name' => 'Cleo', 'elo' => 1067, 'wins' => 8, 'losses' => 3]]]]];
    $slots = PatternScripts::slots($facts);

    expect($slots['items'][0])->not->toHaveKey('leader')
        ->and($slots['items'][0]['second'] ?? null)->toBe('Bob');
});

it('drops a line that fails the guard from the pool for the day and logs it once', function () {
    $data = json_decode((string) file_get_contents(resource_path('data/voice/caster.json')), true);
    $data['promo'] = [
        ['id' => 'test-good', 'category' => 'x', 'when' => ['break'], 'energy' => 'warm', 'text' => 'Schaut einfach mal rein.'],
        ['id' => 'test-bad', 'category' => 'x', 'when' => ['break'], 'energy' => 'warm', 'text' => 'Die Liga hat 500 Spieler.'],
    ];
    $data['pattern_scripts']['use_topics'] = [];
    $path = storage_path('framework/testing/caster-patterns-'.uniqid().'.json');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($data));
    Log::spy();

    $texts = [];
    for ($i = 0; $i < 4; $i++) {
        $texts[] = patternScripts($path)->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE])['text'] ?? '';
    }
    unlink($path);

    expect(implode("\n", $texts))->not->toContain('500')
        ->and(implode("\n", $texts))->toContain('Schaut einfach mal rein.')
        ->and(DB::table('voice_rejections')->where('channel', PatternScripts::CHANNEL)->where('reason', VoiceGuard::NUMBER)->value('count'))->toBe(1);
    Log::shouldHaveReceived('info')->with('Caster pattern line dropped', Mockery::on(fn (array $context): bool => $context['line'] === 'test-bad' && $context['for'] === 'day'))->once();
});

it('keeps at most one expression marker', function () {
    $data = json_decode((string) file_get_contents(resource_path('data/voice/caster.json')), true);
    $data['pattern_scripts']['openings'] = [['id' => 'test-open', 'phases' => ['break'], 'topics' => ['*'], 'text' => '[laughter] Hier ist {sender}.']];
    $data['promo'] = [['id' => 'test-invite', 'category' => 'x', 'when' => ['break'], 'energy' => 'warm', 'text' => '[sigh] Schaut einfach mal rein.']];
    $data['pattern_scripts']['use_topics'] = [];
    $path = storage_path('framework/testing/caster-markers-'.uniqid().'.json');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($data));

    $script = patternScripts($path)->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE]);
    unlink($path);

    expect($script)->not->toBeNull()
        ->and(preg_match_all('/\[[a-z-]+\]/', $script['text'] ?? ''))->toBe(1);
});

it('counts a pattern script under its own channel, without tokens', function () {
    patternScripts()->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE]);

    $row = DB::table('voice_usage')->where('channel', PatternScripts::CHANNEL)->first();

    expect($row?->accepted)->toBe(1)
        ->and($row?->input_tokens)->toBe(0)
        ->and($row?->output_tokens)->toBe(0);
});

it('speaks a name: no hash, no brackets, no emoji, no shortened npub', function (?string $raw, ?string $spoken) {
    expect(SceneFacts::spoken($raw))->toBe($spoken);
})->with([
    'cup number' => ['AoE2 Casual Cup EU #1', 'AoE2 Casual Cup EU Nummer 1'],
    'bracketed letters' => ['(A)ll (C)ats', 'All Cats'],
    'flags' => ['🇦🇹the austrians🇦🇹', 'the austrians'],
    'math letters' => ['𝕞ptf', 'mptf'],
    'npub' => ['npub1mfvw9ur…', null],
    'empty' => ['  ', null],
]);

it('knows the topic of every rotation slide', function (string $kind, ?string $scene, string $topic) {
    expect(SceneTopic::forSlot(['kind' => $kind, 'scene' => $scene]))->toBe($topic);
})->with([
    'match' => [RotationPlanner::MATCH, 'a1', SceneTopic::LIVE_GAMES],
    'board games' => [RotationPlanner::BOARD, 'd5', SceneTopic::LIVE_GAMES],
    'pong result' => [RotationPlanner::PONG, 'p1', SceneTopic::RESULTS],
    'tournament hero' => [RotationPlanner::TOURNAMENT, 'ta1', SceneTopic::TOURNAMENTS],
    'next tournament' => [RotationPlanner::TOURNAMENT, 'tb7', SceneTopic::TOURNAMENTS],
    'live bracket' => [RotationPlanner::TOURNAMENT, 'tc4', SceneTopic::LIVE_TOURNAMENT],
    'tournament tv' => [RotationPlanner::TOURNAMENT, 'tv2', SceneTopic::LIVE_TOURNAMENT],
    'champion' => [RotationPlanner::TOURNAMENT, 'tx6', SceneTopic::RESULTS],
    'ladders teaser' => [RotationPlanner::TEASER, 'a3', SceneTopic::LADDERS],
    'clans teaser' => [RotationPlanner::TEASER, 'b4', SceneTopic::CLANS],
    'mempool' => [RotationPlanner::TEASER, 'm1', SceneTopic::MEMPOOL],
    'tmnf' => [RotationPlanner::TEASER, 'g3', SceneTopic::TMNF],
    'blockfill set' => [RotationPlanner::BLOCKFILL, 'f2', SceneTopic::BLOCKFILL],
    'zap teaser' => [RotationPlanner::TEASER, 'a5', SceneTopic::LEAGUE],
]);
