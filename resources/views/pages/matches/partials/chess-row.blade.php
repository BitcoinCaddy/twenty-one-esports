@php
    /** @var \App\Models\ChessGame $chessGame */
    $live = $chessGame->status === \App\Enums\ChessGameStatus::Active;
    $aborted = $chessGame->status === \App\Enums\ChessGameStatus::Aborted;
    $winner = match ($chessGame->result) { '1-0' => 'w', '0-1' => 'b', default => null };
    $chip = \App\Support\Series\SeriesPresenter::chipFor($live ? 'live' : ($aborted ? 'closed' : 'done'), $live ? __('live') : ($aborted ? __('aborted') : __('done')));
    $format = $chessGame->isCorrespondence()
        ? __('Daily chess')
        : __(':mode :minutes+:seconds', ['mode' => \App\Support\Chess\ChessModes::short($chessGame->mode), 'minutes' => intdiv($chessGame->initial_ms, 60_000), 'seconds' => intdiv($chessGame->increment_ms, 1_000)]);
    $at = $chessGame->ended_at ?? $chessGame->created_at;
@endphp

{{-- A chess game in the Mempool table (x-mempool.row, template T4): each side with its face (P53). --}}
<x-mempool.row :href="route('games.show', $chessGame)" wire:key="c-{{ $chessGame->id }}" data-test="chess-row" data-mode="{{ $chessGame->mode }}"
               :number="$chessGame->number()" :art="$chessGame->rated ? __('rated') : __('casual')" :format="$format" :chip="$chip" :when="$at?->diffForHumans()"
               :score="$chessGame->result ? str_replace(['1/2', '-'], ['½', '–'], $chessGame->result) : '–'">
    <x-slot:sides>
        <span class="flex min-w-0 items-center gap-2">
            @if ($chessGame->white?->exists)<x-avatar :user="$chessGame->white" :size="20" class="rounded-xs max-lg:hidden" data-test="chess-row-face" />@endif
            <span @class(['truncate', 'font-bold' => $winner === 'w', 'text-ink-2' => $winner === 'b'])>{{ $chessGame->white?->displayName() }}</span>
        </span>
        <span class="text-ink-3">vs</span>
        <span class="flex min-w-0 items-center gap-2">
            @if ($chessGame->black?->exists)<x-avatar :user="$chessGame->black" :size="20" class="rounded-xs max-lg:hidden" data-test="chess-row-face" />@endif
            <span @class(['truncate', 'font-bold' => $winner === 'b', 'text-ink-2' => $winner === 'w'])>{{ $chessGame->black?->displayName() }}</span>
        </span>
    </x-slot:sides>
</x-mempool.row>
