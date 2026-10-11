@props(['item', 'nowMs', 'now' => false])

{{--
    One entry of the action dock (ActionDock.dc.html "Einträge", ActionDockPhone.dc.html; rules R12, R17): the same card
    for every reason to act. The game's cover with the other side's face on its corner, a title and one line, on the
    right the running time (red under the entry's own limit, with a dot) and the one action. `now`: the "Jetzt" group,
    a red edge on the left. Everything else of the entry lives on the page its action opens.
--}}
@php
    $urgent = $item->isUrgent($nowMs);
    $model = $item->model;
    $slug = match (true) {
        $item->game() !== null => $item->game(),
        $item->isChess() => 'chess',
        $model instanceof \App\Models\PongMatch, $model instanceof \App\Models\PongInvite => \App\Games\ProofOfPong::SLUG,
        $model instanceof \App\Models\BoardGame, $model instanceof \App\Models\BoardInvite, $model instanceof \App\Models\BoardChallenge => $model->game,
        default => null,
    };
    // The title names the match and what it wants ("Daily #156, your move"), the line under it who is on the other side.
    $title = $item->line !== '' ? $item->line : $item->name;
    $sub = $item->name !== '' && ! str_contains($title, $item->name) ? $item->name : $item->state;
@endphp

<div wire:key="card-{{ $item->key }}" {{ $attributes->class(['dk-card', 'is-now' => $now]) }} data-test="dock-card" data-key="{{ $item->key }}" data-need="{{ $item->needsYou ? '1' : '0' }}">
    <a href="{{ $item->href }}" class="relative block shrink-0" tabindex="-1" aria-hidden="true">
        @if ($slug !== null && app(\App\Games\GameRegistry::class)->find($slug) !== null)
            <x-game-cover :game="$slug" size="thumb" class="h-10 w-[72px] rounded-[2px] object-cover" />
        @else
            <x-match-dock.face :item="$item" :size="40" :badge="false" />
        @endif
        @if ($item->face && $slug !== null)
            <span class="absolute -right-2 -bottom-2 rounded-full ring-2 ring-card"><x-avatar :user="$item->face" :size="22" class="rounded-full" /></span>
        @endif
    </a>
    <a href="{{ $item->href }}" class="flex min-w-0 grow flex-col gap-0.5 text-ink hover:text-ink" data-test="dock-card-link">
        <b class="dk-card-title">{{ $title }}</b>
        <span class="truncate text-[12px] text-ink-2">{{ $sub }}</span>
    </a>
    <span class="flex shrink-0 flex-col items-end gap-1.5">
        @if ($item->tick)
            <span @class(['dk-card-time', 'is-urgent' => $urgent])>
                @if ($urgent)<i class="dk-card-dot" aria-hidden="true"></i>@endif
                <span data-tick='@json($item->tick)'>{{ $item->trailing }}</span>
            </span>
        @endif
        <a href="{{ $item->href }}" class="rv-b is-sm" aria-label="{{ ($item->action ?? __('View')).', '.$item->sentence }}" data-test="dock-card-action">{{ $item->action ?? __('View') }}</a>
    </span>
</div>
