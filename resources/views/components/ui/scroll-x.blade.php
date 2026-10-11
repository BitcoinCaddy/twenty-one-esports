@props(['label'])

{{--
    A wide table (or any wide block) that scrolls sideways inside its card, never the page. The edge fades say there is
    more on that side and fade out at the end (the chain viewport's pattern, rv-chain-fade); the viewport is one tab stop
    whose arrow keys scroll it. The fade takes the card's colour. Without JS the right fade stays hidden, the scroll
    still works.
--}}
<div {{ $attributes->class('rv-xs') }}
     x-data="{ l: false, r: false, edges() { const v = this.$refs.vp; this.l = v.scrollLeft > 1; this.r = v.scrollLeft + v.clientWidth < v.scrollWidth - 1; } }"
     x-init="edges()" x-on:resize.window="edges()">
    <div class="rv-xs-vp" x-ref="vp" x-on:scroll.passive="edges()" tabindex="0" role="region" aria-label="{{ $label }}">
        {{ $slot }}
    </div>
    <span class="rv-xs-fade is-l" x-bind:style="{ opacity: l ? 1 : 0 }" style="opacity: 0" aria-hidden="true" data-test="scroll-fade-l"></span>
    <span class="rv-xs-fade is-r" x-bind:style="{ opacity: r ? 1 : 0 }" style="opacity: 0" aria-hidden="true" data-test="scroll-fade-r"></span>
</div>
