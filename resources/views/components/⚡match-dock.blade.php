<?php

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Dock\DockItem;
use App\Support\Dock\OpenMatches;
use App\Support\Tournaments\CupMatchNow;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The action dock (plan "Refactor und Design-Revamp" P7, ActionDock.dc.html and
 * ActionDockPhone.dc.html; before: P5f's floating MatchDock): every open match
 * and reason to act of a logged-in player, opened from the header's and the tab
 * bar's "Aktionen". What counts as open, and in which order, is
 * App\Support\Dock\OpenMatches. Live updates come from
 * resources/js/matchDock.js: the player's private channel and the watch channel
 * of each chess game on the dock ask for `$refresh`, without a websocket a poll does.
 *
 * The page the player is on is read once, at mount: its game or series never
 * has an entry, and on a game page the title row gets its own button (from lg);
 * a page that owns the bottom edge gets a tab on its bar (phones), which carries
 * `data-live-floor`: the live player (P20, resources/js/livePlayer.js) lifts itself above it.
 */
new class extends Component {
    #[Locked]
    public ?int $excludeGame = null;

    #[Locked]
    public ?int $excludeSeries = null;

    /** The board game on screen (plan "Mühle und Dame", P5), which never has a tab. */
    #[Locked]
    public ?int $excludeBoard = null;

    /** On a running game page: the dock goes into the title row from lg. */
    #[Locked]
    public bool $gamePage = false;

    /** On the player's own live blitz board: no ring, no pop-up. */
    #[Locked]
    public bool $quiet = false;

    /** The page's path, read at mount: the cup entry stays off the page it leads to. */
    #[Locked]
    public string $path = '';

    public function mount(): void
    {
        ['game' => $this->excludeGame, 'series' => $this->excludeSeries] = OpenMatches::onScreen(request());
        $this->excludeBoard = OpenMatches::boardOnScreen(request());
        $this->path = '/'.ltrim(request()->path(), '/');

        if ($this->excludeGame !== null) {
            $game = ChessGame::query()->find($this->excludeGame);
            $this->gamePage = $game?->isActive() === true;
            $this->quiet = $this->gamePage && ! $game->isCorrespondence() && $game->colorOf($this->user()) !== null;
        }

        // A running board game's page has the title-row slot too: the floating bar covered its chat's send button at 1440x900 (PR #5).
        if ($this->excludeBoard !== null) {
            $this->gamePage = BoardGame::query()->find($this->excludeBoard)?->isActive() === true;
        }
    }

    /**
     * @return Collection<int, DockItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return app(OpenMatches::class)->for($this->user(), $this->excludeGame, $this->excludeSeries, $this->excludeBoard);
    }

    /**
     * The player's open cup match (CupMatchNow; user, 2026-10-03): the
     * dock's first entry, highlighted. Not on the page it leads to.
     *
     * @return array{state: 'live'|'lobby'|'waiting', key: string, href: string, label: string, short: string, tournament: string, round: string, opponent: string, cup: bool, locked: bool, match: int}|null
     */
    #[Computed]
    public function cup(): ?array
    {
        $cup = app(CupMatchNow::class)->for($this->user());

        return $cup === null || parse_url($cup['href'], PHP_URL_PATH) === $this->path ? null : $cup;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $items = $this->items;
    $counts = OpenMatches::count($items);
    $nowMs = (int) now()->getTimestampMs();
    $cup = $this->cup;
    $needLabel = $counts['need'] > 0 ? trans_choice('need you|need you', $counts['need']) : __('waiting');
    $handleNumber = $counts['need'] > 0 ? $counts['need'] : $counts['wait'];
    $summary = __(':need need you, :open open', ['need' => $counts['need'], 'open' => $counts['open']]);
    // ActionDock.dc.html "Gruppen": Jetzt (running now or under its limit), Du bist dran, Einladungen, Demnächst.
    $isInvite = fn ($item): bool => str_ends_with($item->kind, '_invite') || str_ends_with($item->kind, '_challenge');
    $nowItems = $items->filter(fn ($item) => $item->needsYou && ($item->isLive() || $item->isUrgent($nowMs)));
    $rest = $items->reject(fn ($item) => $nowItems->contains('key', $item->key));
    $groups = [
        ['now', __('Now'), $nowItems],
        ['turn', __('Your turn'), $rest->filter(fn ($item) => $item->needsYou && ! $isInvite($item))],
        ['invites', __('Invitations'), $rest->filter(fn ($item) => $item->needsYou && $isInvite($item))],
        ['soon', __('Coming up'), $rest->reject(fn ($item) => $item->needsYou)],
    ];
    // The header's "Aktionen" turns red with the entry under its limit (ActionDock.dc.html "Zu, Frist unter 15 Min").
    $urgent = $nowItems->filter(fn ($item) => $item->isUrgent($nowMs))->sortBy(fn ($item) => $item->tick['endsAt'])->first();
    $config = [
        'now' => $nowMs,
        'need' => $counts['need'],
        'poll' => (int) config('esports.dock.poll_seconds'),
        'pollWithSocket' => (int) config('esports.dock.poll_seconds_with_socket'),
        'quiet' => $quiet,
        'labels' => ['hm' => __(':h h :m'), 'min' => __(':m min'), 'turned' => __('Your move: :sentence')],
    ];
@endphp

{{--
    The action dock (ActionDock.dc.html from 1024 px: a panel under the header on the right; ActionDockPhone.dc.html: a
    sheet over the tab bar; rules R4, R12, R17). Nothing floats over the page any more: the header's "Aktionen" and the
    tab bar's "Aktionen" open it, with their count, and turn red with a time when an entry runs under its limit. The
    entries are grouped Jetzt, Du bist dran, Einladungen, Demnächst, each one card with its one action. On a running game
    page the title row keeps its own button (from lg); a page that owns the bottom edge gets a tab on its bar (phones).
--}}
{{-- The config rides in its own attribute, so the x-data expression never changes: a refresh morphs the dock instead of starting it anew (an open sheet stayed open, P7). --}}
<div x-data="matchDock(JSON.parse($el.dataset.config))" data-config="{{ json_encode($config) }}" data-need="{{ $counts['need'] }}" data-open="{{ $counts['open'] }}" data-cup="{{ $cup ? '1' : '0' }}" data-test="match-dock-root"
     @if ($urgent) data-urgent='@json(['label' => $urgent->state, 'tick' => $urgent->tick])' @elseif ($cup && $cup['state'] === 'live') data-urgent='@json(['label' => $cup['short'], 'tick' => null])' @endif
     x-on:keydown.escape.window="close(true)" x-on:dock-open.window="openFromHeader() && $event.detail?.preventDefault()" x-on:bell-toggle.window="$event.detail && close(false)" x-on:chat-sheet-toggle.window="chatToggled($event.detail)">
    <span class="sr-only" aria-live="polite" x-text="announcement"></span>

    @if ($items->isNotEmpty() || $cup)
        {{--
            Every item once, for resources/js/matchDock.js (which turned to "on you", which chess
            games to watch) and alerts.js (no toast for a game that already has an entry).
        --}}
        @foreach ($items as $item)
            <span hidden wire:key="item-{{ $item->key }}" data-dock-item="{{ $item->key }}" data-need="{{ $item->needsYou ? '1' : '0' }}" data-sentence="{{ $item->sentence }}"
                  @if ($item->gameId()) data-dock-game="{{ $item->gameId() }}" @endif></span>
        @endforeach

        {{-- Game pages from lg: one button in the title row, opening the whole list. --}}
        @if ($gamePage && $items->isNotEmpty())
            @teleport('[data-dock-slot]')
                <div class="relative hidden lg:block" data-test="dock-slot">
                    <button type="button" class="flex h-11 cursor-pointer items-center gap-2 rounded-[4px] border border-btc bg-btc-chip px-3 text-xs whitespace-nowrap"
                            x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-controls="dock-sheet" data-test="dock-slot-button">
                        <x-icon name="bolt" :size="14" class="text-btc" />
                        <b @class(['font-display text-[15px]', 'text-btc' => $counts['need'] > 0, 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b>
                        <span class="text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
                    </button>
                </div>
            @endteleport
        @endif

        {{-- A page that owns the bottom edge (the daily move bar, the chat sheet): a tab on its bar, phones only. --}}
        <button type="button" class="fixed left-4 z-[35] flex h-11 cursor-pointer items-center gap-2 rounded-t-lg border-0 bg-bar px-3 text-xs whitespace-nowrap text-btc shadow-[0_-1px_0_var(--color-line),-1px_0_0_var(--color-line),1px_0_0_var(--color-line),inset_0_3px_0_var(--color-btc)] lg:hidden"
                x-show="pageBar && ! row && ! keyboard && ! chatOpen" x-cloak x-bind:style="pageBar && { bottom: pageBar + 'px' }"
                x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-haspopup="dialog" data-test="dock-bar-tab" data-live-floor>
            <x-match-dock.cube :grey="$counts['need'] === 0" />
            <b @class(['font-display text-[15px]', 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b><span class="text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
            <x-icon name="chevron-up" :size="16" class="text-ink-2" />
        </button>

        {{--
            Beside the closed chat bar, in its row (DerCaddy, 2026-10-10: "10 wartet" next to the chat bar, each opening its
            own sheet): the row's left end, as tall as the row, with the same grip as the chat sheet. The row leaves it room
            (resources/js/matchDock.js, --dock-row-w). On the sheet's layer and after it in the page, so it paints over the
            chat bar and under both sheets and their shade.
        --}}
        <button type="button" class="fixed left-0 z-40 flex max-w-[40%] cursor-pointer flex-col items-stretch gap-2 rounded-tl-2xl border-0 bg-bar pt-2 pr-3 pb-3 pl-4 text-xs whitespace-nowrap text-btc shadow-[inset_-1px_0_0_var(--color-hairline)] lg:hidden"
                x-show="row && ! keyboard && ! chatOpen" x-cloak x-bind:style="row && { bottom: row.bottom + 'px', height: row.height + 'px' }"
                x-on:click="toggle('sheet', $el)" x-bind:aria-expanded="(open === 'sheet').toString()" aria-expanded="false" aria-haspopup="dialog" data-dock-row-tab data-test="dock-row-tab" data-live-floor>
            <span aria-hidden="true" class="h-1 w-10 self-center rounded-xs bg-edge"></span>
            {{-- At most 40 % of the row: on a narrow phone a long label ("2 need you") gives way before the chat does. --}}
            <span class="flex min-w-0 items-center gap-2">
                <x-match-dock.cube :grey="$counts['need'] === 0" />
                <b @class(['shrink-0 font-display text-[15px]', 'text-ink-2' => $counts['need'] === 0])>{{ $handleNumber }}</b><span class="min-w-0 truncate text-ink-2">{{ $needLabel }}</span><span class="sr-only">, {{ trans_choice(':count open match|:count open matches', $counts['open']) }}</span>
                <x-icon name="chevron-up" :size="16" class="shrink-0 text-ink-2" />
            </span>
        </button>

        <div x-show="open === 'sheet'" x-cloak>
            <div aria-hidden="true" class="fixed inset-0 z-40 bg-[rgba(10,10,11,.7)] lg:top-14 lg:bg-[rgba(10,10,11,.55)]" x-on:click="close(false)"></div>
            <section id="dock-sheet" role="dialog" aria-modal="true" aria-labelledby="dock-sheet-h" class="dk-drawer" data-test="dock-sheet">
                <div class="flex shrink-0 flex-col gap-2 border-b border-hairline pt-2 pr-1 pb-3 pl-4 lg:pt-4">
                    <span aria-hidden="true" class="h-1 w-10 self-center rounded-[2px] bg-edge lg:hidden"></span>
                    <span class="flex items-center gap-2.5">
                        <h2 id="dock-sheet-h" class="m-0 font-display text-[18px] font-bold">{{ __('Actions') }}</h2>
                        @if ($counts['need'] > 0)
                            <span class="rv-n is-r" data-test="dock-count">{{ $counts['need'] }}</span>
                        @endif
                        <span class="rv-m">{{ $counts['need'] > 0 ? trans_choice('need you|need you', $counts['need']) : __('waiting') }}</span>
                        <span class="grow"></span>
                        <button type="button" class="dk-x" x-ref="sheetClose" x-on:click="close(true)" aria-label="{{ __('Close list') }}"><x-icon name="close" :size="16" /></button>
                    </span>
                    <span class="sr-only">{{ $summary }}</span>
                </div>
                <div class="flex flex-col gap-2 overflow-y-auto px-3 pt-3 pb-4">
                    @foreach ($groups as [$key, $heading, $group])
                        @if ($group->isNotEmpty() || ($key === 'now' && $cup))
                            <h3 class="m-0 flex items-center gap-2 px-1 pt-2 text-[12px] font-normal text-ink-2" data-test="dock-group-{{ $key }}">
                                @if ($key === 'now')<span class="rv-live h-5 w-7 justify-center px-0" aria-hidden="true"><i></i></span>@endif{{ $heading }}
                            </h3>
                            @if ($key === 'now' && $cup)
                                {{-- The open cup match (CupMatchNow; user, 2026-10-03): the first entry, highlighted. --}}
                                <div wire:key="dock-cup-{{ $cup['key'] }}" class="dk-card is-now" data-test="dock-cup" data-state="{{ $cup['state'] }}">
                                    <span class="flex h-10 w-[72px] shrink-0 items-center justify-center rounded-[2px] bg-btc-tint text-btc" aria-hidden="true"><x-icon name="trophy" :size="20" /></span>
                                    <a href="{{ $cup['href'] }}" class="flex min-w-0 grow flex-col gap-0.5 text-ink hover:text-ink">
                                        <b class="dk-card-title" data-test="dock-cup-label">{{ $cup['label'] }}</b>
                                        <span class="truncate text-[12px] text-ink-2">{{ $cup['tournament'] }}, {{ $cup['round'] }}</span>
                                    </a>
                                    <span class="flex shrink-0 flex-col items-end gap-1.5">
                                        @if ($cup['state'] === 'live')
                                            <span class="rv-live h-5"><i></i>{{ __('live') }}</span>
                                        @endif
                                        <a href="{{ $cup['href'] }}" class="rv-b is-sm">{{ $cup['short'] }}</a>
                                    </span>
                                </div>
                            @endif
                            @foreach ($group as $item)
                                <x-match-dock.card :item="$item" :now-ms="$nowMs" :now="$key === 'now'" />
                            @endforeach
                        @endif
                    @endforeach
                </div>
            </section>
        </div>
    @endif
</div>
