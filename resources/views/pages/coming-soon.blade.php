{{--
    Placeholder for a planned route (screens-v1.md), SystemPages.dc.html "Kommt bald". `page` is a translation key,
    `section` the active main-navigation item. The real page replaces this in its phase.
--}}
<x-layouts::app :title="__($page)" :section="$section">
    {{-- An admin placeholder keeps the admin map, so it is never a dead end (P16). --}}
    @if ($section === 'admin')
        <x-admin.nav active="status" />
    @endif
    <x-error-panel mark="•••" :heading="__($page)" :text="__('This page is announced.')">
        <a href="{{ route('home') }}" class="rv-b2">{{ __('Go to Home') }}</a>
    </x-error-panel>
</x-layouts::app>
