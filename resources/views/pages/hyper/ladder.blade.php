{{--
    Hyperbitcoinization's season ladder (plan "Hyperbitcoinization", P5; HyperMatchController::ladder()) through the one
    ladder template T5 (x-ladder.page; LadderHyper.dc.html): game bar, the game's hero with the season's action, the
    ladders as chips, a switch between the three tables (free-for-all points, 1v1 Elo, team Elo), the standings
    (x-ladder.table) or, before Block 0 and between seasons, the empty state that says when rated play starts; the
    scoring on the right ("So wird gezählt").
--}}
@php
    $boards = ['ffa' => __('Free for all'), 'duel' => __('1v1'), 'team' => __('Teams')];
    $points = (array) config('esports.hyper.season_points', []);
    $elo = $board !== 'ffa';
    $slug = \App\Games\Hyperbitcoinization::SLUG;
    // Indexed, with its own link preview (P6): the season's first three by free-for-all points.
    app(\App\Support\PageMeta::class)->describe(__('Hyperbitcoinization ladder'), __('The Hyperbitcoinization season ladder of the TWENTY ONE esports league: free-for-all points by place, 1v1 and team Elo, rated matches without bots only.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('hyper-ladder'));
@endphp
<x-layouts::app :title="__('Hyperbitcoinization ladder')">
    <x-ladder.page :game="$slug" :title="__('Hyperbitcoinization ladder')" data-test="hyper-ladder"
                   :lede="__('Points by place in free-for-all, Elo for 1v1 and teams. The season starts at Block 0; until then every match is casual.')"
                   :chips="\App\Support\Navigation\LadderChips::items($slug)" :chips-label="__('Ladders')">
        <x-slot:action>
            <a href="{{ route('hyper.index') }}" class="rv-b h-[52px] px-4" data-test="hyper-ladder-play">{{ $season === null ? __('Play a casual match') : __('Play a season match') }}</a>
        </x-slot:action>

        <div class="flex flex-wrap items-center gap-3">
            <nav aria-label="{{ __('Table') }}" class="flex gap-2">
                @foreach ($boards as $key => $label)
                    <a href="{{ route('hyper.ladder', $key === 'ffa' ? [] : ['board' => $key]) }}" @if ($key === $board) aria-current="page" @endif data-test="hyper-ladder-board-{{ $key }}" class="gm-chip h-11">{{ $label }}</a>
                @endforeach
            </nav>
            <span class="rv-tag" data-test="hyper-ladder-season">
                @if ($season !== null)
                    {{ \App\Support\Badges\BadgeCopy::season($season) }}
                @else
                    <x-icon name="lock" :size="12" />{{ __('Rated play starts at Block 0') }}
                @endif
            </span>
            @if ($mine !== null)
                <span class="flex flex-wrap items-center gap-2 text-[13px]" data-test="hyper-ladder-mine">
                    <span class="rv-m">{{ __('You') }}</span>
                    <b class="tabular-nums">{{ $mine['ffa'] === null ? '–' : __(':points pts', ['points' => $mine['ffa']]) }}</b>
                    <span class="text-ink-2">1v1</span><b class="tabular-nums">{{ $mine['duel'] ?? '–' }}</b>
                    <span class="text-ink-2">{{ __('Team') }}</span><b class="tabular-nums">{{ $mine['team'] ?? '–' }}</b>
                </span>
            @endif
        </div>

        @if ($cup !== null)
            <a href="{{ route('tournaments.show', $cup) }}" class="gp-panel flex min-h-14 flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-ink hover:bg-row-hover hover:text-ink" data-test="hyper-ladder-cup">
                <x-icon name="trophy" :size="18" class="shrink-0 text-btc" />
                <b class="min-w-0 truncate text-sm">{{ $cup->name }}</b>
                <span class="text-xs text-ink-2">{{ $cup->status === \App\Enums\TournamentStatus::Signup ? __('Sign-up open until :time', ['time' => \App\Support\LeagueTime::stamp($cup->signup_closes_at)]) : __('Running') }}</span>
            </a>
        @endif

        @if ($season === null)
            <section class="gp-hero !grid-cols-1 gap-4 p-6" data-test="hyper-ladder-preseason">
                <span class="rv-tag self-start justify-self-start">{{ __('Season ladder') }}</span>
                <h2 class="m-0 font-display text-[30px] leading-[1.15] font-bold">{{ __('The season starts at Block 0') }}</h2>
                <p class="gp-lede m-0">{{ __('Until then every match is casual. From Block 0 every match without bots counts: free-for-all for points, 1v1 and clan matches for Elo (start :elo).', ['elo' => $start]) }}</p>
                @if (isset($points[4]))
                    {{-- The points per place of a four-seat table, as the board draws them: the winner's column tallest. --}}
                    <figure class="m-0 flex flex-col gap-2" data-test="hyper-ladder-podium">
                        <figcaption class="rv-m">{{ __('Points per place, table of :count', ['count' => 4]) }}</figcaption>
                        <span class="flex items-end gap-2" aria-hidden="true">
                            @foreach ([1, 0, 2, 3] as $place)
                                @php
                                    $value = (int) ($points[4][$place] ?? 0);
                                    $height = max(8, (int) round($value / max(1, (int) max($points[4])) * 88));
                                @endphp
                                <span class="flex w-16 flex-col items-center gap-1">
                                    <b @class(['font-display text-lg', 'text-btc' => $place !== 0 && $place !== 3])>{{ $value }}</b>
                                    <span @class(['block w-full rounded-t-[2px]', 'bg-ink-2' => $place === 0, 'bg-btc-deep' => in_array($place, [1, 2], true), 'bg-raised' => $place === 3]) style="height: {{ $height }}px"></span>
                                    <span class="rv-m">{{ $place + 1 }}.</span>
                                </span>
                            @endforeach
                        </span>
                        <span class="sr-only">{{ implode(', ', array_map(fn (int $value, int $index): string => ($index + 1).'. '.$value, $points[4], array_keys($points[4]))) }}</span>
                    </figure>
                @endif
                <span class="flex flex-wrap gap-3">
                    <a href="{{ route('hyper.index') }}" class="rv-b">{{ __('Play a casual match') }}</a>
                </span>
            </section>
        @elseif ($rows === [])
            <x-empty-state :heading="__('No rated result this season yet. A match without bots counts.')" data-test="hyper-ladder-empty">
                <a href="{{ route('hyper.index') }}" class="rv-b2 is-sm">{{ __('Play a season match') }}</a>
            </x-empty-state>
        @else
            <section aria-labelledby="hyper-ladder-h" class="gp-panel p-2">
                <h2 id="hyper-ladder-h" class="sr-only">{{ $boards[$board] }}</h2>
                <x-ladder.table row-test="hyper-ladder-row" :value-label="$elo ? __('Elo') : __('Points')" :record-label="$elo ? __('W / L') : __('Wins')"
                                :rows="array_map(fn (array $row): array => ['rank' => $row['rank'], 'user' => $row['user'], 'value' => (int) ($elo ? $row['rating'] : $row['points']), 'key' => $row['rank'],
                                    'mark' => ($wins = $row['user'] === null ? 0 : ($cupWins[$row['user']->id] ?? 0)) > 0 ? ($wins > 1 ? __('Cup').' ×'.$wins : __('Cup')) : null,
                                    'record' => $elo ? $row['wins'].' / '.$row['losses'] : (string) $row['wins']], $rows)" />
            </section>
        @endif

        <x-slot:aside>
            {{-- How it counts: the points per table size, forfeits, bots. --}}
            <section aria-labelledby="hyper-ladder-how" class="gp-panel flex flex-col gap-3 p-4">
                <h2 id="hyper-ladder-how" class="gp-h">{{ __('How it counts') }}</h2>
                <ul class="m-0 flex list-disc flex-col gap-1 ps-5 text-[13px] text-ink-2" data-test="hyper-ladder-rules">
                    @foreach ($points as $seats => $table)
                        <li>{{ __(':count players', ['count' => $seats]) }}: {{ implode(' · ', $table) }}</li>
                    @endforeach
                    <li>{{ __('1v1 and clan matches: Elo') }} ({{ __('Start rating') }} {{ $start }})</li>
                    <li>{{ __('Only matches without bots') }}</li>
                    <li>{{ __('Leaving = last place, 0 points') }}</li>
                </ul>
            </section>
        </x-slot:aside>
    </x-ladder.page>
</x-layouts::app>
