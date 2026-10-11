@props(['active' => 'overview'])

{{--
    The tabs of the player's own pages (Profile.dc.html, canvas NEU (Scope) "Reiter"; rule R5's one order): Übersicht,
    Partien, Wertungen, Clan, Einstellungen. Each is a page of its own; the current one carries aria-current.
--}}
@php
    $user = auth()->user();
    $clan = $user?->clanMember?->clan;
    $tabs = [
        'overview' => [__('Overview'), route('dashboard')],
        'games' => [__('Games'), route('me.correspondence')],
        'ratings' => [__('Ratings'), $user ? route('players.show', $user->npub).'#ps-ladders-h' : route('ladder.strongest')],
        'clan' => [__('Clan'), $clan ? route('clans.show', $clan) : route('clans.index')],
        'settings' => [__('Settings'), route('settings.account')],
    ];
@endphp
<nav {{ $attributes->class('rv-tabs overflow-x-auto [scrollbar-width:none]') }} aria-label="{{ __('Your page') }}" data-test="account-tabs">
    @foreach ($tabs as $key => [$label, $href])
        <a href="{{ $href }}" @navigate($href) class="rv-tab shrink-0" @if ($key === $active) aria-current="page" @endif data-test="account-tab-{{ $key }}">{{ $label }}</a>
    @endforeach
</nav>
