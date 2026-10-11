<?php

namespace App\Jobs;

use App\Support\Memes\BotBanter;
use App\Support\Voice\LeagueVoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the league voice for one more line of a bot archetype's occasion (plan "KI-Stimme der Liga", P5,
 * BotBanter::write()), so no game page waits for the model. Sent by BotBanter at most once per archetype in ten
 * minutes and only while the voice's `bot_chat` channel is on; a refused or failed line is simply not kept.
 */
class WriteBotBanter implements ShouldQueue
{
    use Queueable;

    /** One model call (timeout `esports.voice.timeout_seconds`) and a cache write. */
    public int $timeout = 40;

    /** LeagueVoice never throws and the page has its templates: no retry. */
    public int $tries = 1;

    public function __construct(public string $archetype, public string $occasion) {}

    public function handle(BotBanter $banter, LeagueVoice $voice): void
    {
        $banter->write($this->archetype, $this->occasion, $voice);
    }
}
