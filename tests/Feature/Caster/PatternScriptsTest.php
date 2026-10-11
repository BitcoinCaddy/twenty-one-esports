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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
                ->and(count(ScriptGuard::lines($script['text'])))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6);

            // The fact guard part by part, each canon line under its own entry (a script quotes up to two).
            foreach ($script['parts'] as $id => $part) {
                expect(app(CasterVoice::class)->guard()->check(ScriptGuard::withoutMarkers(str_replace("\n", ' ', $part)), $facts, 900, $script['part_canons'][$id] ?? null))->toBe([], $part);
            }

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

it('says at most one fact line, two only in a result or champion moment', function () {
    foreach (patternFactsByTopic() as $topic => $facts) {
        $phase = SceneTopic::phase($topic, now()->setTimezone('Europe/Berlin')->setTime(15, 0)->toImmutable());

        for ($i = 0; $i < 3; $i++) {
            $script = patternScripts()->write($topic, $phase, $facts);
            $factParts = array_keys(array_filter($script['roles'] ?? [], fn (string $role): bool => $role === PatternScripts::FACT));
            $moment = $phase === ScenePhase::AFTER_RESULT || array_filter($factParts, fn (string $id): bool => $id === 'f-lt-4') !== [];

            expect(count($factParts))->toBeLessThanOrEqual($moment ? 2 : 1, "{$topic}: {$script['text']}")
                ->and(count(ScriptGuard::lines($script['text'] ?? '')))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6);
        }
    }
});

it('says no fact again within twenty minutes, in any script, and may after that', function () {
    $facts = patternFactsByTopic()[SceneTopic::LADDERS];
    $atoms = function (array $script): array {
        $said = [];
        foreach ($script['parts'] as $id => $text) {
            if ($script['roles'][$id] === PatternScripts::FACT) {
                preg_match_all('/\d+/', str_replace('Schach Blitz 5+3', '', $text), $numbers);
                $said = [...$said, ...$numbers[0]];
            }
        }

        return $said;
    };

    $first = patternScripts()->write(SceneTopic::LADDERS, ScenePhase::BREAK, $facts);
    $said = $atoms($first);
    expect($said)->not->toBe([]);

    // Every later script within twenty minutes: no number of the first fact again (flavour-only scripts are fine).
    for ($minute = 1; $minute < 20; $minute += 3) {
        $script = patternScripts()->write(SceneTopic::LADDERS, ScenePhase::BREAK, $facts, CarbonImmutable::now()->addMinutes($minute));
        if ($script !== null) {
            expect(array_intersect($atoms($script), $said))->toBe([], $script['text']);
        }
    }

    $sayable = [];
    for ($i = 0; $i < 8; $i++) {
        $script = patternScripts()->write(SceneTopic::LADDERS, ScenePhase::BREAK, $facts, CarbonImmutable::now()->addMinutes(41 + 21 * $i));
        $sayable = [...$sayable, ...($script === null ? [] : $atoms($script))];
    }
    expect(array_intersect($said, $sayable))->not->toBe([]);
});

it('repeats no flavour line within the last forty scripts and gives up when the pool is used up', function () {
    config(['tv.commentary.patterns.flavour_recent' => 400]);
    $facts = ['sender' => 'TWENTY ONE TV', 'players' => 34];
    $seen = [];
    $scripts = 0;

    for ($i = 0; $i < 200; $i++) {
        $script = patternScripts()->write(SceneTopic::LEAGUE, ScenePhase::BREAK, $facts);

        if ($script === null) {
            break;
        }

        $scripts++;
        foreach ($script['roles'] as $id => $role) {
            if ($role !== PatternScripts::FACT) {
                expect($seen)->not->toHaveKey($id, "{$id} again in script {$scripts}");
                $seen[$id] = true;
            }
        }
    }

    expect($scripts)->toBeGreaterThanOrEqual(25)->toBeLessThan(200);
});

it('mixes jokes, invitations, pride, chat calls and emotion into the scripts', function () {
    $roles = [];
    for ($i = 0; $i < 12; $i++) {
        $script = patternScripts()->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE], CarbonImmutable::now()->addMinutes($i));
        foreach ($script['roles'] ?? [] as $role) {
            $roles[$role] = ($roles[$role] ?? 0) + 1;
        }
    }

    expect(array_keys($roles))->toContain(PatternScripts::JOKE, PatternScripts::INVITE, PatternScripts::PRIDE, PatternScripts::ENGAGE, PatternScripts::EMOTION);
});

it('keeps at most two markers, and a joke is followed by laughter', function () {
    $data = json_decode((string) file_get_contents(resource_path('data/voice/caster.json')), true);
    $data['pattern_scripts']['openings'] = [['id' => 'test-open', 'phases' => ['break'], 'topics' => ['*'], 'text' => '[laughter] Hier ist {sender}.']];
    $data['promo'] = [['id' => 'test-invite', 'category' => 'x', 'when' => ['break'], 'energy' => 'warm', 'text' => '[sigh] Schaut einfach mal rein.']];
    $path = storage_path('framework/testing/caster-markers-'.uniqid().'.json');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($data));
    $jokes = 0;

    for ($i = 0; $i < 12; $i++) {
        $script = patternScripts($path)->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE], CarbonImmutable::now()->addMinutes($i));

        expect($script)->not->toBeNull()
            ->and(preg_match_all('/\[[a-z-]+\]/', $script['text'] ?? ''))->toBeLessThanOrEqual(2, $script['text'] ?? '');
        // The first joke of a script laughs, unless two other markers took the budget.
        $firstJoke = array_search(PatternScripts::JOKE, $script['roles'] ?? [], true);
        if (is_string($firstJoke) && preg_match_all('/\[[a-z-]+\]/', implode(' ', array_diff_key($script['parts'], [$firstJoke => true]))) < 2) {
            expect($script['parts'][$firstJoke])->toEndWith('[laughter]');
            $jokes++;
        }
    }
    unlink($path);

    expect($jokes)->toBeGreaterThan(0);
});

it('names nobody outside a result or champion moment, one person at most, and not again within thirty minutes', function () {
    foreach ([SceneTopic::LIVE_GAMES, SceneTopic::LADDERS, SceneTopic::TMNF, SceneTopic::TOURNAMENTS, SceneTopic::LIVE_TOURNAMENT] as $topic) {
        $facts = patternFactsByTopic()[$topic];
        $names = ScriptGuard::personNames($facts);

        for ($i = 0; $i < 3; $i++) {
            $text = patternScripts()->write($topic, SceneTopic::phase($topic, now()->setTimezone('Europe/Berlin')->setTime(15, 0)->toImmutable()), $facts)['text'] ?? '';

            expect(ScriptGuard::namesIn($text, $names))->toBe([], "{$topic}: {$text}");
        }
    }

    $facts = patternFactsByTopic()[SceneTopic::RESULTS];
    $named = [];
    for ($minute = 0; $minute < 30; $minute += 2) {
        $text = patternScripts()->write(SceneTopic::RESULTS, ScenePhase::AFTER_RESULT, $facts, CarbonImmutable::now()->addMinutes($minute))['text'] ?? '';
        $here = ScriptGuard::namesIn($text, ScriptGuard::personNames($facts));

        expect(count($here))->toBeLessThanOrEqual(1, $text)
            ->and(array_intersect($here, $named))->toBe([], "named again within 30 minutes: {$text}");
        $named = [...$named, ...$here];
    }
    expect($named)->not->toBe([]);
});

it('knows the person names of the facts and limits a script to its allowance', function () {
    $facts = [...patternFactsByTopic()[SceneTopic::LIVE_TOURNAMENT], 'clan_spotlight' => ['name' => 'Einundzwanzig Bern']];

    expect(ScriptGuard::personNames($facts))->toBe(['Ada', 'Bob', 'Cleo'])
        ->and(ScriptGuard::limitNames("Ada führt.\nDie Liga lebt.\nBob ist Zweiter.\nSchaut rein.", ['Ada', 'Bob'], 1, [], 3))->toBe("Ada führt.\nDie Liga lebt.\nSchaut rein.")
        ->and(ScriptGuard::limitNames("Ada führt.\nDie Liga lebt.\nSchaut rein.", ['Ada'], 0, [], 3))->toBeNull()
        ->and(ScriptGuard::limitNames("Ada führt.\nDie Liga lebt.\nAda auch.\nSchaut rein.", ['Ada'], 1, [], 3))->toBe("Ada führt.\nDie Liga lebt.\nSchaut rein.")
        ->and(ScriptGuard::limitNames("Ada führt.\nDie Liga lebt.\nBob ist Zweiter.\nSchaut rein.", ['Ada', 'Bob'], 1, ['ada' => true], 3))->toBe("Die Liga lebt.\nBob ist Zweiter.\nSchaut rein.");
});

it('writes forty flavour lines and more that pass the spoken rules and the hard rules', function () {
    $flavour = json_decode((string) file_get_contents(resource_path('data/voice/caster.json')), true)['pattern_scripts']['flavour'];
    $lines = array_column([...$flavour['pride'], ...$flavour['engage'], ...$flavour['emotion'], ...$flavour['invite']], 'text');

    expect(count($flavour['pride']) + count($flavour['engage']) + count($flavour['emotion']))->toBeGreaterThanOrEqual(40);

    // Each line passes the fact guard on its own, a canon catchphrase in it ("Grüße gehen raus") under its own canon id.
    $basics = ['sender' => 'TWENTY ONE TV', 'website_spoken' => 'esports punkt einundzwanzig punkt space'];
    foreach ([...$flavour['pride'], ...$flavour['engage'], ...$flavour['emotion'], ...$flavour['invite']] as $row) {
        $line = (string) PatternScripts::fill($row['text'], $basics);
        expect(app(CasterVoice::class)->guard()->check(ScriptGuard::withoutMarkers($line), $basics, 900, $row['canon'] ?? null))->toBe([], $line);
    }

    foreach ($lines as $line) {
        $split = ScriptGuard::splitSentences($line, 16);

        expect((new ScriptGuard)->check($split, 1, 3, 16))->toBe([], $line)
            ->and(PatternScripts::commaChain($split))->toBeFalse($line)
            ->and($line)->not->toMatch('/#|@|npub|lnurl|gebühr|startgeld|\bfee\b|gesicht|\bface\b|sats? zahlen/iu');
    }

    foreach ($flavour['jokes'] as $id) {
        $wording = (string) (app(VoiceCanon::class)->find($id)['wording'] ?? '');

        expect($wording)->not->toBe('', $id)
            ->and(app(CasterVoice::class)->guard()->check($wording, $basics, 900, $id))->toBe([], $wording)
            ->and((new ScriptGuard)->check(ScriptGuard::splitSentences($wording.' [laughter]', 16), 1, 3, 16))->toBe([], $wording)
            ->and($wording)->not->toMatch('/#|@|gebühr|startgeld|\bfee\b|gesicht|\bface\b/iu');
    }
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
    $pride = array_map(fn (int $n): array => ['id' => "test-pride-{$n}", 'phases' => ['break'], 'text' => 'Das ist unsere Liga.'], range(1, 6));
    $data['pattern_scripts']['flavour'] = ['pride' => $pride, 'engage' => [], 'emotion' => [], 'invite' => [], 'jokes' => []];
    $path = storage_path('framework/testing/caster-patterns-'.uniqid().'.json');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($data));
    Log::spy();

    $texts = [];
    for ($i = 0; $i < 4; $i++) {
        $texts[] = patternScripts($path)->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE], CarbonImmutable::now()->addMinutes(21 * $i))['text'] ?? '';
    }
    unlink($path);

    expect(implode("\n", $texts))->not->toContain('500')
        ->and(implode("\n", $texts))->toContain('Schaut einfach mal rein.')
        ->and(DB::table('voice_rejections')->where('channel', PatternScripts::CHANNEL)->where('reason', VoiceGuard::NUMBER)->value('count'))->toBe(1);
    Log::shouldHaveReceived('info')->with('Caster pattern line dropped', Mockery::on(fn (array $context): bool => $context['line'] === 'test-bad' && $context['for'] === 'day'))->once();
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

/** A copy of caster.json changed by `$change`, at a path the test deletes. */
function patternDataWith(Closure $change): string
{
    $data = json_decode((string) file_get_contents(resource_path('data/voice/caster.json')), true);
    $data = $change($data);
    $path = storage_path('framework/testing/caster-data-'.uniqid().'.json');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($data));

    return $path;
}

it('quotes the full verified canon, one or two lines per script, none again within the last two hundred', function () {
    $patterns = patternScripts();
    $perScript = [];
    $quoted = [];

    expect(count($patterns->canonPool()))->toBeGreaterThan(400);

    for ($i = 0; $i < 50; $i++) {
        $script = patternScripts()->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE], CarbonImmutable::now()->addMinutes($i));

        if ($script === null) {
            continue;
        }

        $perScript[] = count($script['canon_ids']);
        // The jokes; a greeting catchphrase ("Grüße gehen raus …") may come back in another greeting.
        array_push($quoted, ...array_values(array_filter($script['part_canons'], fn (string $id, string $part): bool => str_starts_with($part, 'canon:'), ARRAY_FILTER_USE_BOTH)));
    }

    expect(min($perScript))->toBeGreaterThanOrEqual(1)
        ->and(max($perScript))->toBeLessThanOrEqual(2)
        ->and(array_sum($perScript) / count($perScript))->toBeGreaterThan(1.4)
        ->and(count($quoted))->toBe(count(array_unique($quoted)));
});

it('uses a fact line with a name only in a result or champion moment', function () {
    $path = patternDataWith(function (array $data): array {
        $data['pattern_scripts']['facts'] = [['id' => 'test-named', 'topics' => ['ladders'], 'text' => '{leader} führt mit {leader_elo} Elo.']];
        $data['pattern_scripts']['use_patterns'] = [];

        return $data;
    });

    $text = patternScripts($path)->write(SceneTopic::LADDERS, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LADDERS])['text'] ?? '';
    unlink($path);

    expect($text)->not->toBe('')->not->toContain('Ada');
});

it('never assembles a line with a comma chain', function () {
    $path = patternDataWith(function (array $data): array {
        $data['pattern_scripts']['flavour']['invite'] = [['id' => 'test-chain', 'phases' => ['break'], 'text' => 'Browser auf, einloggen, spielen.']];
        $data['promo'] = [];
        $data['pattern_scripts']['use_topics'] = [];

        return $data;
    });
    $texts = [];

    for ($i = 0; $i < 6; $i++) {
        $texts[] = patternScripts($path)->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE], CarbonImmutable::now()->addMinutes($i))['text'] ?? '';
    }
    unlink($path);

    expect(implode("\n", $texts))->not->toContain('Browser auf')->not->toBe(str_repeat("\n", 5));
});

it('says a tournament name once per script, later as the noun, and not again within fifteen minutes', function () {
    $names = ['Chess Cup Nummer 2' => ['Turnier', 'n'], 'A07-Race' => ['Strecke', 'f'], 'Schach Blitz 5+3' => ['Ladder', 'f']];

    expect(ScriptGuard::entityNames(patternFactsByTopic()[SceneTopic::TOURNAMENTS]))->toBe(['Chess Cup Nummer 2' => ['Turnier', 'n']])
        ->and(ScriptGuard::dedupeEntities("Chess Cup Nummer 2 startet bald.\nNoch 5 Plätze frei bei Chess Cup Nummer 2.\nChess Cup Nummer 2 wird gespielt.\nSchaut rein.", $names, [], 3))
        ->toBe("Chess Cup Nummer 2 startet bald.\nNoch 5 Plätze frei beim Turnier.\nDas Turnier wird gespielt.\nSchaut rein.")
        ->and(ScriptGuard::dedupeEntities("Auf A07-Race fährt die Nummer eins vorn.\nDie Zeit auf A07-Race ist stark.\nPlatz zwei in der Ladder Schach Blitz 5+3.\nIn der Ladder Schach Blitz 5+3 ist es eng.\nSchaut rein.", $names, [], 3))
        ->toBe("Auf A07-Race fährt die Nummer eins vorn.\nDie Zeit auf der Strecke ist stark.\nPlatz zwei in der Ladder Schach Blitz 5+3.\nIn der Ladder ist es eng.\nSchaut rein.")
        // Blocked (named lately) and no grammatical noun: the sentence goes; too few left: rejected.
        ->and(ScriptGuard::dedupeEntities("Heute ist Chess Cup Nummer 2 dran.\nSchaut rein.\nDie Lobby ist offen.\nBis gleich.", $names, ['chess cup nummer 2' => true], 3))->toBe("Schaut rein.\nDie Lobby ist offen.\nBis gleich.")
        ->and(ScriptGuard::dedupeEntities("Heute ist Chess Cup Nummer 2 dran.\nSchaut rein.\nBis gleich.", $names, ['chess cup nummer 2' => true], 3))->toBeNull();

    $facts = patternFactsByTopic()[SceneTopic::TOURNAMENTS];
    $first = patternScripts()->write(SceneTopic::TOURNAMENTS, ScenePhase::PRE_SHOW, $facts)['text'] ?? '';
    $second = patternScripts()->write(SceneTopic::TOURNAMENTS, ScenePhase::PRE_SHOW, $facts, CarbonImmutable::now()->addMinutes(5))['text'] ?? '';

    expect(substr_count($first, 'Chess Cup Nummer 2'))->toBe(1)
        ->and(substr_count($second, 'Chess Cup Nummer 2'))->toBe(0);
});

it('quotes no canon line again within the last canon lines, whatever the rotation', function () {
    config(['tv.commentary.patterns.flavour_recent' => 1, 'tv.commentary.patterns.recent' => 1]);
    $jokes = [];

    for ($i = 0; $i < 3; $i++) {
        Cache::put('caster:patterns:sequence', 0);
        $script = patternScripts()->write(SceneTopic::LEAGUE, ScenePhase::BREAK, patternFactsByTopic()[SceneTopic::LEAGUE], CarbonImmutable::now()->addMinutes(21 * $i));
        $jokes[] = array_values(array_filter($script['part_canons'] ?? [], fn (string $id, string $part): bool => str_starts_with($part, 'canon:'), ARRAY_FILTER_USE_BOTH));
    }

    expect($jokes[0])->not->toBe([])
        ->and(array_intersect($jokes[2], $jokes[0]))->toBe([]);
});

it('writes a long scene as a small monologue: one fact, up to three canon lines, pride and more, 8 to 14 sentences', function () {
    $guard = new ScriptGuard;
    $long = 0;

    foreach ([SceneTopic::LEAGUE, SceneTopic::TOURNAMENTS, SceneTopic::LADDERS, SceneTopic::CLANS] as $index => $topic) {
        $script = patternScripts()->write($topic, ScenePhase::BREAK, patternFactsByTopic()[$topic], CarbonImmutable::now()->addMinutes(25 * $index), long: true);

        if ($script === null) {
            continue;
        }

        $long++;
        $roles = array_count_values($script['roles']);
        expect($guard->check($script['text'], 8, 14, 16))->toBe([], $topic)
            ->and($roles[PatternScripts::FACT] ?? 0)->toBeLessThanOrEqual(1)
            ->and(count($script['canon_ids']))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
            ->and(($roles[PatternScripts::PRIDE] ?? 0) + ($roles[PatternScripts::INVITE] ?? 0) + ($roles[PatternScripts::ENGAGE] ?? 0))->toBeGreaterThanOrEqual(2);
    }

    expect($long)->toBeGreaterThanOrEqual(3);
});

it('never writes a result moment long', function () {
    expect(patternScripts()->write(SceneTopic::RESULTS, ScenePhase::AFTER_RESULT, patternFactsByTopic()[SceneTopic::RESULTS], long: true))->toBeNull()
        ->and(patternScripts()->write(SceneTopic::RESULTS, ScenePhase::AFTER_RESULT, patternFactsByTopic()[SceneTopic::RESULTS]))->not->toBeNull();
});
