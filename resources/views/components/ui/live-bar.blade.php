@props(['ticks' => []])

{{--
    "gerade eben" (design canvas `.tick`, Main.dc.html): the league's latest events in one 32 px row under the key
    art: a move in a running game, a result, a checked run (App\Support\Engagement\HomeBoard::ticker()). Only event
    types the league records. It does not scroll by itself (readable tempo, rule R17); a narrow screen swipes it.

    One tab stop for the whole row (roving tabindex): Tab enters on the first event, the arrow keys move between them,
    Home/End jump to the ends. Each event was its own stop and took 14 of the first 22 Tab presses on the start page.
    Every event fills the row's height, so its target is the row (32 px; 44 px on a touch screen, rule R16).
--}}
@if ($ticks !== [])
    <div role="group" aria-label="{{ __('Latest league events, arrow keys move between them') }}" {{ $attributes->class('rv-tick overflow-x-auto [scrollbar-width:none]') }} data-test="live-bar"
         x-data="{ at: 0, go(to) { const items = [...this.$el.querySelectorAll('[data-test=live-bar-item]')]; this.at = Math.max(0, Math.min(items.length - 1, to)); items.forEach((item, index) => { item.tabIndex = index === this.at ? 0 : -1; }); items[this.at].focus(); } }"
         x-on:keydown.right.prevent="go(at + 1)" x-on:keydown.left.prevent="go(at - 1)" x-on:keydown.home.prevent="go(0)" x-on:keydown.end.prevent="go(Infinity)">
        <span class="rv-live h-5 shrink-0" aria-hidden="true"><i></i>{{ __('just now') }}</span>
        @foreach ($ticks as $index => $tick)
            @if ($index > 0)
                <span class="rv-tick-sep" aria-hidden="true"></span>
            @endif
            <a href="{{ $tick['href'] }}" tabindex="{{ $index === 0 ? '0' : '-1' }}" class="flex shrink-0 items-center gap-2 self-stretch px-1 text-ink hover:text-ink focus-visible:outline-offset-[-2px]" data-test="live-bar-item">
                <span class="text-ink-3">{{ $tick['kind'] }}</span>
                <span>{{ $tick['text'] }}</span>
                <span class="text-ink-3">{{ $tick['at']->diffForHumans(['parts' => 1, 'short' => true]) }}</span>
            </a>
        @endforeach
    </div>
@endif
