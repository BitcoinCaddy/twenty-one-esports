<?php

use App\Support\Caster\CasterPersonas;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ChatWishes;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\RadioCraft;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\WorldMemory;
use App\Support\Voice\NewsFactCheck;
use App\Support\Voice\NoteFactCheck;
use App\Support\Voice\VoiceAgent;
use Laravel\Ai\Prompts\AgentPrompt;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: radio craft in every TV prompt (plan "TWENTY ONE Radio", P3)
|--------------------------------------------------------------------------
|
| resources/data/voice/radio-craft.json holds the presenting and news rules
| with the pages they come from; RadioCraft::block() puts them into the
| cached system prompt of every TV text and the duets. With the laravel/ai
| fakes, never the network.
|
*/

beforeEach(function () {
    config(['tv.commentary.enabled' => true, 'tv.commentary.scripts.materials' => ['league' => 1], 'esports.voice.monthly_budget_usd' => 5]);
});

it('keeps at least twelve radio rules, each with its source pages, a verbatim evidence line and a spoken example', function () {
    $rules = RadioCraft::rules();

    expect(count($rules))->toBeGreaterThanOrEqual(12)
        ->and(array_unique(array_column($rules, 'id')))->toHaveCount(count($rules));

    foreach ($rules as $rule) {
        expect(trim($rule['title']))->not->toBe('', "{$rule['id']} has no title")
            ->and(mb_strlen($rule['rule']))->toBeGreaterThan(40, "{$rule['id']} has no rule text")
            ->and(trim($rule['evidence']))->not->toBe('', "{$rule['id']} has no evidence")
            ->and($rule['sources'])->not->toBeEmpty("{$rule['id']} has no source")
            // The prompt drops every line with a "#" (CasterVoice::withoutHashes()): no rule may carry one.
            ->and($rule['title'].$rule['rule'])->not->toContain('#');

        foreach ($rule['sources'] as $source) {
            expect($source['url'])->toStartWith('https://')
                ->and(trim($source['title']))->not->toBe('')
                ->and($source['read'])->toBe('2026-10-12');
        }

        // The example is a spoken sentence by the house rules (CasterVoice's guide): 7 to 32 words, at most 2 commas.
        $words = count(preg_split('/\s+/u', trim($rule['example'])) ?: []);
        expect($words)->toBeBetween(7, 32, "{$rule['id']} example has {$words} words")
            ->and(substr_count($rule['example'], ','))->toBeLessThanOrEqual(2, "{$rule['id']} example has more than 2 commas");
    }

    expect(array_column($rules, 'id'))->toContain('teaser', 'back_reference', 'time_orientation', 'address_listener', 'one_thought', 'transitions', 'station_ident', 'whats_in_it', 'news_vs_opinion', 'spoken_numbers', 'no_filler', 'vary_openings');
});

it('puts every radio rule into the cached prefix of the TV voice and the duets, byte-identical on every call, without the examples', function () {
    $fixed = app(CasterVoice::class)->fixedPart();
    $block = RadioCraft::block();

    expect($fixed)->toContain($block)
        ->and(app(CasterVoice::class)->fixedPart())->toBe($fixed)
        ->and(app(DuetScripts::class)->systemPrompt())->toStartWith($fixed)
        // Under the league facts and the house rules, in front of Markus' persona.
        ->and(strpos($fixed, $block))->toBeGreaterThan(strpos($fixed, 'Die Liga in Fakten'))
        ->and(strpos($fixed, $block))->toBeLessThan(strpos($fixed, CasterPersonas::markus()))
        ->and($block)->not->toContain('#');

    foreach (RadioCraft::rules() as $rule) {
        expect($block)->toContain("- {$rule['title']}: {$rule['rule']}")
            ->and($fixed)->not->toContain($rule['example']);
    }
});

it('sends the radio craft with every kind of TV text the voice writes', function () {
    NewsFactCheck::fake();
    NoteFactCheck::fake();
    $script = "Hier ist TWENTY ONE TV, und ihr hört gerade die Liga der EINUNDZWANZIG-Community rund um die Uhr.\nGleich schauen wir gemeinsam darauf, was in der Lobby als Nächstes passiert.";
    VoiceAgent::fake(array_fill(0, 40, ['text' => $script, 'canon_id' => null, 'mood' => 'call']));
    $voice = app(CasterVoice::class);

    $voice->write('scene', ScenePhase::PRE_SHOW, ['sender' => 'TWENTY ONE TV', 'players' => 34], 2, 8);
    $voice->write('live', ScenePhase::BREAK, ['sender' => 'TWENTY ONE TV', 'players' => 34], 1, 3);
    $voice->news('Bundesregierung plant neue Zuckersteuer', 'tagesschau', 'germany');
    $voice->followedNote('Heute gibt es Kaffee aus dem Allgäu und dazu eine Runde Schach', 'Ben');
    $voice->chatReply('Hallo aus dem Allgäu, schöne Grüße an alle', 'Ada');
    $voice->callback([['key' => 'news:1', 'at' => now()->subHours(2)->getTimestamp(), 'kind' => WorldMemory::NEWS, 'line' => 'Schlagzeile von tagesschau lief bei dir']]);
    $voice->history(['id' => 'e1', 'title' => 'Der Anfang', 'facts' => [['text' => 'Im Jahr 1525 erhoben sich die Bauern.', 'source' => 'Wikipedia']], 'twists' => []]);
    $voice->riff(ChatWishes::JOKE, [['wording' => 'Stay humble, stack sats', 'meaning' => 'Bescheiden bleiben und sparen']]);
    app(DuetScripts::class)->write([DuetDirector::MARKUS, DuetDirector::SIRIUS], ScenePhase::BREAK, ['players' => 21]);

    $block = RadioCraft::block();
    $kinds = [
        'scene' => 'Szenen-Skript',
        'live' => 'Live-Einwurf',
        'news' => 'Schlagzeile: Bundesregierung plant neue Zuckersteuer',
        'followed' => 'Reaktion auf einen Nostr-Post von Ben',
        'chat' => 'Live-Antwort auf eine Nachricht im Stream-Chat',
        'callback' => 'Rückblick-Spitze',
        'history' => 'Markus erzählt vom Bauernkrieg',
        'riff' => 'Anregung aus dem Kanon',
        'duet' => 'Ein Wortwechsel auf TWENTY ONE TV',
    ];
    foreach ($kinds as $kind => $marker) {
        VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, $marker) && str_contains($prompt->agent->instructions(), $block));
    }

    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->agent->instructions(), $block));
});
