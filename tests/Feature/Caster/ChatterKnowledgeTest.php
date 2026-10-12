<?php

use App\Models\ChatterNote;
use App\Models\ChatterProfile;
use App\Models\User;
use App\Models\WotLevel;
use App\Support\Caster\CasterVoice;
use App\Support\Caster\ChatReactions;
use App\Support\Caster\ChatterCache;
use App\Support\Caster\PersonaCards;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: what Markus knows about a trusted chatter
|--------------------------------------------------------------------------
|
| User 2026-10-12: "dass er kontextual witzige Sachen aus dem Wissen des
| Chatter-Profils und letzten Posts des Chatters einbeziehen kann". The
| refresh reads kind 0 and the newest own notes from the knowledge relays
| (esports.relays when none answers), the persona card drops what it may not
| carry, and a trusted reply prompt carries the card and the newest three
| notes as data. Strangers get none of it, and no gamer tag reaches a prompt.
|
*/

beforeEach(function () {
    $this->streamKey = new TestSigner;
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.chat.reactions' => true,
        'tv.commentary.chat.profiles' => true,
        'tv.commentary.chat.knowledge_relays' => ['wss://knowledge.test'],
        'esports.relays' => ['wss://league.test'],
        'esports.stream_bot.chat_relays' => ['wss://chat.test'],
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->streamKey->pubkey),
        'twentyone.nostr.nsec' => null,
        'twentyone.stream.relays' => ['wss://one.test'],
    ]);

    // Relays by URL: events for a relay that answers, null for one that fails.
    $this->reader = new class extends RelayReader
    {
        /** @var array<string, list<SignedEvent>|null> */
        public array $relays = [];

        /** @var list<SignedEvent> the stream chat */
        public array $chat = [];

        /** @var list<list<string>> */
        public array $asked = [];

        public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
        {
            return $this->chat;
        }

        public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
        {
            $relays ??= config('esports.relays', []);
            $this->asked[] = $relays;
            $events = [];
            $answered = 0;
            foreach ($relays as $relay) {
                if (($this->relays[$relay] ?? null) !== null) {
                    $answered++;
                    $events = [...$events, ...$this->relays[$relay]];
                }
            }
            usort($events, fn (SignedEvent $a, SignedEvent $b): int => $b->createdAt <=> $a->createdAt);

            return ['events' => $events, 'answered' => $answered];
        }
    };
    $this->app->instance(RelayReader::class, $this->reader);
});

function knowledgeEvent(TestSigner $author, int $kind, string $content, int $ago, array $tags = []): SignedEvent
{
    return SignedEvent::fromInput($author->sign($kind, $tags, $content, now()->getTimestamp() - $ago));
}

it('reads kind 0 and own notes from esports.relays when no knowledge relay answers, and never a reply or the lightning address', function () {
    $chatter = new TestSigner;
    $kind0 = knowledgeEvent($chatter, 0, json_encode(['display_name' => 'Ben', 'about' => 'Souveränes Leben und Cypherpunk', 'nip05' => 'ben@Einundzwanzig.space',
        'website' => 'https://www.ben.example/blog?x=1', 'lud16' => 'ben@wallet.example']), 3_600);
    // The name was read before from the same kind 0 (prod 2026-10-12): the about must still come in.
    $profile = ChatterProfile::query()->create(['pubkey' => $chatter->pubkey, 'name' => 'Ben', 'profile_created_at' => $kind0->createdAt]);
    $reply = knowledgeEvent($chatter, 1, 'eine antwort an jemanden', 60, [['e', str_repeat('a', 64), '', 'reply']]);
    ChatterNote::query()->create(['pubkey' => $chatter->pubkey, 'event_id' => $reply->id, 'note_created_at' => $reply->createdAt, 'content' => 'eine antwort an jemanden']);
    $this->reader->relays = ['wss://knowledge.test' => null, 'wss://league.test' => [
        $kind0, $reply,
        knowledgeEvent($chatter, 1, 'Rocket League Cup gewonnen', 120),
        knowledgeEvent($chatter, 1, 'mit Zitat einer Notiz', 180, [['e', str_repeat('b', 64), '', 'mention']]),
        knowledgeEvent($chatter, 1, 'alte Notiz', 240),
    ]];
    config(['tv.commentary.chat.knowledge_notes' => 2]);

    app(ChatterCache::class)->refresh($profile, CarbonImmutable::now());
    $profile->refresh();

    expect($this->reader->asked)->toBe([['wss://knowledge.test'], ['wss://league.test']])
        ->and($profile->about)->toBe('Souveränes Leben und Cypherpunk')
        ->and($profile->nip05_domain)->toBe('einundzwanzig.space')
        ->and($profile->website_domain)->toBe('ben.example')
        ->and(collect($profile->getAttributes())->implode(fn ($value) => (string) $value, ' '))->not->toContain('wallet.example')
        // The reply left the cache, the mention stayed a note, at most knowledge_notes own notes.
        ->and(app(ChatterCache::class)->notes($chatter->pubkey))->toBe(['Rocket League Cup gewonnen', 'mit Zitat einer Notiz']);
});

it('keeps the cache when no relay answers at all', function () {
    $chatter = new TestSigner;
    $profile = ChatterProfile::query()->create(['pubkey' => $chatter->pubkey, 'about' => 'Schach']);
    ChatterNote::query()->create(['pubkey' => $chatter->pubkey, 'event_id' => str_repeat('c', 64), 'note_created_at' => now()->getTimestamp() - 60, 'content' => 'noch da']);

    app(ChatterCache::class)->refresh($profile, CarbonImmutable::now());

    expect($profile->refresh()->about)->toBe('Schach')
        ->and(app(ChatterCache::class)->notes($chatter->pubkey))->toBe(['noch da']);
});

it('drops a persona card with a digit, a link or domain, a quote, a sensitive topic or a gamer tag', function (string $card, ?string $reason) {
    expect(PersonaCards::rejection($card, ['benrocket']))->toBe($reason);
})->with([
    'rich card, numbers spelled out' => ['Rocket League und Fernschach; gewinnt gern den Casual Cup; Running Gag Wololo; baut zweiundzwanzig Sachen gleichzeitig', null],
    'digit' => ['Blockfill Woche 40', 'persona_digit'],
    'domain' => ['postet auf einundzwanzig.space', 'persona_link'],
    'quote' => ['sagt immer „gm“', 'persona_quote'],
    'location' => ['Schach; wohnt im Süden', 'persona_sensitive'],
    'politics' => ['Bitcoin; Anarchie', 'persona_sensitive'],
    'gamer tag' => ['spielt als BenRocket', 'persona_gamer_tag'],
    'game account' => ['teilt seinen Steam-Namen', 'persona_gamer_tag'],
    'too long' => [str_repeat('Schach ', 90), 'persona_too_long'],
]);

it('keeps the parts of a draft that pass and drops each part that does not', function () {
    expect(PersonaCards::kept("Rocket League; Cypherpunk Anarchie; Blockfill Woche 40\n„Wololo“ 🪏 als Running Gag.", ['benrocket']))
        ->toBe(['Rocket League; Wololo als Running Gag', ['persona_sensitive', 'persona_digit']])
        ->and(PersonaCards::kept('Woche 40'))->toBe(['', ['persona_digit']]);
});

it('writes the card again with the reason when the first draft breaks a rule, and never shows the writer a gamer tag', function () {
    $chatter = new TestSigner;
    User::factory()->create(['pubkey' => $chatter->pubkey, 'npub' => NostrKeys::hexToNpub($chatter->pubkey), 'gamer_tags' => ['rocket-league' => 'BenRocket']]);
    $profile = ChatterProfile::query()->create(['pubkey' => $chatter->pubkey]);
    $this->reader->relays = ['wss://knowledge.test' => [
        knowledgeEvent($chatter, 1, 'Fernschach gewonnen, GG', 60),
        knowledgeEvent($chatter, 1, 'Spielt mit mir, ich bin BenRocket', 120),
    ]];
    VoiceAgent::fake([
        ['text' => 'Woche 40; Anarchie', 'canon_id' => null, 'mood' => 'fakten'],
        ['text' => 'Fernschach; freut sich über jeden Sieg', 'canon_id' => null, 'mood' => 'fakten'],
    ]);

    expect(app(PersonaCards::class)->refreshIfDue($profile, CarbonImmutable::now()))->toBe('Fernschach; freut sich über jeden Sieg')
        ->and(DB::table('voice_rejections')->where('channel', PersonaCards::CHANNEL)->pluck('count', 'reason')->all())->toBe(['persona_digit' => 1, 'persona_sensitive' => 1]);
    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Grund: persona_digit'));
    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => str_contains(mb_strtolower($prompt->prompt), 'benrocket'));
});

it('gives a trusted reply the card and the three newest notes, sanitised, and a stranger none of it', function () {
    $trusted = new TestSigner;
    $stranger = new TestSigner;
    WotLevel::query()->create(['pubkey' => $trusted->pubkey, 'level' => 1, 'computed_at' => now()]);
    User::factory()->create(['pubkey' => $trusted->pubkey, 'npub' => NostrKeys::hexToNpub($trusted->pubkey), 'gamer_tags' => ['steam' => 'BenRocket']]);
    $long = 'Heute wieder an TWENTY ONE gebaut '.str_repeat('und noch mehr Schachfiguren poliert ', 8);
    $this->reader->relays = ['wss://knowledge.test' => [
        knowledgeEvent($trusted, 0, json_encode(['name' => 'Ben', 'about' => 'Cypherpunk']), 9_000),
        knowledgeEvent($trusted, 1, 'Rocket League Cup gewonnen 2–1 gegen npub134tv37tqqqqqqqq schau https://evil.test', 3_600),
        knowledgeEvent($trusted, 1, 'Mein Steam-Name ist BenRocket, adden!', 3_700),
        knowledgeEvent($trusted, 1, 'Ich war heute beim Arzt, alles gut', 3_800),
        knowledgeEvent($trusted, 1, $long, 90_000),
        knowledgeEvent($trusted, 1, 'Wololoooo! Fernschach ist das beste Spiel', 200_000),
        knowledgeEvent($trusted, 1, 'Diese vierte Notiz kommt nicht mehr rein', 300_000),
    ]];
    $this->reader->chat = [
        SignedEvent::fromInput($stranger->sign(1311, [['a', StreamCoordinates::fromConfig()->address(), '', 'root']], 'hallo zusammen', now()->getTimestamp())),
        SignedEvent::fromInput($trusted->sign(1311, [['a', StreamCoordinates::fromConfig()->address(), '', 'root']], 'Hey Markus, was geht?', now()->getTimestamp())),
    ];
    VoiceAgent::fake(fn (string $prompt): array => str_contains($prompt, '## Profil')
        ? ['text' => 'Rocket League; Fernschach; Running Gag Wololo', 'canon_id' => null, 'mood' => 'fakten']
        : ['text' => "Ben, du hast doch gestern noch über Fernschach geschrieben, und ich sitze hier und staune über so viel Ausdauer am Brett.\nWas geht bei dir heute noch so?", 'canon_id' => null, 'mood' => 'call']);

    app(ChatReactions::class)->poll(CarbonImmutable::now());

    $notes = app(ChatterCache::class)->promptNotes($trusted->pubkey, CarbonImmutable::now());
    expect($notes)->toHaveCount(3)
        ->and($notes[0])->toBe('heute: Rocket League Cup gewonnen gegen schau')
        ->and($notes[1])->toStartWith('gestern: Heute wieder an TWENTY ONE gebaut')->toEndWith('…')
        ->and(mb_strlen(mb_substr($notes[1], mb_strlen('gestern: '))))->toBeLessThanOrEqual(160)
        ->and($notes[2])->toBe('vor ein paar Tagen: Wololoooo! Fernschach ist das beste Spiel');

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "Eigene Notizen auf Nostr, neueste zuerst:\n<<<notizen\n".implode("\n", $notes)."\nnotizen>>>")
        && str_contains($prompt->prompt, 'Persona: Rocket League; Fernschach; Running Gag Wololo')
        && str_contains($prompt->prompt, 'Überrasch Ben mit genau einer witzigen, freundlichen Anspielung'));
    // No gamer tag, no link, no health note in any prompt (the card writer's included), no digit of a note in a reply
    // prompt; the stranger's greeting knows nothing.
    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => str_contains(mb_strtolower($prompt->prompt), 'benrocket')
        || str_contains($prompt->prompt, 'evil.test') || str_contains($prompt->prompt, 'Arzt')
        || (str_contains($prompt->prompt, '<<<chat') && str_contains($prompt->prompt, '2–1')));
    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'hallo zusammen') && str_contains($prompt->prompt, '<<<notizen'));
    expect(app(ChatterCache::class)->promptNotes($stranger->pubkey, CarbonImmutable::now()))->toBe([]);
});

it('puts no notes and no card into a reply while profiles are off', function () {
    config(['tv.commentary.chat.profiles' => false]);
    $trusted = new TestSigner;
    WotLevel::query()->create(['pubkey' => $trusted->pubkey, 'level' => 1, 'computed_at' => now()]);
    ChatterProfile::query()->create(['pubkey' => $trusted->pubkey, 'persona' => 'Schach', 'persona_at' => now()]);
    ChatterNote::query()->create(['pubkey' => $trusted->pubkey, 'event_id' => str_repeat('d', 64), 'note_created_at' => now()->getTimestamp() - 60, 'content' => 'Fernschach ist das beste Spiel']);
    $this->reader->chat = [SignedEvent::fromInput($trusted->sign(1311, [['a', StreamCoordinates::fromConfig()->address(), '', 'root']], 'Hey Markus, was geht?', now()->getTimestamp()))];
    VoiceAgent::fake(fn (): array => ['text' => "Hey, schön dass du da bist, und ich erzähl dir gleich, was hier im Stream heute noch alles passiert.\nBleib dran.", 'canon_id' => null, 'mood' => 'call']);

    app(ChatReactions::class)->poll(CarbonImmutable::now());

    VoiceAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Hey Markus, was geht?'));
    VoiceAgent::assertNotPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Fernschach') || str_contains($prompt->prompt, CasterVoice::NOTES_HINT));
    expect($this->reader->asked)->toBe([]);
});
