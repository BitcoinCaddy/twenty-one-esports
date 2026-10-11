<?php

use App\Enums\StackerRunStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Jobs\VerifyStackerRun;
use App\Models\BotPost;
use App\Models\NostrEvent;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\StreamBot\BlockfillNotes;
use App\Support\StreamBot\ChampionNotes;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TournamentRecapNotes;
use App\Support\Tournaments\TournamentRecap;
use App\Support\Tournaments\TournamentRunner;
use App\Support\TwentyOne\PublishResult;
use App\Support\Voice\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Tournament recap and weekly report (plan "KI-Stimme der Liga", P3)
|--------------------------------------------------------------------------
|
| The recap's facts come from the stored bracket only: upsets by the Elo at
| sign-up close, the closest match by its margin, the champion's path. The
| profile note takes the league voice's text when its channel is on and
| the text passes the guards, the template otherwise; it waits for the
| champion note and is off by default. The weekly winner note takes the
| voice's report the same way. The voice is the laravel/ai fake, never the
| network; the relays are stood in for.
|
*/

beforeEach(function () {
    Cache::flush();
    $this->league = new TestSigner;
    config([
        'esports.league.nsec' => $this->league->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => (new TestSigner)->secret,
        'esports.stream_bot.profile_limits' => [],
        'esports.stream_bot.profile_gap_minutes' => 0,
        'esports.relays' => ['wss://league.test'],
        'twentyone.stream.relays' => ['wss://one.test'],
        'esports.recap.notes.enabled' => true,
    ]);

    $this->published = [];
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->published[] = SignedEvent::fromInput($event);

            return array_map(fn (string $relay): PublishResult => new PublishResult($relay, true, ''), $relays);
        }
    });

    $this->travelTo(Carbon::parse('2026-10-11 12:00:00', 'UTC'));
});

/**
 * A published chess cup of four (Elo 1490, 1480, 1470, 1460 for Player 1 to 4), played out with upsets: Player 4
 * beats Player 1 in the semifinal (30 Elo below) and Player 2 in the final (20 below); Player 2 beats Player 3.
 * The second semifinal is then stored as a series 2:1 and the final as 3:0, so the closest match has a margin.
 */
function recapCup(TestSigner $league): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $runner = app(TournamentRunner::class);
    $winners = ['Player 4', 'Player 2', 'Player 4'];
    $rounds = 0;

    while ($tournament->refresh()->status === TournamentStatus::Running && $rounds < 5) {
        $round = TournamentRunner::currentRound($tournament);

        foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->with('slots.participant')->orderBy('id')->get() as $match) {
            $names = $match->slots->map(fn ($slot): string => $slot->participant->name)->all();
            $winner = collect($winners)->first(fn (string $name): bool => in_array($name, $names, true));
            $runner->enterResult($match, $tournament->creator, ['result' => $names[0] === $winner ? '1-0' : '0-1']);
        }

        $runner->closeRound($round, $tournament->creator);
        $rounds++;
    }

    $semifinal = recapMatchOf($tournament, 'Player 2', 'Player 3');
    $winner = (int) $semifinal->result['winner'];
    $semifinal->forceFill(['result' => [...$semifinal->result, 'games_won' => $winner === 0 ? [2.0, 1.0] : [1.0, 2.0], 'label' => $winner === 0 ? '2:1' : '1:2']])->save();
    $final = recapMatchOf($tournament, 'Player 4', 'Player 2');
    $winner = (int) $final->result['winner'];
    $final->forceFill(['result' => [...$final->result, 'games_won' => $winner === 0 ? [3.0, 0.0] : [0.0, 3.0], 'label' => $winner === 0 ? '3:0' : '0:3']])->save();

    $event = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(Tournament::CALENDAR_EVENT, [['d', $tournament->slug]])));
    $tournament->forceFill(['name' => 'Recap Cup', 'event_id' => $event->id, 'published_at' => now()])->save();

    return $tournament->refresh();
}

function recapMatchOf(Tournament $tournament, string $a, string $b): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant')->get()
        ->first(fn (TournamentMatch $match): bool => collect($match->slots)->map(fn ($slot): ?string => $slot->participant?->name)->sort()->values()->all() === collect([$a, $b])->sort()->values()->all());
}

/** The champion note as delivered, so the recap may follow. */
function recapChampionDelivered(Tournament $tournament): void
{
    BotPost::query()->insert(['subject_type' => ChampionNotes::SUBJECT, 'subject_id' => $tournament->id, 'kind' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

function recapUser(Tournament $tournament, string $name): User
{
    return User::query()->findOrFail($tournament->participants()->where('name', $name)->value('user_id'));
}

test('the recap names the upsets by Elo gap, the closest match by margin and the champion\'s path, from the stored bracket', function () {
    $tournament = recapCup($this->league);

    $recap = app(TournamentRecap::class)->of($tournament);

    expect($recap['champion']['name'])->toBe('Player 4')
        ->and($recap['entrants'])->toBe(4)
        ->and($recap['played'])->toBe(3)
        // Largest gap first; the better seed's win over Player 3 is no upset.
        ->and(array_map(fn (array $upset): array => [$upset['winner']['name'], $upset['loser']['name'], $upset['gap']], $recap['upsets']))
        ->toBe([['Player 4', 'Player 1', 30], ['Player 4', 'Player 2', 20]])
        ->and($recap['upsets'][0]['winner']['rating'])->toBe(1460)
        ->and($recap['upsets'][0]['loser']['rating'])->toBe(1490)
        // 2:1 beats 3:0 for closeness.
        ->and([$recap['closest']['winner']['name'], $recap['closest']['loser']['name'], $recap['closest']['score'], $recap['closest']['margin']])
        ->toBe(['Player 2', 'Player 3', '2:1', 1])
        ->and(array_column($recap['path'], 'opponent'))->toBe(['Player 1', 'Player 2'])
        ->and(array_column($recap['path'], 'result'))->toBe(['won', 'won']);
});

test('a forfeit or a result the league decided is no upset and no close match', function () {
    $tournament = recapCup($this->league);
    $semifinal = recapMatchOf($tournament, 'Player 4', 'Player 1');
    $semifinal->forceFill(['result' => [...$semifinal->result, 'forfeit' => true]])->save();
    $other = recapMatchOf($tournament, 'Player 2', 'Player 3');
    $other->forceFill(['result' => [...$other->result, 'by' => 'league']])->save();

    $recap = app(TournamentRecap::class)->of($tournament);

    expect(array_map(fn (array $upset): string => $upset['loser']['name'], $recap['upsets']))->toBe(['Player 2'])
        ->and($recap['closest']['score'])->toBe('3:0')
        ->and($recap['played'])->toBe(1);
});

test('when the closest match is one of the upsets, the page draws it once with both tags', function () {
    $this->withoutVite();
    $tournament = recapCup($this->league);
    recapMatchOf($tournament, 'Player 4', 'Player 1')->forceFill(['result' => [...recapMatchOf($tournament, 'Player 4', 'Player 1')->result, 'forfeit' => true]])->save();
    recapMatchOf($tournament, 'Player 2', 'Player 3')->forceFill(['result' => [...recapMatchOf($tournament, 'Player 2', 'Player 3')->result, 'by' => 'league']])->save();

    $html = $this->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'en']))->assertOk()
        ->assertSeeInOrder(['data-kind="upset"', 'data-closest', '20 Elo below', 'Closest match', 'Player 4', '>3<', 'Player 2', '>0<'], false)
        ->getContent();

    expect(substr_count($html, 'data-test="recap-match"'))->toBe(1)
        ->and($html)->not->toContain('data-kind="closest"');
});

test('a running tournament and a league week have no recap', function () {
    $running = runningChess(TournamentFormat::SingleElimination, 4);

    expect(app(TournamentRecap::class)->of($running))->toBeNull()
        ->and(app(TournamentRecap::class)->cached($running))->toBeNull();
});

test('with the voice off the recap note goes out from its template: champion tagged, biggest upset with both Elo, closest match, link', function () {
    $tournament = recapCup($this->league);
    recapChampionDelivered($tournament);
    VoiceAgent::fake();
    $champion = recapUser($tournament, 'Player 4');

    $log = app(TournamentRecapNotes::class)->run(now()->toImmutable());
    $again = app(TournamentRecapNotes::class)->run(now()->addMinutes(5)->toImmutable());
    $note = $this->published[0];
    [$text] = explode("\n\nnostr:", $note->content, 2);
    $npub = 'nostr:'.NostrKeys::hexToNpub($champion->pubkey);

    VoiceAgent::assertNeverPrompted();
    expect($log)->toBe('tournament '.$tournament->id.': recap posted id='.$note->id.' to 1/1 relays')
        ->and($again)->toBe('no recap notes: no newly finished tournament without its recap')
        ->and($this->published)->toHaveCount(1)
        // The upset's winner is the champion: mentioned by key in both lines, never by name; the others are named.
        ->and($text)->toBe(implode("\n", [
            '🏆 '.$npub.' wins Recap Cup (Chess Blitz 5+3)',
            '🤯 Biggest upset: '.$npub.' (1460 Elo) beat Player 1 (1490 Elo), 1:0',
            '😮‍💨 Closest match: Player 2 beat Player 3 2:1',
            '👉 Recap and bracket: '.route('tournaments.show', $tournament),
        ]))
        ->and(StreamBotCopy::violations($text, $note->tags))->toBe([])
        ->and(collect($note->tags)->where(0, 'p')->pluck(1)->all())->toBe([$champion->pubkey])
        ->and(collect($note->tags)->where(0, 't')->all())->toBe([])
        ->and(collect($note->tags)->firstWhere(0, 'q')[1])->toBe('31923:'.$this->league->pubkey.':'.$tournament->slug);
});

test('with the voice on the recap note carries its text, the champion as npub and the link the app adds', function () {
    config(['esports.voice.channels.recap.enabled' => true]);
    $tournament = recapCup($this->league);
    recapChampionDelivered($tournament);
    $champion = recapUser($tournament, 'Player 4');
    VoiceAgent::fake([['text' => '{@champion} wins the recap cup. player 2 beat player 3 by 2:1 in the closest match.', 'canon_id' => null, 'mood' => 'hype']]);

    app(TournamentRecapNotes::class)->run(now()->toImmutable());
    $note = $this->published[0];
    [$text] = explode("\n\nnostr:", $note->content, 2);

    VoiceAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Player 1') && str_contains($prompt->prompt, '1490') && ! str_contains($prompt->prompt, $champion->pubkey));
    expect($text)->toBe('nostr:'.NostrKeys::hexToNpub($champion->pubkey).' wins the recap cup. player 2 beat player 3 by 2:1 in the closest match.'."\n👉 ".route('tournaments.show', $tournament))
        ->and(collect($note->tags)->where(0, 'p')->pluck(1)->all())->toBe([$champion->pubkey])
        ->and(StreamBotCopy::violations($text, $note->tags))->toBe([]);
});

test('a voice text with an invented number falls back to the template', function () {
    config(['esports.voice.channels.recap.enabled' => true]);
    $tournament = recapCup($this->league);
    recapChampionDelivered($tournament);
    VoiceAgent::fake([['text' => '{@champion} wins the recap cup after 99 games.', 'canon_id' => null, 'mood' => 'hype']]);

    app(TournamentRecapNotes::class)->run(now()->toImmutable());

    expect($this->published)->toHaveCount(1)
        ->and($this->published[0]->content)->not->toContain('99 games')
        ->and($this->published[0]->content)->toContain(route('tournaments.show', $tournament));
});

test('the recap waits for the champion note and stays off without its switch', function () {
    $tournament = recapCup($this->league);
    VoiceAgent::fake();

    expect(app(TournamentRecapNotes::class)->due(now()->toImmutable()))->toBe([]);

    config(['esports.recap.notes.enabled' => false]);
    recapChampionDelivered($tournament);

    expect(app(TournamentRecapNotes::class)->run(now()->toImmutable()))->toBe('no recap notes: esports.recap.notes.enabled is off')
        ->and($this->published)->toBe([]);
});

test('the tournament page shows the recap section by default, as match boxes with the seeded Elo, and hides it with its switch off', function () {
    $this->withoutVite();
    $tournament = recapCup($this->league);

    expect(config('esports.recap.page'))->toBeTrue();

    // Upsets by gap first, each a box: round, gap tag, winner row (Elo, name, score), loser row; then the closest match as its own box.
    $this->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'en']))->assertOk()
        ->assertSee('data-test="tournament-recap"', false)
        ->assertSeeInOrder(['Recap', '30 Elo below', '1460', 'Player 4', '1490', 'Player 1', 'data-kind="closest"', 'Closest match', 'Player 2', '>2<', 'Player 3', '>1<'], false);

    config(['esports.recap.page' => false]);

    $this->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'en']))->assertOk()->assertDontSee('data-test="tournament-recap"', false);

    config(['esports.recap.page' => true]);

    // A Livewire roundtrip renders it again without an error (a 500 there never reaches the browser console).
    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])->assertSeeHtml('data-test="tournament-recap"')
        ->call('$refresh')->assertOk()->assertSeeHtml('data-test="tournament-recap"');
});

/** A finished Blockfill week with `$players` (name => ticks) on its board. */
function recapBlockfillWeek(array $players): array
{
    app()->instance(Verifier::class, new FakeStackerVerifier);
    test()->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    leagueWeeksApproved(Blockfill::SLUG);
    BlockfillOn::play();
    $users = [];

    foreach ($players as $name => $ticks) {
        $users[$name] = User::factory()->create(['name' => $name]);
        $at = CarbonImmutable::now()->subHour();
        $run = StackerRun::factory()->for($users[$name])->create([
            'status' => StackerRunStatus::Verifying, 'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
            'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA',
        ]);
        VerifyStackerRun::dispatchSync($run->id);
    }

    $week = app(BlockfillWeeks::class)->current();
    test()->travelTo(CarbonImmutable::parse('2026-10-12 23:30:00'));
    app(ScoreLeaderboards::class)->tick();

    return [$week->refresh(), $users];
}

test('the weekly winner note takes the voice\'s report with the podium tagged, and its template with the voice off', function () {
    [$week, $users] = recapBlockfillWeek(['Ada' => 2900, 'Ben' => 3000, 'Cy' => 3100]);
    $notes = app(BlockfillNotes::class);
    VoiceAgent::fake([['text' => '{@winner} takes the week, {@second} and {@third} right behind.', 'canon_id' => null, 'mood' => 'hype']]);

    $template = $notes->content($week, BlockfillNotes::SLOT_WINNER);

    config(['esports.voice.channels.weekly.enabled' => true]);
    $voiced = $notes->content($week, BlockfillNotes::SLOT_WINNER);
    [$text] = explode("\n\nnostr:", $voiced, 2);
    $npub = fn (string $name): string => 'nostr:'.NostrKeys::hexToNpub($users[$name]->pubkey);

    expect($template)->toMatch('/Top 3:|Podium:/')
        ->and($text)->toStartWith($npub('Ada').' takes the week, '.$npub('Ben').' and '.$npub('Cy').' right behind.')
        ->and($text)->toContain("\n👉 ")
        ->and(StreamBotCopy::violations($text))->toBe([]);
    VoiceAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Blockfill Week 41, 2026') && ! str_contains($prompt->prompt, $users['Ada']->pubkey));
});

test('the signed weekly winner note is the voice\'s report, its p tags exactly the players it mentions', function () {
    [$week, $users] = recapBlockfillWeek(['Ada' => 2900, 'Ben' => 3000, 'Cy' => 3100]);
    config(['esports.stream_bot.nsec' => (new TestSigner)->secret, 'esports.voice.channels.weekly.enabled' => true]);
    VoiceAgent::fake([['text' => '{@winner} takes the week, {@second} and {@third} right behind.', 'canon_id' => null, 'mood' => 'hype']]);

    $event = app(BlockfillNotes::class)->event(LeagueKey::streamBot(), $week, BlockfillNotes::SLOT_WINNER, now()->getTimestamp());
    [$text] = explode("\n\nnostr:", $event->content, 2);
    $npub = fn (string $name): string => 'nostr:'.NostrKeys::hexToNpub($users[$name]->pubkey);
    $mentioned = [];
    preg_match_all('/nostr:(npub1[0-9a-z]+)/', $text, $mentioned);
    $pTags = array_values(array_map(fn (array $tag): string => $tag[1], array_filter($event->tags, fn (array $tag): bool => $tag[0] === 'p')));

    expect($text)->toStartWith($npub('Ada').' takes the week, '.$npub('Ben').' and '.$npub('Cy').' right behind.')
        ->and($text)->toContain("\n👉 ")
        ->and($pTags)->toBe([$users['Ada']->pubkey, $users['Ben']->pubkey, $users['Cy']->pubkey])
        ->and(array_map(fn (string $npub): string => NostrKeys::npubToHex($npub), $mentioned[1]))->toBe($pTags)
        ->and($event->hasValidSignature())->toBeTrue();
});
