<?php

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Support\Chess\ChessModes;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Live games (P10, "Spectating"): every chess game running now, blitz and
 * daily, for anyone, guests included. Each links to the game page, which a
 * guest already watches live over the public `game.{id}.watch` channel (or
 * by polling without a websocket). The chess lobby shows three blitz boards;
 * this page is the full list it links to. Nothing here is private: the
 * players' chat stays on the game page, for the players only.
 *
 * Rapid and blitz (plan "Schach Rapid und Clan", P3): the live section lists
 * every live mode, each board names its own, and the mode filter (`?mode=`,
 * one chip per live mode of the registry) narrows it. The chips carry no
 * counts: that would be one more query on a hot route (PageQueryBudgetTest).
 */
new #[Layout('layouts::app', ['section' => 'chess', 'scripts' => ['resources/js/chess.js']])] class extends Component {
    public const LIMIT = 48;

    /** The live mode filter: '' = every live mode. */
    #[Url(as: 'mode', except: '')]
    public string $mode = '';

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Live games'));
        app(PageMeta::class)->describe(__('Live games'), __('Watch the chess games of the TWENTY ONE esports league live: every rapid and blitz board and every daily game running now, no login needed.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('games'));
    }

    /**
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function blitz(): Collection
    {
        return ChessGame::query()->live()->where('status', ChessGameStatus::Active)
            ->when(ChessModes::isLive($this->mode), fn ($query) => $query->where('mode', $this->mode))
            ->with(['white', 'black'])->latest('id')->limit(self::LIMIT)->get();
    }


    /**
     * Daily games, the most recent move first.
     *
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function daily(): Collection
    {
        return ChessGame::query()->daily()->where('status', ChessGameStatus::Active)->with(['white', 'black'])->latest('updated_at')->latest('id')->limit(self::LIMIT)->get();
    }
}; ?>

{{-- GamesIndex.dc.html (template T14): the running games in one head with the biggest live count, live chess with its mode chips, daily chess as rows. --}}
@php
    $liveCount = $this->blitz->count();
    $dailyCount = $this->daily->count();
    $leadLive = $liveCount > 0;
@endphp
<div class="gp flex flex-col gap-6" style="--game: var(--color-chess)" data-test="live-games">
    <header class="gp-hero !grid-cols-1 sm:!grid-cols-[minmax(0,1fr)_auto]" data-test="live-games-head">
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-4 gap-y-1">
            <h1 class="m-0 font-display text-[30px] leading-[1.15] font-bold lg:text-[48px]">{{ __('Running games') }}</h1>
            <span class="text-[13px] text-ink-2">{{ __('Watch any game, no login needed') }} · <a href="{{ route('matches.index') }}" @navigate(route('matches.index')) class="rv-lk">{{ __('all matches in the mempool') }}</a></span>
        </div>
        <div class="flex items-center gap-3 pr-3">
            <span class="rv-live h-6 font-normal"><i></i>{{ $leadLive ? __('Live chess') : __('Daily chess') }}</span>
            <b class="font-display text-[30px] leading-none tabular-nums">{{ $leadLive ? $liveCount : $dailyCount }}</b>
            <span class="text-[13px] text-ink-2">{{ trans_choice('game running|games running', $leadLive ? $liveCount : $dailyCount) }}</span>
        </div>
    </header>

    <section aria-labelledby="live-blitz-h" class="gp-panel flex flex-col gap-4 px-4 py-5 lg:px-6">
        <span class="flex flex-wrap items-center gap-3">
            <h2 id="live-blitz-h" class="gp-h flex items-center gap-2">@if ($this->blitz->isNotEmpty())<span class="size-2 animate-live rounded-full bg-win" aria-hidden="true"></span>@endif{{ __('Live chess') }}</h2>
            <span class="rv-tag">{{ trans_choice(':count live board|:count live boards', $liveCount) }}</span>
        </span>
        {{-- One chip per live mode of the registry (rapid first); links, so the filter is in the address. --}}
        <nav aria-label="{{ __('Mode') }}" class="flex gap-2 overflow-x-auto" data-test="live-mode-filter">
            @foreach (['' => __('All'), ...collect(ChessModes::live())->mapWithKeys(fn (string $mode): array => [$mode => ChessModes::label($mode)])->all()] as $value => $label)
                <a href="{{ route('games.index', $value === '' ? [] : ['mode' => $value]) }}" @if ($mode === $value) aria-current="page" @endif data-test="live-mode-{{ $value === '' ? 'all' : $value }}" class="gm-chip h-11">{{ $label }}</a>
            @endforeach
        </nav>
        @if ($this->blitz->isEmpty())
            <x-empty-state :heading="__('No live game right now.')" data-test="live-empty">
                <a href="{{ route('chess.lobby') }}" @navigate(route('chess.lobby')) class="rv-b2">{{ __('Find opponent') }}</a>
            </x-empty-state>
        @else
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->blitz as $game)
                    <li wire:key="blitz-{{ $game->id }}" class="flex min-w-0 flex-col gap-2.5 border-b border-hairline pb-3 sm:border-0 sm:pb-0" data-test="live-game" data-mode="{{ $game->mode }}">
                        <span class="flex min-w-0 items-center gap-2 text-[13px]"><x-avatar :user="$game->black" :size="18" class="rounded-sm" /><b class="truncate">{{ $game->black->displayName() }}</b></span>
                        {{-- The 64 squares are built when the board comes into view: a phone hides the boards and builds none (48 boards were 24 576 of the page's 25 996 DOM nodes, tests/Browser/RenderScopeTest.php). --}}
                        <div class="hidden justify-center py-1 sm:flex" x-data="{ cells: [], boardLabel: @js(__('Live board of :number', ['number' => $game->number()])) }"
                             x-intersect.once.margin.200px="cells = window.chessBoardCells(@js($game->fen), { noCoords: true })" data-test="live-board">
                            <x-chess.board class="mt-4 mr-4 max-w-40" />
                        </div>
                        <span class="flex min-w-0 items-center gap-2 text-[13px]"><x-avatar :user="$game->white" :size="18" class="rounded-sm" /><b class="truncate">{{ $game->white->displayName() }}</b></span>
                        <span class="text-xs text-ink-2"><span data-test="live-game-mode">{{ ChessModes::label($game->mode) }}</span> · {{ $game->rated ? __('Rated') : __('Casual') }} · {{ __('move :n', ['n' => intdiv($game->ply, 2) + 1]) }} · {{ $game->number() }}</span>
                        <a href="{{ route('games.show', $game) }}" class="rv-b2 is-sm self-start" data-test="watch"><x-icon name="eye" :size="14" />{{ __('Watch') }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="live-daily-h" class="gp-panel flex flex-col gap-3 px-4 py-5 lg:px-6">
        <span class="flex flex-wrap items-center gap-3">
            <h2 id="live-daily-h" class="gp-h">{{ __('Daily chess') }}</h2>
            <span class="rv-live h-5 font-normal"><i></i>{{ trans_choice(':count game running|:count games running', $dailyCount) }}</span>
        </span>
        @if ($this->daily->isEmpty())
            <p class="m-0 text-[13px] text-ink-2">{{ __('No daily game running.') }}</p>
        @else
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($this->daily as $game)
                    <li wire:key="daily-{{ $game->id }}" class="border-t border-hairline first:border-0" data-test="live-game">
                        <a href="{{ route('games.show', $game) }}" class="flex min-h-14 flex-wrap items-center gap-x-3 gap-y-1 py-2 pr-1 pl-3 text-[13px] text-ink shadow-[inset_3px_0_0_var(--game)] hover:bg-row-hover hover:text-ink">
                            <span class="flex min-w-0 grow items-center gap-2">
                                <x-avatar :user="$game->white" :size="24" class="shrink-0 rounded-full" /><b class="truncate">{{ $game->white->displayName() }}</b>
                                <span class="shrink-0 text-xs text-ink-3">{{ __('vs') }}</span>
                                <x-avatar :user="$game->black" :size="24" class="shrink-0 rounded-full" /><b class="truncate">{{ $game->black->displayName() }}</b>
                            </span>
                            <span class="flex shrink-0 items-baseline gap-2 text-xs text-ink-2">
                                {{ $game->rated ? __('Rated') : __('Casual') }} · {{ __('Move') }}
                                <b class="font-display text-lg text-ink tabular-nums">{{ intdiv($game->ply, 2) + 1 }}</b>
                                <span>{{ $game->number() }}</span>
                                <span class="rv-lk text-[13px]">{{ __('Watch') }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
