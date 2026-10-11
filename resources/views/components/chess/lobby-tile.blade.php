@props(['label', 'icon' => null, 'href' => null, 'variant' => 'default', 'count' => 0, 'countLabel' => null, 'action' => null])

{{--
    One way to play in a lobby's "Spielarten" list (template T1, GameLobby.dc.html): the name and one line under it
    on the left, its live count in the middle, its action on the right. Used by the chess lobby (pages/chess/
    partials/lobby-play) and every board game's lobby (pages/board/partials/lobby-play).
    A link when `href` is given, a <div> for a muted "soon" row, else a button (the rows that open their panel under
    the list pass aria-expanded/aria-controls); the whole row is the target.

    Slots: `detail` the line under the name, `meta` the middle column (counts in <b>, states as tags); `glyph` is
    kept for callers that still pass a clock and is not drawn (the name carries the clock since the revamp).
    `count` > 0 is a number badge on the action (games waiting for your move). `action`: the action's words;
    without it the name is the action.

    variant: default | primary (the lobby's one orange action) | muted (not playable yet: dashed, not focusable).
--}}
@php
    $tag = $href !== null ? 'a' : ($variant === 'muted' ? 'div' : 'button');
    $actionClass = match ($variant) {
        'primary' => 'rv-b is-sm',
        'muted' => 'rv-b2 is-sm border-dashed text-ink-3',
        default => 'rv-b2 is-sm',
    };
@endphp

<{{ $tag }} @if ($tag === 'a') href="{{ $href }}" @elseif ($tag === 'button') type="button" @else aria-disabled="true" @endif
    {{ $attributes->class(['gp-mode w-full', 'cursor-pointer' => $tag !== 'div', 'hover:bg-row-hover' => $tag !== 'div']) }}>
    <span class="gp-mode-l">
        <b>{{ $label }}</b>
        @isset($detail)<span class="max-sm:sr-only">{{ $detail }}</span>@endisset
    </span>
    <span class="gp-mode-m">@isset($meta){{ $meta }}@endisset</span>
    <span class="gp-mode-a {{ $actionClass }} relative">
        {{ $action ?? $label }}
        @if ($count > 0)
            <span class="absolute -top-2 -right-2 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-btc px-1 text-[11px] leading-none font-bold text-on-btc shadow-[0_0_0_2px_var(--color-card)]" data-test="{{ $attributes->get('data-test') }}-count">{{ $count }}<span class="sr-only"> {{ $countLabel }}</span></span>
        @endif
    </span>
</{{ $tag }}>
