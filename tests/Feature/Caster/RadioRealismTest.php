<?php

use App\Models\CasterScript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\Idents;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\NewsTalk;
use App\Support\Caster\Programme\Roasts;
use App\Support\Caster\Programme\RoastScripts;
use App\Support\Caster\Programme\Rubrics;
use App\Support\Caster\Programme\RubricScripts;
use App\Support\Caster\Programme\ShowCraft;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\Programme\ShowScripts;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\RadioCraft;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio: sounds like real radio (plan "TWENTY ONE Radio", P17)
|--------------------------------------------------------------------------
|
| User 2026-10-12: "dass sie sich wie echte Programme aus dem Radio
| anhören", 2026-10-13: loose comedy radio, not the stiff public broadcaster.
| Every format opens with its ritual and ends with a back-announce that hands
| over only to a slot the clock really plays next; a draft that is too long
| loses sentences before its end, never the handoff; shows, roasts and
| rubrics carry the bed of their format under the talk.
|
*/

beforeEach(function () {
    Storage::fake('local');
    config(['tv.commentary.enabled' => true, 'tv.commentary.programme.enabled' => true, 'tv.commentary.programme.timezone' => 'Europe/Berlin']);
});

/** A slot of the default clock starting at `$berlin` (Berlin wall clock, today). */
function realismSlot(string $berlin): array
{
    return BroadcastClock::fromConfig()->slotAt(CarbonImmutable::parse('today '.$berlin, 'Europe/Berlin')->utc());
}

it('gives every show, roast and rubric format a ritual of its own', function () {
    // The talk show of three (P16) is staged by its own host moves (TalkShowScripts), not by ShowCraft.
    $formats = array_diff([...array_keys(Shows::CATALOGUE), ...array_keys(Roasts::CATALOGUE), ...array_keys(Rubrics::CATALOGUE), 'sport_wetter', 'nachrichten_talk'], [Shows::STAMMTISCH]);

    foreach ($formats as $id) {
        expect(ShowCraft::RITUALS)->toHaveKey($id);
    }

    expect(array_unique(array_column(ShowCraft::RITUALS, 'open')))->toHaveCount(count(ShowCraft::RITUALS));
});

it('hands over to the slot the clock really plays next, by the fixed format when there is one', function () {
    $clock = BroadcastClock::fromConfig();

    expect(ShowCraft::next(realismSlot('18:45'), $clock))->toBe('um neunzehn Uhr die Nachrichten')
        ->and(ShowCraft::next(realismSlot('19:00'), $clock))->toBe('um neunzehn Uhr fünfzehn die erste Rubrik der Stunde');

    // The rubric at 19:15 is fixed already: the handoff names it.
    Cache::put('caster:rubric:plan:'.realismSlot('19:15')['key'], Shows::FRIEDHOF, 3600);
    expect(ShowCraft::next(realismSlot('19:00'), $clock))->toBe('um neunzehn Uhr fünfzehn '.Shows::CATALOGUE[Shows::FRIEDHOF]['name']);

    // Without the clock no slot follows, so no handoff names one.
    config(['tv.commentary.programme.enabled' => false]);
    expect(ShowCraft::next(realismSlot('18:45'), BroadcastClock::fromConfig()))->toBeNull();
});

it('writes the ritual, the comedy rhythm and a back-announce without goodbye into the block, and names no next item it was not given', function () {
    $with = ShowCraft::block(Shows::TRIBUNAL, 'das Chat-Tribunal', ['next' => 'um halb die Kurznachrichten', 'earlier' => ['Erste Pointe.', 'Zweite.', 'Dritte.', 'Vierte.', 'Fünfte.'], 'energy' => 'volle Energie.']);
    $without = ShowCraft::block(Shows::TRIBUNAL, 'das Chat-Tribunal', null);

    expect($with)->toContain(ShowCraft::RITUALS[Shows::TRIBUNAL]['open'], ShowCraft::RITUALS[Shows::TRIBUNAL]['close'])
        ->toContain('übergibt an das, was als Nächstes kommt: um halb die Kurznachrichten')
        ->toContain('kein Tschüss', '„bis gleich“')
        ->toContain('· Vierte.')->not->toContain('· Fünfte.')
        ->toContain('volle Energie.')
        ->and($without)->toContain('ohne etwas Bestimmtes anzukündigen')
        ->not->toContain('als Nächstes kommt:')
        ->not->toContain('Vorhin auf Sendung');

    // A format with its own programme preview (the news talk's Vorschau) gets no back-announce here.
    expect(ShowCraft::block('nachrichten_talk', 'der Nachrichten-Talk', ['next' => 'um neunzehn Uhr fünfzehn X', 'earlier' => [], 'energy' => ''], true, false))
        ->not->toContain('Abmoderation')->not->toContain('um neunzehn Uhr fünfzehn X');
});

it('puts the block into the prompt of every format, with the next slot it was given', function () {
    $context = ['next' => 'um neunzehn Uhr die Nachrichten', 'earlier' => [], 'energy' => 'Anlauf zur vollen Stunde.'];
    $material = ['data' => 'Daten.', 'hash' => 'h', 'keys' => [], 'names' => [], 'sources' => [], 'same_story' => false, 'digits' => [], 'numbers' => [], 'years' => [], 'foreign' => [], 'questions' => []];
    $weather = ['data' => 'Wetter.', 'sport' => false, 'weather' => true, 'digits' => [], 'numbers' => [], 'scores' => [], 'temperatures' => []];

    $prompts = [
        ShowScripts::prompt(Shows::FRIEDHOF, $material, [], $context),
        RoastScripts::prompt(Roasts::SCHWARZBUCH, $material, [], $context),
        RubricScripts::prompt(Rubrics::PRESS, $material, true, [], $context),
        SportWeather::prompt($weather, true, false, [], $context),
    ];

    foreach ($prompts as $prompt) {
        expect($prompt)->toContain('So klingt die Sendung', 'um neunzehn Uhr die Nachrichten', 'Abmoderation')
            // The old ban on any "gleich geht es weiter" is gone: the handoff is the one true announcement.
            ->not->toContain('kein „gleich geht es weiter“');
    }

    expect(NewsTalk::prompt([['key' => 'k', 'section' => 'welt', 'source' => 'n-tv', 'title' => 'Titel', 'news_id' => null, 'summary' => '']], [], [], [], $context))
        ->toContain('So klingt die Sendung', 'um neunzehn Uhr die Nachrichten');
});

it('cuts a draft that is too long before its end, so the back-announce stays', function () {
    $lines = ['eins', 'zwei', 'drei', 'vier', 'fünf', 'Abmoderation eins', 'Abmoderation zwei'];
    $speakers = ['markus', 'sirius', 'markus', 'sirius', 'markus', 'sirius', 'markus'];

    [$kept, $voices] = ShowCraft::cut($lines, $speakers, fn (array $now): bool => count($now) > 4);

    expect($kept)->toBe(['eins', 'zwei', 'Abmoderation eins', 'Abmoderation zwei'])
        ->and($voices)->toBe(['markus', 'sirius', 'sirius', 'markus']);

    // A short draft loses its end, never below the minimum.
    expect(ShowCraft::cut(['a', 'b', 'c'], ['m', 's', 'm'], fn (array $now): bool => true, 2)[0])->toBe(['a', 'b']);
});

it('keeps an initial or a title inside its sentence, so a name is never cut in two on air', function () {
    // P17 eval: the Presseschau went out as "… geht an die US-Ökonomen Robert E." and "Hall und Michael Woodford …".
    expect(ScriptGuard::runs('Der Preis geht an Robert E. Hall und Michael Woodford. Dr. Müller und Prof. Weber lachen. Sankt St. Pauli bleibt.'))
        ->toBe(['Der Preis geht an Robert E. Hall und Michael Woodford.', 'Dr. Müller und Prof. Weber lachen.', 'Sankt St. Pauli bleibt.'])
        ->and(ScriptGuard::runs('Das ist der erste Satz. Und das ist der zweite.'))->toHaveCount(2);
});

it('reads the punchlines voiced within the hour for one callback, newest first and never older', function () {
    $at = CarbonImmutable::parse('2026-10-12 18:45', 'Europe/Berlin')->utc();
    foreach ([[$at->subMinutes(90), "Alt.\nZu alt."], [$at->subMinutes(30), "Anfang.\nVorhin die Pointe."], [$at->subMinutes(10), "Neu.\nDie neueste Pointe."]] as [$created, $text]) {
        $script = CasterScript::query()->create(['kind' => CasterScript::SCENE, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => $text, 'facts_hash' => 'h', 'status' => CasterScript::VOICED, 'estimated_seconds' => 3, 'expires_at' => $created->addHour()]);
        $script->forceFill(['created_at' => $created])->save();
    }

    expect(ShowCraft::earlier($at))->toBe(['Die neueste Pointe.', 'Vorhin die Pointe.']);
});

it('keeps the comedy and satire craft in the cached prefix of every TV text', function () {
    $ids = array_column(RadioCraft::rules(), 'id');

    expect($ids)->toContain('double_act', 'short_turns', 'absurd_escalation', 'deadpan_reversal', 'callback', 'running_gags', 'cold_open', 'format_ritual', 'back_announce', 'loose_not_stiff')
        ->and(app(CasterVoice::class)->fixedPart())->toContain('Doppelpass statt Abwechseln', 'Abmoderieren und übergeben');
});

it('carries the libertarian lens in the shared tone, without a self-label, and the banter in the duets', function () {
    expect(CasterVoice::TONE)->toContain('freier Menschen und freier Märkte', 'nie Anarchokapitalisten oder Libertäre', 'Locker wie Comedy-Radio')
        ->and(DuetScripts::prompt(['markus', 'sirius', 'markus', 'sirius'], 'abend', ['game' => 'Blockli']))->toContain('Comedy-Duo', 'nicht immer mit „Moment mal“')
        ->not->toContain('mindestens drei lange, ganze Sätze');
});

it('lays the bed of its format under a show or roast, twenty-four dB under the voice, and every format bed exists', function () {
    config(['tv.commentary.idents.enabled' => true, 'tv.commentary.idents.bed_at_night' => true]);
    $idents = Idents::fromConfig();
    $script = casterScript(CasterScript::RUBRIC);
    $script->forceFill(['topic' => Roasts::SCHWARZBUCH])->save();
    $clip = app(ClipSpool::class)->store($script, casterOpus(), 10.0, [5.0, 10.0], CarbonImmutable::now());

    expect($idents->bedUnder($clip, CarbonImmutable::now()))->toBe(['file' => resource_path('audio/idents/bed-courtroom.opus'), 'gain_db' => -24.0]);

    foreach ([...array_keys(Shows::CATALOGUE), ...array_keys(Roasts::CATALOGUE), ...array_keys(Rubrics::CATALOGUE), 'sport_wetter', 'nachrichten_talk'] as $id) {
        $file = config("tv.commentary.idents.format_beds.{$id}");
        expect($file)->toBeString("{$id} has no format bed")
            ->and(is_file(resource_path('audio/idents/'.$file)))->toBeTrue("{$file} is missing");
    }
});
