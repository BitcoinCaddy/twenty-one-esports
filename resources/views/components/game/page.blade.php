@props(['game', 'active' => 'play', 'mode' => null, 'template' => 'T1', 'title' => null, 'lede' => null, 'stat' => null, 'statLabel' => null, 'statLive' => false, 'stickyRail' => true])

{{--
    The one lobby template of every game (template T1, rules R1, R5, R6, R7; GameLobby.dc.html, HyperIndex.dc.html,
    GameScore.dc.html, GameSeries.dc.html). Every game page renders through it, so every game has the same slots in
    the same place; what differs is only what a slot holds:

        bar        the game bar (x-game.subnav): name, fixed tabs, `primary` on the right, `mode` beside it
        hero       cover, `title` (the page's h1), `lede` (one data line), `stat` + `statLabel` (one big number);
                   a page may replace the whole card with its own `hero` slot
        main       the default slot: the main column, 8 of 12 columns (Spielarten, your games, the ladder …)
        foot       `next` (the next tournament) | `secondary` (the secondary slot, x-game.secondary), the main
                   column's last row
        rail       `chat`: the right column, 4 of 12, sticky (R6); below lg it follows the main column. A page whose
                   column holds more than the chat (Hyperbitcoinization's quick match) passes :sticky-rail="false"
        heroAside  replaces the big number with an action (HyperIndex.dc.html: "Gegen Bots spielen")

    `template`: T1 for a lobby; a game page of another kind keeps the same frame and names itself.
--}}
@php
    $colour = app(\App\Games\GameRegistry::class)->find((string) $game)?->assets()->colour;
    $heroAbove = isset($heroAside) && ! isset($hero);
@endphp

<div {{ $attributes->class('flex grow flex-col') }} style="--game: {{ $colour }}" data-template="{{ $template }}" data-game="{{ $game }}">
    <x-game.subnav :game="$game" :active="$active" :mode="$mode">{{ $primary ?? '' }}</x-game.subnav>

    <div class="gp">
        @isset($top)
            <div class="mb-6 flex flex-col gap-3 empty:hidden">{{ $top }}</div>
        @endisset
        @if ($heroAbove)
            {{-- With an action instead of a number the hero spans both columns (HyperIndex.dc.html), so a long name keeps one line. --}}
            <div class="mb-6"><x-game.hero-card :game="$game" :title="$title" :lede="$lede" :stat="$stat" :stat-label="$statLabel" :stat-live="$statLive">{{ $heroAside ?? '' }}</x-game.hero-card></div>
        @endif
        <div class="gp-grid">
            <div class="gp-main">
                @isset($hero)
                    {{ $hero }}
                @elseif (! $heroAbove)
                    <x-game.hero-card :game="$game" :title="$title" :lede="$lede" :stat="$stat" :stat-label="$statLabel" :stat-live="$statLive">{{ $heroAside ?? '' }}</x-game.hero-card>
                @endisset

                {{ $slot }}

                @if (isset($next) || isset($secondary))
                    <div class="gp-foot">
                        <div class="flex min-w-0 flex-col gap-3">{{ $next ?? '' }}</div>
                        <div class="flex min-w-0 flex-col gap-3">{{ $secondary ?? '' }}</div>
                    </div>
                @endif
            </div>

            @isset($chat)
                <aside @class(['gp-rail', 'is-loose' => ! $stickyRail]) aria-label="{{ __('Chat') }}" data-test="game-rail">{{ $chat }}</aside>
            @endisset
        </div>
    </div>
</div>
