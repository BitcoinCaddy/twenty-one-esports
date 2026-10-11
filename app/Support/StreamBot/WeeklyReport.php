<?php

namespace App\Support\StreamBot;

use App\Games\ScoreMetric;
use App\Models\Tournament;
use App\Support\Scores\ScoreStanding;
use App\Support\TwentyOne\Stream\BlockfillSlides;
use App\Support\Voice\LeagueVoice;
use App\Support\Voice\SpamGate;
use Carbon\CarbonImmutable;

/**
 * The weekly report of a finished league week (plan "KI-Stimme der Liga",
 * P3), written by the league voice (channel `weekly`, moment
 * `weekly_recap`) for the winner note of WeeklyBoardNotes (Blockfill, TMNF).
 *
 * Facts from the week's board only (ScoreRuns::standings(), the same rows
 * the template's podium is read from): how many players placed, the
 * winner's time, the top 3 with their times, the gap between first and
 * second, the link. The top 3 are mentionable by their account's Nostr key
 * (role tokens, VoiceMentions), as far as the spam gate allows.
 *
 * line() returns null whenever the template should go out instead: the
 * channel is off (default), the voice failed or its guards rejected the
 * text, the copy rules (StreamBotCopy::violations) or the spam gate refuse
 * it, or nobody placed. The app adds the link line when the voice left it
 * out.
 */
final class WeeklyReport
{
    public const MOMENT = 'weekly_recap';

    public const CHANNEL = 'weekly';

    /** The places the report names. */
    public const PODIUM = 3;

    private const NAME_LENGTH = 40;

    private const ROLES = ['winner', 'second', 'third'];

    public function __construct(
        private readonly LeagueVoice $voice,
        private readonly SpamGate $spam,
    ) {}

    /**
     * The report's text and `p` tags, null for the template.
     *
     * @param  list<ScoreStanding>  $standings
     * @return array{content: string, tags: list<list<string>>}|null
     */
    public function line(Tournament $week, array $standings, string $game, string $url): ?array
    {
        $now = CarbonImmutable::now();
        $facts = $this->facts($week, $standings, $game, $url, $now);

        if ($facts === null) {
            return null;
        }

        $line = $this->voice->say(self::MOMENT, $facts, self::CHANNEL);

        if ($line === null) {
            return null;
        }

        $content = $url === '' || str_contains($line->text, $url) ? $line->text : $line->text."\n👉 ".$url;

        if (StreamBotCopy::violations($content, $line->tags) !== [] || $this->spam->textSkip(SpamGate::PROFILE, $line->text, $now, $line->canonId) !== null) {
            return null;
        }

        return ['content' => $content, 'tags' => $line->tags];
    }

    /**
     * The facts the voice gets, null when nobody placed.
     *
     * @param  list<ScoreStanding>  $standings
     * @return array<string, mixed>|null
     */
    public function facts(Tournament $week, array $standings, string $game, string $url, CarbonImmutable $now): ?array
    {
        $placed = array_values(array_filter($standings, fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null));

        if ($placed === []) {
            return null;
        }

        $metric = ScoreMetric::time();
        $podium = [];
        $people = [];

        foreach (array_slice($placed, 0, self::PODIUM) as $index => $row) {
            $name = StreamBotCopy::clean($row->participant->name, self::NAME_LENGTH);
            $pubkey = (string) $row->participant->user?->pubkey;
            $pubkey = preg_match('/^[0-9a-f]{64}$/', $pubkey) === 1 ? $pubkey : null;
            $podium[] = ['place' => $row->place, 'name' => $name, 'time' => $metric->format((int) $row->value)];
            $people[] = [
                'role' => self::ROLES[$index],
                'name' => $name,
                'pubkey' => $pubkey,
                'mentionable' => $pubkey !== null && $this->spam->mentionAllowed(SpamGate::PROFILE, $pubkey, self::MOMENT, $now),
            ];
        }

        $gap = isset($placed[1]) ? (int) $placed[1]->value - (int) $placed[0]->value : null;

        return [
            'week' => StreamBotCopy::clean($week->title(), 60),
            'game' => $game,
            'players_placed' => count($placed),
            'winner_time' => $metric->format((int) $placed[0]->value),
            'podium' => $podium,
            'gap_first_to_second' => $gap === null || $gap <= 0 ? null : BlockfillSlides::seconds($gap),
            'url' => $url,
            'people' => $people,
        ];
    }
}
