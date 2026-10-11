<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\OrganizerBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The organizers' tournaments first (user, 2026-09-28)
|--------------------------------------------------------------------------
|
| "alle manuell angelegten Turniere sind die WICHTIGSTEN. Die müssen alle mit
| Bildern oben groß und nicht unten klein Listen!" Every tournament an
| organizer set up heads the tournaments page as a large card with its
| cover, open ones first, then in progress, then past; the casual cups'
| board comes after them. The browser measures it in CasualCupRegionsTest.
|
*/

beforeEach(function () {
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', 'rocket-league']]);
    // Monday 5 October 2026: the cups start Saturday 20:00 on their region's clock.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** A published organizer tournament in the given state. */
function byOrganizer(string $name, TournamentStatus $status, CarbonImmutable $startsAt, array $attributes = []): Tournament
{
    $tournament = Tournament::factory()->create(['name' => $name, 'starts_at' => $startsAt, 'created_by_id' => organizer()->id]);
    $tournament->forceFill(['status' => $status, 'published_at' => now()->subDay(), 'signup_closes_at' => $startsAt->subHour(), ...$attributes])->save();

    return $tournament;
}

test('the board groups open, in progress and past, each in its own order, and leaves out drafts and casual cups', function () {
    cupTick();
    $now = CarbonImmutable::now();
    $later = byOrganizer('Open later', TournamentStatus::Signup, $now->addDays(9));
    $sooner = byOrganizer('Open sooner', TournamentStatus::Signup, $now->addDays(3));
    $closed = byOrganizer('Sign-up over', TournamentStatus::Signup, $now->addHours(2), ['signup_closes_at' => $now->subMinute()]);
    $running = byOrganizer('Running now', TournamentStatus::Running, $now->subHour());
    $drawing = byOrganizer('Waiting for the block', TournamentStatus::Drawing, $now->addMinutes(30));
    $old = byOrganizer('Long ago', TournamentStatus::Finished, $now->subDays(30));
    $recent = byOrganizer('Last week', TournamentStatus::Finished, $now->subDays(7));
    $off = byOrganizer('Called off', TournamentStatus::Cancelled, $now->subDays(2));
    byOrganizer('Still a draft', TournamentStatus::Draft, $now->addDays(4));

    $groups = app(OrganizerBoard::class)->groups();
    $ids = fn (string $group): array => array_map(fn (array $card): int => $card['tournament']->id, $groups[$group]);

    expect($ids('open'))->toBe([$sooner->id, $later->id])
        ->and($ids('progress'))->toBe([$running->id, $drawing->id, $closed->id])
        ->and(array_column($groups['progress'], 'state'))->toBe(['running', 'drawing', 'closed'])
        ->and($ids('past'))->toBe([$off->id, $recent->id, $old->id])
        ->and(array_column($groups['past'], 'state'))->toBe(['cancelled', 'finished', 'finished'])
        ->and(Tournament::query()->casualCup()->count())->toBe(4)
        ->and(app(OrganizerBoard::class)->groups(except: $sooner->id)['open'])->toHaveCount(1);
});

test('a card counts the places taken and names the start as day, clock and city, on the viewer\'s own clock when set', function () {
    $tournament = byOrganizer('Blitz Night', TournamentStatus::Signup, CarbonImmutable::parse('2026-10-10 18:00', 'UTC'));
    foreach (User::factory()->count(3)->create() as $player) {
        TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->name, 'members' => [$player->pubkey]]);
    }
    TournamentSignup::query()->create(['tournament_id' => $tournament->id, 'user_id' => User::factory()->create()->id, 'name' => 'gone', 'members' => ['x'], 'withdrawn_at' => now()]);

    $card = app(OrganizerBoard::class)->groups()['open'][0];
    expect([$card['taken'], $card['places'], $card['day'], $card['clock'], $card['city'], $card['fixedZone']])->toBe([3, 12, 'Sat, Oct 10', '8:00 PM', 'Berlin', false]);

    $tokyo = app(OrganizerBoard::class)->groups(viewerZone: 'Asia/Tokyo')['open'][0];
    expect([$tokyo['day'], $tokyo['clock'], $tokyo['city'], $tokyo['fixedZone']])->toBe(['Sun, Oct 11', '3:00 AM', 'Tokyo', true]);

    // A start in another year names it.
    expect(OrganizerBoard::start(CarbonImmutable::parse('2025-12-06 18:00', 'UTC'), 'Europe/Berlin')['day'])->toBe('Sat, Dec 6, 2025');
    app()->setLocale('de');
    expect(OrganizerBoard::start(CarbonImmutable::parse('2025-12-06 18:00', 'UTC'), 'Europe/Berlin')['day'])->toBe('Sa, 6. Dez 2025');
    app()->setLocale('en');
});

test('the tournaments page features the next organizer tournament, lists the open ones before the casual cups and the running ones apart; ended ones go to the archive', function () {
    cupTick();
    $now = CarbonImmutable::now();
    $next = byOrganizer('Friday Blitz', TournamentStatus::Signup, $now->addDays(2));
    $open = byOrganizer('RL Night', TournamentStatus::Signup, $now->addDays(6), ['game' => 'rocket-league', 'mode' => '3v3']);
    $running = byOrganizer('Running Cup', TournamentStatus::Running, $now->subHour());
    $finished = byOrganizer('Autumn Final', TournamentStatus::Finished, $now->subDays(8));
    $endedCup = Tournament::query()->where('cup_open_series', 'chess-us')->sole();
    $endedCup->forceFill(['status' => TournamentStatus::Finished, 'published_at' => now()])->save();

    $html = $this->get(route('tournaments.index'))->assertOk()->getContent();
    $featured = str($html)->after('data-test="featured-tournament" data-tournament="')->before('</section>')->toString();

    expect($featured)->toStartWith($next->id.'"')->toContain('data-game-cover="chess"')->toContain('loading="eager"');

    // The open table: the organizers' tournaments first (featured edge), then the casual cups.
    preg_match_all('/data-test="open-row" data-tournament="(\d+)"\s*(data-featured)?/', $html, $rows);
    $ids = array_map(intval(...), $rows[1]);
    expect(array_slice($ids, 0, 2))->toBe([$next->id, $open->id])
        ->and(array_slice($rows[2], 0, 2))->toBe(['data-featured', 'data-featured'])
        ->and(count($ids))->toBeGreaterThan(2);

    // Running in its own table; nothing that ended is on this page, it is on the archive.
    expect(str($html)->after('data-test="running-tournaments"')->toString())->toContain('Running Cup')
        ->and($html)->not->toContain('Autumn Final')->not->toContain($endedCup->name);
    $this->get(route('tournaments.archive'))->assertOk()->assertSee('Autumn Final')->assertSee($endedCup->name);
});

test('with only casual cups on, the page has no featured tournament and the open table holds the cups', function () {
    cupTick();

    $html = $this->get(route('tournaments.index'))->assertOk()
        ->assertDontSeeHtml('data-test="featured-tournament"')
        ->assertSeeHtml('data-test="open-row"')
        ->getContent();

    expect($html)->not->toContain('data-featured');
});
