<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The tournaments of one game (plan "RL-Startseite", P1)
|--------------------------------------------------------------------------
|
| "Man kommt direkt auf die seite des letzten Tuniers statt auf eine
| Übersichtsseite und muss dann auf "alle tuniere" gehen." A game page leads
| to `/tournaments?game=<slug>`: every part of the list shows that game only,
| a chip with the game's name leads back to every game, an unknown slug is
| no filter, and the canonical URL stays the unfiltered list.
|
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
});

/** A published organizer tournament of a game. */
function publishedOf(string $game, string $name, TournamentStatus $status, int $days): Tournament
{
    $factory = $game === 'rocket-league' ? Tournament::factory()->rocketLeague() : Tournament::factory();
    $tournament = $factory->create(['name' => $name, 'starts_at' => now()->addDays($days)]);
    $tournament->forceFill(['status' => $status, 'published_at' => now()->subDay(), 'signup_closes_at' => now()->addDays($days)->subHour(), 'slug' => Str::slug($name)])->save();

    return $tournament;
}

test('?game=rocket-league shows only Rocket League tournaments, as hero, cards and counts, with a chip back to every game', function () {
    publishedOf('rocket-league', 'RL Open Signup', TournamentStatus::Signup, 3);
    publishedOf('rocket-league', 'RL Finished Cup', TournamentStatus::Finished, -7);
    publishedOf('chess', 'Chess Open Signup', TournamentStatus::Signup, 2);
    publishedOf('chess', 'Chess Finished', TournamentStatus::Finished, -3);

    $html = (string) $this->get(route('tournaments.index', ['game' => 'rocket-league']))->assertOk()->getContent();

    // The featured card and the rows are Rocket League's; its chip is the current one, "Alle" leads back to every game.
    expect(str($html)->after('data-test="featured-tournament"')->before('</section>')->toString())->toContain('RL Open Signup')
        ->and($html)->not->toContain('Chess Open Signup')->not->toContain('Chess Finished')->not->toContain('RL Finished Cup')
        ->and($html)->toMatch('/aria-current="page"\s+class="rv-chip"\s+data-test="filter-game-rocket-league"/')
        ->and($html)->toMatch('#href="'.preg_quote(route('tournaments.index'), '#').'"\s+data-chip="0"#');

    // The ended ones are on the archive, narrowed the same way.
    $archive = (string) $this->get(route('tournaments.archive', ['game' => 'rocket-league']))->assertOk()->getContent();
    expect($archive)->toContain('RL Finished Cup')->not->toContain('Chess Finished');

    Livewire::withQueryParams(['game' => 'rocket-league'])->test('pages::tournaments.index')->call('$refresh')->assertOk();
});

test('without a filter or with an unknown slug the list shows every game and no chip, without an error', function (?string $game) {
    publishedOf('rocket-league', 'RL Open Signup', TournamentStatus::Signup, 3);
    publishedOf('chess', 'Chess Open Signup', TournamentStatus::Signup, 2);

    $html = (string) $this->get(route('tournaments.index', $game === null ? [] : ['game' => $game]))->assertOk()->getContent();

    expect($html)->toContain('RL Open Signup')->toContain('Chess Open Signup')
        ->not->toContain('data-test="tournaments-game-filter"');
})->with([null, 'not-a-game', '<script>']);

test('the filtered list names the unfiltered list as its canonical URL', function () {
    // Only production on the APP_URL host is indexable and carries a canonical (PageMetaTest).
    $this->app['env'] = 'production';
    $html = (string) $this->get(route('tournaments.index', ['game' => 'rocket-league']))->assertOk()->getContent();

    preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $match);

    expect($match[1] ?? null)->not->toBeNull()
        ->and($match[1])->not->toContain('game=')
        ->and(parse_url($match[1], PHP_URL_PATH))->toBe('/tournaments');
});
