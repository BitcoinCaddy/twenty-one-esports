@props(['items' => [], 'label' => null, 'caption' => null])

{{--
    A chip filter row, Priority+ (rule R8, LadderPage.dc.html "Spiel"): one line, the current chip first in the
    caller's order; every chip that fits is shown, and only when the line really runs out of width the rest folds
    into a trailing "+N weitere" menu. Below lg the row scrolls sideways with an edge fade instead of folding.
    Never a second line. Each item: ['href' => string, 'label' => string, 'current' => bool, 'test' => ?string,
    'dim' => bool (no hits: dimmed, still a link)].
--}}
<div {{ $attributes->class('flex min-w-0 items-center gap-3') }}
     x-data="{
         hidden: [], open: false,
         fit() {
             const row = this.$refs.row; const chips = [...row.querySelectorAll('[data-chip]')];
             chips.forEach((chip) => { chip.hidden = false; });
             const hidden = [];
             if (matchMedia('(min-width: 64rem)').matches && row.scrollWidth > row.clientWidth) {
                 const room = row.clientWidth - 150;
                 let used = 0;
                 chips.forEach((chip, index) => {
                     used += chip.offsetWidth + 8;
                     if (used > room && index > 0) { chip.hidden = true; hidden.push(index); }
                 });
             }
             this.hidden = hidden;
         },
     }"
     x-init="$nextTick(() => fit()); new ResizeObserver(() => fit()).observe($refs.row)" x-on:keydown.escape="open = false" data-test="chip-row">
    @if ($caption)
        <span class="rv-m shrink-0 max-lg:hidden">{{ $caption }}</span>
    @endif
    <nav class="relative flex min-w-0 grow items-center gap-2 max-lg:overflow-x-auto max-lg:[mask-image:linear-gradient(90deg,#000_85%,transparent)]" aria-label="{{ $label }}" x-ref="row">
        @foreach ($items as $item)
            <a href="{{ $item['href'] }}" @navigate($item['href']) data-chip @if ($item['current'] ?? false) aria-current="page" @endif
               @class(['gm-chip h-11', 'is-dim' => $item['dim'] ?? false]) @if ($item['test'] ?? null) data-test="{{ $item['test'] }}" @endif
               >{{ $item['label'] }}</a>
        @endforeach
        <div class="relative ms-auto shrink-0 max-lg:hidden" x-show="hidden.length > 0" x-cloak x-on:click.outside="open = false">
            <button type="button" class="gm-chip h-11 gap-2 text-ink" x-ref="more" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="true" data-test="chip-more">
                <span x-text="@js(__('+:count more', ['count' => '__N__'])).replace('__N__', hidden.length)"></span><x-icon name="chevron-down" :size="14" />
            </button>
            <div x-show="open" x-cloak class="absolute top-full right-0 z-30 mt-1 flex max-h-80 w-max min-w-48 flex-col overflow-y-auto rounded-[4px] border border-line bg-row-hover p-1 shadow-[0_8px_24px_rgba(0,0,0,.5)]">
                @foreach ($items as $index => $item)
                    <a href="{{ $item['href'] }}" @navigate($item['href']) class="flex h-10 items-center rounded-[3px] px-2.5 text-[14px] text-ink-2 hover:bg-raised hover:text-ink"
                       x-show="hidden.includes({{ $index }})">{{ $item['label'] }}</a>
                @endforeach
            </div>
        </div>
    </nav>
</div>
