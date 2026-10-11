<?php

use App\Models\Tournament;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\TournamentScheduler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * AdminTournaments (AdminTournaments.dc.html, P8a): the tournaments, newest
 * first — all of them for an admin, their own for an organizer. The
 * organizers themselves have their own page since P17. Each row links its
 * management actions under its name (<x-tournaments.manage-actions>:
 * publish for a draft, first and orange, then prize pool, edit, payouts,
 * each behind its own gate).
 */
new #[Title('Tournaments')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    /** Which tournaments the list shows: the ones people set up (default), the casual cups the league opens, or all. */
    #[Url(except: 'manual')]
    public string $kind = 'manual';

    public function mount(): void
    {
        Gate::authorize('create-tournaments');
    }

    /**
     * The tournament clock's heartbeat (P18): a warning when it is stale.
     *
     * @return array{last_run_at: \Carbon\CarbonImmutable|null, stale: bool}
     */
    #[Computed]
    public function scheduler(): array
    {
        return TournamentScheduler::health();
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return Gate::allows('admin');
    }

    /**
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        // Blockfill's weekly boards (plan "Blockfill", P6) are opened by the league, nobody manages them here.
        return $this->listed()
            ->with('creator')
            ->latest('id')
            ->limit(200)
            ->get();
    }

    /**
     * How many tournaments each filter shows.
     *
     * @return array{manual: int, casual: int, all: int}
     */
    #[Computed]
    public function counts(): array
    {
        $kind = $this->kind;
        $counts = [];
        foreach (['manual', 'casual', 'all'] as $option) {
            $this->kind = $option;
            $counts[$option] = $this->listed()->count();
        }
        $this->kind = $kind;

        return $counts;
    }

    /**
     * The list's query without order and limit: casual cups only under "casual" or "all".
     *
     * @return \Illuminate\Database\Eloquent\Builder<Tournament>
     */
    private function listed(): \Illuminate\Database\Eloquent\Builder
    {
        return Tournament::query()->exceptLeagueWeeks()
            ->when(! $this->isAdmin, fn ($query) => $query->where('created_by_id', auth()->id()))
            ->when(! in_array($this->kind, ['casual', 'all'], true), fn ($query) => $query->special())
            ->when($this->kind === 'casual', fn ($query) => $query->casualCup());
    }
}; ?>

@php
    // The readout (AdminTournaments.dc.html): how many run now (live), how many are open for sign-up, how many in all.
    $byStatus = Tournament::query()->exceptLeagueWeeks()
        ->when(! $this->isAdmin, fn ($query) => $query->where('created_by_id', auth()->id()))
        ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
    $running = (int) ($byStatus[\App\Enums\TournamentStatus::Running->value] ?? 0) + (int) ($byStatus[\App\Enums\TournamentStatus::Drawing->value] ?? 0);
    $tone = fn (\App\Enums\TournamentStatus $status): string => match ($status) {
        \App\Enums\TournamentStatus::Signup => 'is-btc',
        \App\Enums\TournamentStatus::Running, \App\Enums\TournamentStatus::Drawing => 'is-ok',
        \App\Enums\TournamentStatus::Cancelled => 'is-bad',
        default => '',
    };
@endphp

<x-admin.page active="tournaments" :title="__('Tournaments')" :lead="__('Tournament matches are normal rated challenges and count for Elo. They never mine season blocks; every tournament has its own prize pool.')" flash-test="tournaments-notice" data-test="admin-tournaments">
    <x-slot:readout>
        @if ($running > 0)<span class="rv-live" style="height: 20px"><i></i>{{ trans_choice(':count running|:count running', $running) }}</span>@endif
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('in sign-up') }}</span><span class="rv-num text-base text-ink">{{ (int) ($byStatus[\App\Enums\TournamentStatus::Signup->value] ?? 0) }}</span></span>
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('in all') }}</span><span class="rv-num text-base text-ink" data-test="readout-value">{{ (int) $byStatus->sum() }}</span></span>
    </x-slot:readout>
    <x-slot:actions>
        @if ($this->isAdmin)
            <a href="{{ route('admin.organizers') }}" class="rv-b2" data-test="to-organizers">{{ __('Organizers') }}</a>
        @endif
        <a href="{{ route('admin.tournaments.create') }}" class="rv-b" data-test="new-tournament">{{ __('New tournament') }}</a>
    </x-slot:actions>

    @if ($this->scheduler['stale'])
        <x-admin.flash tone="error" data-test="scheduler-stale">
            <b>{{ __('The tournament clock is not running.') }}</b>
            {{ $this->scheduler['last_run_at'] === null
                ? __('It has not run yet: no sign-up closes, no draw runs and no deadline is applied until the scheduler runs `tournaments:tick` every minute.')
                : __('Its last run was :ago: no sign-up closes, no draw runs and no deadline is applied until the scheduler runs `tournaments:tick` every minute again.', ['ago' => $this->scheduler['last_run_at']->diffForHumans()]) }}
        </x-admin.flash>
    @endif

    {{-- The casual cups the league opens on its own stay out of the way unless asked for (chips, rule R8). --}}
    <div role="tablist" class="flex flex-wrap gap-2" data-test="tournament-kind">
        @foreach (['all' => __('All'), 'manual' => __('Set up by people'), 'casual' => __('Casual cups')] as $key => $label)
            <button type="button" role="tab" wire:click="$set('kind', '{{ $key }}')" aria-selected="{{ $kind === $key ? 'true' : 'false' }}" @if ($kind === $key) aria-current="true" @endif data-test="kind-{{ $key }}"
                    class="rv-chip">{{ $label }} <span class="rv-m">{{ $this->counts[$key] }}</span></button>
        @endforeach
    </div>

    @if ($this->tournaments->isEmpty())
        <x-admin.panel :title="$this->isAdmin ? __('All tournaments') : __('Your tournaments')" id="list-h">
            <x-admin.empty :text="__('No tournaments yet. Create the first one as a draft; players see it once you publish.')">
                <a href="{{ route('admin.tournaments.create') }}" class="rv-b2">{{ __('New tournament') }}</a>
            </x-admin.empty>
        </x-admin.panel>
    @else
        <div class="rv-card relative overflow-x-auto" aria-label="{{ $this->isAdmin ? __('All tournaments') : __('Your tournaments') }}" role="region">
            <table class="rv-tb min-w-[760px]">
                <thead>
                    <tr>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Tournament') }}</th>
                        <th>{{ __('Game, mode') }}</th>
                        <th>{{ __('Starts') }}</th>
                        <th>{{ __('Format') }}</th>
                        <th class="is-r">{{ __('Entries') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->tournaments as $tournament)
                        <tr class="align-top hover:bg-row-hover" wire:key="t-{{ $tournament->id }}" data-test="tournament-row">
                            <td><span class="rv-tag {{ $tone($tournament->status) }}">{{ $tournament->status->label() }}</span></td>
                            <td>
                                <a href="{{ route('tournaments.show', $tournament) }}" class="font-bold text-ink hover:text-btc-hi">{{ $tournament->name }}</a>
                                <span class="block rv-m">{{ $tournament->cup_series !== null ? __('Casual cup') : __('by :name', ['name' => $tournament->creator?->displayName() ?? __('a former player')]) }}</span>
                                {{-- Prize pool, Edit and Payouts as buttons in the name column, reachable on a phone without scrolling the table sideways. --}}
                                <x-tournaments.manage-actions :tournament="$tournament" class="pt-2" />
                            </td>
                            <td class="text-[13px]">{{ \App\Support\Tournaments\Lobbies::gameLine($tournament) }}</td>
                            <td class="text-[13px] whitespace-nowrap"><x-league-time :at="$tournament->starts_at" /></td>
                            <td class="text-[13px]">{{ \App\Support\Tournaments\Lobbies::formatLabel($tournament) }}
                                <span class="block rv-m">{{ __('about :duration', ['duration' => Estimator::format($tournament->plannedDuration(), $tournament->profile())]) }}</span>
                            </td>
                            <td class="is-r tabular-nums">{{ $tournament->capacity }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <span class="rv-m">{{ __('newest first') }}</span>
    @endif
</x-admin.page>
