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
use Illuminate\Support\Facades\Cache;
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

        public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
        {
            $this->asked[] = ['filters' => $filters, 'relays' => $relays];

            // Like a relay that ignores `since` and `until`: the reader's own checks must hold without them.
            $kinds = array_merge(...array_map(fn (array $filter): array => $filter['kinds'], $filters));

            return ['events' => array_values(array_filter($this->events, fn (SignedEvent $event): bool => in_array($event->kind, $kinds, true))), 'answered' => 1];
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
    config(['tv.commentary.followed_profiles' => [followedNprofile($this->author->pubkey, ['wss://hint.test'])], 'tv.commentary.followed.fresh_hours' => 24]);
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
        // A followed note does not expire (user 2026-10-18: only the news, Sport und Wetter are bound to their time).
        ->and($script->expires_at->greaterThan(CarbonImmutable::now()->addYears(9)))->toBeTrue()
        ->and($note->reacted_at)->not->toBeNull();
    VoiceAgent::assertPromptedTimes(1);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "<<<post\nDieses Halloween verkleide ich mich als Deutscher\npost>>>") && str_contains($prompt->prompt, 'SKIP'));
    NoteFactCheck::assertNeverPrompted();
});

it('airs a take that breaks a house rule without the sentence that breaks it, and counts the rule', function (string $draft, string $reason) {
    $this->reader->events = [followedNote($this->author, 'Dieses Schmuckstück wurde für sehr viel Geld in den Hang betoniert und ist heute für die Hälfte zu haben, typisch Kesselstadt', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    VoiceAgent::fake([followedDraft($draft)]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    // The take airs all the same (user 2026-10-18: "Nie mehr verwerfen"); the link never does.
    $script = CasterScript::query()->sole();
    expect($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->text)->not->toContain('example.com')
        ->and($script->text)->toContain('Pech gehabt, kann ich da nur sagen')
        ->and(DB::table('voice_rejections')->where('channel', CasterVoice::FOLLOWED_CHANNEL)->where('reason', $reason)->value('count'))->toBe(1);
    VoiceAgent::assertPromptedTimes(1);
})->with([
    'a link (guard)' => ["Da hat einer seine Villa am Hang für die Hälfte verscherbelt.\nSchaut euch das unter https://example.com selbst einmal ganz genau an.\nPech gehabt, kann ich da nur sagen, und weiter geht es.", VoiceGuard::URL],
]);

it('voices the fragment take as it comes: no style verdict refuses it any more (user 2026-10-15)', function () {
    $this->reader->events = [followedNote($this->author, 'Dieses Schmuckstück wurde für sehr viel Geld in den Hang betoniert und ist heute für die Hälfte zu haben, typisch Kesselstadt', 30)];
    app(FollowedNotes::class)->fetch(CarbonImmutable::now());
    VoiceAgent::fake([followedDraft("Tja.\nPech gehabt.\nSo ist das.")]);

    expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

    $script = CasterScript::query()->sole();
    expect($script->source)->toBe(CasterScript::SOURCE_AI)
        ->and($script->text)->toContain("Tja.\nPech gehabt.\nSo ist das.");
    VoiceAgent::assertPromptedTimes(1);
});

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
        ->and(CasterVoice::followedPrompt('Hallo Welt', 'Markus', true, 3, 7))->toContain('Kein wörtliches Zitat');
});

it('never speaks a note older than fresh_hours (when set), of a profile no longer followed, or while the switch is off', function () {
    config(['tv.commentary.ai' => false, 'tv.commentary.followed.fresh_hours' => 24]);
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

it('prunes notes past keep_days and keeps at most max_rows while fresh_hours is set, and never without it', function () {
    config(['tv.commentary.followed.fresh_hours' => 0, 'tv.commentary.followed.keep_days' => 7, 'tv.commentary.followed.max_rows' => 2]);
    foreach ([8 * 24 * 60, 30, 20, 10] as $i => $minutesAgo) {
        CasterFollowedNote::query()->create(['event_id' => str_repeat((string) $i, 64), 'pubkey' => $this->author->pubkey, 'note_created_at' => now()->subMinutes($minutesAgo), 'content' => "Notiz {$i}"]);
    }

    // The whole history: a deleted row would be read back by the backfill and spoken twice.
    expect(app(FollowedNotes::class)->prune(CarbonImmutable::now()))->toBe(0)
        ->and(CasterFollowedNote::query()->count())->toBe(4);

    config(['tv.commentary.followed.fresh_hours' => 24]);
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

/*
| The whole history (user 2026-10-12: "Die Kommentare für die Nostr-Posts des
| npubs dürfen bis an den Anfang aller je geposteten kind-1-Events gehen.
| Also ALL! … aber sortiert nach Zeit."): paged back with `until`, bounded
| per run, spoken oldest first.
*/

/**
 * A relay reader that honours `authors`, `since`, `until` (inclusive) and
 * `limit` per relay, newest first, like a real relay; relays in `$down` do
 * not answer.
 */
function followedPagingReader(): RelayReader
{
    return new class extends RelayReader
    {
        /** @var array<string, list<SignedEvent>> */
        public array $relays = [];

        /** @var list<string> */
        public array $down = [];

        /** @var list<array<string, mixed>> */
        public array $asked = [];

        public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
        {
            $collected = [];
            $answered = 0;

            foreach ($relays ?? [] as $relay) {
                if (in_array($relay, $this->down, true)) {
                    continue;
                }

                $answered++;

                foreach ($filters as $filter) {
                    $this->asked[] = $filter;
                    $matching = array_values(array_filter($this->relays[$relay] ?? [], fn (SignedEvent $event): bool => in_array($event->kind, $filter['kinds'], true)
                        && in_array($event->pubkey, $filter['authors'] ?? [$event->pubkey], true)
                        && $event->createdAt >= ($filter['since'] ?? 0) && $event->createdAt <= ($filter['until'] ?? PHP_INT_MAX)));
                    usort($matching, fn (SignedEvent $a, SignedEvent $b): int => $b->createdAt <=> $a->createdAt);

                    foreach (array_slice($matching, 0, $filter['limit'] ?? 500) as $event) {
                        if (! isset($known[$event->id])) {
                            $collected[$event->id] = $event;
                        }
                    }
                }
            }

            $collected = array_values($collected);
            usort($collected, fn (SignedEvent $a, SignedEvent $b): int => $b->createdAt <=> $a->createdAt);

            return ['events' => array_slice($collected, 0, $perAuthor), 'answered' => $answered];
        }
    };
}

function followedNoteAt(TestSigner $author, string $content, int $timestamp): SignedEvent
{
    return SignedEvent::fromInput($author->sign(1, [], $content, $timestamp));
}

/**
 * `$count` notes, one an hour back from `$newest`, every fifth sharing its
 * second with the one before (a page may end inside such a second); the even
 * ones on relay A, the odd ones on relay B, every third on both.
 *
 * @return list<SignedEvent>
 */
function followedHistory(RelayReader $reader, TestSigner $author, int $count, int $newest): array
{
    $notes = [];
    foreach (range(0, $count - 1) as $i) {
        $at = $newest - 3600 * ($i - intdiv($i, 5));
        $notes[] = $note = followedNoteAt($author, "Gedanke Nummer {$i} über Bitcoin und die Freiheit", $at);
        if ($i % 2 === 0 || $i % 3 === 0) {
            $reader->relays['wss://a.test'][] = $note;
        }
        if ($i % 2 === 1 || $i % 3 === 0) {
            $reader->relays['wss://b.test'][] = $note;
        }
    }

    return $notes;
}

describe('the whole history', function () {
    beforeEach(function () {
        $this->paging = followedPagingReader();
        $this->app->instance(RelayReader::class, $this->paging);
        Cache::put('caster:followed:name:'.$this->author->pubkey, 'markusturm', 3600);
        config([
            'tv.commentary.followed_profiles' => [followedNprofile($this->author->pubkey, ['wss://a.test', 'wss://b.test'])],
            'twentyone.stream.relays' => [],
            'esports.stream_chat.relays' => [],
            'tv.commentary.followed.fresh_hours' => 0,
            'tv.commentary.followed.page_limit' => 40,
            'tv.commentary.followed.per_run' => 1000,
        ]);
    });

    it('pages back through both relays until nothing older comes, each note stored once', function () {
        $notes = followedHistory($this->paging, $this->author, 130, now()->subDays(3)->getTimestamp());

        $counts = app(FollowedNotes::class)->fetch(CarbonImmutable::now());

        expect($counts)->toMatchArray(['read' => 130, 'stored' => 130, 'filtered' => 0, 'exhausted' => 1])
            ->and(CasterFollowedNote::query()->pluck('event_id')->sort()->values()->all())->toBe(collect($notes)->pluck('id')->sort()->values()->all())
            ->and(collect($this->paging->asked)->every(fn (array $filter): bool => $filter['limit'] === 40 && ! isset($filter['since'])))->toBeTrue()
            ->and(collect($this->paging->asked)->pluck('until')->filter()->unique()->count())->toBeGreaterThanOrEqual(3);

        // Read to the first note: the next run asks only for what is new, and stores nothing twice.
        $this->paging->asked = [];
        $this->paging->relays['wss://b.test'][] = followedNoteAt($this->author, 'Ganz frisch aus der Druckerpresse geschrieben heute', now()->getTimestamp() - 60);
        expect(app(FollowedNotes::class)->fetch(CarbonImmutable::now()))->toMatchArray(['stored' => 1, 'exhausted' => 0])
            ->and(CasterFollowedNote::query()->count())->toBe(131)
            ->and(collect($this->paging->asked)->every(fn (array $filter): bool => isset($filter['since'])))->toBeTrue();
    });

    it('reads at most per_run notes a run and resumes at the oldest one stored, until the first note', function () {
        config(['tv.commentary.followed.per_run' => 50]);
        followedHistory($this->paging, $this->author, 130, now()->subDays(3)->getTimestamp());

        $runs = [];
        foreach (range(1, 4) as $run) {
            $counts = app(FollowedNotes::class)->fetch(CarbonImmutable::now());
            $runs[] = [$counts['stored'], $counts['exhausted'], CasterFollowedNote::query()->count()];
        }

        // 50 read a run: the tail starts AT the oldest note stored (`until` is inclusive), so it reads that one again
        // (known, not stored twice): 50 + 49 + 31 new notes, and the third run reaches the first note.
        expect($runs)->toBe([[50, 0, 50], [49, 0, 99], [31, 1, 130], [0, 0, 130]])
            ->and(CasterFollowedNote::query()->distinct()->count('event_id'))->toBe(130);
    });

    it('never calls the history read to the end when no relay answered, and goes on next run', function () {
        followedHistory($this->paging, $this->author, 60, now()->subDays(3)->getTimestamp());
        $this->paging->down = ['wss://a.test', 'wss://b.test'];

        expect(app(FollowedNotes::class)->fetch(CarbonImmutable::now()))->toMatchArray(['stored' => 0, 'exhausted' => 0]);

        $this->paging->down = [];
        expect(app(FollowedNotes::class)->fetch(CarbonImmutable::now()))->toMatchArray(['stored' => 60, 'exhausted' => 1]);
    });

    it('stops a run at run_seconds between pages, whatever is left', function () {
        config(['tv.commentary.followed.run_seconds' => 0.05]);
        $slow = new class extends RelayReader
        {
            public int $calls = 0;

            /** @var list<float|null> */
            public array $cutOff = [];

            public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
            {
                $this->calls++;
                $this->cutOff[] = $until;
                usleep(60_000);

                return ['events' => [SignedEvent::fromInput((new TestSigner)->sign(1, [], 'irrelevant', now()->getTimestamp() - $this->calls))], 'answered' => 1];
            }
        };
        $this->app->instance(RelayReader::class, $slow);

        app(FollowedNotes::class)->fetch(CarbonImmutable::now());

        // Between pages, never inside one: a page cut after some relays would move the cursor past the others' notes.
        expect($slow->calls)->toBe(1)
            ->and($slow->cutOff)->toBe([null]);
    });

    it('speaks the backlog oldest first from the very first note, a note under an hour old ahead, each once, the old ones with month and year in words', function () {
        config(['tv.commentary.ai' => false, 'tv.commentary.repeat_hours' => 0]);
        $this->paging->relays['wss://a.test'] = [
            followedNoteAt($this->author, 'Mein allererster Post auf Nostr, hallo zusammen', CarbonImmutable::parse('2023-03-15 12:00', 'Europe/Berlin')->getTimestamp()),
            followedNoteAt($this->author, 'Sommer auf dem Berg und Bitcoin im Kopf', CarbonImmutable::parse('2024-07-01 09:00', 'Europe/Berlin')->getTimestamp()),
            followedNoteAt($this->author, 'Vorgestern ging es um den Stream und die Liga', now()->subDays(2)->subHour()->getTimestamp()),
            followedNoteAt($this->author, 'Gestern Abend noch ein Gedanke zu Nostr gehabt', now()->subHours(20)->getTimestamp()),
        ];
        app(FollowedNotes::class)->fetch(CarbonImmutable::now());
        expect(CasterFollowedNote::query()->whereNotNull('skipped')->count())->toBe(0);

        $queue = app(ScriptQueue::class);
        $spoken = [];
        $fill = function () use ($queue, &$spoken): void {
            if ($queue->fillSegments(CarbonImmutable::now()) === 1) {
                $spoken[] = CasterScript::query()->where('kind', CasterScript::FOLLOWED)->orderByDesc('id')->first()->sentences()[0];
            }
            $this->travel(11)->minutes();
        };

        $fill();
        // A brand-new note jumps the queue, once.
        $this->paging->relays['wss://a.test'][] = followedNoteAt($this->author, 'Gerade eben geschrieben, frischer geht es nicht mehr', now()->subMinutes(5)->getTimestamp());
        app(FollowedNotes::class)->fetch(CarbonImmutable::now());
        foreach (range(1, 6) as $round) {
            $fill();
        }

        expect($spoken)->toBe([
            'Im März zweitausenddreiundzwanzig hab ich auf Nostr Folgendes geschrieben: Mein allererster Post auf Nostr, hallo zusammen.',
            'Auf Nostr hab ich vor Kurzem Folgendes geschrieben: Gerade eben geschrieben, frischer geht es nicht mehr.',
            'Im Juli zweitausendvierundzwanzig hab ich auf Nostr Folgendes geschrieben: Sommer auf dem Berg und Bitcoin im Kopf.',
            'Im Oktober zweitausendsechsundzwanzig hab ich auf Nostr Folgendes geschrieben: Vorgestern ging es um den Stream und die Liga.',
            'Auf Nostr hab ich vor Kurzem Folgendes geschrieben: Gestern Abend noch ein Gedanke zu Nostr gehabt.',
        ])
            ->and(CasterScript::query()->where('kind', CasterScript::FOLLOWED)->distinct()->count('facts_hash'))->toBe(5)
            ->and(CasterFollowedNote::query()->whereNull('reacted_at')->count())->toBe(0);
    });

    it('puts the month and year in the intro of the voice\'s take, and tells the voice it is a look back without a date of its own', function () {
        $this->paging->relays['wss://a.test'] = [followedNoteAt($this->author, 'Mein allererster Post auf Nostr, hallo zusammen', CarbonImmutable::parse('2023-03-15 12:00', 'Europe/Berlin')->getTimestamp())];
        app(FollowedNotes::class)->fetch(CarbonImmutable::now());
        $take = "Ich hab damals meinen allerersten Post auf Nostr abgesetzt, ganz schüchtern.\nHallo zusammen hab ich geschrieben, und zwar ganz brav und anständig.\nSo fängt eben jede große Geschichte an, auch meine eigene.";
        VoiceAgent::fake([followedDraft($take)]);
        NoteFactCheck::fake([['claims' => []]]);

        expect(app(ScriptQueue::class)->fillSegments(CarbonImmutable::now()))->toBe(1);

        $first = CasterScript::query()->sole()->sentences()[0];
        expect(CasterScript::query()->sole()->source)->toBe(CasterScript::SOURCE_AI)
            ->and($first)->toStartWith('Im März zweitausenddreiundzwanzig ')
            ->and(str_replace('Im März zweitausenddreiundzwanzig', '{when}', $first))->toBeIn(FollowedScripts::data()['self_dated_intros']);
        VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Rückblick') && str_contains($prompt->prompt, 'nenn kein Datum') && ! str_contains($prompt->prompt, 'zweitausend'));
    });

    it('keeps the dated intros free of digits and within the spoken limits, for the longest month and year', function () {
        $data = FollowedScripts::data();
        $when = 'Im September zweitausendsiebenundsiebzig';

        foreach ([...$data['dated_intros'], ...$data['self_dated_intros'], $data['dated_attribution'].' Kurz.', $data['self_dated_attribution'].' Kurz.'] as $line) {
            $line = str_replace(['{when}', '{name}'], [$when, FollowedNotes::FALLBACK_NAME], $line);
            expect(preg_match('/\d/', $line))->toBe(0)
                ->and($line)->toStartWith($when)
                ->and((new ScriptGuard)->check($line, 1, 3, 32, [], 0))->toBe([], $line);
        }
    });
});
