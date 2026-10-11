{{--
    The tale of the tape before a final (plan "KI-Stimme der Liga", P4, board TournamentTape.dc.html; PrideMoments::upcomingFinal(), PrideCard::tape()):
    both finalists side by side, five rows from the league's records (Elo and record in the tournament's ladder, wins
    in this tournament, tournaments won before, seed). The leading value of a row is bright AND underlined, with its
    word for screen readers (colour never carries it alone, WCAG 1.4.1). The line under it comes from PrideVoice (the
    model's once written and switched on, else PrideMoments::tapeLine()). A finalist posts the card (share-button,
    `tape`); everybody can take the picture. Shown only while the final waits with both sides known.

    `$tournament`, `$final` (TournamentMatch).
--}}
@php
    $tape = \App\Support\Cards\PrideCard::tape($tournament, $final);
    $tapeSides = $tape->facts['sides'];
    $tapeMine = auth()->check() && collect($final->slots)->contains(fn ($slot) => in_array(auth()->id(), $slot->participant?->memberIds() ?? [], true));
@endphp
<section aria-labelledby="tape-h" class="rv-card flex flex-col gap-5 p-4 shadow-[inset_0_0_0_1px_var(--color-btc-ring)] lg:p-6" data-test="tale-of-the-tape">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <span class="flex min-w-0 flex-col gap-1">
            <h2 id="tape-h" class="rv-h2">{{ __('Tale of the tape') }}</h2>
            <span class="text-[13px] text-ink-2">{{ $tape->facts['best_of'] !== null ? __('Final, best of :count, in :ladder', ['count' => $tape->facts['best_of'], 'ladder' => $tape->facts['ladder']]) : __('Final in :ladder', ['ladder' => $tape->facts['ladder']]) }}</span>
        </span>
        @unless ($tapeMine)
            <a href="{{ $tape->path('wide') }}" download="twentyone-tape.png" data-test="tape-download"
               class="btn-w inline-flex h-11 shrink-0 items-center gap-1.5 rounded-md border border-line bg-well px-3 text-[13px] whitespace-nowrap text-ink hover:text-ink"><x-icon name="download" :size="16" />{{ __('Image') }}</a>
        @endunless
    </div>

    {{-- The two sides and VS: names wrap, never cut; the pictures stay square. --}}
    <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 lg:gap-6">
        @foreach ([0, 1] as $i)
            @if ($i === 1)
                <span class="font-display text-[28px] font-bold text-btc lg:text-[36px]" aria-hidden="true">VS</span>
            @endif
            {{-- Phone: picture over the name, so a long name gets the whole half (measured: beside it, 27 characters broke into five lines at 390). --}}
            <span @class(['flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:gap-3', 'items-end text-right sm:flex-row-reverse' => $i === 1, 'items-start' => $i === 0]) data-test="tape-side-{{ $i }}">
                @php
                    $tapeUser = $tapeSides[$i]['user_id'] !== null ? collect($final->slots)->first(fn ($slot) => $slot->participant?->user_id === $tapeSides[$i]['user_id'])?->participant?->user : null;
                @endphp
                @if ($tapeUser)
                    <x-avatar :user="$tapeUser" :size="56" class="size-12! shrink-0 rounded-[4px] lg:size-14!" />
                @else
                    <img src="{{ route('avatars.generated', $tapeSides[$i]['pubkey']) }}" width="56" height="56" alt="" class="size-12 shrink-0 rounded-[4px] lg:size-14" loading="lazy">
                @endif
                <b class="min-w-0 font-display text-[18px] leading-[1.2] [overflow-wrap:anywhere] lg:text-[24px]">{{ $tapeSides[$i]['name'] }}</b>
            </span>
        @endforeach
    </div>

    <table class="w-full border-collapse text-[14px]" data-test="tape-rows">
        <caption class="sr-only">{{ __(':left against :right, side by side', ['left' => $tapeSides[0]['name'], 'right' => $tapeSides[1]['name']]) }}</caption>
        <thead class="sr-only"><tr><th scope="col">{{ $tapeSides[0]['name'] }}</th><th scope="col">{{ __('Row') }}</th><th scope="col">{{ $tapeSides[1]['name'] }}</th></tr></thead>
        <tbody>
            @foreach ($tape->tapeTable() as [$word, $values, $lead])
                <tr class="border-t border-hairline">
                    @foreach ([0, 1] as $i)
                        @if ($i === 1)
                            <th scope="row" class="px-2 py-3 text-center text-[13px] font-normal whitespace-nowrap text-ink-2">{{ $word }}</th>
                        @endif
                        <td @class(['w-[38%] py-3 font-display text-[20px] font-bold tabular-nums lg:text-[24px]', 'text-right' => $i === 1, 'text-btc-hi' => $lead === $i, 'text-ink-2' => $lead !== null && $lead !== $i])>
                            <span @class(['inline-block', 'border-b-[3px] border-btc pb-0.5' => $lead === $i])>{{ $values[$i] }}</span>@if ($lead === $i)<span class="sr-only"> ({{ __('ahead') }})</span>@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="m-0 max-w-[68ch] font-display text-[18px] leading-[1.35] font-bold [overflow-wrap:anywhere]" data-test="tape-line">{{ $tape->line }}</p>

    @if ($tapeMine)
        <livewire:share-button type="tape" :moment="(string) $tournament->id" :label="__('Post the tale of the tape')" :wire:key="'tape-share-'.$tournament->id.'-'.$final->id" />
    @endif
</section>
