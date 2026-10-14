@props(['item'])

{{--
    One dock item as a list row in the floating bar's "+N more" list (MatchDock.dc.html). The phone sheet and the
    header's panel use the action dock's cards (components/match-dock/card) instead.
--}}
<a href="{{ $item->href }}" wire:key="row-{{ $item->key }}" {{ $attributes->class('dk-row text-ink hover:text-ink') }} data-test="dock-row" data-key="{{ $item->key }}">
    <x-match-dock.face :item="$item" :size="32" :badge="false" />
    <span class="flex min-w-0 grow flex-col gap-0.5">
        <span @class(['dk-name', 'text-ink' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])>{{ $item->name }} @if ($item->number !== '')<span class="font-normal text-ink-3">{{ $item->number }}</span>@endif</span>
        <span @class(['dk-line', 'text-btc' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])><span class="truncate">{{ $item->line }}</span></span>
    </span>
    <x-icon name="next" :size="16" class="text-ink-3" />
</a>
