{{-- The recap of a finished tournament (plan "KI-Stimme der Liga", P3): its upsets by Elo and its closest match,
     read from the stored bracket (TournamentRecap, cached as scalars). The champion's path stands in the champion hero
     already and is not repeated here. Behind esports.recap.page until the TournamentDetail board carries the section. --}}
@php
    $recap = config('esports.recap.page') ? app(\App\Support\Tournaments\TournamentRecap::class)->cached($tournament) : null;
    $upsets = $recap['upsets'] ?? [];
    $closest = $recap['closest'] ?? null;
@endphp
@if ($upsets !== [] || $closest !== null)
    <section id="recap" aria-labelledby="recap-h" class="flex scroll-mt-24 flex-col gap-3" data-test="tournament-recap">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="recap-h" class="rv-h3">{{ __('Recap') }}</h2>
            @if ($upsets !== [])
                <span class="rv-m">{{ __('Upsets by the Elo each side was seeded with') }}</span>
            @endif
        </div>
        <div @class(['grid grid-cols-1 items-start gap-3', 'lg:grid-cols-2' => $upsets !== [] && $closest !== null])>
            @if ($upsets !== [])
                <div class="rv-card flex min-w-0 flex-col gap-3 p-4" data-test="recap-upsets">
                    <h3 class="m-0 text-[15px] font-bold text-ink">{{ __('Upsets') }}</h3>
                    <ol class="m-0 flex list-none flex-col gap-3 p-0">
                        @foreach ($upsets as $upset)
                            <li class="flex min-w-0 flex-col gap-1 border-l-2 border-btc pl-3" data-test="recap-upset">
                                @if ($upset['round'] !== '')
                                    <span class="text-xs leading-5 text-ink-3">{{ $upset['round'] }}</span>
                                @endif
                                <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-5">
                                    <span class="min-w-0 font-bold text-ink [overflow-wrap:anywhere]">{{ $upset['winner']['name'] }}</span>
                                    <span class="shrink-0 text-ink-2">{{ __('beat') }}</span>
                                    <span class="min-w-0 font-bold text-ink [overflow-wrap:anywhere]">{{ $upset['loser']['name'] }}</span>
                                    @if ($upset['score'])
                                        <span class="shrink-0 text-ink-2 tabular-nums">{{ $upset['score'] }}</span>
                                    @endif
                                </span>
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="rv-tag is-btc tabular-nums">{{ __(':gap Elo below', ['gap' => $upset['gap']]) }}</span>
                                    <span class="rv-m tabular-nums">{{ __(':winner vs :loser Elo', ['winner' => $upset['winner']['rating'], 'loser' => $upset['loser']['rating']]) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
            @if ($closest !== null)
                <div class="rv-card flex min-w-0 flex-col gap-3 p-4" data-test="recap-closest">
                    <h3 class="m-0 text-[15px] font-bold text-ink">{{ __('Closest match') }}</h3>
                    <div class="flex min-w-0 flex-col gap-1 border-l-2 border-hairline pl-3">
                        @if ($closest['round'] !== '')
                            <span class="text-xs leading-5 text-ink-3">{{ $closest['round'] }}</span>
                        @endif
                        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-[13px] leading-5">
                            <span class="min-w-0 font-bold text-ink [overflow-wrap:anywhere]">{{ $closest['winner']['name'] }}</span>
                            <span class="shrink-0 font-display text-base font-bold text-btc tabular-nums">{{ $closest['score'] }}</span>
                            <span class="min-w-0 font-bold text-ink [overflow-wrap:anywhere]">{{ $closest['loser']['name'] }}</span>
                        </span>
                    </div>
                </div>
            @endif
        </div>
    </section>
@endif
