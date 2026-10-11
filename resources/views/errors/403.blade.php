{{-- A system page in the frame of SystemPages.dc.html: the page needs another role or account. --}}
<x-layouts::app :title="__('You may not open this page')">
    <x-error-panel code="403" :heading="__('You may not open this page')" :text="__('It belongs to another role. Log in with the account that has it, or go back to Home.')">
        <a href="{{ route('home') }}" class="rv-b" data-test="system-home">{{ __('Go to Home') }}</a>
    </x-error-panel>
</x-layouts::app>
