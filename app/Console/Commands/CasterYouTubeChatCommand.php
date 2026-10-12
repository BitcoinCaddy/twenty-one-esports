<?php

namespace App\Console\Commands;

use App\Support\Caster\YouTubeChat;
use App\Support\Caster\YouTubeChatSummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The YouTube restream's live chat for the caster (radio P13): reads the
 * chat when a read is due (YouTubeChat keeps the interval and the quota),
 * then queues one summary when it is due (YouTubeChatSummary). Every minute
 * while the commentary and `twentyone.stream.youtube.chat` are on.
 */
#[Signature('caster:youtube-chat')]
#[Description('Read the YouTube live chat and queue a spoken summary of it')]
class CasterYouTubeChatCommand extends Command
{
    public function handle(YouTubeChat $chat, YouTubeChatSummary $summary): int
    {
        if (! YouTubeChat::enabled()) {
            $this->line('YouTube chat is off (YOUTUBE_CHAT_ENABLED, YOUTUBE_API_KEY, YOUTUBE_CHAT_VIDEO_ID or YOUTUBE_CHAT_CHANNEL_ID).');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $stored = $chat->poll($now);
        $queued = $summary->summarise($now);

        $this->line("YouTube chat: {$stored} new message(s), {$queued} summary queued.");

        return self::SUCCESS;
    }
}
