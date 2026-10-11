@props(['tournament' => null, 'series' => null])

{{--
    The night pause banner (App\Support\Tournaments\NightPause, NightPauseNotices; user, 2026-10-10: "mit eindeutigen
    großen Hinweisen versehen werden, damit die Leute das nicht übersehen"). Renders nothing outside the pause and its
    warning time, so it can sit on every tournament page and match room:

    - `soon` (from `esports.tournaments.night_pause.warn_minutes` before, urgent in the last 15 minutes): when it starts,
      that a match under way may be finished, and when play goes on;
    - `paused`: the restart time in big figures with a countdown, and for a signed-in player of the tournament their
      match, the opponent and what is due then (check-in or the next deadline).

    Pass the tournament, or the series of a match room (its tournament is looked up; a series outside a tournament shows
    nothing).
--}}
@php
    use App\Models\Tournament;
    use App\Support\LeagueTime;
    use App\Support\Series\SeriesPresenter;
    use App\Support\Tournaments\NightPauseNotices;

    if (! $tournament instanceof Tournament && $series instanceof \App\Models\SeriesMatch && $series->tournament_match_id !== null) {
        $tournament = Tournament::query()->whereKey(\App\Models\TournamentMatch::query()->whereKey($series->tournament_match_id)->select('tournament_id'))->first();
    }

    $night = $tournament instanceof Tournament ? NightPauseNotices::state($tournament) : null;
    $viewer = auth()->user();
    $mine = $night !== null && $viewer !== null ? NightPauseNotices::forPlayer($tournament, $viewer, $night['resumes']) : null;
    $clock = fn ($at): string => SeriesPresenter::time($at, $viewer, 'H:i');
    // The start as players say it: midnight is "24:00", the day that ends.
    $startLabel = $night === null ? '' : (($start = $clock($night['starts'])) === '00:00' ? '24:00' : $start);
    $urgent = $night !== null && $night['state'] === 'soon' && $night['starts']->getTimestamp() - now()->getTimestamp() <= 15 * 60;
@endphp

@if ($night !== null)
    <section role="status" aria-live="polite" data-test="night-pause-banner" data-state="{{ $night['state'] }}"
             {{ $attributes->class(['flex flex-col gap-3 rounded-lg border-l-4 bg-card p-4 lg:flex-row lg:items-center lg:gap-6 lg:px-6 lg:py-5', 'border-btc' => ! $urgent, 'border-loss' => $urgent]) }}>
        <div class="flex min-w-0 grow flex-col gap-1">
            <span class="flex items-center gap-2 text-xs font-bold tracking-wide text-btc-hi uppercase">
                <x-icon name="clock" :size="14" class="shrink-0" />
                {{ $night['state'] === 'paused' ? __('Night pause') : __('Night pause soon') }}
            </span>
            @if ($night['state'] === 'paused')
                <p class="m-0 text-[15px] leading-snug font-bold text-ink">{{ __('No new match starts and no deadline runs until :resume.', ['resume' => $clock($night['resumes'])]) }}</p>
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('A match under way may be played to its end and reported. Every deadline goes on at :resume with the time it had left.', ['resume' => $clock($night['resumes'])]) }}</p>
            @else
                <p class="m-0 text-[15px] leading-snug font-bold text-ink">{{ __('At :until the night pause starts; a match under way may be played to its end.', ['until' => $startLabel]) }}</p>
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('No new match starts then, and every deadline stands until :resume the next day.', ['resume' => $clock($night['resumes'])]) }}</p>
            @endif
            @if ($mine !== null)
                <p class="m-0 text-[13px] leading-normal text-ink" data-test="night-pause-mine">
                    {{ __('Your match against :name goes on at :resume.', ['name' => $mine['opponent'], 'resume' => $clock($night['resumes'])]) }}
                    @if ($mine['due'] !== null)
                        <b class="font-bold">{{ $mine['due_kind'] === 'checkin' ? __('Check in by :time.', ['time' => $clock($mine['due'])]) : __('Your next deadline: :time.', ['time' => $clock($mine['due'])]) }}</b>
                    @endif
                    <a href="{{ $mine['url'] }}" @navigate($mine['url']) class="font-bold whitespace-nowrap text-btc-hi underline-offset-2 hover:underline">{{ __('Open match') }}</a>
                </p>
            @endif
        </div>
        <div class="flex shrink-0 flex-col items-start gap-1 lg:items-end">
            <span class="text-xs text-ink-2">{{ $night['state'] === 'paused' ? __('Goes on at') : __('Pause from') }}</span>
            <b class="font-display text-[40px] leading-none font-bold text-ink tabular-nums lg:text-5xl" data-test="night-pause-time">{{ $night['state'] === 'paused' ? $clock($night['resumes']) : $startLabel }}</b>
            <span class="text-xs text-ink-3">{{ LeagueTime::date($night['state'] === 'paused' ? $night['resumes'] : $night['starts']) }}</span>
            <x-ui.countdown :at="$night['state'] === 'paused' ? $night['resumes'] : $night['starts']" size="sm" :label="$night['state'] === 'paused' ? __('Goes on in') : __('Night pause in')" />
        </div>
    </section>
@endif
