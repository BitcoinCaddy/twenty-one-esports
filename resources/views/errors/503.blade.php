{{--
    SystemPages.dc.html "Kurz in Wartung". Deviation: the line under it and the button stay what holds true
    while the whole site is down ("Zum Stream" would lead to the same site; that running games lose no time
    is not something maintenance guarantees).
--}}
<x-layouts::minimal :title="__('Briefly down for maintenance')">
    <x-error-panel code="503" :heading="__('Briefly down for maintenance')" :text="__('TWENTY ONE is being updated. Your games and results are safe, and the league picks up where it stopped.')">
        <a href="{{ url()->current() }}" class="rv-b2" data-test="system-reload">{{ __('Try again') }}</a>
    </x-error-panel>
</x-layouts::minimal>
