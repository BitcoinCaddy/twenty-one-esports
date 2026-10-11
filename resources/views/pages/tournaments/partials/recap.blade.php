{{-- The recap of a finished tournament (plan "KI-Stimme der Liga", P3; TournamentDetail.dc.html, frame "Rückblick"): its
     upsets by Elo and its closest match, read from the stored bracket (TournamentRecap, cached as scalars). Each is drawn
     as the bracket's match box (partials/box), the Elo each side was seeded with in the seed slot, the reason as a tag in
     the quiet line above. The closest match gets its own box only when it is not one of the upsets already; otherwise
     that upset carries both tags. Two columns like the row below, one on the phone. The champion's path stands in the
     champion hero already and is not repeated here. Behind esports.recap.page (on by default). --}}
@php
    $recap = config('esports.recap.page') ? app(\App\Support\Tournaments\TournamentRecap::class)->cached($tournament) : null;
    $upsets = $recap['upsets'] ?? [];
    $closest = $recap['closest'] ?? null;
    $boxes = [];
    $closestShown = false;
    foreach ($upsets as $upset) {
        $isClosest = $closest !== null && $upset['round'] === $closest['round'] && $upset['winner']['id'] === $closest['winner']['id'] && $upset['loser']['id'] === $closest['loser']['id'];
        $closestShown = $closestShown || $isClosest;
        $boxes[] = [...$upset, 'closest' => $isClosest];
    }
    if ($closest !== null && ! $closestShown) {
        $boxes[] = [...$closest, 'gap' => null, 'closest' => true];
    }
@endphp
@if ($boxes !== [])
    <section id="recap" aria-labelledby="recap-h" class="flex scroll-mt-24 flex-col gap-3" data-test="tournament-recap">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 id="recap-h" class="rv-h3">{{ __('Recap') }}</h2>
            @if ($upsets !== [])
                <span class="rv-m">{{ __('Upsets by the Elo each side was seeded with') }}</span>
            @endif
        </div>
        <ol class="m-0 grid list-none grid-cols-1 items-start gap-3 p-0 lg:grid-cols-2 lg:gap-6" aria-label="{{ __('Recap') }}">
            @foreach ($boxes as $box)
                @php
                    // "2:1" from the winner's side splits onto the two rows like the bracket's scores; any other label stays whole on the winner's row.
                    $scores = $box['score'] !== null && preg_match('/^([^:]+):([^:]+)$/', $box['score'], $parts) === 1 ? [trim($parts[1]), trim($parts[2])] : [$box['score'], null];
                @endphp
                <li class="flex min-w-0 flex-col rounded-[4px] border border-line bg-card text-[13px]" data-test="recap-match"
                    data-kind="{{ $box['gap'] !== null ? 'upset' : 'closest' }}" @if ($box['closest']) data-closest @endif>
                    <span class="flex min-h-8 flex-wrap items-center gap-2 px-3 pt-1.5 text-[11px] text-ink-3">
                        <span class="grow">{{ $box['round'] }}</span>
                        @if ($box['gap'] !== null)
                            <span class="rv-tag is-btc tabular-nums" data-test="recap-gap">{{ __(':gap Elo below', ['gap' => $box['gap']]) }}</span>
                        @endif
                        @if ($box['closest'])
                            <span class="rv-tag" data-test="recap-closest">{{ __('Closest match') }}</span>
                        @endif
                    </span>
                    @foreach ([[$box['winner'], $scores[0], true], [$box['loser'], $scores[1], false]] as [$side, $score, $won])
                        <span @class(['flex min-h-10 min-w-0 items-center gap-2 px-3 py-1', 'bg-raised' => $won, 'rounded-b-[3px]' => ! $won])>
                            <span class="w-9 flex-none text-[11px] text-ink-3 tabular-nums" @if ($side['rating'] !== null) title="{{ __(':rating Elo', ['rating' => $side['rating']]) }}" @endif>
                                @if ($side['rating'] !== null)
                                    {{ $side['rating'] }}<span class="sr-only"> Elo</span>
                                @endif
                            </span>
                            <span @class(['min-w-0 grow leading-snug [overflow-wrap:anywhere]', 'font-bold text-ink' => $won, 'text-ink-2' => ! $won])>{{ $side['name'] }}</span>
                            @if ($score !== null)
                                <span @class(['shrink-0 font-display tabular-nums', 'font-bold text-btc' => $won, 'text-ink-3' => ! $won])>{{ $score }}</span>
                            @endif
                        </span>
                    @endforeach
                </li>
            @endforeach
        </ol>
    </section>
@endif
