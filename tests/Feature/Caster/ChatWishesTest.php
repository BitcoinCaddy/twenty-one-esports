<?php

use App\Enums\ModerationAction;
use App\Models\CasterClip;
use App\Models\CasterNews;
use App\Models\CasterScript;
use App\Models\NostrEvent;
use App\Models\PubkeyModeration;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\WotLevel;
use App\Support\Caster\ChatReactions;
use App\Support\Caster\ChatWishes;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\HistoryScripts;
use App\Support\Caster\NewsFeeds;
use App\Support\Caster\PatternScripts;
use App\Support\Caster\ReactionLines;
use App\Support\Caster\SceneFacts;
use App\Support\Caster\SceneTopic;
use App\Support\Caster\ScriptGuard;
use App\Support\Caster\ScriptQueue;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\Voice\VoiceAgent;
use App\Support\Voice\VoiceCanon;
use App\Support\Voice\VoiceGuard;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| TWENTY ONE TV: wishes of trusted chatters
|--------------------------------------------------------------------------
|
| User 2026-10-11: "Er reagiert nicht inhaltlich auf die Texte. Ich habe ihn
| nach den Bauernkriegen gefragt, er soll den Leuten im Level 1 Web of Trust
| gerne die Wünsche erfüllen können." A trusted chatter's wish is
| acknowledged by name and the segment is queued right behind the reply,
| both live, within the per-person and hourly limits; strangers get no wish.
|
*/

beforeEach(function () {
    $this->streamKey = new TestSigner;
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.ai' => false,
        'tv.commentary.chat.reactions' => true,
        'tv.commentary.chat.per_person_minutes' => 0,
        'esports.stream_bot.chat_relays' => ['wss://chat.test'],
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->streamKey->pubkey),
        'twentyone.nostr.nsec' => null,
        'twentyone.stream.relays' => ['wss://one.test'],
    ]);
    VoiceAgent::fake();

    $this->reader = new class extends RelayReader
    {
        /** @var list<SignedEvent> */
        public array $events = [];

        public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
        {
            $kinds = array_merge(...array_map(fn (array $filter): array => $filter['kinds'], $filters));

            return array_values(array_filter($this->events, fn (SignedEvent $event): bool => in_array($event->kind, $kinds, true)));
        }
    };
    $this->app->instance(RelayReader::class, $this->reader);
});

/** A chatter with a public name, trusted at `$level` (null: not in the web of trust), and their message as the reader's events. */
function wishChatter(object $test, string $message, ?int $level = 1, string $name = 'El Presidento Ben', ?TestSigner $signer = null, int $at = 0): TestSigner
{
    $signer ??= new TestSigner;
    if ($level !== null && ! WotLevel::query()->whereKey($signer->pubkey)->exists()) {
        WotLevel::query()->create(['pubkey' => $signer->pubkey, 'level' => $level, 'computed_at' => now()]);
    }
    $test->reader->events = [
        SignedEvent::fromInput($signer->sign(1311, [['a', StreamCoordinates::fromConfig()->address(), '', 'root']], $message, $at ?: now()->getTimestamp())),
        SignedEvent::fromInput($signer->sign(0, [], (string) json_encode(['name' => $name]), now()->getTimestamp() - 100)),
    ];

    return $signer;
}

/** @return list<CasterScript> the queued scripts in claim order */
function wishQueue(): array
{
    return CasterScript::query()->where('status', CasterScript::QUEUED)->where('kind', '!=', CasterScript::FILLER)->orderByDesc('priority')->orderBy('id')->get()->all();
}

/** The acknowledgement lines of a wish type with the name filled in. */
function wishAcks(string $type, string $name = 'El Presidento Ben'): array
{
    return array_map(fn (string $line): string => strtr($line, ['{name}' => $name]), app(ReactionLines::class)->pool('wish_'.$type));
}

it('reads the wish in a chat message', function (string $message, ?string $wish) {
    expect(ChatWishes::intent($message))->toBe($wish);
})->with([
    ['Kannst du was über die Bauernkriege erzählen, Markus?', ChatWishes::HISTORY],
    ['Kommst du noch mal zu den Bauernkriegen zurück?', ChatWishes::HISTORY],
    ['Was war mit Thomas Müntzer?', ChatWishes::HISTORY],
    ['Was gibt es Neues?', ChatWishes::NEWS],
    ['Lies mal die Nachrichten vor', ChatWishes::NEWS],
    ['Wer führt im Turnier?', ChatWishes::GAME],
    ['Erzähl was über EINUNDZWANZIG', ChatWishes::CANON],
    ['Hau mal einen Spruch raus', ChatWishes::JOKE],
    ['Erzähl mehr!', ChatWishes::MORE],
    ['Bauernopfer', null],
    ['Lies meine Nachricht', null],
    ['Hallo Markus', null],
]);

it('keeps every wish line speakable with a three-word name', function () {
    $lines = app(ReactionLines::class);

    foreach ([ChatWishes::HISTORY, ChatWishes::NEWS, ChatWishes::JOKE, ChatWishes::GAME, ChatWishes::CANON] as $type) {
        expect($lines->pool('wish_'.$type))->not->toBeEmpty();

        foreach ($lines->pool('wish_'.$type) as $line) {
            expect($line)->toContain('{name}')
                ->and((new ScriptGuard)->check(strtr($line, ['{name}' => 'El Presidento Ben']), 1, 1, 32))->toBe([], $line)
                ->and(ReactionLines::withName($line, null))->not->toContain('{name}');
        }
    }

    foreach (['wish_intro_joke', 'wish_intro_canon'] as $pool) {
        foreach ($lines->pool($pool) as $line) {
            expect((new ScriptGuard)->check($line, 1, 1, 32))->toBe([], $line);
        }
    }
});

it('tells a Bauernkrieg episode right behind the reply when a trusted chatter asks for it (live, 2026-10-11)', function () {
    wishChatter($this, 'Kannst du was über die Bauernkriege erzählen, Markus?');

    expect(app(ChatReactions::class)->poll(CarbonImmutable::now()))->toBe(1);
    [$reply, $segment] = wishQueue();
    $parts = array_slice(wishQueue(), 1);

    expect($reply->kind)->toBe(CasterScript::CHAT)
        ->and(strtok($reply->text, "\n"))->toBeIn(wishAcks(ChatWishes::HISTORY))
        ->and($segment->kind)->toBe(CasterScript::HISTORY)
        ->and($segment->scene)->toBe(ChatWishes::SCENE)
        ->and($segment->facts_hash)->toStartWith(HistoryScripts::HASH_PREFIX)
        ->and($segment->priority)->toBe($reply->priority)->toBe((int) config('tv.commentary.scripts.live_priority'))
        ->and($segment->id)->toBeGreaterThan($reply->id)
        // The long episode: at least `length.history_long.min` sentences, every fact from the file.
        ->and(count(casterWholeSentences($segment)))->toBeGreaterThanOrEqual((int) config('tv.commentary.length.history_long.min'))
        // Queued in parts (live 2026-10-11: 2.5 minutes of silence while the whole episode was voiced), all of them wished.
        ->and(count($parts))->toBeGreaterThan(1)
        ->and(array_unique(array_map(fn (CasterScript $part): string => $part->scene.'|'.$part->part_group.'|'.$part->priority, $parts)))->toHaveCount(1)
        ->and(array_map(fn (CasterScript $part): int => (int) $part->part_index, $parts))->toBe(range(1, count($parts)));
    VoiceAgent::assertNeverPrompted();
});

it('does not tell the same Bauernkrieg episode twice within an hour', function () {
    $ben = wishChatter($this, 'Erzähl vom Bauernkrieg');
    $anna = new TestSigner;
    $now = CarbonImmutable::now();
    app(ChatReactions::class)->poll($now);
    wishChatter($this, 'Noch mehr Bauernkrieg bitte', name: 'Anna', signer: $anna, at: $now->addMinute()->getTimestamp());
    app(ChatReactions::class)->poll($now->addMinute());

    $episodes = CasterScript::query()->whole()->where('kind', CasterScript::HISTORY)->pluck('facts_hash')->all();
    expect($episodes)->toHaveCount(2)->and($episodes[0])->not->toBe($episodes[1]);
});

it('picks the episode told more than an hour ago, and grants no Bauernkrieg wish when every episode was told within the hour', function (int $minutesAgoOfLast, ?string $expected) {
    $episodes = array_column(HistoryScripts::data()['episodes'], 'id');
    foreach ($episodes as $index => $episode) {
        $script = app(ScriptQueue::class)->enqueue(CasterScript::HISTORY, 'break', "Folge {$episode} vom Bauernkrieg.", HistoryScripts::HASH_PREFIX.$episode, 0, CarbonImmutable::now());
        $script->forceFill(['created_at' => now()->subMinutes($index === count($episodes) - 1 ? $minutesAgoOfLast : 20), 'status' => CasterScript::VOICED])->save();
    }
    wishChatter($this, 'Erzähl vom Bauernkrieg');

    app(ChatReactions::class)->poll(CarbonImmutable::now());

    expect(CasterScript::query()->where('scene', ChatWishes::SCENE)->value('facts_hash'))->toBe($expected === null ? null : HistoryScripts::HASH_PREFIX.$expected);
})->with([
    'last one 61 minutes ago' => [61, 'folgen'],
    'all within the hour' => [40, null],
]);

it('reads the newest headline for a news wish, Bitcoin first when asked for Bitcoin', function () {
    CasterNews::query()->create(['hash' => NewsFeeds::hash('Bundestag beschließt Haushalt'), 'section' => CasterNews::GERMANY, 'feed' => 'test', 'source' => 'tagesschau', 'language' => 'de', 'title' => 'Bundestag beschließt Haushalt', 'link' => null, 'published_at' => now()->subMinutes(5)]);
    CasterNews::query()->create(['hash' => NewsFeeds::hash('Bitcoin-Hashrate erreicht Rekord'), 'section' => CasterNews::BITCOIN, 'feed' => 'test', 'source' => 'Bitcoin Magazine', 'language' => 'de', 'title' => 'Bitcoin-Hashrate erreicht Rekord', 'link' => null, 'published_at' => now()->subMinutes(20)]);
    // The German headline would come first on its own (the last segment was Bitcoin news).
    CasterScript::query()->create(['kind' => CasterScript::NEWS, 'phase' => 'break', 'priority' => 0, 'text' => 'x', 'facts_hash' => 'news:0', 'status' => CasterScript::VOICED, 'source' => 'pattern', 'topic' => CasterNews::BITCOIN, 'estimated_seconds' => 1, 'expires_at' => now()->subMinute()]);
    wishChatter($this, 'Was gibt es Neues bei Bitcoin?');

    app(ChatReactions::class)->poll(CarbonImmutable::now());
    [$reply, $segment] = wishQueue();

    expect(strtok($reply->text, "\n"))->toBeIn(wishAcks(ChatWishes::NEWS))
        ->and($segment->kind)->toBe(CasterScript::NEWS)
        ->and($segment->text)->toContain('Bitcoin-Hashrate erreicht Rekord')
        ->and($segment->topic)->toBe(CasterNews::BITCOIN);
});

it('tells verified canon jokes for a joke wish', function () {
    wishChatter($this, 'Erzähl mal einen Witz!');

    app(ChatReactions::class)->poll(CarbonImmutable::now());
    [$reply, $segment] = wishQueue();
    $lines = ScriptGuard::lines($segment->text);
    $canon = array_map(fn (string $id): string => rtrim((string) app(VoiceCanon::class)->find($id)['wording'], '.!?'), app(PatternScripts::class)->canonPool());

    expect(strtok($reply->text, "\n"))->toBeIn(wishAcks(ChatWishes::JOKE))
        ->and($segment->kind)->toBe(CasterScript::LIVE)
        ->and($segment->scene)->toBe(ChatWishes::SCENE)
        // Three jokes asked; the 24 h no-repeat guard may drop one whose carrier repeats a phrase of another (SpokenMemory).
        ->and(count($lines))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(4)
        ->and($lines[0])->toBeIn(app(ReactionLines::class)->pool('wish_intro_joke'));

    // Every joke carries a canon entry word for word (a short saying inside a carrier sentence), nothing made up.
    foreach (array_slice($lines, 1) as $line) {
        expect(array_filter($canon, fn (string $wording): bool => preg_match(VoiceGuard::wordingPattern($wording), $line) === 1))->not->toBeEmpty($line)
            ->and((new ScriptGuard)->check($line, 1, 1, 32))->toBe([], $line);
    }
});

it('tells EINUNDZWANZIG lore from the canon for a canon wish', function () {
    wishChatter($this, 'Erzähl uns was über EINUNDZWANZIG');

    app(ChatReactions::class)->poll(CarbonImmutable::now());
    [$reply, $segment] = wishQueue();

    expect(strtok($reply->text, "\n"))->toBeIn(wishAcks(ChatWishes::CANON))
        ->and(count(ScriptGuard::lines($segment->text)))->toBeGreaterThanOrEqual(3) // the 24 h no-repeat guard may drop a line whose carrier repeats a phrase
        ->and((new ScriptGuard)->check($segment->text, 1, 12, 32))->toBe([], $segment->text);
});

it('comments the live games for a game wish, and grants nothing when no game has data', function () {
    $facts = ['league_players' => 21, 'slide' => 'Live-Partien der Liga', 'live_games' => [['game' => 'Schach', 'white' => 'Ada', 'black' => 'Bob', 'mode' => 'Blitz', 'move' => 14]], 'live_now' => 1];
    $this->mock(SceneFacts::class, fn ($mock) => $mock->shouldReceive('forTopic')->andReturnUsing(fn (string $topic): array => $topic === SceneTopic::LIVE_GAMES ? $facts : ['slide' => $topic]));
    wishChatter($this, 'Wer führt gerade im Spiel?');

    app(ChatReactions::class)->poll(CarbonImmutable::parse('2026-10-11 18:00'));
    [$reply, $segment] = wishQueue();

    expect(strtok($reply->text, "\n"))->toBeIn(wishAcks(ChatWishes::GAME))
        ->and($segment->kind)->toBe(CasterScript::LIVE)
        ->and($segment->topic)->toBe(SceneTopic::LIVE_GAMES)
        ->and($segment->scene)->toBe(ChatWishes::SCENE);
});

it('grants no game wish without game data: the plain reply answers', function () {
    $this->mock(SceneFacts::class, fn ($mock) => $mock->shouldReceive('forTopic')->andReturnUsing(fn (string $topic): array => ['slide' => $topic]));
    wishChatter($this, 'Wer führt gerade im Spiel?');

    app(ChatReactions::class)->poll(CarbonImmutable::parse('2026-10-11 18:00'));

    expect(wishQueue())->toHaveCount(1)
        ->and(CasterScript::query()->where('scene', ChatWishes::SCENE)->exists())->toBeFalse();
});

it('continues with the type of the last wish on "erzähl mehr"', function () {
    $ben = wishChatter($this, 'Erzähl vom Bauernkrieg');
    $now = CarbonImmutable::now();
    app(ChatReactions::class)->poll($now);
    wishChatter($this, 'Erzähl mehr!', name: 'Anna', at: $now->addMinute()->getTimestamp());
    app(ChatReactions::class)->poll($now->addMinute());

    $segments = CasterScript::query()->whole()->where('scene', ChatWishes::SCENE)->orderBy('id')->get();
    expect($segments)->toHaveCount(2)
        ->and($segments->pluck('kind')->all())->toBe([CasterScript::HISTORY, CasterScript::HISTORY])
        ->and($segments[0]->facts_hash)->not->toBe($segments[1]->facts_hash);
});

it('grants one wish per person within the cooldown, and at most per_hour in all', function () {
    config(['tv.commentary.chat.wishes_per_hour' => 2]);
    $now = CarbonImmutable::now();
    $ben = wishChatter($this, 'Erzähl einen Witz');
    app(ChatReactions::class)->poll($now);
    wishChatter($this, 'Noch einen Witz', signer: $ben, at: $now->addMinutes(4)->getTimestamp());
    app(ChatReactions::class)->poll($now->addMinutes(4));

    expect(CasterScript::query()->where('scene', ChatWishes::SCENE)->count())->toBe(1);

    // After the person's five minutes the wish is granted again; the hourly cap then stops a third one.
    wishChatter($this, 'Noch einen Witz', signer: $ben, at: $now->addMinutes(6)->getTimestamp());
    app(ChatReactions::class)->poll($now->addMinutes(6));
    expect(CasterScript::query()->where('scene', ChatWishes::SCENE)->count())->toBe(2);
    wishChatter($this, 'Erzähl einen Witz', name: 'Anna', at: $now->addMinutes(7)->getTimestamp());
    app(ChatReactions::class)->poll($now->addMinutes(7));

    expect(CasterScript::query()->where('scene', ChatWishes::SCENE)->count())->toBe(2)
        // Every message still got its reply.
        ->and(CasterScript::query()->where('kind', CasterScript::CHAT)->count())->toBe(4);
});

it('counts a long wished segment queued in parts as one wish against per_hour', function () {
    config(['tv.commentary.chat.wishes_per_hour' => 2]);
    $now = CarbonImmutable::now();
    wishChatter($this, 'Erzähl vom Bauernkrieg');
    app(ChatReactions::class)->poll($now);
    wishChatter($this, 'Noch mehr Bauernkrieg bitte', name: 'Anna', at: $now->addMinute()->getTimestamp());
    app(ChatReactions::class)->poll($now->addMinute());

    expect(CasterScript::query()->where('scene', ChatWishes::SCENE)->count())->toBeGreaterThan(2)
        ->and(CasterScript::query()->whole()->where('scene', ChatWishes::SCENE)->count())->toBe(2);
});

it('lifts each wish and chat limit with 0 (unlimited via .env)', function (string $limit) {
    // Every other limit roomy; the one under test at 0, with earlier traffic that a non-zero limit would count.
    config([
        'tv.commentary.chat.wishes_person_minutes' => 0,
        'tv.commentary.chat.wishes_per_hour' => 100,
        'tv.commentary.chat.per_person_minutes' => 0,
        'tv.commentary.chat.per_hour' => 100,
        "tv.commentary.chat.{$limit}" => 0,
        // The limits alone: the no-repeat memory would drop the repeated test lines.
        'tv.commentary.repeat_hours' => 0,
    ]);
    foreach (range(1, 3) as $turn) {
        app(ScriptQueue::class)->enqueue(CasterScript::LIVE, 'break', 'x', 'x', 0, CarbonImmutable::now(), scene: ChatWishes::SCENE);
        app(ScriptQueue::class)->enqueue(CasterScript::CHAT, 'break', 'x', 'x', 0, CarbonImmutable::now());
    }
    $now = CarbonImmutable::now();
    $ben = wishChatter($this, 'Erzähl einen Witz');
    app(ChatReactions::class)->poll($now);
    wishChatter($this, 'Noch einen Witz', signer: $ben, at: $now->addMinute()->getTimestamp());
    app(ChatReactions::class)->poll($now->addMinute());

    // Both messages answered within a minute, both wishes granted.
    expect(CasterScript::query()->where('kind', CasterScript::CHAT)->count())->toBe(3 + 2)
        ->and(CasterScript::query()->where('scene', ChatWishes::SCENE)->count())->toBe(3 + 2);
})->with(['wishes_person_minutes', 'wishes_per_hour', 'per_person_minutes', 'per_hour']);

it('tells a Bauernkrieg episode again at once with a repeat window of 0', function () {
    config(['tv.commentary.chat.wishes_history_repeat_minutes' => 0]);
    foreach (array_column(HistoryScripts::data()['episodes'], 'id') as $episode) {
        app(ScriptQueue::class)->enqueue(CasterScript::HISTORY, 'break', 'x', HistoryScripts::HASH_PREFIX.$episode, 0, CarbonImmutable::now());
    }
    wishChatter($this, 'Erzähl vom Bauernkrieg');

    app(ChatReactions::class)->poll(CarbonImmutable::now()->addSecond());

    expect(CasterScript::query()->whole()->where('scene', ChatWishes::SCENE)->where('kind', CasterScript::HISTORY)->count())->toBe(1);
});

it('grants no wish to a stranger, a muted chatter or level 2 below wishes_min_level', function (string $who) {
    $signer = new TestSigner;
    match ($who) {
        'stranger' => null,
        'muted' => PubkeyModeration::query()->create(['pubkey' => $signer->pubkey, 'action' => ModerationAction::Mute, 'reason' => 'spam', 'actor_pubkey' => str_repeat('c', 64)]),
        'level two' => config(['tv.commentary.chat.wishes_min_level' => 1]),
    };
    wishChatter($this, 'Kannst du was über die Bauernkriege erzählen, Markus?', level: $who === 'stranger' ? null : ($who === 'level two' ? 2 : 1), signer: $signer);

    app(ChatReactions::class)->poll(CarbonImmutable::now());

    expect(CasterScript::query()->where('scene', ChatWishes::SCENE)->exists())->toBeFalse()
        ->and(CasterScript::query()->where('kind', CasterScript::HISTORY)->exists())->toBeFalse();
})->with(['stranger', 'muted', 'level two']);

it('grants a wish to a trust rank at the minimum (not below) and to level 2 by default', function (string $who, int $granted) {
    config(['season.trust_minimum' => 50]);
    $signer = new TestSigner;
    if ($who !== 'level two') {
        $trust = new TestSigner;
        $rank = $who === 'rank at minimum' ? 50 : 49;
        $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
        $run = TrustRun::query()->create(['season_id' => null, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
            'anchors' => 0, 'lists' => 0, 'ranked' => 1, 'published' => 1, 'computed_at' => now()]);
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $signer->pubkey], ['p', $signer->pubkey], ['rank', (string) $rank], ['alt', 'rank']])));
        TrustRank::query()->create(['pubkey' => $signer->pubkey, 'rank' => $rank, 'raw' => 0.5, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
    }
    wishChatter($this, 'Erzähl vom Bauernkrieg', level: $who === 'level two' ? 2 : null, signer: $signer);

    app(ChatReactions::class)->poll(CarbonImmutable::now());

    expect(CasterScript::query()->whole()->where('scene', ChatWishes::SCENE)->count())->toBe($granted);
})->with([['rank at minimum', 1], ['rank below minimum', 0], ['level two', 1]]);

it('plays the reply and then the wished segment ahead of scenes, also a long segment on a busy slot, and claims both while the spool is full', function () {
    wishChatter($this, 'Kannst du was über die Bauernkriege erzählen, Markus?');
    $now = CarbonImmutable::now();
    $scene = app(ScriptQueue::class)->enqueue(CasterScript::SCENE, 'live_match', "Ein Satz.\nNoch einer.", 'x', 0, $now);
    app(ChatReactions::class)->poll($now);

    // Claimed while full (live kinds only): the reply first, then the segment part by part, the scene never.
    $queue = app(ScriptQueue::class);
    $first = $queue->claim($now, ClipSpool::LIVE_KINDS);
    $second = $queue->claim($now, ClipSpool::LIVE_KINDS);
    $rest = [];
    while (($part = $queue->claim($now, ClipSpool::LIVE_KINDS)) !== null) {
        $rest[] = $part;
    }
    expect($first->kind)->toBe(CasterScript::CHAT)
        ->and($second->kind)->toBe(CasterScript::HISTORY)
        ->and($second->part_index)->toBe(1)
        ->and(array_map(fn (CasterScript $part): int => (int) $part->part_index, $rest))->toBe(range(2, count($rest) + 1))
        ->and(array_column(array_map(fn (CasterScript $part): array => $part->toArray(), $rest), 'id'))->not->toContain($scene->id);

    // Voiced out of order (the segment's clip first): still played reply first, then the segment, on a live-match slot.
    $clip = fn (CasterScript $script): CasterClip => CasterClip::query()->create(['caster_script_id' => $script->id, 'phase' => 'break', 'kind' => $script->kind, 'path' => 'x/'.$script->id.'.opus', 'duration_seconds' => 5, 'bytes' => 10, 'sentence_ends' => [5.0], 'status' => CasterClip::READY, 'expires_at' => $now->addMinutes(10)]);
    $clip($second);
    $clip($first);
    $clip($scene);
    $spool = app(ClipSpool::class);

    expect($spool->nextLive($now)->caster_script_id)->toBe($first->id);
    $spool->played($spool->nextLive($now));
    expect($spool->next('live_match', $now)['clip']->caster_script_id)->toBe($second->id);
});

it('never queues a goodbye: farewell sentences leave every script, the rest stays', function () {
    expect(ScriptQueue::withoutSignOffs("Stack Sats und bleib bullisch damit. Ciao.\nDer Euro wackelt."))->toBe("Stack Sats und bleib bullisch damit.\nDer Euro wackelt.")
        ->and(ScriptQueue::withoutSignOffs("[laughter] Peace out.\nBis zum nächsten Mal.\nWeiter geht's."))->toBe("Weiter geht's.")
        ->and(ScriptQueue::withoutSignOffs('Gute Nacht von TWENTY ONE. Morgen geht es weiter.'))->toBe('Morgen geht es weiter.');
});
