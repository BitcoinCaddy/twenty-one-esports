@props(['code' => null, 'mark' => null, 'heading', 'text' => null])

{{--
    The body of every system page, 1:1 from SystemPages.dc.html (revamp P8): one card in the middle of the page,
    left-aligned, the status code as the one big display numeral in orange (`mark` instead of a code: a glyph or
    an icon slot for pages that are not errors, e.g. "#" for a match that does not exist yet), the heading in
    the display face, one line that says what to do, the action(s) in the slot. An orange glow from the top
    left and scanlines (`.rv-hero`), nothing else moves.
--}}
<div class="flex grow items-center justify-center px-4 py-10 lg:px-12 lg:py-16">
    <section {{ $attributes->merge(['data-test' => 'system-panel'])->class('rv-card rv-hero flex w-full max-w-[560px] flex-col items-start gap-4 p-6 lg:p-8') }} aria-labelledby="system-h">
        @if ($code !== null)
            <span class="rv-num text-[56px] text-btc lg:text-[64px]" data-test="system-code"><span class="sr-only">{{ __('Error :code', ['code' => $code]) }}: </span><span aria-hidden="true">{{ $code }}</span></span>
        @elseif ($mark !== null)
            <span class="rv-num text-[44px] text-btc" aria-hidden="true">{{ $mark }}</span>
        @elseif (isset($icon))
            <span class="text-btc" aria-hidden="true">{{ $icon }}</span>
        @endif
        <h1 id="system-h" class="m-0 font-display text-[22px] leading-[1.25] font-bold [overflow-wrap:anywhere] lg:text-[24px]">{{ $heading }}</h1>
        @if ($text)
            <p class="m-0 max-w-[52ch] text-[13px] leading-normal text-ink-2">{{ $text }}</p>
        @endif
        @isset($extra){{ $extra }}@endisset
        @if ($slot->isNotEmpty())
            <div class="mt-2 flex flex-wrap gap-3">{{ $slot }}</div>
        @endif
    </section>
</div>
