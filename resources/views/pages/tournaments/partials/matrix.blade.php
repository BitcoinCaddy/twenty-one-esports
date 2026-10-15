{{--
    The live page's overview (user 2026-10-15: "Eine Matrix aller Spiele … mit Ampel-System"; then "jetzt sehen die
    Admins nicht mehr die ablaufenden Timeouts … ALLES IST zu textlich"): the counts per light, the deadlines as tiles
    (the one that runs out first first: the time left big, who is to move), and per group a cross table whose cells
    show the time left to the move deadline, a dot where the row's player is to move, and the games so far small.
    Colour is never the only signal: the time, the dot and the cell's label carry it too.
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
    $legend = [
        'red' => __('Critical'),
        'yellow' => __('Due soon'),
        'green' => __('On track'),
        'done' => __('Decided'),
    ];
    $flags = ['restart' => 'retry', 'nofirst' => 'clock', 'held' => 'warn', 'disputed' => 'flag', 'overdue' => 'flag', 'reported' => 'check', 'nogame' => 'clock'];
    // Every red and yellow pairing; with fewer than four, the next deadlines on track fill up to four (a page where
    // everything is on track does not repeat the tables as tiles).
    $urgent = array_values(array_filter($matrix['clock'], fn (array $tile): bool => in_array($tile['state'], ['red', 'yellow'], true)));
    $tiles = count($urgent) >= 4 ? $urgent : array_slice([...$urgent, ...array_values(array_filter($matrix['clock'], fn (array $tile): bool => ! in_array($tile['state'], ['red', 'yellow'], true)))], 0, 4);
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

    @if ($tiles !== [])
        {{-- The deadlines, the one that runs out first first: the time left big, who is to move, against whom. --}}
        {{-- A phone gets one row to swipe (the list must not push the tables down), wider screens a grid. --}}
        <ol class="relative m-0 flex list-none gap-2 overflow-x-auto overscroll-x-contain p-0 pb-1 sm:grid sm:grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] sm:overflow-visible sm:pb-0" aria-label="{{ __('Deadlines, soonest first') }}" data-test="matrix-clock">
            @foreach ($tiles as $tile)
                @php($label = ($tile['mover'] ? __(':name to move', ['name' => $tile['mover']]).', ' : '').$tile['names'].($tile['left'] ? ', '.__('deadline in :left', ['left' => $tile['left']]) : '').($tile['reason'] && ! $tile['mover'] ? '. '.$tile['reason'] : ''))
                <li class="w-34 shrink-0 sm:w-auto sm:min-w-0">
                    <a @if ($tile['href']) href="{{ $tile['href'] }}" @endif class="flex h-full min-w-0 flex-col gap-0.5 rounded-sm px-3 py-2 {{ $tone[$tile['state']] }} {{ $tile['href'] ? 'hover:brightness-125' : '' }}"
                       aria-label="{{ $label }}" title="{{ $label }}" data-test="matrix-tile" data-state="{{ $tile['state'] }}">
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-display text-xl leading-none font-bold tabular-nums" data-test="matrix-tile-left">{{ $tile['left'] ?? '—' }}</span>
                            @if ($tile['flag'] && isset($flags[$tile['flag']]))
                                <x-icon :name="$flags[$tile['flag']]" :size="14" class="shrink-0" />
                            @endif
                        </span>
                        <span class="truncate text-[13px] font-bold text-ink">{{ $tile['mover'] ?? $tile['names'] }}</span>
                        <span class="truncate text-[11px] text-ink-3">{{ $tile['against'] ? __('vs :name', ['name' => $tile['against']]) : $tile['key'] }}</span>
                    </a>
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
                                    <th scope="col" class="w-14 min-w-14 text-center font-normal text-ink-3 tabular-nums" title="{{ $player['name'] }}"><span aria-hidden="true">{{ $index + 1 }}</span><span class="sr-only">{{ $player['name'] }}</span></th>
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
                                                <span class="block h-12 rounded-xs bg-ground" aria-hidden="true"></span>
                                            @elseif ($cell === null)
                                                <span class="flex h-12 items-center justify-center rounded-xs bg-well text-ink-3" aria-label="{{ __('No pairing') }}">–</span>
                                            @else
                                                @php($main = $cell['state'] === 'done' ? $cell['score'] : ($cell['left'] ?? '–'))
                                                @php($label = $row['name'].' – '.$column['name'].': '.($cell['state'] === 'done' ? $cell['score'] : ($cell['left'] ? __('deadline in :left', ['left' => $cell['left']]) : ($legend[$cell['state']] ?? __('Waiting')))).($cell['toMove'] ? ', '.__(':name to move', ['name' => $row['name']]) : '').($cell['state'] !== 'done' && $cell['score'] !== '' ? ', '.__('standing :score', ['score' => $cell['score']]) : ''))
                                                <a @if ($cell['href']) href="{{ $cell['href'] }}" @endif
                                                   class="relative flex h-12 min-w-14 items-center justify-center rounded-xs font-display text-[13px] font-bold tabular-nums {{ $tone[$cell['state']] }} {{ $cell['href'] ? 'hover:brightness-125' : '' }}"
                                                   aria-label="{{ $label }}" title="{{ $label }}" data-test="matrix-cell" data-state="{{ $cell['state'] }}" data-key="{{ $cell['key'] }}" @if ($cell['toMove']) data-to-move @endif>
                                                    {{ $main }}
                                                    @if ($cell['toMove'])
                                                        {{-- The row's player is to move. --}}
                                                        <span class="absolute top-1 left-1 size-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                                    @endif
                                                    @if ($cell['state'] !== 'done' && $cell['score'] !== '')
                                                        <span class="absolute right-1 bottom-0.5 font-mono text-[10px] leading-none font-normal opacity-80" aria-hidden="true">{{ $cell['score'] }}</span>
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
    <p class="m-0 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-3" data-test="matrix-legend">
        <span class="inline-flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-ink-2" aria-hidden="true"></span>{{ __('to move') }}</span>
        <span>{{ __('5h12 = time to the move deadline') }}</span>
    </p>
</section>
