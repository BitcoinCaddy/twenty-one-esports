<?php

use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Disputes, 1:1 from AdminDisputes.dc.html: series where a captain reported
 * a problem, no-shows, and reports nobody answered for
 * `esports.tournaments.unanswered_report_hours` (P18, SeriesMatch::openCase()),
 * and tournament series nobody reported by their report deadline (P18,
 * `overdue_at`), each with its history and how long it is open.
 * Admins never decide a case of their own clan (enforced on the case page);
 * "Hide matches of my own clans" only filters the list.
 */
new #[Title('Disputes')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    #[Url(except: 'open')]
    public string $tab = 'open';

    #[Url(except: '')]
    public string $mode = '';

    #[Url(except: '')]
    public string $clan = '';

    #[Url(except: 'oldest')]
    public string $sort = 'oldest';

    public bool $hideOwn = false;

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @param  Builder<SeriesMatch>  $query
     * @return Builder<SeriesMatch>
     */
    private function scope(Builder $query, string $tab): Builder
    {
        $user = auth()->user();
        $own = $user instanceof User ? $user->clanMember?->clan_id : null;
        $clan = Clan::query()->where('slug', $this->clan)->first();

        return $query
            ->when($tab === 'open', fn (Builder $query) => $query->openCase())
            ->when($tab === 'resolved', fn (Builder $query) => $query->where('status', SeriesStatus::Resolved))
            ->when($this->mode !== '', fn (Builder $query) => $query->where('mode', $this->mode))
            ->when($clan !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('challenger_lineup_id', $clan->lineups()->select('id'))->orWhereIn('challenged_lineup_id', $clan->lineups()->select('id'))))
            ->when($this->hideOwn && $own !== null, fn (Builder $query) => $query
                ->whereNotIn('challenger_lineup_id', Lineup::query()->where('clan_id', $own)->select('id'))
                ->whereNotIn('challenged_lineup_id', Lineup::query()->where('clan_id', $own)->select('id')));
    }

    /**
     * @return Collection<int, SeriesMatch>
     */
    #[Computed]
    public function cases(): Collection
    {
        return $this->scope(SeriesMatch::query()->with(['reports.user', 'latestReport', 'challengerLineup.clan', 'challengedLineup.clan']), $this->tab)
            ->orderBy('updated_at', $this->sort === 'newest' ? 'desc' : 'asc')
            ->get();
    }

    /**
     * @return array{open: int, resolved: int}
     */
    #[Computed]
    public function counts(): array
    {
        return ['open' => $this->scope(SeriesMatch::query(), 'open')->count(), 'resolved' => $this->scope(SeriesMatch::query(), 'resolved')->count()];
    }

    /**
     * @return Collection<int, Clan>
     */
    #[Computed]
    public function clans(): Collection
    {
        return Clan::query()->orderBy('name')->get();
    }

    /**
     * The history chips of a case (Report / Dispute / New report / Decision).
     *
     * @return list<array{kind: string, title: string, who: string, note: string}>
     */
    public function history(SeriesMatch $match): array
    {
        $chips = [];

        foreach ($match->reports as $index => $report) {
            $wins = $report->score();
            $chips[] = ['kind' => 'report', 'title' => $index === 0 ? __('Report') : __('New report'), 'who' => $match->sideName($report->side), 'note' => $wins[$report->side].' : '.$wins[SeriesMatch::otherSide($report->side)].', '.trans_choice(':count game|:count games', count($report->games))];

            if ($report->status === ReportStatus::Disputed || ($report->status === ReportStatus::Superseded && $report->response_reason !== null)) {
                $chips[] = ['kind' => 'dispute', 'title' => __('Dispute'), 'who' => $match->sideName(SeriesMatch::otherSide($report->side)), 'note' => '“'.Str::limit((string) $report->response_reason, 24).'”'];
            }
        }

        if ($match->noshow_reported_at !== null) {
            $chips[] = ['kind' => 'noshow', 'title' => __('No-show reported'), 'who' => $match->sideName((string) $match->noshow_side), 'note' => $match->noshow_reported_at->format('H:i').', '.__('match room')];
        }

        $chips[] = $match->status === SeriesStatus::Resolved
            ? ['kind' => 'decided', 'title' => __('Decided'), 'who' => $match->resolution?->label() ?? '', 'note' => '']
            : ['kind' => 'open', 'title' => match (true) {
                $match->isUnansweredReport() => __('Unanswered report'),
                $match->status === SeriesStatus::Accepted && $match->overdue_at !== null && $match->noshow_reported_at === null => __('Report overdue'),
                default => __('Decision open'),
            }, 'who' => __('admin to decide'), 'note' => $match->noshow_reported_at !== null && $match->reports->isEmpty() ? __('Forfeit possible') : __('Who played: recorded')];

        return $chips;
    }
}; ?>

@php
    $kindTag = ['report' => '', 'dispute' => 'is-bad', 'noshow' => 'is-btc'];
    $kindLabel = ['report' => __('Report'), 'dispute' => __('Dispute'), 'noshow' => __('No-show reported')];
    $modes = ['' => __('All modes'), '3v3' => '3v3', '2v2' => '2v2', '1v1' => '1v1'];
    $clans = ['' => __('All clans')] + $this->clans->mapWithKeys(fn ($option): array => [$option->slug => $option->name])->all();
    $sorts = ['oldest' => __('Oldest first'), 'newest' => __('Newest first')];
@endphp

{{--
    AdminDisputes.dc.html: the title with its lead beside it, the status chips (Open N, Decided N) and the
    filters of the page in one row, then the cases as one table: match, kind, pairing, reason, open for, the
    action. The four decision kinds stay one click away (a disclosure), not as cards on the page.
--}}
<x-admin.page active="disputes" :title="__('Disputes')" :lead="__('Matches where a captain disputed the result')" data-test="admin-disputes">
    <x-slot:readout>
        <span class="flex items-baseline gap-2"><span class="rv-m">{{ __('open') }}</span><span @class(['rv-num text-base', 'text-btc-hi' => $this->counts['open'] > 0, 'text-ink' => $this->counts['open'] === 0]) data-test="readout-value">{{ $this->counts['open'] }}</span></span>
    </x-slot:readout>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
        <span class="rv-m">{{ __('Status') }}</span>
        <div role="tablist" class="flex flex-wrap gap-2">
            @foreach (['open' => __('Open'), 'resolved' => __('Resolved')] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @if ($tab === $key) aria-current="true" @endif class="rv-chip">{{ $label }} <span class="rv-m">{{ $this->counts[$key] }}</span></button>
            @endforeach
        </div>
        <span class="grow"></span>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select-menu model="mode" live :value="$this->mode" :options="$modes" :label="__('Mode')" width="140px" data-test="filter-mode" />
            <x-ui.select-menu model="clan" live :value="$this->clan" :options="$clans" :label="__('Clan')" width="180px" data-test="filter-clan" />
            <x-ui.select-menu model="sort" live :value="$this->sort" :options="$sorts" :label="__('Sort')" width="170px" data-test="filter-sort" />
            <x-ui.check model="hideOwn" live :checked="$this->hideOwn" data-test="hide-own">{{ __('Hide matches of my own clans') }}</x-ui.check>
        </div>
    </div>

    <div class="rv-card relative overflow-x-auto">
        @if ($this->cases->isEmpty())
            <div class="p-4 lg:p-6"><x-admin.empty :text="$tab === 'open' ? __('No open disputes. Every result is settled.') : __('No resolved cases yet.')" /></div>
        @else
            <table class="rv-tb min-w-[760px]">
                <thead><tr><th>{{ __('Match') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Pairing') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Open for') }}</th><th class="is-r"><span class="sr-only">{{ __('Action') }}</span></th></tr></thead>
                <tbody>
                    @foreach ($this->cases as $case)
                        @php
                            $history = $this->history($case);
                            $last = collect($history)->reject(fn (array $chip): bool => in_array($chip['kind'], ['open', 'decided'], true))->last();
                            $hours = (int) ($case->updated_at?->diffInHours(now()) ?? 0);
                        @endphp
                        <tr wire:key="c-{{ $case->id }}" @class(['is-featured' => $tab === 'open']) data-test="dispute-row">
                            <td class="whitespace-nowrap"><b class="text-btc-hi">{{ $case->label() }}</b> <span class="rv-m">{{ $case->mode }}, BO{{ $case->best_of }}</span></td>
                            <td><span class="rv-tag {{ $last ? $kindTag[$last['kind']] : '' }}" @if ($last) title="{{ $kindLabel[$last['kind']] }}" @endif>{{ collect($history)->last()['title'] ?? '' }}</span></td>
                            <td class="text-[13px]"><span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1"><x-clan-tag :clan="$case->sideClan('challenger')" :tag="$case->challenger_tag" size="sm" /><b>{{ $case->sideName('challenger') }}</b><span class="rv-m">{{ __('vs') }}</span><x-clan-tag :clan="$case->sideClan('challenged')" :tag="$case->challenged_tag" size="sm" /><b>{{ $case->sideName('challenged') }}</b></span></td>
                            <td class="max-w-[28ch] text-[13px] text-ink-2 [overflow-wrap:anywhere]">{{ $last['note'] ?? '' }}</td>
                            <td class="whitespace-nowrap text-[13px]"><b @class(['text-loss' => $hours >= 48, 'text-ink' => $hours < 48])>{{ $case->updated_at?->diffForHumans(null, true) }}</b>@if ($hours >= 48) <span class="rv-m">{{ __('overdue') }}</span>@endif</td>
                            <td class="is-r"><a href="{{ route('admin.disputes.show', $case) }}" class="{{ $tab === 'open' ? 'rv-b' : 'rv-b2' }} is-sm" data-test="review">{{ $tab === 'open' ? __('Review') : __('View') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <details class="group rv-card px-4 lg:px-6" data-test="dispute-kinds">
        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 text-[13px] text-ink-2 hover:text-ink [&::-webkit-details-marker]:hidden">
            {{ __('How a case is decided') }}<x-icon name="chevron-down" :size="14" class="transition-transform duration-150 group-open:rotate-180 motion-reduce:transition-none" />
        </summary>
        <div class="grid grid-cols-1 gap-4 pb-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                [__('Accept a report'), __('One of the two reports stands. The league record says "decided by admin".')],
                [__('Enter the result'), __('You enter each game\'s goals, or its winner in a game without goals, after checking the evidence.')],
                [__('Forfeit'), __('One side didn\'t show up, reported with the no-show button from 15 min after the start while no game was entered. The other side wins.')],
                [__('Void'), __('The match doesn\'t count, nobody wins. For breakdowns that are nobody\'s fault.')],
            ] as [$title, $text])
                <div class="flex flex-col gap-1"><b class="text-[13px]">{{ $title }}</b><p class="m-0 text-[13px] leading-normal text-ink-2">{{ $text }}</p></div>
            @endforeach
            <p class="m-0 max-w-[70ch] text-xs leading-normal text-ink-3 sm:col-span-2 lg:col-span-4">{{ __('Every decision is saved with your public reason. Casual matches get no league record; rated ones get it from Block 0. You can\'t decide a case involving your own clan.') }} {{ __('Open cases older than 48 h are marked red.') }}</p>
        </div>
    </details>
</x-admin.page>
