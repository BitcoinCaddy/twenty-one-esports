{{--
    Proof of Pong's Elo ladder (plan "Proof of Pong", P4; PongController::ladder()) through the one ladder template T5
    (x-ladder.page; LadderPong.dc.html): game bar, the game's hero with "Live-Match spielen", the ladders as chips, the
    standings (x-ladder.table) or the empty state with the three numbers that matter, and "So wird gezählt" on the
    right. Permanent Elo, no season: every rated live match between two players moves it.

    $rows: PongLadder::standings(); $mine: PongLadder::card() of the viewer or null; $start: the start Elo.
--}}
@php
    // Indexed, with its own link preview: the first three by Elo.
    app(\App\Support\PageMeta::class)->describe(__('Proof of Pong ladder'), __('The Proof of Pong Elo ladder of the TWENTY ONE esports league: live 1v1 Pong to 21, every rated match between two players counts.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('pong-ladder'));
    $slug = \App\Games\ProofOfPong::SLUG;
    $points = (int) config('esports.pong.points_to_win', 21);
    $forfeit = (int) config('esports.pong.forfeit_seconds', 30);
@endphp
<x-layouts::app :title="__('Proof of Pong ladder')">
    <x-ladder.page :game="$slug" :title="__('Proof of Pong ladder')" data-test="pong-ladder"
                   :lede="__('Live 1v1 Pong to :points; every rated match between two players counts. Every match is in the mempool.', ['points' => $points])"
                   :chips="\App\Support\Navigation\LadderChips::items($slug)" :chips-label="__('Ladders')">
        <x-slot:action>
            <a href="{{ route('pong.index') }}" class="rv-b h-[52px] px-4" data-test="pong-ladder-play">{{ __('Play a live match') }}</a>
        </x-slot:action>

        @if ($mine !== null)
            <p class="m-0 flex flex-wrap items-center gap-2 text-[13px]" data-test="pong-ladder-mine">
                <span class="rv-m">{{ __('You') }}</span>
                <b class="tabular-nums">{{ $mine['rated'] ? $mine['rating'] : '–' }}</b>
                @if ($mine['rank'] !== null)<span class="rv-tag">#{{ $mine['rank'] }}</span>@endif
                <span class="text-ink-2 tabular-nums">{{ __(':w W, :l L', ['w' => $mine['wins'], 'l' => $mine['losses']]) }}</span>
            </p>
        @endif

        @if ($rows === [])
            <section class="gp-hero !grid-cols-1 gap-4 p-6" aria-labelledby="pong-ladder-h" data-test="pong-ladder-empty">
                <span class="rv-tag self-start justify-self-start">{{ __('Elo ladder') }}</span>
                <h2 id="pong-ladder-h" class="m-0 font-display text-[30px] leading-[1.15] font-bold">{{ __('The first live match opens the ladder') }}</h2>
                <p class="gp-lede m-0">{{ __('No rated match yet. Live 1v1 between two players, to :points; games against a bot do not count.', ['points' => $points]) }}</p>
                <dl class="m-0 flex flex-wrap gap-x-8 gap-y-3">
                    <div class="flex flex-col"><dt class="rv-m">{{ __('Start rating') }}</dt><dd class="m-0 font-display text-[48px] leading-none font-bold tabular-nums">{{ $start }}</dd></div>
                    <div class="flex flex-col"><dt class="rv-m">{{ __('To win') }}</dt><dd class="m-0 font-display text-[48px] leading-none font-bold tabular-nums text-proof">{{ $points }}</dd></div>
                    <div class="flex flex-col"><dt class="rv-m">{{ __('gone = loss') }}</dt><dd class="m-0 font-display text-[48px] leading-none font-bold tabular-nums">{{ $forfeit }} s</dd></div>
                </dl>
                <span class="flex flex-wrap gap-3">
                    <a href="{{ route('pong.index') }}#pong-way-live" class="rv-b">{{ __('Play a live match') }}</a>
                    <a href="{{ route('pong.index') }}" class="rv-b2">{{ __('Practise against the bot') }}</a>
                </span>
            </section>
        @else
            <section class="gp-panel p-2" aria-labelledby="pong-ladder-h">
                <h2 id="pong-ladder-h" class="sr-only">{{ __('Elo') }}</h2>
                <x-ladder.table row-test="pong-ladder-row" :rows="array_map(fn (array $row): array => ['rank' => $row['rank'], 'user' => $row['user'], 'value' => (int) $row['rating'], 'key' => $row['rank'],
                    'mark' => $row['provisional'] ? __('provisional') : null, 'record' => __(':w W, :l L', ['w' => $row['wins'], 'l' => $row['losses']])], $rows)" />
            </section>
        @endif

        <x-slot:aside>
            <section aria-labelledby="pong-ladder-how" class="gp-panel flex flex-col gap-3 p-4">
                <h2 id="pong-ladder-how" class="gp-h">{{ __('How it counts') }}</h2>
                <ul class="m-0 flex list-disc flex-col gap-1 ps-5 text-[13px] text-ink-2" data-test="pong-ladder-rules">
                    <li>{{ __('Start rating') }} {{ $start }}</li>
                    <li>{{ __('Live 1v1 between two players') }}</li>
                    <li>{{ __('Games against a bot do not count') }}</li>
                    <li>{{ __('Gone for :seconds s = loss', ['seconds' => $forfeit]) }}</li>
                </ul>
            </section>
        </x-slot:aside>
    </x-ladder.page>
</x-layouts::app>
