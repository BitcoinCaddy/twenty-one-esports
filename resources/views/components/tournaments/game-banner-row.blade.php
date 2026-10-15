@props(['banner' => null])

{{--
    A tournament game's banner on a phone, in the board page's title row (DerCaddy, 2026-10-15: "Das Turnier Banner soll
    über dem Spiel stehen mobil. Allerdings darf das Spielfeld dadurch nicht kleiner werden! Und das Banner auch nur in der
    kleinen Ansicht"): one line, as tall as the row's connection pill (34 px), in place of the game's name and the lobby
    link, so the board below keeps its size. The tournament's name gives way first, the round stays (the game of a duel
    is read out only, it would take the name's room); the whole line
    opens the tournament page, the desk button sits beside it for its players and direction. Both take taps on 44 px (an
    invisible 5 px above and below) without making the row taller. From lg the full banner stands above the page.
--}}
@if ($banner)
    @php($desk = isset($banner['tournamentId']) && ($deskTournament = \App\Models\Tournament::query()->find($banner['tournamentId'])) !== null ? \App\Support\Tournaments\TournamentDesk::for($deskTournament, auth()->user()) : null)
    @php($round = $banner['round'].($banner['step'] ? ' · '.$banner['step'] : ''))
    <a href="{{ $banner['url'] }}" data-tournament-inline {{ $attributes->class('relative -my-[5px] flex h-11 min-w-0 grow basis-0 items-center lg:hidden') }}
       aria-label="{{ ($banner['cup'] ? __('Cup game') : __('Tournament game')).': '.$banner['tournament'].', '.$round.'. '.__('Tournament page') }}" data-test="tournament-banner-row">
        <span class="flex h-[34px] w-full min-w-0 items-center gap-2 rounded-md bg-btc-tint px-2.5 text-[13px] shadow-[inset_0_0_0_2px_var(--color-btc)]" aria-hidden="true">
            <x-icon name="trophy" :size="16" class="shrink-0 text-btc" />
            <b class="min-w-0 truncate text-ink" data-test="tournament-banner-row-name">{{ $banner['tournament'] }}</b>
            <span class="max-w-[40%] shrink-0 truncate font-bold text-btc-hi" data-test="tournament-banner-row-round">{{ $banner['round'] }}</span>
        </span>
    </a>
    @if ($desk)
        <x-tournaments.desk-button :desk="$desk" compact class="h-[34px]! px-2.5! before:absolute before:inset-x-0 before:-inset-y-[5px] before:content-[''] max-sm:w-[34px]! max-sm:px-0! lg:hidden" />
    @endif
@endif
