@props(['label', 'items' => [], 'test' => 'chips'])

{{--
    A chip filter row as Priority+ (design canvas Components.dc.html "Chip-Filter, Priority+", rule R8): the label,
    then one chip per choice, "Alle" first and the rest by count; a zero count dims, the chip stays clickable. Every
    chip that fits stays in the row, the overflow folds into a trailing "+N weitere" menu (resources/js/chipRow.js);
    a phone scrolls the row sideways with an edge fade instead. The chips are links (the filter is the page's URL),
    the current one carries aria-current.

    `items`: list of ['label' => string, 'count' => int|null, 'href' => string, 'active' => bool, 'key' => string].
--}}
<div {{ $attributes->class('rv-chips') }} x-data="chipRow" role="group" aria-label="{{ $label }}" data-test="{{ $test }}">
    <span class="rv-m w-14 flex-none max-lg:hidden" aria-hidden="true">{{ $label }}</span>
    <div class="rv-chips-track" x-ref="track">
        @foreach ($items as $index => $item)
            <a href="{{ $item['href'] }}" data-chip="{{ $index }}" @if ($item['active']) aria-current="page" @endif
               @class(['rv-chip', 'is-zero' => $item['count'] === 0 && ! $item['active']]) data-test="{{ $test }}-{{ $item['key'] }}">
                {{ $item['label'] }}@if ($item['count'] !== null) <span class="rv-m">{{ $item['count'] }}</span>@endif
            </a>
        @endforeach
    </div>
    <div class="relative flex-none" x-ref="more" hidden x-on:keydown.escape="open = false" x-on:click.outside="open = false">
        <button type="button" class="rv-chip" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-expanded="false" data-test="{{ $test }}-more">
            +<span x-text="folded"></span> {{ __('more') }}
        </button>
        <div class="rv-chips-menu" x-show="open" x-cloak>
            @foreach ($items as $index => $item)
                <a href="{{ $item['href'] }}" data-chip-menu="{{ $index }}" hidden @if ($item['active']) aria-current="page" @endif>
                    <span>{{ $item['label'] }}</span>@if ($item['count'] !== null) <span class="rv-m">{{ $item['count'] }}</span>@endif
                </a>
            @endforeach
        </div>
    </div>
</div>
