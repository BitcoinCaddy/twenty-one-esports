@php
    /** @var \App\Models\StackerRun|\App\Models\ScoreRun $run */
    // A highscore attempt (App\Support\Matches\ScoreAttempts) in the Mempool table: one player (face and name, nothing
    // else of theirs), the checked value or none while it waits, and a link to the game's leaderboard.
    $waiting = \App\Support\Matches\ScoreAttempts::isWaiting($run);
    $slug = \App\Support\Matches\ScoreAttempts::game($run);
    $chip = \App\Support\Series\SeriesPresenter::chipFor($waiting ? 'to_confirm' : 'done', $waiting ? __('to confirm') : __('done'));
    $at = \App\Support\Matches\ScoreAttempts::at($run);
    $value = \App\Support\Matches\ScoreAttempts::value($run);
@endphp

{{-- No match number: an attempt is one player against the clock, so Art says what it is. --}}
<x-mempool.row :href="$href" wire:key="{{ \App\Support\Matches\ScoreAttempts::key($run) }}" data-test="score-row" data-game="{{ $slug }}" data-state="{{ $waiting ? 'waiting' : 'done' }}"
               :art="__('highscore')" :format="\App\Support\GameNames::game($slug).', '.\App\Support\Matches\ScoreAttempts::mode($run)" :chip="$chip"
               :check="$waiting ? ['label' => __('unconfirmed'), 'tone' => 'btc'] : ['label' => __('confirmed'), 'tone' => 'ok']"
               :when="$at?->diffForHumans()" :score="$value ?? '–'" score-test="score-row-value">
    <x-slot:sides>
        <span class="flex min-w-0 items-center gap-2">
            @if ($run->user?->exists)<x-avatar :user="$run->user" :size="20" class="rounded-xs max-lg:hidden" data-test="score-row-face" />@endif
            <span @class(['truncate', 'font-bold' => ! $waiting]) data-test="score-row-name">{{ $run->user?->displayName() ?? __('Deleted player') }}</span>
        </span>
    </x-slot:sides>
</x-mempool.row>
