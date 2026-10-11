@props(['at' => null, 'size' => 'md', 'label' => null, 'units' => 'dhm'])

{{--
    Segmented countdown (design canvas Components.dc.html "Countdown-Segmente", Main.dc.html hero and season card):
    days, hours and minutes as three Unbounded figures in their own boxes, a quiet colon between. Without a moment
    the boxes show "--" (a date that is not set yet). The first frame comes from the server; Alpine counts on once
    a minute, so nothing jumps. `label`: the accessible name ahead of the time ("Start in"). `units`: `dhm` (default),
    `hms` or `ms` (the match room's step clock, MatchRoom-*.dc.html "Min / Sek"), which count every second, or `dhms`
    (Season.dc.html: Tage, Std, Min, Sek).
--}}
@php
    $ms = $at?->getTimestampMs();
    $left = $at === null ? null : max(0, (int) ceil(now()->diffInSeconds($at, false)));
    $split = match ($units) {
        'hms' => $left === null ? null : [intdiv($left, 3600), intdiv($left % 3600, 60), $left % 60],
        'ms' => $left === null ? null : [intdiv($left, 60), $left % 60],
        'dhms' => $left === null ? null : [intdiv($left, 86400), intdiv($left % 86400, 3600), intdiv($left % 3600, 60), $left % 60],
        default => $left === null ? null : [intdiv($left, 86400), intdiv($left % 86400, 3600), intdiv($left % 3600, 60)],
    };
    $labels = match ($units) {
        'hms' => [__('Hrs'), __('Mins'), __('Secs')],
        'ms' => [__('Mins'), __('Secs')],
        'dhms' => [__('Days'), __('Hrs'), __('Mins'), __('Secs')],
        default => [__('Days'), __('Hrs'), __('Mins')],
    };
    $parts = $split === null ? array_fill(0, count($labels), '--') : array_map(fn (int $n): string => sprintf('%02d', $n), $split);
    $spoken = match (true) {
        $left === null => __('date coming soon'),
        $units === 'ms' => trans_choice(':count minute|:count minutes', (int) $parts[0]).', '.trans_choice(':count second|:count seconds', (int) $parts[1]),
        $units === 'dhms' => trans_choice(':count day|:count days', (int) $parts[0]).', '.trans_choice(':count hour|:count hours', (int) $parts[1]).', '.trans_choice(':count minute|:count minutes', (int) $parts[2]).', '.trans_choice(':count second|:count seconds', (int) $parts[3]),
        $units === 'hms' => trans_choice(':count hour|:count hours', (int) $parts[0]).', '.trans_choice(':count minute|:count minutes', (int) $parts[1]).', '.trans_choice(':count second|:count seconds', (int) $parts[2]),
        default => trans_choice(':count day|:count days', (int) $parts[0]).', '.trans_choice(':count hour|:count hours', (int) $parts[1]).', '.trans_choice(':count minute|:count minutes', (int) $parts[2]),
    };
@endphp

<span role="timer" aria-label="{{ trim(($label ? $label.' ' : '').$spoken) }}" {{ $attributes->class(['rv-cd', 'is-sm' => $size === 'sm']) }}
      @if ($ms) x-data="segmentCountdown({{ $ms }}, @js($parts), @js($units))" @endif
      data-test="countdown-segments">
    @foreach ($parts as $index => $part)
        @if ($index > 0)
            <span class="rv-cd-colon" aria-hidden="true">:</span>
        @endif
        <span class="rv-cd-seg" aria-hidden="true">
            <b @if ($ms) x-text="p[{{ $index }}]" @endif>{{ $part }}</b>
            <span class="rv-m">{{ $labels[$index] }}</span>
        </span>
    @endforeach
</span>
