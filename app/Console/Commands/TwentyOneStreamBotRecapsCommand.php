<?php

namespace App\Console\Commands;

use App\Support\StreamBot\ProfileNotes;
use App\Support\StreamBot\TournamentRecapNotes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The stream bot's tournament recaps on its own profile
 * (App\Support\StreamBot\TournamentRecapNotes, plan "KI-Stimme der Liga",
 * P3). Scheduled every five minutes; each run posts at most
 * `esports.recap.notes.per_run` notes. Off unless ESPORTS_STREAM_BOT_RECAP_NOTES,
 * ESPORTS_STREAM_BOT_ENABLED and ESPORTS_STREAM_BOT_NSEC are set.
 *
 * `--dry-run` prints the notes the next run would post (the league voice is
 * asked when its `recap` channel is on); it claims, signs, sends and stores
 * nothing.
 */
#[Signature('twentyone:stream-bot:recaps
    {--dry-run : Print the notes the next run would post; claim, sign, send and store nothing}')]
#[Description('Post a kind-1 recap note on the stream bot profile for each newly finished tournament')]
class TwentyOneStreamBotRecapsCommand extends Command
{
    public function handle(TournamentRecapNotes $notes): int
    {
        $now = CarbonImmutable::now();

        if (! $this->option('dry-run')) {
            $this->line($notes->run($now));

            return self::SUCCESS;
        }

        $due = $notes->due($now);
        $variant = ProfileNotes::nextVariant(TournamentRecapNotes::NOTE_TYPE, count(TournamentRecapNotes::TEMPLATES));

        foreach ($due as $tournament) {
            $this->line('--- tournament '.$tournament->id);
            $this->line($notes->compose($tournament, $variant, $now)['content'] ?? 'no recap');
            $this->newLine();
        }

        $this->info('Dry run: '.count($due).' note(s), nothing was signed, sent or stored.');

        return self::SUCCESS;
    }
}
