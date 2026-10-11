@php
    /** @var \App\Models\PongMatch $pongMatch */
    // A Proof of Pong row (plan "Proof of Pong", P2): one row per finished live match, the winner alone with the score
    // (winner's points first), as the league lists every multi-side game (user 2026-10-09). The match page is
    // full-screen, so the row opens a new tab. Only listed while its route is registered (MempoolStrip::pong()).
    $winner = $pongMatch->winner;
    [$left, $right] = $pongMatch->score();
    $score = $pongMatch->winner_id === $pongMatch->right_id ? $right.':'.$left : $left.':'.$right;
    $chip = \App\Support\Series\SeriesPresenter::chipFor('done', __('done'));
@endphp

{{-- No league match number: Proof of Pong's Elo is its own, so the row says "casual" like every match that mines nothing. --}}
<x-mempool.row :href="route('pong.match', $pongMatch)" blank wire:key="p-{{ $pongMatch->id }}" data-test="pong-row" data-game="{{ \App\Games\ProofOfPong::SLUG }}"
               :art="__('casual')" :format="\App\Support\GameNames::game(\App\Games\ProofOfPong::SLUG).', '.__('Live').' · 1v1'" :chip="$chip"
               :when="$pongMatch->ended_at?->diffForHumans()" :score="$score">
    <x-slot:sides>
        <span class="flex min-w-0 items-center gap-2">
            @if ($winner?->exists)<x-avatar :user="$winner" :size="20" class="rounded-xs max-lg:hidden" data-test="pong-row-face" />@endif
            <span class="truncate font-bold" data-test="pong-row-winner">{{ $winner?->displayName() ?? '–' }}</span>
        </span>
    </x-slot:sides>
</x-mempool.row>
