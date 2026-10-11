{{-- SystemPages.dc.html "Hier ist etwas schiefgegangen". --}}
<x-layouts::minimal :title="__('Something went wrong here')">
    <x-error-panel code="500" :heading="__('Something went wrong here')" :text="__('The error is logged. Reload the page.')">
        <a href="{{ url()->current() }}" class="rv-b" data-test="system-reload">{{ __('Reload') }}</a>
        <a href="/" class="rv-b2">{{ __('Go to Home') }}</a>
    </x-error-panel>
</x-layouts::minimal>
