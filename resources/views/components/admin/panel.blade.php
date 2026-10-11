@props(['title' => null, 'meta' => null, 'tag' => null, 'id' => null, 'flush' => false])

{{--
    One section of an admin page, the board's `sec` (canvas/gen/kit.py): a card (padding 24, gap 12) with its
    h2 and an optional tag beside it (a count: "6 Namen"), the meta line on the right ("neueste zuerst") and
    the section's actions after it (slot `actions`), then the content. `flush` keeps the old prop working for
    row lists whose rows carry their own hairlines; the gap is the same.
--}}
@php($headingId = $id ?? ($title ? 'panel-'.\Illuminate\Support\Str::slug($title) : null))

<section @if ($headingId) aria-labelledby="{{ $headingId }}" @endif {{ $attributes->class('rv-sec') }}>
    @if ($title || isset($actions) || $meta)
        <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
            @if ($title)<h2 id="{{ $headingId }}" class="rv-h3 min-w-0 [overflow-wrap:anywhere]">{{ $title }}</h2>@endif
            @if ($tag !== null && $tag !== '')<span class="rv-tag">{{ $tag }}</span>@endif
            <span class="grow"></span>
            @if ($meta)<span class="rv-m">{{ $meta }}</span>@endif
            @isset($actions)<span class="flex flex-wrap items-center gap-3">{{ $actions }}</span>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
