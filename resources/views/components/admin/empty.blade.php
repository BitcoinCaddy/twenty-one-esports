@props(['text'])

{{--
    An empty list inside an admin section, the board's `empty()` (canvas/gen/kit.py over common.py
    `empty_state`): three dashed block slots, the first sentence as a display heading (16 px), the
    rest as the line of direction, and the action that fills the list, when there is one (slot).
--}}
@php
    $heading = $text;
    $rest = '';
    if (preg_match('/^(.+?[.!?])\s+(.+)$/su', $text, $parts) === 1) {
        [, $heading, $rest] = $parts;
    }
@endphp

<div {{ $attributes->class('flex flex-col items-start gap-3.5 py-2') }}>
    <span class="flex gap-3.5" aria-hidden="true">
        @foreach (range(1, 3) as $blockSlot)
            <span class="size-8 rounded-[3px] border-[1.5px] border-dashed border-dash"></span>
        @endforeach
    </span>
    <p class="m-0 font-display text-base leading-[1.3] font-bold [overflow-wrap:anywhere]">{{ $heading }}</p>
    @if ($rest !== '')
        <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2">{{ $rest }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <span class="mt-1 flex flex-wrap gap-3">{{ $slot }}</span>
    @endif
</div>
