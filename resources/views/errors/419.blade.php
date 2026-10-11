{{-- A system page in the frame of SystemPages.dc.html: the form's session ran out. --}}
<x-layouts::minimal :title="__('This page has expired')">
    <x-error-panel code="419" :heading="__('This page has expired')" :text="__('It was open too long. Reload it and try again.')">
        <a href="{{ url()->previous() }}" class="rv-b" data-test="system-reload">{{ __('Reload') }}</a>
    </x-error-panel>
</x-layouts::minimal>
