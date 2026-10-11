@php
    /** @var \App\Models\SeriesMatch $match */
    // A series (Rocket League, EA Sports FC, AoE2, a chess team match) in the Mempool table: the clan tags at the sides.
    $chip = \App\Support\Series\SeriesPresenter::chip($match);
    $score = \App\Support\Series\SeriesPresenter::score($match);
    $format = \App\Support\GameNames::game($match->game).', '.\App\Support\Series\SeriesPresenter::format($match);
@endphp

<x-mempool.row :href="route('matches.show', $match)" wire:key="m-{{ $match->id }}" data-test="match-row" data-game="{{ $match->game }}"
               :number="$match->label()" :art="$match->rated ? __('rated') : __('casual')" :format="$format" :chip="$chip"
               :when="\App\Support\Series\SeriesPresenter::when($match, $viewer)" :score="$score['text']" :score-sub="$score['sub'] !== '' ? $score['sub'] : null">
    <x-slot:sides>
        <span class="flex min-w-0 items-center gap-2">
            <x-clan-tag :clan="$match->sideClan('challenger')" :tag="$match->challenger_tag" size="sm" compact class="max-lg:hidden" />
            <span @class(['truncate', 'font-bold' => $match->winner === 'challenger', 'text-ink-2' => $match->winner === 'challenged']) data-test="match-side-name">{{ $match->challenger_name }}</span>
        </span>
        <span class="text-ink-3">vs</span>
        <span class="flex min-w-0 items-center gap-2">
            <x-clan-tag :clan="$match->sideClan('challenged')" :tag="$match->challenged_tag" size="sm" compact class="max-lg:hidden" />
            <span @class(['truncate', 'font-bold' => $match->winner === 'challenged', 'text-ink-2' => $match->winner === 'challenger']) data-test="match-side-name">{{ $match->challenged_name }}</span>
        </span>
    </x-slot:sides>
</x-mempool.row>
