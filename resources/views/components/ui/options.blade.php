@props(['model' => null, 'options', 'current' => null, 'method' => null, 'live' => false, 'label' => null, 'labelledby' => null, 'test' => null])

{{--
    The board's vertical choice list (`opts()`, canvas/gen/kit.py) in place of native radio buttons: one 48 px
    row per option with a round mark and its title (and hint), the chosen one orange-edged; role=radiogroup.
    `options` is a list of [value, title, hint?]. Bound either to the Livewire property `model`
    ($wire.$entangle, deferred unless `live`) or to a Livewire `method` called with the value; `current`
    paints the first frame. `test` names each row's data-test as "<test>-<value>".
--}}
<div role="radiogroup" @if ($labelledby) aria-labelledby="{{ $labelledby }}" @elseif ($label) aria-label="{{ $label }}" @endif
     @if ($model) x-data="{ value: $wire.$entangle(@js($model), @js((bool) $live)) }" @endif
     {{ $attributes->class('flex flex-col gap-1') }}>
    @foreach ($options as $option)
        @php
            [$value, $title] = $option;
            $hint = $option[2] ?? null;
        @endphp
        <button type="button" role="radio" class="rv-opt" wire:key="opt-{{ $model ?? $method }}-{{ $value }}"
                aria-checked="{{ (string) $current === (string) $value ? 'true' : 'false' }}"
                @if ($model) :aria-checked="String(value) === @js((string) $value) ? 'true' : 'false'" x-on:click="value = @js($value)"
                @elseif ($method) wire:click="{{ $method }}({{ \Illuminate\Support\Js::from($value) }})" @endif
                @if ($test) data-test="{{ $test }}-{{ $value }}" @endif>
            <i aria-hidden="true"></i>
            <span class="flex min-w-0 grow flex-col"><b class="font-bold">{{ $title }}</b>@if ($hint)<span class="rv-m">{{ $hint }}</span>@endif</span>
        </button>
    @endforeach
</div>
