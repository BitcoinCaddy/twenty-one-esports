@props(['model' => null, 'live' => false, 'checked' => false, 'label' => null, 'labelledby' => null, 'disabled' => false, 'onValue' => true, 'offValue' => false])

{{--
    The board's switch (`sw()`, canvas/gen/kit.py): 52 × 32, on orange with a dark knob on the right, off on the
    raised grey with an edge and a grey knob on the left; role=switch, never a native checkbox. Bound to the
    Livewire property `model` ($wire.$entangle, deferred unless `live`), whose values are `onValue`/`offValue`
    (true/false, or e.g. 'on'/'off'); `checked` paints the first frame. Without `model` it is a plain switch for
    a wire:click on the caller (attributes pass through).
--}}
@if ($model)
    <button type="button" role="switch" class="rv-sw" @if ($label) aria-label="{{ $label }}" @endif @if ($labelledby) aria-labelledby="{{ $labelledby }}" @endif @disabled($disabled)
            x-data="{ v: $wire.$entangle(@js($model), @js((bool) $live)), onV: @js($onValue), offV: @js($offValue), get on() { return String(this.v) === String(this.onV) } }"
            :aria-checked="on ? 'true' : 'false'" aria-checked="{{ $checked ? 'true' : 'false' }}"
            x-on:click="v = on ? offV : onV" {{ $attributes }}><i aria-hidden="true"></i></button>
@else
    <button type="button" role="switch" class="rv-sw" aria-checked="{{ $checked ? 'true' : 'false' }}" @if ($label) aria-label="{{ $label }}" @endif @if ($labelledby) aria-labelledby="{{ $labelledby }}" @endif @disabled($disabled) {{ $attributes }}><i aria-hidden="true"></i></button>
@endif
