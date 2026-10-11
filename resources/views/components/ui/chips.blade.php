@props(['label', 'items' => [], 'test' => 'chips', 'caption' => null, 'size' => 'md', 'navigate' => false])

{{--
    The one chip filter row, Priority+ (design canvas Components.dc.html "Chip-Filter, Priority+", rule R8): the caption,
    then one chip per choice in the caller's order ("Alle" first, the rest by count, or the current ladder first); a
    zero count or a `dim` item dims, the chip stays clickable. Every chip that fits stays in the row, the overflow folds
    into a trailing "+N weitere" menu (resources/js/chipRow.js); a phone scrolls the row sideways with an edge fade
    instead. The chips are links (the filter is the page's URL), the current one carries aria-current. A Livewire
    round trip of the page around the row folds it again before the next paint (chipRow.js, morphed hook).

    `items`: list of ['label' => string, 'href' => string, 'count' => ?int, 'active'|'current' => bool,
                      'key' => ?string (data-test "<test>-<key>"), 'test' => ?string (own data-test), 'dim' => ?bool].
    `caption`: the word before the row (default: `label`, the group's accessible name). `size`: md 36 px, lg 44 px.
    `navigate`: the chips switch pages with wire:navigate where the page allows it (@navigate).
--}}
@php
    $chips = array_values(array_map(fn (array $item): array => [
        'label' => $item['label'],
        'href' => $item['href'],
        'count' => $item['count'] ?? null,
        'active' => (bool) ($item['active'] ?? $item['current'] ?? false),
        'test' => $item['test'] ?? (isset($item['key']) ? $test.'-'.$item['key'] : null),
        'dim' => (bool) ($item['dim'] ?? (($item['count'] ?? null) === 0)),
    ], $items));
@endphp
<div {{ $attributes->class(['rv-chips', 'rv-chips-lg' => $size === 'lg']) }} x-data="chipRow" role="group" aria-label="{{ $label }}" data-test="{{ $test }}">
    <span class="rv-m min-w-14 flex-none max-lg:hidden" aria-hidden="true">{{ $caption ?? $label }}</span>
    <div class="rv-chips-track" x-ref="track">
        @foreach ($chips as $index => $chip)
            <a href="{{ $chip['href'] }}" @if ($navigate) @navigate($chip['href']) @endif data-chip="{{ $index }}" @if ($chip['active']) aria-current="page" @endif
               @class(['rv-chip', 'is-zero' => $chip['dim'] && ! $chip['active']]) @if ($chip['test']) data-test="{{ $chip['test'] }}" @endif>
                {{ $chip['label'] }}@if ($chip['count'] !== null) <span class="rv-m">{{ $chip['count'] }}</span>@endif
            </a>
        @endforeach
    </div>
    <div class="relative flex-none" x-ref="more" hidden x-on:keydown.escape="open = false" x-on:click.outside="open = false">
        <button type="button" class="rv-chip" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-expanded="false" aria-haspopup="true" data-test="{{ $test }}-more">
            +<span x-text="folded"></span> {{ __('more') }}
        </button>
        <div class="rv-chips-menu" x-show="open" x-cloak>
            @foreach ($chips as $index => $chip)
                <a href="{{ $chip['href'] }}" @if ($navigate) @navigate($chip['href']) @endif data-chip-menu="{{ $index }}" hidden @if ($chip['active']) aria-current="page" @endif>
                    <span>{{ $chip['label'] }}</span>@if ($chip['count'] !== null) <span class="rv-m">{{ $chip['count'] }}</span>@endif
                </a>
            @endforeach
        </div>
    </div>
</div>
