<?php

namespace App\Console\Commands;

use App\Support\Caster\CasterVoice;
use App\Support\Caster\NewsFeeds;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reads the headlines of the TWENTY ONE TV news segment from the public
 * feeds in `tv.commentary.news.feeds` (NewsFeeds) and prunes the old ones.
 * Scheduled every 15 minutes while `tv.commentary.enabled` and
 * `tv.commentary.news.enabled`; off, it does nothing.
 */
#[Signature('caster:news')]
#[Description('Read the headlines for the TV news segment from public RSS/Atom feeds')]
class CasterNewsCommand extends Command
{
    public function handle(NewsFeeds $feeds): int
    {
        if (! CasterVoice::enabled() || config('tv.commentary.news.enabled') !== true) {
            $this->line('The TV news segment is off (TV_COMMENTARY, TV_COMMENTARY_NEWS).');

            return self::SUCCESS;
        }

        $counts = $feeds->fetch(CarbonImmutable::now());
        $this->line(sprintf('%d feed(s), %d failed; %d new headline(s), %d filtered, %d pruned.', $counts['feeds'], $counts['failed'], $counts['stored'], $counts['filtered'], $counts['pruned']));

        return self::SUCCESS;
    }
}
