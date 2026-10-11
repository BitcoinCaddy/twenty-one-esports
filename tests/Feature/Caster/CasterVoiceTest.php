<?php

use App\Support\Caster\CasterVoice;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceFactCheck;
use App\Support\Voice\VoiceGuard;
use App\Support\Voice\VoiceHygiene;
use Illuminate\Support\Facades\Cache;
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
    // The cut mechanics on their own; the sentence minimum (below) keeps a cut from leaving a fragment.
    config(['tv.commentary.scripts.min_words_per_sentence' => 0]);

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

it('makes no cut that would leave a part under the sentence minimum (user 2026-10-12: "komplette Sätze ab jetzt nur noch")', function () {
    $draft = 'Die Lobby ist offen, die Bretter sind frei, und ihr könnt jederzeit rein.';

    expect(ScriptGuard::splitSentences($draft, 12))->toBe($draft)
        ->and((new ScriptGuard)->check($draft, 1, 1, 12))->toContain(ScriptGuard::SENTENCE_LONG);

    config(['tv.commentary.scripts.min_words_per_sentence' => 0]);
    expect(ScriptGuard::splitSentences($draft, 12))->toBe("Die Lobby ist offen, die Bretter sind frei.\nIhr könnt jederzeit rein.");
});

it('drops sentences from the end down to the maximum, unless a required fact goes with them', function () {
    $script = "Eins zwei.\nDrei vier.\nFünf sechs.\nSieben acht.";

    expect(ScriptGuard::trimSentences($script, 2))->toBe("Eins zwei.\nDrei vier.")
        ->and(ScriptGuard::trimSentences($script, 4))->toBe($script)
        ->and(ScriptGuard::trimSentences($script, 2, ['Sieben']))->toBe($script);
});

it('repairs a one-line draft with long sentences and too many of them, and counts the repair', function () {
    // The limits the drafts of 2026-10-11 were cut at; the repair itself does not depend on them.
    config(['tv.commentary.scripts.max_words_per_sentence' => 12, 'tv.commentary.scripts.max_commas' => 1, 'tv.commentary.scripts.min_words_per_sentence' => 0]);
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

it('voices a draft the old rules turned down, without a fact check: free mode (user 2026-10-12: "FREIE KI-generierte Rede!!!")', function () {
    // Invented match content, a stock phrase, a number and an opening of the last script: only house and stream rules still count.
    $draft = "Darüber hinaus sind gerade drei Bretter live, und Zug 34 räumt die Damen vom Brett.\nIch schwöre euch, dass hier heute Abend noch einiges passieren wird, also bleibt dran.\nSchreibt mir in den Chat, wer von euch heute noch eine Partie spielen will.";
    VoiceAgent::fake([['text' => $draft, 'canon_id' => null, 'mood' => 'hype']]);
    VoiceFactCheck::fake();

    expect(casterVoiceWrite()['text'] ?? null)->toBe($draft);
    VoiceFactCheck::assertNeverPrompted();
    VoiceAgent::assertPromptedTimes(1);
});

it('still turns a draft down for what breaks the stream or a sentence too short to speak, and keeps nothing else as a reason', function () {
    expect(CasterVoice::free([VoiceGuard::NAME, VoiceGuard::NUMBER, VoiceGuard::UNSUPPORTED_CLAIM, ScriptGuard::STOCK_PHRASE, ScriptGuard::OPENING_REPEAT, ScriptGuard::SENTENCE_COUNT, ScriptGuard::COMMAS, VoiceGuard::CANON_WITHOUT_ID]))->toBe([])
        ->and(CasterVoice::free([VoiceGuard::HASHTAG, ScriptGuard::SENTENCE_SHORT, VoiceGuard::NAME]))->toBe([VoiceGuard::HASHTAG, ScriptGuard::SENTENCE_SHORT]);

    VoiceAgent::fake([
        ['text' => "Hey!\nLäuft.\nJetzt aber.", 'canon_id' => null, 'mood' => 'hype'],
        ['text' => "Hey!\nLäuft.\nJetzt aber.", 'canon_id' => null, 'mood' => 'hype'],
    ]);

    expect(casterVoiceWrite())->toBeNull()
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->where('reason', ScriptGuard::SENTENCE_SHORT)->value('count'))->toBe(2);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Ein Satz war zu kurz'));
});

it('drops a fragment from a draft as a repair while enough long sentences stay', function () {
    VoiceAgent::fake([['text' => "Boah.\nDie Lobby ist rund um die Uhr offen, und jeder von euch kann sofort einsteigen.\nLäuft.\nSchreibt mir in den Chat, wer heute Abend noch eine Partie spielen möchte.\nIch freu mich schon richtig auf die nächste Runde mit euch allen hier im Stream.", 'canon_id' => null, 'mood' => 'hype']]);

    expect(casterVoiceWrite()['text'] ?? null)->toBe("Die Lobby ist rund um die Uhr offen, und jeder von euch kann sofort einsteigen.\nSchreibt mir in den Chat, wer heute Abend noch eine Partie spielen möchte.\nIch freu mich schon richtig auf die nächste Runde mit euch allen hier im Stream.")
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL.'_repaired')->pluck('reason')->all())->toContain(ScriptGuard::SENTENCE_SHORT);
});

it('asks every prompt for long complete sentences, a random angle and none of the openings on air', function () {
    app(ScriptQueue::class)->enqueue('scene', ScenePhase::BREAK, 'Mal mit einem Augenzwinkern erzähl ich euch jetzt etwas über die Liga.', 'x', 0, now()->toImmutable());
    VoiceAgent::fake([['text' => "Die Liga hat heute vierunddreißig Spieler, und die sind alle mit Begeisterung dabei.\nSchreibt mir in den Chat, wer von euch heute noch eine Partie spielen will.\nIch freu mich schon richtig auf die nächste Runde mit euch allen hier im Stream.", 'canon_id' => null, 'mood' => 'fakten']]);

    casterVoiceWrite();

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Dein Blickwinkel diesmal:')
        && str_contains($prompt->prompt, '- Mal mit einem Augenzwinkern erzähl')
        && str_contains($prompt->prompt, CasterVoice::SENTENCE_STYLE));
    expect(count(CasterVoice::ANGLES))->toBeGreaterThanOrEqual(20)
        ->and(CasterVoice::chatReplyPrompt('Hallo', 'Ada', 1, 4))->toContain('Dein Blickwinkel diesmal:')->toContain(CasterVoice::SENTENCE_STYLE)
        ->and(CasterVoice::newsPrompt('Hashrate erreicht Rekord', 'BTC-Echo', 'bitcoin', 1, 3))->toContain('Dein Blickwinkel diesmal:')->toContain('die Schlagzeile wörtlich und vollständig')
        ->and(CasterVoice::followedPrompt('Ein Post', 'Ada', true, 3, 7))->toContain('Dein Blickwinkel diesmal:');
});

it('lets the station ident open a script and still turns a preamble down', function () {
    $hygiene = new VoiceHygiene;

    expect($hygiene->check('Hier ist TWENTY ONE TV. Die Lobby ist offen.', 'de'))->not->toContain(VoiceHygiene::PREAMBLE)
        ->and($hygiene->check('Hier ist der Text: Die Lobby ist offen.', 'de'))->toContain(VoiceHygiene::PREAMBLE);
});

it('states the hard limits in a system prompt that stays byte-identical', function () {
    $voice = app(CasterVoice::class);

    expect($voice->fixedPart())->toBe(app(CasterVoice::class)->fixedPart())
        ->toContain('Mindestens 7 und höchstens 32 Wörter pro Satz')
        ->toContain('Höchstens 2 Kommas pro Satz.')
        ->toContain('2 bis 8 Sätze')
        // The style guide's chat rules (short sentences, one-word exclamations) stay out of the TV prompt.
        ->not->toContain('Höchstens 14 Wörter pro Satz')
        ->not->toContain('Einwortsätze und Ausrufe sind erlaubt')
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

it('offers the canon as material with its source as context, least recently offered first, so the whole canon rotates', function () {
    $draft = ['text' => "Die Liga hat heute vierunddreißig Spieler, und die sind alle mit Begeisterung dabei.\nSchreibt mir in den Chat, wer von euch heute noch eine Partie spielen will.\nIch freu mich schon richtig auf die nächste Runde mit euch allen hier im Stream.", 'canon_id' => null, 'mood' => 'fakten'];
    VoiceAgent::fake([$draft, $draft]);

    casterVoiceWrite();
    $first = Cache::get('caster:recent:canon_offered');
    casterVoiceWrite();
    $both = Cache::get('caster:recent:canon_offered');

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'mit seiner Quelle als Kontext') && str_contains($prompt->prompt, 'Kategorie: ') && str_contains($prompt->prompt, 'erfinde keine Quelle'));
    // The second pick offers none of the first: twice as many distinct entries offered.
    expect(count($first))->toBeGreaterThanOrEqual(12)
        ->and(count($both))->toBe(2 * count($first));
});

it('speaks a hash in the facts or the draft out instead of rejecting the script', function () {
    VoiceAgent::fake([['text' => "Der Cup #1 ist ab sofort offen für alle, die mitspielen wollen.\nAnmelden geht ganz einfach auf esports punkt einundzwanzig punkt space.\nDas wird bestimmt ein richtig #lustig Abend mit euch allen.", 'canon_id' => null, 'mood' => 'fakten']]);
    $facts = [...casterVoiceQuietFacts(), 'tournaments_open' => [['name' => 'Cup #1']]];

    $script = app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, $facts, 2, 8);

    expect($script['text'] ?? null)->toBe("Der Cup Nummer 1 ist ab sofort offen für alle, die mitspielen wollen.\nAnmelden geht ganz einfach auf esports punkt einundzwanzig punkt space.\nDas wird bestimmt ein richtig lustig Abend mit euch allen.")
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL.'_repaired')->pluck('reason')->all())->toContain(VoiceGuard::HASHTAG)
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->count())->toBe(0);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Cup Nummer 1') && ! str_contains($prompt->prompt, '#'));
});

it('lets a spoken sentence run from seven to thirty-two words with two commas, a decimal comma not counted', function () {
    $guard = new ScriptGuard;
    $long = 'El-z fährt 16,13 Sekunden, schneller als die Autorenzeit, und das schon in der ersten Woche der Strecke während alle anderen noch üben und sich die Kurven anschauen.';

    expect($guard->check("Die Strecke der Woche läuft schon seit Montag bei uns.\n{$long}", 2, 8, 32))->toBe([])
        ->and($guard->check('Eins, zwei, drei, vier, fünf, sechs und sieben sind gezählt.', 1, 8, 32))->toContain(ScriptGuard::COMMAS)
        ->and($guard->check("Hey.\n{$long}", 2, 8, 32))->toBe([ScriptGuard::SENTENCE_SHORT])
        // Two sentences in one line count one by one; a marker is no word; a chat reply has no minimum.
        ->and($guard->check('Hey! Die Lobby ist rund um die Uhr für euch alle offen.', 1, 8, 32))->toBe([ScriptGuard::SENTENCE_SHORT])
        ->and($guard->check('[laughter] Na also, das läuft.', 1, 8, 32))->toBe([ScriptGuard::SENTENCE_SHORT])
        ->and($guard->check('Na also, das läuft.', 1, 8, 32, [], 0))->toBe([])
        ->and($guard->check(str_repeat('Wort ', 33).'.', 1, 8, 32))->toContain(ScriptGuard::SENTENCE_LONG)
        ->and((new VoiceHygiene)->check('Die Autorenzeit liegt bei 16,25 Sekunden. Platz zwei fährt 16,25 Sekunden.', 'de'))->not->toContain(VoiceHygiene::REPEATED);

    config(['tv.commentary.scripts.min_words_per_sentence' => 4]);
    expect($guard->check('Na also, das läuft.', 1, 8, 32))->toBe([]);
});

it('drops a sentence that names a player outside a result moment, and keeps the draft as it is when too few would stay', function () {
    $facts = [...casterVoiceQuietFacts(), 'live_games' => [['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'mode' => 'Blitz', 'move' => 14]]];
    $few = "Ada spielt heute im Blitz richtig stark auf und lässt nichts anbrennen.\nBob hält dagegen und denkt bei jedem Zug ganz lange nach.\nSchaut unbedingt rein, denn das hier lohnt sich wirklich für euch.";
    VoiceAgent::fake([
        ['text' => "Wir sind live im Blitz, und auf dem Brett geht es gerade richtig zur Sache.\nAda spielt gegen Bob, und beide sind hellwach.\nDie Lobby ist offen, und jeder von euch kann jederzeit einsteigen.\nSchreibt in den Chat, wer von den beiden eurer Meinung nach gewinnt.", 'canon_id' => null, 'mood' => 'hype'],
        ['text' => $few, 'canon_id' => null, 'mood' => 'hype'],
    ]);

    expect(app(CasterVoice::class)->write('scene', ScenePhase::LIVE_MATCH, $facts, 3, 8)['text'] ?? null)->toBe("Wir sind live im Blitz, und auf dem Brett geht es gerade richtig zur Sache.\nDie Lobby ist offen, und jeder von euch kann jederzeit einsteigen.\nSchreibt in den Chat, wer von den beiden eurer Meinung nach gewinnt.")
        ->and(app(CasterVoice::class)->write('scene', ScenePhase::LIVE_MATCH, $facts, 3, 8)['text'] ?? null)->toBe($few)
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::CHANNEL)->count())->toBe(0);
});

it('lets one name through in a result moment, and not the same person again within thirty minutes', function () {
    $facts = [...casterVoiceQuietFacts(), 'results' => [['winner' => 'Ada', 'loser' => 'Bob', 'game' => 'Schach', 'mode' => 'Blitz']]];
    $draft = ['text' => "Ada gewinnt im Blitz, und das Ergebnis ist jetzt eingetragen.\nDie Lobby ist offen, und jeder von euch kann jederzeit einsteigen.\nSchreibt in den Chat, wer als Nächstes an die Reihe kommen soll.", 'canon_id' => null, 'mood' => 'hype'];
    $again = ['text' => "Ada holt sich schon wieder den Sieg in dieser Runde.\nWer ist zum ersten Mal hier und will ein kurzes Hallo in den Chat schreiben?\nMit Nostr einloggen und loslegen geht bei uns in wenigen Sekunden.", 'canon_id' => null, 'mood' => 'hype'];
    VoiceAgent::fake([$draft, $again]);

    expect(app(CasterVoice::class)->write('scene', ScenePhase::AFTER_RESULT, $facts, 2, 8)['text'] ?? null)->toBe($draft['text']);

    $this->travel(10)->minutes();
    expect(app(CasterVoice::class)->write('scene', ScenePhase::AFTER_RESULT, $facts, 2, 8)['text'] ?? null)->toBe("Wer ist zum ersten Mal hier und will ein kurzes Hallo in den Chat schreiben?\nMit Nostr einloggen und loslegen geht bei uns in wenigen Sekunden.");
});

it('says a tournament name once in a league-voice script, the repeat as the noun', function () {
    $facts = [...casterVoiceQuietFacts(), 'tournaments_open' => [['name' => 'Chess Cup', 'game' => 'Schach', 'start' => 'am Samstag']]];
    VoiceAgent::fake([['text' => "Chess Cup startet am Samstag, und die Anmeldung ist schon offen.\nBei Chess Cup ist noch Platz für alle, die mitspielen wollen.\nSchreibt in den Chat, wer von euch am Samstag mitspielt.", 'canon_id' => null, 'mood' => 'werbung']]);

    expect(app(CasterVoice::class)->write('scene', ScenePhase::PRE_SHOW, $facts, 3, 8)['text'] ?? null)
        ->toBe("Chess Cup startet am Samstag, und die Anmeldung ist schon offen.\nBeim Turnier ist noch Platz für alle, die mitspielen wollen.\nSchreibt in den Chat, wer von euch am Samstag mitspielt.");
});

it('asks for one fact and few names, and shows the flavour lines as examples', function () {
    VoiceAgent::fake([['text' => "Number go up, und das sieht man hier bei uns jeden Tag. [laughter]\nWer von euch ist Team Schach, der schreibt das bitte gleich in den Chat.\nDie Lobby ist offen, und jeder von euch kann jetzt sofort einsteigen.", 'canon_id' => 'btc-number-go-up', 'mood' => 'witz']]);

    casterVoiceWrite();

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Höchstens EIN Fakt') && str_contains($prompt->prompt, 'Keine Spielernamen'));
    expect(app(CasterVoice::class)->fixedPart())->toContain('Ich lieb diesen Sender einfach');
});
