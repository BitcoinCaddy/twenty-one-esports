@props([
    'model',
    'options',
    'live' => false,
    'label' => null,
    'labelledby' => null,
    'describedby' => null,
    'id' => null,
    'disabled' => false,
    'placeholder' => '–',
    'width' => null,
    'value' => null,
])

{{--
    The board's dropdown (`.dd`, canvas/gen/common.py; Components.dc.html "Dropdown") in place of a native
    <select> (rule: no native select/checkbox/radio): a 44 px button with the chosen label and a chevron that
    opens a listbox of the options. Bound to the Livewire property `model` ($wire.$entangle, deferred unless
    `live`; `value` is the current value for the first paint); `options` is [value => label] or a list of [value, label]. Keyboard: Enter/Space/Down opens,
    Up/Down/Home/End move, Enter picks, Escape closes and gives the focus back. Extra attributes (data-test,
    aria-*) land on the button, where the <select> had them.
--}}
@php
    $pairs = [];
    foreach ($options as $key => $option) {
        // The value keeps its PHP type (an int stays an int on the Livewire property); labels match it as a string.
        $pairs[] = is_array($option) ? [$option[0], (string) $option[1]] : [$key, (string) $option];
    }
    $labels = collect($pairs)->mapWithKeys(fn (array $pair): array => [(string) $pair[0] => $pair[1]])->all();
    $id ??= 'dd-'.\Illuminate\Support\Str::slug(str_replace('.', '-', $model));
@endphp

<div class="relative min-w-0" @if ($width) style="width: {{ $width }}" @endif
     x-data="{ open: false, value: $wire.$entangle(@js($model), @js((bool) $live)), labels: @js($labels),
               shown() { const v = this.value === null || this.value === undefined ? '' : String(this.value); return this.labels[v] ?? @js($placeholder) },
               pick(v) { this.value = v; this.open = false; this.$refs.btn.focus() },
               show() { this.open = true; this.$nextTick(() => requestAnimationFrame(() => (this.$refs.list.querySelector('[aria-selected=true]') ?? this.$refs.list.querySelector('[role=option]'))?.focus())) },
               step(el, by) { const all = [...this.$refs.list.querySelectorAll('[role=option]')]; const i = all.indexOf(el); all[Math.max(0, Math.min(all.length - 1, i + by))]?.focus() } }"
     x-on:click.outside="open = false"
     x-on:keydown.escape.stop="if (open) { open = false; $refs.btn.focus() }">
    <button type="button" x-ref="btn" id="{{ $id }}" class="rv-dd" aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $id }}-list"
            @if ($label) aria-label="{{ $label }}" @endif @if ($labelledby) aria-labelledby="{{ $labelledby }} {{ $id }}" @endif @if ($describedby) aria-describedby="{{ $describedby }}" @endif
            @disabled($disabled)
            x-on:click="open ? (open = false) : show()" x-on:keydown.arrow-down.prevent="show()" x-on:keydown.arrow-up.prevent="show()"
            {{ $attributes }}>
        <span class="v" x-text="shown()">{{ $labels[(string) $value] ?? $placeholder }}</span>
        <x-icon name="chevron-down" :size="14" class="shrink-0 text-ink-2 transition-transform duration-150 motion-reduce:transition-none" x-bind:class="open && 'rotate-180'" />
    </button>
    <ul x-ref="list" id="{{ $id }}-list" role="listbox" @if ($label) aria-label="{{ $label }}" @endif x-show="open" x-cloak x-transition.opacity.duration.150ms
        class="absolute inset-x-0 top-[calc(100%+4px)] z-40 m-0 max-h-72 min-w-max list-none overflow-y-auto rounded border border-line bg-bar p-1 shadow-[0_8px_24px_rgb(0_0_0/0.5)]">
        @foreach ($pairs as [$optionValue, $text])
            <li role="option" tabindex="-1" data-value="{{ $optionValue }}" wire:key="{{ $id }}-{{ $optionValue }}"
                :aria-selected="String(value ?? '') === @js((string) $optionValue) ? 'true' : 'false'"
                x-on:click="pick(@js($optionValue))" x-on:keydown.enter.prevent="pick(@js($optionValue))" x-on:keydown.space.prevent="pick(@js($optionValue))"
                x-on:keydown.arrow-down.prevent="step($el, 1)" x-on:keydown.arrow-up.prevent="step($el, -1)"
                x-on:keydown.home.prevent="step($el, -999)" x-on:keydown.end.prevent="step($el, 999)" x-on:keydown.tab="open = false"
                class="rv-dd-opt">{{ $text }}</li>
        @endforeach
    </ul>
</div>
