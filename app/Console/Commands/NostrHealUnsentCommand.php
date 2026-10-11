<?php

namespace App\Console\Commands;

use App\Models\NostrEvent;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\RelayRetries;
use App\Support\Tournaments\TournamentSignups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Sleep;

/**
 * Sends the regular events no configured relay ever accepted and that
 * `nostr:republish` no longer retries (older than RelayRetries::WINDOW_HOURS).
 *
 * Made for the game records of 2026-09-25/26: ESPORTS_RELAYS was empty in
 * production until 2026-09-28, so PublishNostrEvent ran against no relay
 * and wrote no delivery, and the one-day retry window had passed when the
 * relay list came back. Their game pages showed "retrying" for good.
 *
 * Replaceable and addressable events are left to `nostr:republish` (it
 * sends their current version at any age); gift wraps and tournament
 * consents are never sent. Prints the plan and changes nothing without
 * `--publish`. Idempotent: an event a relay accepted drops out of the plan.
 */
#[Signature('nostr:heal-unsent
    {--publish : Send the planned events; without it nothing is sent}
    {--kind=* : Only these kinds (e.g. --kind=64 for the game records)}
    {--limit=50 : At most this many events per run}
    {--pause=1 : Seconds between two events, to stay under the relays\' rate limits}')]
#[Description('Send old regular events no relay ever accepted (dry run by default)')]
class NostrHealUnsentCommand extends Command
{
    public function handle(RelayPublisher $publisher): int
    {
        $relays = config('esports.relays', []);

        if ($relays === []) {
            $this->error('No relay is configured (esports.relays): nothing to send to.');

            return self::FAILURE;
        }

        $events = $this->plan($relays);
        $this->line('Relays: '.implode(', ', $relays));
        $this->line('Regular events older than '.RelayRetries::WINDOW_HOURS.' h that no relay accepted: '.$events->count());

        foreach ($events->countBy('kind')->sortKeys() as $kind => $count) {
            $this->line("  kind {$kind}: {$count}");
        }

        foreach ($events as $event) {
            $this->line(sprintf('  #%d kind %d signed %s, %s, %d earlier attempt(s)', $event->id, $event->kind, $event->created_at?->toDateTimeString(), substr($event->event_id, 0, 12), $event->deliveries->sum('attempts')));
        }

        if (! $this->option('publish')) {
            $this->info('Dry run: nothing was sent. Run with --publish to send these events.');

            return self::SUCCESS;
        }

        $published = 0;

        foreach ($events->values() as $index => $event) {
            if ($index > 0) {
                Sleep::for((int) $this->option('pause'))->seconds();
            }

            $results = $publisher->publish($event, $relays);
            $accepted = count(array_filter($results, fn (array $result): bool => $result['accepted']));
            $published += $accepted > 0 ? 1 : 0;
            $this->line(sprintf('  #%d: accepted by %d of %d relays', $event->id, $accepted, count($relays)));
        }

        $this->info("Sent {$events->count()} event(s), {$published} now on at least one relay. Run again without --publish: those drop out of the plan.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $relays
     * @return Collection<int, NostrEvent>
     */
    private function plan(array $relays): Collection
    {
        $kinds = array_map('intval', (array) $this->option('kind'));

        return NostrEvent::query()
            ->where(fn ($query) => $query->whereNotNull('queued_at')->orWhereHas('deliveries'))
            ->whereNotIn('kind', [0, 3, 1059, TournamentSignups::CONSENT])
            // Replaceable and addressable kinds (RelayRetries::isReplaceable()) stay with nostr:republish.
            ->whereNotBetween('kind', [10000, 19999])
            ->whereNotBetween('kind', [30000, 39999])
            ->when($kinds !== [], fn ($query) => $query->whereIn('kind', $kinds))
            ->where('created_at', '<', now()->subHours(RelayRetries::WINDOW_HOURS))
            ->whereDoesntHave('deliveries', fn ($delivery) => $delivery->whereIn('relay', $relays)->where('accepted', true))
            ->with('deliveries')
            ->oldest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }
}
