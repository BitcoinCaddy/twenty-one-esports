<?php

namespace App\Http\Controllers;

use App\Models\Season;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\PrideCard;
use App\Support\Cards\PrideMoments;
use App\Support\Cards\PrideVoice;
use App\Support\Cards\ShareCard;
use App\Support\Cards\ShareMoments;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * The pride pictures and the Wrapped story (plan "KI-Stimme der Liga", P4):
 * the player card and the tale of the tape as PNG (public, no session, the
 * locale in the URL, like the share cards), and a player's Season Wrapped as
 * a story to click through. Only what happened is drawn: a player with a
 * result, a block or a title; a published final with both sides known; a
 * season the player played in.
 */
class PrideController extends Controller
{
    public function playerCard(string $locale, string $npub, string $format): Response
    {
        App::setLocale($locale);
        $user = User::query()->where('npub', $npub)->firstOrFail();
        $card = PrideCard::player($user);
        abort_unless(PrideMoments::hasCard($card->facts), 404);

        return $this->png($card->png($format));
    }

    public function tapeCard(string $locale, int $tournament, string $format): Response
    {
        App::setLocale($locale);
        $tournament = Tournament::query()->findOrFail($tournament);
        $final = PrideMoments::upcomingFinal($tournament);
        abort_if($final === null, 404);

        return $this->png(PrideCard::tape($tournament, $final)->png($format));
    }

    /**
     * The story: page by page, each waits for a click (rule R17), the last
     * one with the season card and, for the player, the way to post it.
     */
    public function wrapped(string $season, string $npub): View
    {
        $season = Season::query()->where('slug', $season)->firstOrFail();
        $user = User::query()->where('npub', $npub)->firstOrFail();
        abort_unless(ShareMoments::hasWrapped($season, $user), 404);
        $facts = PrideMoments::wrapped($season, $user);

        return view('pages.season.wrapped', [
            'season' => $season,
            'player' => $user,
            'facts' => $facts,
            'card' => ShareCard::wrapped($season, $user),
            'line' => app(PrideVoice::class)->line('wrapped', $season->slug.'-'.$user->npub, self::wrappedVoiceFacts($facts), PrideMoments::wrappedLine($facts)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function wrappedVoiceFacts(array $facts): array
    {
        return [
            'what' => 'Schlusszeile eines Season-Rückblicks, ein bis zwei kurze Sätze',
            'season' => $facts['season'],
            'season_running' => $facts['live'],
            'blocks' => $facts['blocks'],
            'sats' => $facts['sats'],
            'wins' => $facts['wins'],
            'results' => $facts['results'],
            'tournament_wins' => $facts['tournaments'],
            'people' => [PrideVoice::person('player', $facts)],
        ];
    }

    private function png(string $bytes): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
