@props(['moments' => []])

{{--
    One row of proud moments from real records (canvas NEU (Scope) "Stolzmoment-Zeile", Main.dc.html and
    Mempool.dc.html; rule R13): App\Support\Engagement\HomeBoard::pride(), each moment left out without data.
--}}
@if ($moments !== [])
    <div {{ $attributes->class('flex h-11 flex-nowrap items-center gap-4 overflow-hidden rounded-[4px] border border-hairline bg-tick px-4 text-[13px]') }} data-test="pride-row">
        @foreach ($moments as $index => $moment)
            @if ($index > 0)
                <span class="h-5 w-px shrink-0 bg-line" aria-hidden="true"></span>
            @endif
            <span class="flex items-center gap-2 whitespace-nowrap" @if ($moment['title']) title="{{ $moment['title'] }}" @endif data-test="pride-{{ $moment['kind'] }}">
                <x-icon :name="$moment['kind'] === 'zap' ? 'bolt' : 'trophy'" :size="14" @class(['text-btc' => $moment['kind'] !== 'zap', 'text-bolt' => $moment['kind'] === 'zap']) />
                <span class="text-ink-2">{{ $moment['label'] }}</span>
                <b class="tabular-nums">{{ $moment['value'] }}</b>
                @if ($moment['name'])
                    <span>{{ $moment['name'] }}</span>
                @endif
            </span>
        @endforeach
    </div>
@endif
