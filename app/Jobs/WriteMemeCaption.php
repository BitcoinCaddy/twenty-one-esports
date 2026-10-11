<?php

namespace App\Jobs;

use App\Models\PongMatch;
use App\Support\Memes\MemeShares;
use App\Support\Voice\LeagueVoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes the league voice's line for the meme card of a finished Proof of Pong match (plan "KI-Stimme der Liga", P5,
 * MemeShares::write()), so no page waits for the model. Sent by MemeShares at most once per match in ten minutes and
 * only while the voice's `meme` channel is on; whatever fails leaves the card on its template line.
 */
class WriteMemeCaption implements ShouldQueue
{
    use Queueable;

    /** One model call (timeout `esports.voice.timeout_seconds`) and a cache write. */
    public int $timeout = 40;

    /** LeagueVoice never throws and the card has its template: no retry. */
    public int $tries = 1;

    public function __construct(public int $matchId) {}

    public function handle(MemeShares $memes, LeagueVoice $voice): void
    {
        $match = PongMatch::query()->find($this->matchId);

        if ($match !== null) {
            $memes->write($match, $voice);
        }
    }
}
