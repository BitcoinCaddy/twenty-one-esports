@php
    /** @var \App\Models\HyperMatch $hyperMatch */
    // A Hyperbitcoinization row (plan "Hyperbitcoinization", P6): one row per match a player won, with the winner alone
    // (user 2026-10-09: no seats beyond the winner, no round). The match page is full-screen, so the row opens a new tab.
    // Only listed while its route is registered (MempoolStrip::hyper()), so route('hyper.match') always exists.
    $live = $hyperMatch->status === \App\Enums\HyperMatchStatus::Active;
    $aborted = $hyperMatch->status === \App\Enums\HyperMatchStatus::Aborted;
    $seats = \App\Support\Hyper\HyperNames::ordered($hyperMatch);
    $first = collect($seats)->firstWhere('place', 1);
    $chip = \App\Support\Series\SeriesPresenter::chipFor($live ? 'live' : ($aborted ? 'closed' : 'done'), $live ? __('live') : ($aborted ? __('aborted') : __('done')));
    $format = ($hyperMatch->isCorrespondence() ? __('Correspondence') : __('Live')).' · '.($hyperMatch->isTeamMatch() ? intdiv(count($seats), 2).'v'.intdiv(count($seats), 2) : trans_choice(':count seat|:count seats', count($seats)));
    $winner = \App\Support\Hyper\HyperNames::winner($hyperMatch);
    $at = $hyperMatch->ended_at ?? $hyperMatch->created_at;
@endphp

<x-mempool.row :href="route('hyper.match', $hyperMatch)" blank wire:key="h-{{ $hyperMatch->id }}" data-test="hyper-row" data-game="{{ \App\Games\Hyperbitcoinization::SLUG }}"
               :art="$hyperMatch->rated ? __('rated') : __('casual')" :format="\App\Support\GameNames::game(\App\Games\Hyperbitcoinization::SLUG).', '.$format" :chip="$chip"
               :when="$at?->diffForHumans()" :score="__('Winner')">
    <x-slot:sides>
        @if ($first !== null)
            <span class="flex min-w-0 items-center gap-2">
                @if (! $hyperMatch->isTeamMatch() && $first->user?->exists)<x-avatar :user="$first->user" :size="20" class="rounded-xs max-lg:hidden" data-test="hyper-row-face" />@endif
                <span class="truncate font-bold">{{ $winner ?? \App\Support\Hyper\HyperNames::seat($first) }}</span>
            </span>
        @endif
    </x-slot:sides>
</x-mempool.row>
