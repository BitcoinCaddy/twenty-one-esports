<?php

use App\Support\Caster\CasterVoice;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptGuard;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceFactCheck;
use App\Support\Voice\VoiceGuard;
use App\Support\Voice\VoiceHygiene;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the caster voice's repairs and fact check (P6 yield round)
|--------------------------------------------------------------------------
|
| With the laravel/ai fakes, never the network. The real Haiku drafts failed
| on commas, long sentences and the sentence count (5 of 5 scenes,
| 2026-10-11), and passed with invented match content ("Zug 34, die Damen
| sind runter." while no game ran). The repairs cut sentences at clause
| boundaries and drop the overflow; what they cannot repair, and every claim
| the facts do not support, is still rejected.
|
*/

beforeEach(function () {
    config(['tv.commentary.enabled' => true]);
});

/**
 * Facts of a quiet league moment: no live game, no result, no tournament.
 *
 * @return array<string, mixed>
 */
function casterVoiceQuietFacts(): array
{
    return ['sender' => 'TWENTY ONE TV', 'website_spoken' => 'esports punkt einundzwanzig punkt space', 'players' => 34, 'clans' => 8, 'live_games' => []];
}

/**
 * @return array{text: string, canon_id: string|null, mood: string}|null
 */
function casterVoiceWrite(): ?array
{
    return app(CasterVoice::class)->write('scene', ScenePhase::LIVE_MATCH, casterVoiceQuietFacts(), 3, 8);
}

it('puts every sentence on its own line and cuts long ones at clause boundaries', function (string $draft, string $expected) {
    expect(ScriptGuard::splitSentences($draft, 12))->toBe($expected);
})->with([
    'several sentences on one line' => ['Wir sind live. Es gibt im Moment keine laufenden Partien. 34 Spieler sind dabei.', "Wir sind live.\nEs gibt im Moment keine laufenden Partien.\n34 Spieler sind dabei."],
    'comma before und, the und goes' => ['Die Lobby ist offen, die Bretter sind frei, und ihr könnt jederzeit rein.', "Die Lobby ist offen, die Bretter sind frei.\nIhr könnt jederzeit rein."],
    'comma before aber, the aber stays' => ['Die Liga ist noch jung und klein, aber jeder Sieg zählt hier richtig viel.', "Die Liga ist noch jung und klein.\nAber jeder Sieg zählt hier richtig viel."],
    'colon first' => ['Soviel zum Format: Sechzehn Leute, K.-o., und die oberen vier teilen sich Sats aus dem Topf.', "Soviel zum Format.\nSechzehn Leute, K.-o.\nDie oberen vier teilen sich Sats aus dem Topf."],
    'plain comma before a main clause' => ['Folgt dem Profil auf esports punkt einundzwanzig punkt space, dann verpasst ihr nichts.', "Folgt dem Profil auf esports punkt einundzwanzig punkt space.\nDann verpasst ihr nichts."],
    'bare und before a clause' => ['Gleich geht die Lobby wieder auf und wir warten dort auf euch alle.', "Gleich geht die Lobby wieder auf.\nWir warten dort auf euch alle."],
    'markers stay beside their words' => ['[surprise-oh] Die Lobby ist voll, die Bretter sind heiß, und dann kommt der Zug. [laughter]', "[surprise-oh] Die Lobby ist voll, die Bretter sind heiß.\nDann kommt der Zug. [laughter]"],
    'full stop before closing markers' => ['Die Lobby ist schon voll [laughter], und gleich geht die nächste Runde los.', "Die Lobby ist schon voll. [laughter]\nGleich geht die nächste Runde los."],
    'ordinal is no sentence end' => ['Der 3. Platz ist knapp.', 'Der 3. Platz ist knapp.'],
]);

it('leaves a sentence uncut where no cut keeps its meaning, so the guard still rejects it', function (string $draft) {
    config(['tv.commentary.scripts.max_commas' => 1]);
    $repaired = ScriptGuard::splitSentences($draft, 12);

    expect($repaired)->toBe($draft)
        ->and((new ScriptGuard)->check("Eins zwei.\n{$repaired}\nDrei vier.", 3, 8, 12))->not->toBe([]);
})->with([
    'relative clause' => ['Die Partie, die gerade läuft, ist richtig eng für beide Spieler heute.'],
    'subordinate clause first' => ['Wer heute Nacht noch mitspielen will, findet hier den passenden Gegner sofort und gleich.'],
    'noun list with und' => ['Die Liga hat 21 Spieler und alle spielen jeden Tag sehr gerne mit ihren Freunden zusammen.'],
    'list item alone' => ['Klar, da geht noch mehr, Leute.'],
]);

it('drops sentences from the end down to the maximum, unless a required fact goes with them', function () {
    $script = "Eins zwei.\nDrei vier.\nFünf sechs.\nSieben acht.";

    expect(ScriptGuard::trimSentences($script, 2))->toBe("Eins zwei.\nDrei vier.")
        ->and(ScriptGuard::trimSentences($script, 4))->toBe($script)
        ->and(ScriptGuard::trimSentences($script, 2, ['Sieben']))->toBe($script);
});

it('repairs a one-line draft with long sentences and too many of them, and counts the repair', function () {
    // The limits the drafts of 2026-10-11 were cut at; the repair itself does not depend on them.
    config(['tv.commentary.scripts.max_words_per_sentence' => 12, 'tv.commentary.scripts.max_commas' => 1]);
    VoiceAgent::fake([[
        'text' => 'Die Lobby ist offen, die Bretter sind frei, und ihr könnt jederzeit rein. Heute sind 34 Spieler dabei. Acht Clans sind am Start. Gleich geht es weiter. Kurz durchatmen. Schaut rein. Na also, läuft. Bleibt dran, Leute. Bis gleich, ihr Lieben.',
        'canon_id' => null, 'mood' => 'fakten',
    ]]);

    $script = casterVoiceWrite();

    expect($script['text'] ?? null)->toBe("Die Lobby ist offen, die Bretter sind frei.\nIhr könnt jederzeit rein.\nHeute sind 34 Spieler dabei.\nAcht Clans sind am Start.\nGleich geht es weiter.\nKurz durchatmen.\nSchaut rein.\nNa also, läuft.")
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL.'_repaired')->pluck('reason')->sort()->values()->all())->toBe([ScriptGuard::SENTENCE_COUNT, ScriptGuard::SENTENCE_LONG]);
    VoiceAgent::assertPromptedTimes(1);
});

it('reads a fact number spelled out as that fact, and any other number word as an invented name', function () {
    $guard = app(CasterVoice::class)->guard();

    expect($guard->check('Acht Clans und Vierunddreißig Spieler.', casterVoiceQuietFacts(), 900, null))->toBe([])
        ->and($guard->check('Vierzehn Spieler mehr als gestern.', casterVoiceQuietFacts(), 900, null))->toContain(VoiceGuard::NAME)
        ->and(VoiceGuard::spelledNumber('Zweihundertelf'))->toBe(211)
        ->and(VoiceGuard::spelledNumber('Achtung'))->toBeNull();
});

it('rejects invented match content through the fact check, after one more try', function (string $draft, string $claim) {
    VoiceAgent::fake([
        ['text' => $draft, 'canon_id' => null, 'mood' => 'hype'],
        ['text' => $draft, 'canon_id' => null, 'mood' => 'hype'],
    ]);
    VoiceFactCheck::fake([
        ['claims' => [['claim' => $claim, 'evidence' => '', 'supported' => false]]],
        ['claims' => [['claim' => $claim, 'evidence' => '', 'supported' => false]]],
    ]);

    expect(casterVoiceWrite())->toBeNull()
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->where('reason', VoiceGuard::UNSUPPORTED_CLAIM)->value('count'))->toBe(2)
        ->and(DB::table('voice_usage')->where('channel', CasterVoice::FACT_CHECK)->value('attempts'))->toBe(2);
    VoiceFactCheck::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, $claim) && str_contains($prompt->prompt, '"live_games": []'));
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, VoiceGuard::UNSUPPORTED_CLAIM) === false && str_contains($prompt->prompt, 'Eine Aussage stand so nicht in den Fakten'));
})->with([
    'pieces' => ["Achtung, es geht los.\nSchwarz hat gerade einen Springer verloren.\nBleibt dran.", 'Schwarz hat gerade einen Springer verloren.'],
    'boards' => ["Achtung, es geht los.\nDrei Bretter sind live.\nBleibt dran.", 'Drei Bretter sind live.'],
    'move and fact number' => ["Willkommen im Endspiel.\nZug 34, die Damen sind runter.\nBleibt dran.", 'Zug 34, die Damen sind runter.'],
]);

it('voices the second draft when the fact check clears it', function () {
    VoiceAgent::fake([
        ['text' => "Achtung, es geht los.\nDrei Bretter sind live.\nBleibt dran.", 'canon_id' => null, 'mood' => 'hype'],
        ['text' => "Die Liga hat 34 Spieler.\nGerade läuft keine Partie.\nDie Lobby wartet auf euch.", 'canon_id' => null, 'mood' => 'fakten'],
    ]);
    VoiceFactCheck::fake([
        ['claims' => [['claim' => 'Drei Bretter sind live.', 'evidence' => '', 'supported' => false]]],
        ['claims' => [['claim' => 'Die Liga hat 34 Spieler.', 'evidence' => '"players": 34', 'supported' => true]]],
    ]);

    expect(casterVoiceWrite()['text'] ?? null)->toBe("Die Liga hat 34 Spieler.\nGerade läuft keine Partie.\nDie Lobby wartet auf euch.");
    // How the league works counts as fact; a play-by-play example of the cast research does not.
    VoiceFactCheck::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Fernschach heißt: ein Zug am Tag') && ! str_contains($prompt->prompt, 'die Damen sind runter'));
});

it('fails closed when the fact check fails or answers nothing readable', function (Closure|array $answer) {
    $draft = ['text' => "Die Liga hat 34 Spieler.\nGerade läuft keine Partie.\nDie Lobby wartet auf euch.", 'canon_id' => null, 'mood' => 'fakten'];
    VoiceAgent::fake([$draft, $draft]);
    VoiceFactCheck::fake($answer);

    expect(casterVoiceWrite())->toBeNull();
})->with([
    'timeout' => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    'unreadable' => fn () => [['claims' => 'none'], ['claims' => 'none']],
]);

it('lets the station ident open a script and still turns a preamble down', function () {
    $hygiene = new VoiceHygiene;

    expect($hygiene->check('Hier ist TWENTY ONE TV. Die Lobby ist offen.', 'de'))->not->toContain(VoiceHygiene::PREAMBLE)
        ->and($hygiene->check('Hier ist der Text: Die Lobby ist offen.', 'de'))->toContain(VoiceHygiene::PREAMBLE);
});

it('states the hard limits in a system prompt that stays byte-identical', function () {
    $voice = app(CasterVoice::class);

    expect($voice->fixedPart())->toBe(app(CasterVoice::class)->fixedPart())
        ->toContain('Höchstens 16 Wörter pro Satz.')
        ->toContain('Höchstens 2 Kommas pro Satz.')
        ->toContain('2 bis 8 Sätze')
        // Nothing in the prompt shows the model a hash to copy (the hashtag rejections of 2026-10-11).
        ->not->toContain('#')
        ->and($voice->variablePart('scene', ScenePhase::PRE_SHOW, ['tournaments_open' => [['name' => 'Cup Nummer 1']]], 2, 8, [], [], [], []))->not->toContain('#');
});

it('offers the model a small canon pick, not the posts\' fifty to a hundred entries', function () {
    VoiceAgent::fake([['text' => "Die Liga hat 34 Spieler.\nAcht Clans sind dabei.\nSchaut rein.", 'canon_id' => null, 'mood' => 'fakten']]);

    casterVoiceWrite();

    VoiceAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $section = explode('Kanon-Auswahl', $prompt->prompt)[1] ?? '';
        $entries = preg_match_all('/^- \S+: „/mu', explode('Schreibe jetzt', $section)[0]);

        return $entries >= 12 && $entries <= 15;
    });
});

it('speaks a hash in the facts or the draft out instead of rejecting the script', function () {
    VoiceAgent::fake([['text' => "Der Cup #1 ist offen.\nAnmelden geht auf esports punkt einundzwanzig punkt space.\nDas wird #lustig.", 'canon_id' => null, 'mood' => 'fakten']]);
    $facts = [...casterVoiceQuietFacts(), 'tournaments_open' => [['name' => 'Cup #1']]];

    $script = app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, $facts, 2, 8);

    expect($script['text'] ?? null)->toBe("Der Cup Nummer 1 ist offen.\nAnmelden geht auf esports punkt einundzwanzig punkt space.\nDas wird lustig.")
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL.'_repaired')->pluck('reason')->all())->toContain(VoiceGuard::HASHTAG)
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->count())->toBe(0);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Cup Nummer 1') && ! str_contains($prompt->prompt, '#'));
});

it('lets a spoken sentence run to sixteen words and two commas, a decimal comma not counted', function () {
    $guard = new ScriptGuard;

    expect($guard->check("Hey.\nEl-z fährt 16,13 Sekunden, schneller als die Autorenzeit, und das in der ersten Woche.", 2, 8, 16))->toBe([])
        ->and($guard->check("Hey.\nEins, zwei, drei, vier.", 2, 8, 16))->toContain(ScriptGuard::COMMAS)
        ->and((new VoiceHygiene)->check('Die Autorenzeit liegt bei 16,25 Sekunden. Platz zwei fährt 16,25 Sekunden.', 'de'))->not->toContain(VoiceHygiene::REPEATED);
});

it('drops a sentence that names a player outside a result moment, and rejects the draft when too few stay', function () {
    $facts = [...casterVoiceQuietFacts(), 'live_games' => [['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'mode' => 'Blitz', 'move' => 14]]];
    VoiceAgent::fake([
        ['text' => "Live im Blitz.\nAda spielt gegen Bob.\nDie Lobby ist offen.\nSchreibt in den Chat, wer gewinnt.", 'canon_id' => null, 'mood' => 'hype'],
        ['text' => "Ada spielt.\nBob auch.\nSchaut rein.", 'canon_id' => null, 'mood' => 'hype'],
        ['text' => "Ada spielt.\nBob auch.\nSchaut rein.", 'canon_id' => null, 'mood' => 'hype'],
    ]);
    VoiceFactCheck::fake([['claims' => []]]);

    expect(app(CasterVoice::class)->write('scene', ScenePhase::LIVE_MATCH, $facts, 3, 8)['text'] ?? null)->toBe("Live im Blitz.\nDie Lobby ist offen.\nSchreibt in den Chat, wer gewinnt.")
        ->and(app(CasterVoice::class)->write('scene', ScenePhase::LIVE_MATCH, $facts, 3, 8))->toBeNull()
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->where('reason', ScriptGuard::NAME_COUNT)->value('count'))->toBe(2);
});

it('lets one name through in a result moment, and not the same person again within thirty minutes', function () {
    $facts = [...casterVoiceQuietFacts(), 'results' => [['winner' => 'Ada', 'loser' => 'Bob', 'game' => 'Schach', 'mode' => 'Blitz']]];
    $draft = ['text' => "Ada gewinnt im Blitz.\nDie Lobby ist offen.\nSchreibt in den Chat, wer als Nächstes dran ist.", 'canon_id' => null, 'mood' => 'hype'];
    $again = ['text' => "Ada holt sich den Sieg.\nWer ist zum ersten Mal hier? Ein kurzes Hallo in den Chat!\nMit Nostr einloggen und loslegen.", 'canon_id' => null, 'mood' => 'hype'];
    VoiceAgent::fake([$draft, $again]);
    VoiceFactCheck::fake([['claims' => []], ['claims' => []]]);

    expect(app(CasterVoice::class)->write('scene', ScenePhase::AFTER_RESULT, $facts, 2, 8)['text'] ?? null)->toBe($draft['text']);

    $this->travel(10)->minutes();
    expect(app(CasterVoice::class)->write('scene', ScenePhase::AFTER_RESULT, $facts, 2, 8)['text'] ?? null)->toBe("Wer ist zum ersten Mal hier?\nEin kurzes Hallo in den Chat!\nMit Nostr einloggen und loslegen.");
});

it('says a tournament name once in a league-voice script, the repeat as the noun', function () {
    $facts = [...casterVoiceQuietFacts(), 'tournaments_open' => [['name' => 'Chess Cup', 'game' => 'Schach', 'start' => 'am Samstag']]];
    VoiceAgent::fake([['text' => "Chess Cup startet am Samstag.\nBei Chess Cup ist noch Platz.\nSchreibt in den Chat, wer mitspielt.", 'canon_id' => null, 'mood' => 'werbung']]);
    VoiceFactCheck::fake([['claims' => []]]);

    expect(app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, $facts, 3, 8)['text'] ?? null)
        ->toBe("Chess Cup startet am Samstag.\nBeim Turnier ist noch Platz.\nSchreibt in den Chat, wer mitspielt.");
});

it('asks for one fact and few names, and lets the fact check take jokes, invitations and chat questions as no claims', function () {
    VoiceAgent::fake([['text' => "Number go up. [laughter]\nWer von euch ist Team Schach? Ab in den Chat damit.\nDie Lobby ist offen.", 'canon_id' => 'btc-number-go-up', 'mood' => 'witz']]);
    VoiceFactCheck::fake([['claims' => []]]);

    casterVoiceWrite();

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Höchstens EIN Fakt') && str_contains($prompt->prompt, 'Keine Spielernamen'));
    VoiceFactCheck::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Das hier ist unsere Liga. Von Bitcoinern für Bitcoiner.') && str_contains($prompt->prompt, 'Mit Nostr einloggen, Brett nehmen und loslegen.'));
    expect((new VoiceFactCheck)->instructions())->toContain('jokes and sayings')->toContain('calls to write in the chat')->toContain('invitations to play, watch, log in, sign up or bring friends')->toContain('league_basics')
        ->and(app(CasterVoice::class)->fixedPart())->toContain('Ich lieb diesen Sender.');
});
