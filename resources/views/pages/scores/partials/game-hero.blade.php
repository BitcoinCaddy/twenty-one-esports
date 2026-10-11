{{--
    Blockfill's hero on its game page (template T1 hero, GameScore.dc.html): the cover, the name, the week in one line,
    the time to beat with who set it, the countdown to the week's close, who plays, and the way a run counts as four
    linked steps; the big number on the right is the week's players. The week page of a tournament keeps its own
    hero (partials/blockfill-hero).
    $heading: the h1; $week: the running week (null: none opened yet); $standings: its list<ScoreStanding>;
    $metric: its App\Games\ScoreMetric (null without a week).
--}}
@php
    $window = $week !== null ? \App\Support\Scores\ScoreWindow::of($week) : null;
    $finished = $week?->status === \App\Enums\TournamentStatus::Finished;
    $open = $week !== null && $week->status === \App\Enums\TournamentStatus::Running && $window->hasStarted() && ! $window->hasEnded();
    $leader = collect($standings)->first(fn ($row): bool => $row->place !== null && $row->value !== null);
    $leaderUser = $leader?->participant->user;
    // The first frame of the countdown comes from the server, so nothing jumps (countdown() in resources/js/tournamentLanding.js).
    $seconds = $open ? max(0, (int) now()->diffInSeconds($window->end, false)) : 0;
    $days = intdiv($seconds, 86400);
    $countdown = ($days > 0 ? trans_choice(':count day|:count days', $days).' ' : '').sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    $weekNumber = $week !== null ? (int) $week->starts_at->copy()->timezone(\App\Support\Stacker\BlockfillWeeks::TIMEZONE)->format('W') : null;
    $blocks = \App\Support\Stacker\BlockfillRules::blocks(app(\App\Support\Stacker\BlockfillWeeks::class)->difficultyAt());
    $range = $window !== null
        ? $window->start->copy()->timezone(\App\Support\Stacker\BlockfillWeeks::TIMEZONE)->locale(app()->getLocale())->isoFormat(app()->getLocale() === 'de' ? 'D.' : 'MMM D').' – '.$window->end->copy()->subSecond()->timezone(\App\Support\Stacker\BlockfillWeeks::TIMEZONE)->locale(app()->getLocale())->isoFormat(app()->getLocale() === 'de' ? 'D.M.' : 'MMM D')
        : null;
    $players = count($standings);
@endphp
<section aria-labelledby="game-hero-h" class="gp-hero" data-test="blockfill-game-hero">
    <x-game-cover game="blockfill" size="card" class="gp-hero-cover bg-black" loading="eager" />

    <div class="flex min-w-0 flex-col gap-3">
        <div>
            <h1 id="game-hero-h" class="gp-title" data-test="lobby-title">{{ $heading }}</h1>
            <p class="gp-lede">
                @if ($week !== null)
                    {{ __('Week :week (:range): the best time over :blocks wins. Practice runs without an account.', ['week' => $weekNumber, 'range' => $range, 'blocks' => $blocks]) }}
                @else
                    {{ __('Next week starts soon. Ranked runs count again once it is open.') }}
                @endif
            </p>
        </div>

        @if ($week !== null)
            <dl class="m-0 flex flex-wrap items-end gap-x-6 gap-y-2" data-test="hero-chips">
                @if ($leader !== null)
                    <div class="flex flex-col" data-test="{{ $finished ? 'week-winner' : 'time-to-beat' }}">
                        <dt class="rv-m">{{ $finished ? __('Winner') : __('Time to beat') }}</dt>
                        <dd class="m-0 font-display text-[30px] leading-none font-bold tabular-nums">{{ $metric->format($leader->value) }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="rv-m">{{ __('set by') }}</dt>
                        <dd class="m-0 text-[14px] font-bold">
                            @if ($leaderUser)
                                <a href="{{ route('players.show', $leaderUser->npub) }}" class="text-ink hover:text-btc-hi">{{ $leader->participant->name }}</a>
                            @else
                                {{ $leader->participant->name }}
                            @endif
                        </dd>
                    </div>
                @elseif ($open)
                    <div class="flex flex-col" data-test="first-place-free">
                        <dt class="rv-m">{{ __('Time to beat') }}</dt>
                        <dd class="m-0 font-display text-[30px] leading-none font-bold">{{ __('#1 is free') }}</dd>
                    </div>
                @endif
                @if ($open)
                    <div class="flex flex-col" data-test="chip-closes">
                        <dt class="rv-m">{{ __('Week ends in') }}</dt>
                        <dd class="m-0 font-display text-[20px] leading-tight font-bold tabular-nums" role="timer" x-data="countdown({ at: {{ (int) $window->end->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text">{{ $countdown }}</dd>
                    </div>
                @elseif ($finished)
                    <div class="flex flex-col" data-test="chip-final"><dt class="rv-m">{{ __('Week') }}</dt><dd class="m-0 text-[14px] font-bold">{{ __('Finished') }}</dd></div>
                @else
                    <div class="flex flex-col" data-test="chip-closed"><dt class="rv-m">{{ __('Week') }}</dt><dd class="m-0 text-[14px] font-bold">{{ __('Window closed') }}</dd></div>
                @endif
                @if ($players > 0)
                    <div class="flex flex-col" data-test="chip-players">
                        <dt class="rv-m">{{ __('in') }}</dt>
                        <dd class="m-0 font-display text-[20px] leading-tight font-bold">{{ trans_choice(':count player|:count players', $players) }}</dd>
                    </div>
                @endif
            </dl>
        @endif

        {{-- How a run reaches the board: a real sequence, so it reads left to right (StackerRuns, the verifier, ScoreRuns). --}}
        <ol class="m-0 flex list-none flex-wrap items-center gap-x-2 gap-y-2 p-0" aria-label="{{ __('How a run counts') }}" data-test="blockfill-steps">
            @foreach ([__('Play, ranked, logged in'), __('The server replays it'), __('Verified'), __('Your best time counts on the leaderboard')] as $step)
                <li class="flex max-w-full min-w-0 items-center gap-2">
                    <span class="rv-tag h-auto min-h-5 py-0.5 whitespace-normal">{{ $step }}</span>
                    @unless ($loop->last)<x-icon name="next" :size="12" class="text-ink-3" />@endunless
                </li>
            @endforeach
        </ol>

        @if ($week !== null)
            {{-- The week's own rules (BlockfillRules::chips): data the nerds read, kept from the old hero (hybrid density). --}}
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0" aria-label="{{ __('Rules') }}">
                @foreach (\App\Support\Stacker\BlockfillRules::chips(app(\App\Support\Stacker\BlockfillWeeks::class)->difficultyOf($week)) as $rule)
                    <li class="rv-tag" data-test="chip-rule">{{ $rule }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($week !== null)
        <div class="gp-stat" data-test="game-stat">
            <b class="flex items-center gap-2">@if ($open && $players > 0)<span class="size-2 rounded-full bg-live" aria-hidden="true"></span>@endif{{ $players }}</b>
            <span>{{ __('players in week :week', ['week' => $weekNumber]) }}</span>
        </div>
    @endif
</section>
