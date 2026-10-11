{{--
    Season Wrapped as a story (plan "KI-Stimme der Liga", P4; PrideController::wrapped(); board SeasonWrapped.dc.html, desktop and phone). The season card's facts
    (ShareMoments::wrapped(), PrideMoments::wrapped()) page by page: who and which season, the blocks and sats, the
    games, the best rank, the tournaments won (only when there are any) and a last page with a line and the card.

    Rule R17: nothing moves on its own. A page waits for a click, a tap (left third back, the rest forward), the arrow
    keys or the buttons under it; the change is a 240 ms fade (none with reduced motion). Rule R13: every number is a
    league record. Rule R7: posting and the summary sit in the right column (under the story on a phone); the share
    button renders for the player only. Grid R1: the story in the 8 main columns, the column in the 4 on the right.

    `$season`, `$player`, `$facts`, `$card` (ShareCard::wrapped()), `$line` (PrideVoice: the model's line once
    written and switched on, else PrideMoments::wrappedLine()).
--}}
@php
    $name = $player->displayName();
    $seasonName = \App\Support\Badges\BadgeCopy::season($season->slug);
    $live = (bool) $facts['live'];
    $isMe = auth()->id() === $player->id;
    $best = is_array($facts['best']) ? $facts['best'] + \App\Support\Rating\Ratings::badge((string) $facts['best']['tier']) : null;
    $sats = \App\Support\Cards\ShareCard::sats((int) $facts['sats']);
    $results = (int) $facts['results'];
    $split = [
        ['count' => (int) $facts['wins'], 'label' => trans_choice(':count win|:count wins', (int) $facts['wins']), 'class' => 'bg-win'],
        ['count' => (int) $facts['draws'], 'label' => trans_choice(':count draw|:count draws', (int) $facts['draws']), 'class' => 'bg-ink-3'],
        ['count' => (int) $facts['losses'], 'label' => trans_choice(':count loss|:count losses', (int) $facts['losses']), 'class' => 'bg-loss'],
    ];
    $pages = array_values(array_filter(['intro', 'blocks', 'games', 'rank', $facts['tournaments'] > 0 ? 'titles' : null, 'card']));
    $cubes = min(21, (int) $facts['blocks']);

    app(\App\Support\PageMeta::class)->describe(
        __(':name’s :season, wrapped', ['name' => $name, 'season' => $seasonName]),
        __(':name mined :blocks blocks and :sats sats in :season of the TWENTY ONE esports league.', ['name' => $name, 'blocks' => $facts['blocks'], 'sats' => $sats, 'season' => $seasonName]),
    )->card(fn () => new class($card)
    {
        public function __construct(private \App\Support\Cards\ShareCard $card) {}

        public function url(): string
        {
            return $this->card->url('wide');
        }

        public function alt(): string
        {
            return app(\App\Support\Cards\SharePosts::class)->sentence($this->card);
        }
    });
@endphp
<x-layouts::app :title="__(':season, wrapped', ['season' => $seasonName])">
    <div class="grid grid-cols-1 gap-6 px-4 pb-8 lg:grid-cols-12 lg:gap-6 lg:px-12" data-test="wrapped-page">
        {{-- The story: 9:16, as tall as the screen allows; one page at a time. --}}
        <section class="flex min-w-0 flex-col items-center gap-3 lg:col-span-8 lg:items-start" aria-labelledby="wrapped-h"
                 x-data="{
                     page: 0,
                     count: {{ count($pages) }},
                     go(step) { this.page = (this.page + step + this.count) % this.count; },
                     key(event) {
                         if (event.target.closest('input, textarea, [contenteditable]')) return;
                         if (['ArrowRight', 'PageDown'].includes(event.key)) { event.preventDefault(); this.go(1); }
                         if (['ArrowLeft', 'PageUp'].includes(event.key)) { event.preventDefault(); this.go(-1); }
                     },
                 }"
                 x-on:keydown.window="key($event)" data-test="wrapped-story">
            <h1 id="wrapped-h" class="sr-only">{{ __(':name’s :season, wrapped', ['name' => $name, 'season' => $seasonName]) }}</h1>

            <div class="relative isolate flex aspect-[9/16] w-full max-w-[440px] flex-col overflow-hidden rounded-[4px] bg-card shadow-[inset_0_0_0_1px_var(--color-line)] max-lg:max-h-[calc(100svh-200px)] max-lg:min-h-[560px] lg:h-[max(560px,min(760px,calc(100svh-260px)))] lg:w-auto lg:max-w-none"
                 role="region" aria-roledescription="{{ __('story') }}" aria-label="{{ __(':season, wrapped', ['season' => $seasonName]) }}" data-test="wrapped-stage">
                {{-- Where the reader is: one segment per page, filled up to the current one; nothing fills by time. --}}
                <ol class="m-0 flex list-none gap-1 p-3 pb-0" aria-hidden="true">
                    @foreach ($pages as $index => $key)
                        <li class="h-1 flex-1 rounded-[2px] bg-line" :class="page >= {{ $index }} && 'bg-btc!'"></li>
                    @endforeach
                </ol>

                {{-- Tap zones: the left third goes back, the rest forward (the buttons below do the same for keyboards). --}}
                <button type="button" class="absolute inset-y-0 left-0 z-10 w-1/3 cursor-w-resize outline-none" x-on:click="go(-1)" tabindex="-1" aria-hidden="true" data-test="wrapped-tap-back"></button>
                <button type="button" class="absolute inset-y-0 right-0 z-10 w-2/3 cursor-e-resize outline-none" x-on:click="go(1)" tabindex="-1" aria-hidden="true" data-test="wrapped-tap-next"></button>

                @foreach ($pages as $index => $key)
                    <div class="absolute inset-x-0 top-6 bottom-0 flex flex-col justify-center gap-6 p-6 sm:p-8" role="group" aria-roledescription="{{ __('page') }}"
                         aria-label="{{ __(':page of :count', ['page' => $index + 1, 'count' => count($pages)]) }}"
                         x-show="page === {{ $index }}" @if ($index > 0) x-cloak @endif
                         x-transition:enter="transition duration-[240ms] ease-out motion-reduce:transition-none" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                         data-test="wrapped-page-{{ $key }}">
                        @switch($key)
                            @case('intro')
                                {{-- The opening: who, then the season as the big word, its first blocks as a chain under it. --}}
                                <span class="flex items-center gap-3">
                                    <x-avatar :user="$player" :size="64" class="size-16! rounded-full" />
                                    <p class="m-0 min-w-0 font-display text-[24px] leading-[1.2] font-bold [overflow-wrap:anywhere]" data-test="wrapped-name">{{ $name }}</p>
                                </span>
                                <p class="m-0 font-display text-[48px] leading-[1.05] font-bold text-btc [overflow-wrap:anywhere]">{{ $seasonName }}</p>
                                <p class="m-0 text-[20px] leading-[1.4] text-ink-2">{{ $live ? __('your season so far, page by page') : __('your season, page by page') }}</p>
                                <span class="flex items-center gap-1" aria-hidden="true">
                                    @foreach (range(1, count($pages)) as $link)
                                        <span class="size-5 rounded-[2px] {{ $link === 1 ? 'bg-btc shadow-[inset_0_-4px_0_var(--color-btc-deep)]' : 'bg-line' }}"></span>
                                        @if ($link < count($pages))<span class="h-0.5 w-3 bg-line"></span>@endif
                                    @endforeach
                                </span>
                                <p class="m-0 text-[13px] text-ink-3">{{ __('Tap or press → for the next page.') }}</p>
                                @break
                            @case('blocks')
                                <p class="m-0 text-[20px] text-ink-2">{{ __('You mined') }}</p>
                                <p class="m-0 font-display text-[96px] leading-none font-bold text-btc tabular-nums" data-test="wrapped-blocks">{{ $facts['blocks'] }}</p>
                                <p class="m-0 text-[20px] leading-[1.4]">{{ trans_choice('block on the season chain|blocks on the season chain', (int) $facts['blocks']) }}</p>
                                @if ($cubes > 0)
                                    {{-- One cube per block, at most 21: the season chain in small. --}}
                                    <span class="flex flex-wrap gap-1.5" aria-hidden="true">
                                        @for ($i = 0; $i < $cubes; $i++)
                                            <span class="size-4 rounded-[2px] bg-btc shadow-[inset_0_-3px_0_var(--color-btc-deep)]"></span>
                                        @endfor
                                    </span>
                                @endif
                                <p class="m-0 text-[16px] leading-[1.5] text-btc-hi"><b class="tabular-nums">{{ $sats }}</b> {{ __('sats mined') }}</p>
                                @break
                            @case('games')
                                <p class="m-0 text-[20px] text-ink-2">{{ __('You played') }}</p>
                                <p class="m-0 font-display text-[96px] leading-none font-bold tabular-nums" data-test="wrapped-results">{{ $results }}</p>
                                <p class="m-0 text-[20px] leading-[1.4]">{{ trans_choice('rated game|rated games', $results) }}</p>
                                @if ($results > 0)
                                    <span class="flex h-3 w-full overflow-hidden rounded-[2px] bg-line" aria-hidden="true">
                                        @foreach ($split as $part)
                                            @if ($part['count'] > 0)
                                                <span class="{{ $part['class'] }} h-full" style="width: {{ round($part['count'] / $results * 100, 2) }}%"></span>
                                            @endif
                                        @endforeach
                                    </span>
                                    <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[14px]">{{-- one per line (board SeasonWrapped): a row ran 16 px past a 390 story --}}
                                        @foreach ($split as $part)
                                            <li class="flex items-center gap-2"><span class="{{ $part['class'] }} size-2.5 rounded-[2px]" aria-hidden="true"></span>{{ $part['label'] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @break
                            @case('rank')
                                <p class="m-0 text-[20px] text-ink-2">{{ __('Your best rank') }}</p>
                                @if ($best !== null)
                                    <svg width="120" height="120" viewBox="0 0 14 14" aria-hidden="true" style="color: var(--color-rank-{{ $best['tier'] }})"><path d="M7 1.5 12.5 4.7v4.6L7 12.5 1.5 9.3V4.7z" fill="currentColor"></path><path d="M7 1 13 4.5 7 8 1 4.5z" fill="#FFFFFF" fill-opacity=".35"></path></svg>
                                    <p class="m-0 font-display text-[40px] leading-[1.1] font-bold" style="color: var(--color-rank-{{ $best['tier'] }})" data-test="wrapped-rank">{{ \App\Support\Rating\RankTiers::label((string) $facts['best']['tier']) }}</p>
                                    <p class="m-0 text-[16px] leading-[1.5]">{{ __(':rating in :ladder', ['rating' => $facts['best']['rating'], 'ladder' => $facts['best']['ladder']]) }}</p>
                                @else
                                    <p class="m-0 font-display text-[40px] leading-[1.1] font-bold text-ink-2" data-test="wrapped-rank">{{ __('Provisional') }}</p>
                                    <p class="m-0 max-w-[34ch] text-[16px] leading-[1.5] text-ink-2">{{ __('A rank shows after your first rated games in a ladder.') }}</p>
                                @endif
                                @break
                            @case('titles')
                                <p class="m-0 text-[20px] text-ink-2">{{ __('You won') }}</p>
                                <p class="m-0 font-display text-[96px] leading-none font-bold text-rank-gold tabular-nums" data-test="wrapped-titles">{{ $facts['tournaments'] }}</p>
                                <p class="m-0 text-[20px] leading-[1.4]">{{ trans_choice('tournament|tournaments', (int) $facts['tournaments']) }}</p>
                                <ul class="m-0 flex list-none flex-col gap-2 p-0 text-[16px]">
                                    @foreach ($facts['won'] as $won)
                                        <li class="flex min-w-0 items-center gap-2"><x-icon name="trophy" :size="16" class="shrink-0 text-rank-gold" /><span class="truncate">{{ $won }}</span></li>
                                    @endforeach
                                </ul>
                                @break
                            @case('card')
                                <p class="m-0 font-display text-[24px] leading-[1.25] font-bold [overflow-wrap:anywhere]" data-test="wrapped-line">{{ $line }}</p>
                                <img src="{{ $card->path('wide') }}" width="1200" height="630" alt="{{ __('Your :season on one card', ['season' => $seasonName]) }}" decoding="async"
                                     class="aspect-[1200/630] h-auto w-full rounded-[4px] shadow-ring" data-test="wrapped-card">
                                <p class="m-0 text-[13px] text-ink-3">@if ($isMe)<span class="lg:hidden">{{ __('Post it or download it below the story.') }}</span><span class="max-lg:hidden">{{ __('Post it or download it beside the story.') }}</span>@else{{ __('Every number is a league record.') }}@endif</p>
                                @break
                        @endswitch
                    </div>
                @endforeach
            </div>

            <div class="flex w-full max-w-[440px] items-center gap-3 lg:max-w-[calc(max(560px,min(760px,100svh-260px))*9/16)]">
                <button type="button" x-on:click="go(-1)" x-bind:disabled="page === 0" data-test="wrapped-back"
                        class="inline-flex h-11 shrink-0 items-center gap-2 rounded-md border border-line bg-well px-4 text-[14px] whitespace-nowrap text-ink disabled:opacity-40">
                    <x-icon name="prev" :size="16" />{{ __('Back') }}
                </button>
                <span class="grow text-center text-[13px] text-ink-3 tabular-nums" aria-live="polite" data-test="wrapped-position">
                    <span x-text="page + 1">1</span> / {{ count($pages) }}
                </span>
                <button type="button" x-on:click="go(1)" data-test="wrapped-next"
                        class="inline-flex h-11 shrink-0 items-center gap-2 rounded-md bg-btc px-4 text-[14px] font-bold whitespace-nowrap text-on-btc hover:bg-btc-hi">
                    <span x-text="page === count - 1 ? @js(__('From the start')) : @js(__('Next page'))">{{ __('Next page') }}</span><x-icon name="next" :size="16" />
                </button>
            </div>
        </section>

        {{-- The column (R7): post the card (the player only), every number at a glance, the ways on. --}}
        <aside class="flex min-w-0 flex-col gap-4 lg:col-span-4" aria-labelledby="wrapped-side-h" data-test="wrapped-side">
            <div class="rv-card flex flex-col gap-3 p-4 lg:p-6">
                <h2 id="wrapped-side-h" class="rv-h2">{{ $isMe ? __('Your :season on one card', ['season' => $seasonName]) : __(':season of :name', ['season' => $seasonName, 'name' => $name]) }}</h2>
                @if ($isMe)
                    <livewire:share-button type="wrapped" :moment="$season->slug" :label="__('Post my season')" :wire:key="'wrapped-story-share-'.$season->slug" />
                @endif
                <dl class="m-0 grid grid-cols-2 gap-x-4 gap-y-3 text-[13px]" data-test="wrapped-summary">
                    <div class="flex flex-col gap-0.5"><dt class="text-ink-3">{{ __('Blocks') }}</dt><dd class="m-0 font-bold text-ink tabular-nums">{{ $facts['blocks'] }}</dd></div>
                    <div class="flex flex-col gap-0.5"><dt class="text-ink-3">{{ __('Sats') }}</dt><dd class="m-0 font-bold text-ink tabular-nums">{{ $sats }}</dd></div>
                    <div class="flex flex-col gap-0.5"><dt class="text-ink-3">{{ __('Rated games') }}</dt><dd class="m-0 font-bold text-ink tabular-nums">{{ $results }}</dd></div>
                    <div class="flex flex-col gap-0.5"><dt class="text-ink-3">{{ __('Rated wins') }}</dt><dd class="m-0 font-bold text-ink tabular-nums">{{ $facts['wins'] }}</dd></div>
                    <div class="flex flex-col gap-0.5"><dt class="text-ink-3">{{ __('Best rank') }}</dt><dd class="m-0 font-bold text-ink">{{ $best !== null ? \App\Support\Rating\RankTiers::label((string) $facts['best']['tier']) : __('Provisional') }}</dd></div>
                    <div class="flex flex-col gap-0.5"><dt class="text-ink-3">{{ __('Tournament wins') }}</dt><dd class="m-0 font-bold text-ink tabular-nums">{{ $facts['tournaments'] }}</dd></div>
                </dl>
            </div>
            <nav class="flex flex-col" aria-label="{{ __('More') }}">
                <a href="{{ route('players.show', $player->npub) }}" class="flex min-h-11 items-center gap-2 border-b border-hairline text-[14px]" data-test="wrapped-to-player"><x-icon name="user" :size="16" />{{ $isMe ? __('Your player page') : __(':name’s player page', ['name' => $name]) }}</a>
                <a href="{{ route('mining') }}" class="flex min-h-11 items-center gap-2 border-b border-hairline text-[14px]" data-test="wrapped-to-season"><x-icon name="mining" :size="16" />{{ __('The season chain') }}</a>
            </nav>
        </aside>
    </div>
</x-layouts::app>
