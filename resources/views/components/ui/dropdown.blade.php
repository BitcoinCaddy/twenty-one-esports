@props(['label', 'options' => [], 'value' => null, 'model' => null, 'name' => null, 'test' => 'dropdown', 'caption' => true])

{{--
    The dropdown (`.dd`, Components.dc.html; rule R11: never the browser's own select): a 44 px button that opens a
    listbox. Arrow keys move, Enter or a click picks, Escape closes and gives the focus back to the button.

    `options`: [value => label]. `model`: a Livewire property the pick sets (`$wire.set`, one round trip);
    `name`: a hidden input for a plain form instead. `caption`: show `label` above the button (else only its name).
--}}
@php
    $id = 'dd-'.\Illuminate\Support\Str::random(6);
    $keys = array_map('strval', array_keys($options));
    $current = (string) ($value ?? ($keys[0] ?? ''));
@endphp
<div {{ $attributes->class('relative flex flex-col gap-1') }}
     x-data="{
         open: false,
         value: @js($current),
         active: 0,
         keys: @js($keys),
         labels: @js(array_values(array_map('strval', $options))),
         show() { this.open = true; this.active = Math.max(0, this.keys.indexOf(this.value)); this.$nextTick(() => this.$refs.menu.children[this.active]?.scrollIntoView({ block: 'nearest' })); },
         pick(index) {
             this.value = this.keys[index];
             this.open = false;
             this.$refs.button.focus();
             @if ($model) this.$wire.set(@js($model), this.value); @endif
         },
         move(step) { if (! this.open) { this.show(); return; } this.active = (this.active + step + this.keys.length) % this.keys.length; this.$refs.menu.children[this.active]?.scrollIntoView({ block: 'nearest' }); },
     }"
     x-on:keydown.escape.prevent.stop="open = false; $refs.button.focus()" x-on:click.outside="open = false" data-test="{{ $test }}">
    @if ($caption)
        <span class="rv-m" id="{{ $id }}-l">{{ $label }}</span>
    @endif
    <button type="button" class="rv-dd" x-ref="button" aria-haspopup="listbox" x-bind:aria-expanded="open.toString()" aria-expanded="false"
            @if ($caption) aria-labelledby="{{ $id }}-l {{ $id }}-v" @else aria-label="{{ $label }}" @endif
            x-on:click="open ? open = false : show()" x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)"
            x-on:keydown.enter.prevent="open ? pick(active) : show()" data-test="{{ $test }}-button">
        <span id="{{ $id }}-v" x-text="labels[keys.indexOf(value)] ?? ''">{{ $options[$current] ?? '' }}</span>
        <x-icon name="chevron-down" :size="14" class="shrink-0 text-ink-2" />
    </button>
    <div class="rv-dd-menu" role="listbox" x-ref="menu" x-show="open" x-cloak @if ($caption) aria-labelledby="{{ $id }}-l" @else aria-label="{{ $label }}" @endif>
        @foreach ($options as $key => $optionLabel)
            <div role="option" x-on:click="pick({{ $loop->index }})" x-on:mouseenter="active = {{ $loop->index }}"
                 x-bind:class="active === {{ $loop->index }} && 'is-active'" x-bind:aria-selected="(value === @js((string) $key)).toString()"
                 aria-selected="{{ (string) $key === $current ? 'true' : 'false' }}" data-test="{{ $test }}-option-{{ $key === '' ? 'all' : $key }}">{{ $optionLabel }}</div>
        @endforeach
    </div>
    @if ($name)
        <input type="hidden" name="{{ $name }}" x-bind:value="value" value="{{ $current }}">
    @endif
</div>
