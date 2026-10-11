<?php

use App\Models\CasterFollowedNote;
use App\Models\CasterScript;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\FollowedNotes;
use App\Support\Caster\FollowedScripts;
use App\Support\Caster\NewsFilter;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\Voice\NoteFactCheck;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Support\TestSigner;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\encode;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: the followed-profile segment
|--------------------------------------------------------------------------
|
| User 2026-10-11: "Für mehr Varianz kann er noch auf die Nostr-Posts von
| nprofile1… reagieren, aber pro Post nur einmal." With a fake relay reader
| and laravel/ai fakes, never the network: the nprofile decodes to its
| pubkey and relay hints, replies, link-only, short, explicit and tragic
| notes never reach the air, each note gets at most one segment ever (across
| runs and new instances), one segment per `every_minutes`, the league
| voice's take passes a fact check against the note, and a quote with a
| pattern reaction stands in when it fails.
|
*/

beforeEach(function () {
    $this->author = new TestSigner;
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.news.enabled' => false,
        'tv.commentary.history.enabled' => false,
        'tv.commentary.followed.enabled' => true,
        'tv.commentary.followed_profiles' => [NostrKeys::hexToNpub($this->author->pubkey)],
        'twentyone.stream.relays' => ['wss://stream.test'],
        'twentyone.nostr.npub' => NostrKeys::hexToNpub((new TestSigner)->pubkey),
        'twentyone.nostr.nsec' => null,
        'esports.stream_chat.relays' => ['wss://stream.test'],
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-10-11 20:30:00', 'Europe/Berlin'));

    $this->reader = new class extends RelayReader
    {
        /** @var list<SignedEvent> */
        public array $events = [];

        /** @var list<array{filters: list<array<string, mixed>>, relays: list<string>|null}> */
        public array $asked = [];

        public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
        {
            $this->asked[] = ['filters' => $filters, 'relays' => $relays];

            // Like a relay that ignores `since`: the reader's own checks must hold without it.
            $kinds = array_merge(...array_map(fn (array $filter): array => $filter['kinds'], $filters));

            return array_values(array_filter($this->events, fn (SignedEvent $event): bool => in_array($event->kind, $kinds, true)));
        }
    };
    $this->app->instance(RelayReader::class, $this->reader);
});

/**
 * An nprofile with relay hints, encoded here (TLV 0 = pubkey, 1 = relay).
 *
 * @param  list<string>  $relays
 */
function followedNprofile(string $pubkey, array $relays = []): string
{
    $tlv = chr(0).chr(32).hex2bin($pubkey);
    foreach ($relays as $relay) {
        $tlv .= chr(1).chr(strlen($relay)).$relay;
    }
    $bytes = array_values(unpack('C*', $tlv) ?: []);

    return encode('nprofile', convertBits($bytes, count($bytes), 8, 5, true));
}

/** @param  list<list<string>>  $tags */
function followedNote(TestSigner $author, string $content, int $minutesAgo = 10, array $tags = []): SignedEvent
{
    return SignedEvent::fromInput($author->sign(1, $tags, $content, now()->getTimestamp() - 60 * $minutesAgo));
}

function followedName(TestSigner $author, string $name): SignedEvent
{
    return SignedEvent::fromInput($author->sign(0, [], (string) json_encode(['name' => strtolower($name), 'display_name' => $name]), now()->getTimestamp() - 3600));
}

/**
 * @return array{text: string, canon_id: null, mood: string}
 */
function followedDraft(string $text): array
{
    return ['text' => $text, 'canon_id' => null, 'mood' => 'deadpan'];
}

it('decodes the nprofile Markus named to its pubkey, and one with relay hints beyond bech32\'s 90 characters', function () {
    expect(NostrKeys::profileReference('nprofile1qqs0ys979d5ylpwvs9tx7gyp8p40s8t5yl4gvfgv300xk759qrrkrws3c57u3'))
        ->toBe(['pubkey' => 'f240be2b684f85cc81566f2081386af81d7427ea86250c8bde6b7a8500c761ba', 'relays' => []]);

    $pubkey = $this->author->pubkey;
    $long = followedNprofile($pubkey, ['wss://relay.one.example', 'wss://relay.two.example']);

    expect(strlen($long))->toBeGreaterThan(90)
        ->and(NostrKeys::profileReference('nostr:'.$long))->toBe(['pubkey' => $pubkey, 'relays' => ['wss://relay.one.example', 'wss://relay.two.example']])
        ->and(NostrKeys::profileReference(NostrKeys::hexToNpub($pubkey)))->toBe(['pubkey' => $pubkey, 'relays' => []])
        ->and(NostrKeys::profileReference(strtoupper($pubkey)))->toBe(['pubkey' => $pubkey, 'relays' => []]);
});

it('refuses a broken nprofile, an nsec, a truncated TLV and a second pubkey', function (string $reference) {
    expect(NostrKeys::profileReference($reference))->toBeNull();
})->with([
    'bad checksum' => ['nprofile1qqs0ys979d5ylpwvs9tx7gyp8p40s8t5yl4gvfgv300xk759qrrkrws3c57u4'],
    'nsec' => [(string) encode('nsec', convertBits(array_fill(0, 32, 7), 32, 8, 5, true))],
    'truncated pubkey' => [(string) encode('nprofile', convertBits([0, 32, 1, 2, 3], 5, 8, 5, true))],
    'two pubkeys' => [(string) encode('nprofile', convertBits([0, 32, ...array_fill(0, 32, 1), 0, 32, ...array_fill(0, 32, 2)], 68, 8, 5, true))],
    'garbage' => ['not a key'],
]);

it('reads the followed profiles from the config once per pubkey, hints merged, unreadable ones left out', function () {
    $pubkey = $this->author->pubkey;
    config(['tv.commentary.followed_profiles' => [followedNprofile($pubkey, ['wss://hint.test']), NostrKeys::hexToNpub($pubkey), 'npub1broken']]);

    expect(FollowedNotes::profiles())->toBe([['pubkey' => $pubkey, 'relays' => ['wss://hint.test']]]);
});

it('reads the notes from the hints and the stream relays, stores each once and turns down replies, links, short, explicit and tragic ones', function () {
    config(['tv.commentary.followed_profiles' => [followedNprofile($this->author->pubkey, ['wss://hint.test'])]]);
    $stranger = new TestSigner;
    $this->reader->events = [
        followedName($this->author, 'Markus'),
        followedNote($this->author, 'Dieses Halloween verkleide ich mich als Deutscher https://blossom.example/a.jpg', 30),
        followedNote($this->author, 'wurde 2010 komplett renoviert für viel Geld, sagt der Makler', 20, [['e', str_repeat('a', 64), '', 'root'], ['e', str_repeat('b', 64), '', 'reply']]),
        followedNote($this->author, 'Positional reply ohne Marker, aber lang genug hier', 19, [['e', str_repeat('c', 64)]]),
        followedNote($this->author, 'Es sind noch ein paar Plätze im Turnier frei nostr:nevent1qqspupg3sfjxrgl5p89j03w59v5r2hjctlhpc2pqxtclmdvhn2fpgsg2pczk4', 18, [['e', str_repeat('d', 64), '', 'mention'], ['q', str_repeat('d', 64)]]),
        followedNote($this->author, ' https://blossom.example/b.jpg https://blossom.example/c.mp4', 17),
        followedNote($this->author, 'kempten 🤣', 16),
        followedNote($this->author, 'Heute gibt es hier mal NSFW Inhalte für euch alle', 15),
        followedNote($this->author, 'Spoiler hinter Warnung, aber sonst ganz harmlos hier', 14, [['content-warning', 'spoiler']]),
        followedNote($this->author, 'Drei Tote nach dem Unglück auf der Autobahn heute früh', 13),
        followedNote($this->author, 'Gestern schon geschrieben und erst jetzt gelesen, alter Kram', 25 * 60),
        followedNote($stranger, 'Ein ganz anderer Mensch schreibt hier was Langes dazu', 5),
    ];

    $counts = app(FollowedNotes::class)->fetch(CarbonImmutable::now());

    expect($counts)->toMatchArray(['profiles' => 1, 'stored' => 2, 'filtered' => 8])
        ->and(CasterFollowedNote::query()->whereNull('skipped')->orderBy('note_created_at')->pluck('content')->all())->toBe([
            'Dieses Halloween verkleide ich mich als Deutscher',
            'Es sind noch ein paar Plätze im Turnier frei',
        ])
        ->and(CasterFollowedNote::query()->whereNotNull('skipped')->whereNull('reacted_at')->count())->toBe(0)
        ->and(CasterFollowedNote::query()->where('pubkey', '!=', $this->author->pubkey)->count())->toBe(0)
        ->and(FollowedNotes::displayName($this->author->pubkey))->toBe('Markus');

    $skipped = CasterFollowedNote::query()->whereNotNull('skipped')->pluck('skipped')->countBy()->sortKeys()->all();
    expect($skipped)->toBe(collect([FollowedNotes::REPLY => 2, FollowedNotes::MEDIA => 1, FollowedNotes::SHORT => 1, FollowedNotes::NSFW => 2, NewsFilter::TRAGEDY => 1, FollowedNotes::OLD => 1])->sortKeys()->all());

    $notesRead = collect($this->reader->asked)->first(fn (array $ask): bool => $ask['filters'][0]['kinds'] === [1]);
    expect($notesRead['relays'])->toBe(['wss://hint.test', 'wss://stream.test'])
        ->and($notesRead['filters'][0]['authors'])->toBe([$this->author->pubkey])
        ->and($notesRead['filters'][0]['since'])->toBe(now()->subDay()->getTimestamp());

    // The next run asks only after the newest note stored, and stores nothing twice.
    $this->reader->asked = [];
    expect(app(FollowedNotes::class)->fetch(CarbonImmutable::now())['stored'])->toBe(0)
        ->and(CasterFollowedNote::query()->count())->toBe(10)
        ->and($this->reader->asked[0]['filters'][0]['since'])->toBe(now()->getTimestamp() - 60 * 13 + 1);
});

it('reacts to each note exactly once, across runs, new instances and the note coming back from a relay', function () {
    config(['tv.commentary.ai' => false]);
    $this->reader->events = [followedName($this->author, 'Markus'), followedNote($this->author, 'Dieses Halloween verkleide ich mich als Deutscher', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    foreach (range(1, 4) as $run) {
        $this->travel(15)->minutes();
        // A fresh reader run (the relay still serves the note) and a fresh queue, as after a restart.
        CasterFollowedNote::query()->update(['note_created_at' => now()->subMinutes(5)]);
        app(FollowedNotes::class)->fetch(CarbonImmutable::now());
        $this->app->forgetInstance(ScriptQueue::class);
        expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0);
    }

    expect(CasterScript::query()->where('kind', CasterScript::FOLLOWED)->count())->toBe(1)
        ->and(CasterFollowedNote::query()->count())->toBe(1)
        ->and(CasterFollowedNote::query()->sole()->reacted_at)->not->toBeNull();
});

it('writes at most one followed-profile segment per every_minutes, newest note first', function () {
    // The order alone: the no-repeat memory would drop the second stand-in's attribution (same first words).
    config(['tv.commentary.ai' => false, 'tv.commentary.repeat_hours' => 0]);
    $this->reader->events = [
        followedNote($this->author, 'Erster Gedanke des Tages zum Thema Bitcoin', 40),
        followedNote($this->author, 'Zweiter Gedanke des Tages zum Thema Fiatgeld', 20),
    ];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    $queue = app(ScriptQueue::class);

    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(1);
    $this->travel(9)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(0);
    $this->travel(2)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(1);
    $this->travel(11)->minutes();
    expect($queue->fillSegments(CarbonImmutable::now()))->toBe(0);

    expect(CasterScript::query()->where('kind', CasterScript::FOLLOWED)->orderBy('id')->get()->map(fn (CasterScript $script): string => $script->sentences()[0])->all())->toBe([
        'Auf Nostr schreibt ein Nostr-Kollege gerade Folgendes: Zweiter Gedanke des Tages zum Thema Fiatgeld.',
        'Auf Nostr schreibt ein Nostr-Kollege gerade Folgendes: Erster Gedanke des Tages zum Thema Bitcoin.',
    ]);
});

it('writes the segment: intro with the display name, then the league voice\'s retelling and reaction, after one model call and no fact check', function () {
    $this->reader->events = [followedName($this->author, 'Markus'), followedNote($this->author, 'Dieses Halloween verkleide ich mich als Deutscher', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    $take = "Der Markus will sich an Halloween tatsächlich als Deutscher verkleiden.\nGruseliger geht es eigentlich kaum, und das meine ich ganz ernst.\nFormular ausfüllen und Stempel holen, und schon ist das Kostüm fertig.";
    VoiceAgent::fake([followedDraft($take)]);
    NoteFactCheck::fake();

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->where('kind', CasterScript::FOLLOWED)->sole();
    $note = CasterFollowedNote::query()->sole();
    expect($script->sentences())->toBe([
        str_replace('{name}', 'Markus', FollowedScripts::data()['intros'][$note->id % count(FollowedScripts::data()['intros'])]),
        ...ScriptGuard::lines($take),
    ])
        ->and($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->priority)->toBe(0)
        ->and($script->facts_hash)->toBe('followed:'.$note->id)
        ->and($script->expires_at->equalTo(CarbonImmutable::now()->addMinutes(30)))->toBeTrue()
        ->and($note->reacted_at)->not->toBeNull();
    VoiceAgent::assertPromptedTimes(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "<<<post\nDieses Halloween verkleide ich mich als Deutscher\npost>>>") && str_contains($prompt->prompt, 'SKIP'));
    NoteFactCheck::assertNeverPrompted();
});

it('falls back to a quote and a pattern reaction when the take breaks a house rule twice', function (string $draft, string $reason) {
    $this->reader->events = [followedNote($this->author, 'Dieses Schmuckstück wurde für sehr viel Geld in den Hang betoniert und ist heute für die Hälfte zu haben, typisch Kesselstadt', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    VoiceAgent::fake([followedDraft($draft)]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->sole();
    expect($script->source)->toBe(CasterScript::SOURCE_PATTERN)
        ->and($script->sentences()[0])->toBe('Auf Nostr schreibt ein Nostr-Kollege gerade Folgendes: Dieses Schmuckstück wurde für sehr viel Geld in den Hang betoniert und ist heute für die Hälfte zu haben, typisch…')
        ->and(implode("\n", array_slice($script->sentences(), 1)))->toBeIn(FollowedScripts::data()['reactions'])
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::FOLLOWED_CHANNEL)->where('reason', $reason)->value('count'))->toBe(1);
    VoiceAgent::assertPromptedTimes(1);
})->with([
    'a link (guard)' => ["Da hat einer seine Villa am Hang für die Hälfte verscherbelt.\nSchaut euch das unter https://example.com selbst einmal ganz genau an.\nPech gehabt, kann ich da nur sagen, und weiter geht es.", VoiceGuard::URL],
    'fragments' => ["Tja.\nPech gehabt.\nSo ist das.", ScriptGuard::SENTENCE_SHORT],
]);

it('voices a take with an invented detail: no fact check any more (free mode)', function () {
    $this->reader->events = [followedNote($this->author, 'Ich hab jetzt auch ein paar Sats in den Preispool gelegt.', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    VoiceAgent::fake([followedDraft("Der Markus hat gerade drei Millionen Sats in den Preispool gelegt.\nDas ist großzügig, und zwar richtig großzügig für einen Allgäuer.\nIch hoffe, dass die Spieler das auch zu schätzen wissen.")]);
    NoteFactCheck::fake();

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->sole()->source)->toBe(CasterScript::SOURCE_AI);
    NoteFactCheck::assertNeverPrompted();
});

it('drops the note when the model calls it unfit, and a foreign note when only the stand-in is left', function () {
    $this->reader->events = [
        followedNote($this->author, 'Ganz komische Sache heute, ich sag lieber nichts', 30),
        followedNote($this->author, 'The Cybercab floodgates are officially open in Texas and that is wild', 20),
    ];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    VoiceAgent::fake([followedDraft('SKIP')]);
    NoteFactCheck::fake();

    // The newest first: the English one, the model says SKIP.
    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0)
        ->and(CasterFollowedNote::query()->where('content', 'like', 'The Cybercab%')->value('skipped'))->toBe('model');
    NoteFactCheck::assertNeverPrompted();

    // With the voice off, a foreign note gets no stand-in.
    config(['tv.commentary.ai' => false]);
    CasterFollowedNote::query()->where('content', 'like', 'Ganz%')->update(['content' => 'Das ist ein ganz langer englischer Text und so weiter', 'reacted_at' => null, 'skipped' => null]);
    CasterFollowedNote::query()->where('content', 'like', 'Das ist%')->update(['content' => 'The weather is great and the bitcoin price is going up']);
    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0)
        ->and(CasterFollowedNote::query()->where('content', 'like', 'The weather%')->value('skipped'))->toBe(NewsFilter::FOREIGN)
        ->and(CasterScript::query()->count())->toBe(0);
});

it('asks the voice for a German retelling and no quote when the note is not German', function () {
    $prompt = CasterVoice::followedPrompt('The Cybercab floodgates are open', 'Markus', false, 3, 7);

    expect($prompt)->toContain('sinngemäß auf Deutsch')->not->toContain('wörtliches Zitat aus dem Post (höchstens zwanzig Wörter) ist erlaubt')
        ->and(CasterVoice::followedPrompt('Hallo Welt', 'Markus', true, 3, 7))->toContain('höchstens zwanzig Wörter');
});

it('never speaks a note older than fresh_hours, of a profile no longer followed, or while the switch is off', function () {
    config(['tv.commentary.ai' => false]);
    $this->reader->events = [followedNote($this->author, 'Dieses Halloween verkleide ich mich als Deutscher', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());

    config(['tv.commentary.followed.enabled' => false]);
    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0);

    config(['tv.commentary.followed.enabled' => true, 'tv.commentary.followed_profiles' => []]);
    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0);

    config(['tv.commentary.followed_profiles' => [NostrKeys::hexToNpub($this->author->pubkey)]]);
    $this->travel(25)->hours();
    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(0)
        ->and(CasterFollowedNote::query()->sole()->reacted_at)->toBeNull();
});

it('plays the segment behind chat reactions: normal priority, a calm-slot segment, no airtime ahead', function () {
    config(['tv.commentary.ai' => false]);
    $this->reader->events = [followedNote($this->author, 'Dieses Halloween verkleide ich mich als Deutscher', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    $queue = app(ScriptQueue::class);
    $queue->fillSegments(CarbonImmutable::now());
    expect($queue->airtimeAhead())->toBe(0.0);
    $queue->enqueue(CasterScript::CHAT, 'break', 'Hallo Chat.', 'chat', (int) config('tv.commentary.scripts.chat_priority', 50), CarbonImmutable::now());

    expect($queue->claim(CarbonImmutable::now())?->kind)->toBe(CasterScript::CHAT)
        ->and($queue->claim(CarbonImmutable::now())?->kind)->toBe(CasterScript::FOLLOWED)
        ->and(CasterScript::SEGMENTS)->toContain(CasterScript::FOLLOWED);
});

it('quotes at most twenty words: up to the last full sentence within them, else with an ellipsis', function (string $text, string $quote) {
    expect(FollowedScripts::quote($text, 20))->toBe($quote);
})->with([
    'short' => ['Kurz und knapp gesagt hier', 'Kurz und knapp gesagt hier.'],
    'sentence end inside' => ['Eins zwei drei vier fünf. Sechs sieben acht neun zehn elf zwölf dreizehn vierzehn fünfzehn sechzehn siebzehn achtzehn neunzehn zwanzig einundzwanzig', 'Eins zwei drei vier fünf.'],
    'no sentence end' => [implode(' ', array_fill(0, 25, 'Wort')), implode(' ', array_fill(0, 20, 'Wort')).'…'],
]);

it('keeps the pattern lines free of numbers and within the spoken limits, enough reactions to rotate', function () {
    $data = FollowedScripts::data();

    expect(count($data['reactions']))->toBeGreaterThanOrEqual(15)
        ->and($data['reactions'][0])->toBe('Na, das lass ich jetzt einfach mal so stehen, weil es eigentlich für sich selbst spricht.');

    foreach ([...$data['reactions'], ...$data['self_intros'], ...array_map(fn (string $intro): string => str_replace('{name}', FollowedNotes::FALLBACK_NAME, $intro), $data['intros'])] as $line) {
        expect(preg_match('/\d/', $line))->toBe(0)
            ->and((new ScriptGuard)->check($line, 1, 3, 32))->toBe([]);
    }
});

it('prunes notes past keep_days and keeps at most max_rows', function () {
    config(['tv.commentary.followed.keep_days' => 7, 'tv.commentary.followed.max_rows' => 2]);
    foreach ([8 * 24 * 60, 30, 20, 10] as $i => $minutesAgo) {
        CasterFollowedNote::query()->create(['event_id' => str_repeat((string) $i, 64), 'pubkey' => $this->author->pubkey, 'note_created_at' => now()->subMinutes($minutesAgo), 'content' => "Notiz {$i}"]);
    }

    expect(app(FollowedNotes::class)->prune(CarbonImmutable::now()))->toBe(2)
        ->and(CasterFollowedNote::query()->orderBy('note_created_at')->pluck('content')->all())->toBe(['Notiz 2', 'Notiz 3']);
});

it('does nothing when the segment is off', function () {
    config(['tv.commentary.followed.enabled' => false]);

    $this->artisan('caster:followed')->expectsOutputToContain('is off')->assertSuccessful();
    expect($this->reader->asked)->toBe([]);
});

it('speaks of Markus\' own profile in the first person: the intro, the prompt and the stand-in', function () {
    $this->reader->events = [followedName($this->author, 'markusturm'), followedNote($this->author, 'Ben nimmt einfach meine Stimme und lässt alles kommentieren im Livestream.', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    $take = "Ich hab auf Nostr geschrieben, dass Ben meine Stimme nimmt.\nDer Markus Turm im Dauerbetrieb, wer hätte das gedacht.\nHauptsache, ich muss nicht mehr wach bleiben.";
    VoiceAgent::fake([followedDraft($take)]);
    expect(FollowedScripts::isSelf('markusturm'))->toBeTrue()
        ->and(FollowedScripts::isSelf('Markus'))->toBeFalse()
        ->and(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->sole();
    expect($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->sentences()[0])->toBeIn(FollowedScripts::data()['self_intros']);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'deinen eigenen Nostr-Post') && str_contains($prompt->prompt, 'Ich-Form'));

    // The stand-in reads "Auf Nostr hab ich vor Kurzem Folgendes geschrieben: …".
    config(['tv.commentary.ai' => false]);
    $this->travel(11)->minutes();
    $this->reader->events[] = followedNote($this->author, 'Dieses Halloween verkleide ich mich als Deutscher', 1);
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1)
        ->and(CasterScript::query()->orderByDesc('id')->first()->sentences()[0])->toBe('Auf Nostr hab ich vor Kurzem Folgendes geschrieben: Dieses Halloween verkleide ich mich als Deutscher.');
});
