{{--
    Up to three tie-breaks in order (Challonge's list; median Buchholz for
    Swiss only). `key` is the options key, `current` the chosen list, `labels`
    value => label of the tie-breaks this format offers.
--}}
<div class="grid gap-2 border-t border-hairline py-3 @[560px]:grid-cols-[minmax(0,1fr)_280px] @[560px]:gap-4">
    <span class="flex flex-col items-start gap-2">
        <span id="tb-{{ $key }}" class="text-[13px] font-bold">{{ __('Tie-breaks, in this order') }}</span>
        <ol class="m-0 flex w-full flex-col gap-1.5 pl-5 text-xs text-ink-2">
            @foreach ([0, 1, 2] as $index)
                <li wire:key="tb-{{ $key }}-{{ $index }}">
                    <x-ui.select-menu :model="'options.'.$key.'.'.$index" live :value="$current[$index] ?? null" :label="__('Tie-break :number', ['number' => $index + 1])" :options="$labels" width="16rem" />
                </li>
            @endforeach
        </ol>
    </span>
    <span class="text-xs leading-normal text-ink-2">{{ __('Used when two have the same points, in this order. Buchholz: the stronger your opponents were, the higher you rank.') }}</span>
</div>
