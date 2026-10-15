{{--
    The live page's overview (user 2026-10-15: "Eine Matrix aller Spiele … mit Ampel-System, wo was steht und wo
    nachhak-Bedarf besteht"): how many pairings stand at each light, what needs following up (red first, with the
    reason and a link), and per group a cross table, a cell per pairing with the row's score and its light.
    Colour is never the only signal: every cell carries a shape (! red, · yellow, ✓ done) and its reason as its label.
    Only the table scrolls sideways on a phone, never the page (R3).

    $matrix: TournamentMatrix::overview()
--}}
@php
    $tone = [
        'red' => 'bg-loss-tint text-loss shadow-[inset_0_0_0_1px_var(--color-loss)]',
        'yellow' => 'bg-btc-chip text-btc-hi',
        'green' => 'bg-win-tint text-win',
        'done' => 'bg-raised text-ink',
        'waiting' => 'bg-well text-ink-3',
    ];
    $mark = ['red' => '!', 'yellow' => '·', 'green' => '', 'done' => '✓', 'waiting' => ''];
    $legend = [
        'red' => __('Follow up'),
        'yellow' => __('Keep an eye on it'),
        'green' => __('Under way'),
        'done' => __('Decided'),
    ];
@endphp
<section aria-labelledby="live-matrix-h" class="flex flex-col gap-4" data-test="live-matrix">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="live-matrix-h" class="m-0 text-[15px] font-bold">{{ __('All pairings at a glance') }}</h2>
        <ul class="m-0 flex list-none flex-wrap gap-2 p-0" data-test="matrix-counts">
            @foreach ($legend as $state => $label)
                <li class="inline-flex h-8 items-center gap-2 rounded-xs px-2.5 text-[13px] font-bold whitespace-nowrap {{ $tone[$state] }}" data-test="matrix-count" data-state="{{ $state }}">
                    <span class="font-display tabular-nums">{{ $matrix['counts'][$state] ?? 0 }}</span>{{ $label }}
                </li>
            @endforeach
        </ul>
    </div>

    @if ($matrix['attention'] !== [])
        {{-- What needs following up, red first: the pairing, the reason, the way to the game. --}}
        <ol class="m-0 flex list-none flex-col gap-1.5 rounded-lg bg-card p-3 text-[13px] lg:p-4" data-test="matrix-attention">
            @foreach ($matrix['attention'] as $row)
                <li class="grid grid-cols-[1.75rem_minmax(0,1fr)] items-start gap-x-2" data-test="matrix-attention-row" data-state="{{ $row['state'] }}">
                    <span class="inline-flex size-6 items-center justify-center rounded-xs text-xs font-bold {{ $tone[$row['state']] }}" aria-hidden="true">{{ $mark[$row['state']] }}</span>
                    <span class="flex min-w-0 flex-wrap items-baseline gap-x-2 leading-snug">
                        <span class="sr-only">{{ $legend[$row['state']] }}:</span>
                        @if ($row['href'])
                            <a href="{{ $row['href'] }}" class="font-bold text-ink [overflow-wrap:anywhere] hover:text-btc-hi">{{ $row['names'] }}</a>
                        @else
                            <b class="text-ink [overflow-wrap:anywhere]">{{ $row['names'] }}</b>
                        @endif
                        <span class="text-ink-3">{{ $row['group'] ? $row['group'].' · ' : '' }}{{ $row['key'] }}</span>
                        <span class="text-ink-2">{{ $row['reason'] }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
    @endif

    <div @class(['grid gap-4', 'xl:grid-cols-2' => count($matrix['groups']) > 1])>
        @foreach ($matrix['groups'] as $group)
            <section @if ($group['title']) aria-label="{{ $group['title'] }}" @endif class="flex min-w-0 flex-col gap-2 rounded-lg bg-card p-3 lg:p-4" data-test="matrix-group">
                @if ($group['title'])
                    <h3 class="m-0 text-[13px] font-bold text-ink-2">{{ $group['title'] }}</h3>
                @endif
                {{-- relative: the screen reader names in the headers are absolutely placed; they stay inside the scrolling box, not past the page edge. --}}
                <div class="relative overflow-x-auto overscroll-x-contain" data-test="matrix-scroll">
                    <table class="border-separate border-spacing-1 text-[13px]">
                        <thead>
                            <tr>
                                <th scope="col" class="text-left font-normal text-ink-3"><span class="sr-only">{{ __('Player') }}</span></th>
                                @foreach ($group['players'] as $index => $player)
                                    <th scope="col" class="w-11 min-w-11 text-center font-normal text-ink-3 tabular-nums" title="{{ $player['name'] }}"><span aria-hidden="true">{{ $index + 1 }}</span><span class="sr-only">{{ $player['name'] }}</span></th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['players'] as $index => $row)
                                <tr>
                                    <th scope="row" class="max-w-32 min-w-24 pr-2 text-left font-bold sm:max-w-56">
                                        <span class="flex min-w-0 items-center gap-2"><span class="w-4 shrink-0 text-right font-normal text-ink-3 tabular-nums">{{ $index + 1 }}</span><span class="truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</span></span>
                                    </th>
                                    @foreach ($group['players'] as $column)
                                        @php($cell = $row['id'] === $column['id'] ? null : ($group['cells'][$row['id']][$column['id']] ?? null))
                                        <td class="p-0">
                                            @if ($row['id'] === $column['id'])
                                                <span class="block h-11 rounded-xs bg-ground" aria-hidden="true"></span>
                                            @elseif ($cell === null)
                                                <span class="flex h-11 items-center justify-center rounded-xs bg-well text-ink-3" aria-label="{{ __('No pairing') }}">–</span>
                                            @else
                                                @php($label = $row['name'].' – '.$column['name'].': '.$cell['score'].', '.($legend[$cell['state']] ?? __('Waiting')).($cell['reason'] ? '. '.$cell['reason'] : ''))
                                                <a @if ($cell['href']) href="{{ $cell['href'] }}" @endif
                                                   class="relative flex h-11 min-w-11 items-center justify-center rounded-xs font-display text-[13px] font-bold tabular-nums {{ $tone[$cell['state']] }} {{ $cell['href'] ? 'hover:brightness-125' : '' }}"
                                                   aria-label="{{ $label }}" title="{{ $label }}" data-test="matrix-cell" data-state="{{ $cell['state'] }}" data-key="{{ $cell['key'] }}">
                                                    {{ $cell['score'] }}
                                                    @if ($mark[$cell['state']] !== '')
                                                        <span class="absolute top-0.5 right-1 text-[10px] leading-none" aria-hidden="true">{{ $mark[$cell['state']] }}</span>
                                                    @endif
                                                </a>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    </div>
</section>
