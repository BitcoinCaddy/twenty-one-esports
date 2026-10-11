{{-- SystemPages.dc.html "Dieses Match gibt es noch nicht": an unknown or not-yet-known match number (404). --}}
<x-layouts::app :title="__('Match :number not found', ['number' => '#'.$number])" section="matches">
    <x-error-panel mark="#" :heading="__('Match :number not found', ['number' => '#'.$number])" :text="__('There is no match with this number yet.')" data-test="match-not-found">
        <x-slot:extra>
            <p class="m-0 flex items-center gap-2 text-[13px] text-ink-2"><span class="size-3.5 animate-spin rounded-full border-2 border-btc border-t-transparent motion-reduce:animate-none" aria-hidden="true"></span>{{ __('Still syncing, give it a few seconds …') }}</p>
            @if ($latest)
                <p class="m-0 rv-m">{{ __('The latest confirmed match is :number.', ['number' => '#'.$latest]) }}</p>
            @endif
        </x-slot:extra>
        <a href="{{ route('matches.index') }}" class="rv-b2">{{ __('All matches') }}</a>
    </x-error-panel>
</x-layouts::app>
