{{-- SystemPages.dc.html "Diese Seite gibt es nicht". --}}
<x-layouts::app :title="__('This page does not exist')">
    <x-error-panel code="404" :heading="__('This page does not exist')" :text="__('The link is old or mistyped.')">
        <a href="{{ route('home') }}" class="rv-b" data-test="system-home">{{ __('Go to Home') }}</a>
        <a href="{{ route('matches.index') }}" class="rv-b2">{{ __('Latest matches') }}</a>
    </x-error-panel>
</x-layouts::app>
