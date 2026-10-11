@props(['model' => null, 'live' => false, 'checked' => false, 'disabled' => false])

{{--
    The board's checkbox (`.ck`, Components.dc.html; revamp-tournaments.css `.rv-ck`) in place of a native
    checkbox: a 44 px row, a 20 px box that fills orange with a tick, the label in the slot. Bound to the
    Livewire property `model` ($wire.$entangle, deferred unless `live`); `checked` paints the first frame.
    Without `model` the caller wires it (wire:click) and passes `checked`.
--}}
@if ($model)
    <button type="button" role="checkbox" class="rv-ck whitespace-normal text-left" @disabled($disabled)
            x-data="{ on: $wire.$entangle(@js($model), @js((bool) $live)) }" :aria-checked="on ? 'true' : 'false'" aria-checked="{{ $checked ? 'true' : 'false' }}"
            x-on:click="on = ! on" {{ $attributes }}><i aria-hidden="true"></i><span class="min-w-0">{{ $slot }}</span></button>
@else
    <button type="button" role="checkbox" class="rv-ck whitespace-normal text-left" aria-checked="{{ $checked ? 'true' : 'false' }}" @disabled($disabled) {{ $attributes }}><i aria-hidden="true"></i><span class="min-w-0">{{ $slot }}</span></button>
@endif
