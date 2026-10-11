@props(['href', 'number' => null, 'art', 'score' => '–', 'scoreSub' => null, 'format', 'chip', 'check' => null, 'when' => null, 'blank' => false, 'scoreTest' => null])

{{--
    One row of the Mempool table (template T4 "Zeile", Mempool.dc.html, MempoolPhone.dc.html; rules R2, R8): every kind
    of match and highscore attempt in the same eight columns, Match · Art · Seiten · Ergebnis · Format · Status ·
    Prüfung · Wann, numbers tabular. On a phone two lines: the match number (or the result) bold with the check on the
    right, the sides under it. The caller passes its own data-test, wire:key and data attributes on the component.

    `chip`: SeriesPresenter::chip()/chipFor() (`key` one of waiting, scheduled, live, to_confirm, disputed, done, closed;
    `label`, `icon` an svg path). `check`: the "Prüfung" column, derived from `chip.key` unless given
    (['label' => string, 'tone' => ok|btc|bad|plain]). Slot `sides`: the sides, each name in its own element.
--}}
@php
    $check ??= match ($chip['key']) {
        'done' => ['label' => __('confirmed'), 'tone' => 'ok'],
        'to_confirm' => ['label' => __('to confirm'), 'tone' => 'btc'],
        'disputed' => ['label' => __('disputed'), 'tone' => 'bad'],
        'live' => ['label' => __('running'), 'tone' => 'btc'],
        'closed' => ['label' => $chip['label'], 'tone' => 'plain'],
        default => null,
    };
    $chipTone = match ($chip['key']) {
        'done' => 'is-ok',
        'live' => 'is-live',
        'to_confirm' => 'is-btc',
        'disputed' => 'is-bad',
        default => '',
    };
    $checkTone = match ($check['tone'] ?? null) {
        'ok' => 'is-ok',
        'btc' => 'is-btc',
        'bad' => 'is-bad',
        default => '',
    };
@endphp

<a href="{{ $href }}" @if ($blank) target="_blank" @endif {{ $attributes->class('mp-row') }}>
    <span class="mp-num max-lg:hidden">{{ $number }}</span>
    <span class="max-lg:hidden"><span class="rv-tag">{{ $art }}</span></span>
    <span class="mp-head lg:hidden">{{ $number ?? $score }}</span>
    <span class="mp-sides">{{ $sides }}</span>
    <span class="mp-score max-lg:hidden"><b @if ($scoreTest) data-test="{{ $scoreTest }}" @endif>{{ $score }}</b>@if ($scoreSub)<span class="text-[11px] text-btc-hi">{{ $scoreSub }}</span>@endif</span>
    <span class="truncate text-ink-2 max-lg:hidden">{{ $format }}</span>
    <span class="max-lg:hidden"><span @class(['rv-tag', $chipTone]) data-test="row-status"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chip['icon'] }}"></path></svg>{{ $chip['label'] }}</span></span>
    <span class="mp-check">
        @if ($check !== null)
            <span @class(['rv-tag', $checkTone]) data-test="row-check">{{ $check['label'] }}</span>
        @else
            <span class="text-ink-3" aria-hidden="true">–</span>
        @endif
    </span>
    <span class="truncate text-right text-ink-2 max-lg:hidden">{{ $when }}</span>
</a>
