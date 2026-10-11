{{--
    The four steps of every match room (MatchRoom-*.dc.html, TournamentMatchRoom.dc.html): Present, Lobby, Result,
    Confirmed. Desktop a row of four 48 px tabs with a 3 px rule (green done, orange now, red a reported problem);
    phone four bars and "Step n of 4". What each step means comes from the match itself:
    present = ready or checked in (a casual 1v1), both lobby check-ins (a tournament series), the start passed;
    lobby = the guest joined (casual), else a lobby set and present done; result = a score sent; confirmed = saved.
    Needs $m, $casual, $games and $report from pages/matches/⚡room.
--}}
@php
    use App\Enums\ReportStatus;
    use App\Enums\SeriesStatus;

    $reported = $report !== null && $report->status !== ReportStatus::Superseded;
    $played = $games !== [] || $reported || $m->status->hasResult();
    $present = $m->status->hasResult() || $played || match (true) {
        $m->status === SeriesStatus::Open => false,
        $casual => $m->casualUnderWay(),
        $m->tournament_match_id !== null && $m->deadlines !== null => $m->readyAt('challenger') !== null && $m->readyAt('challenged') !== null,
        default => $m->start_at !== null && ! $m->start_at->isFuture(),
    };
    $lobby = $played || ($present && ($casual ? $m->joined_at !== null : ($m->lobby_name !== null || $m->isTeamMatch())));
    $roomSteps = [
        ['present', __('Present'), $present],
        ['lobby', __('Lobby'), $lobby],
        ['result', __('Result'), $reported || $m->status->hasResult()],
        ['confirmed', __('Confirmed'), $m->status->hasResult()],
    ];
    $nowIndex = null;

    foreach ($roomSteps as $index => [, , $done]) {
        if (! $done) {
            $nowIndex = $index;
            break;
        }
    }

    $disputed = $m->status === SeriesStatus::Disputed;
    // A reported problem marks the last step red, with its own word.
    if ($disputed) {
        $roomSteps[3][1] = __('Problem');
        $nowIndex = 3;
    }
@endphp
<nav aria-label="{{ __('Match steps') }}" data-test="room-stepper" data-now="{{ $nowIndex === null ? 'done' : $roomSteps[$nowIndex][0] }}">
    <ol class="rv-stepper max-sm:hidden">
        @foreach ($roomSteps as $index => [$key, $label, $done])
            @php($state = $disputed && $index === 3 ? 'bad' : ($done ? 'done' : ($index === $nowIndex ? 'now' : 'next')))
            <li class="is-{{ $state }}" data-test="room-step-{{ $key }}" data-state="{{ $state }}" aria-current="{{ $index === $nowIndex ? 'step' : 'false' }}">
                <span @class(['rv-n', 'is-o' => $state === 'now', 'is-r' => $state === 'bad'])>{{ $index + 1 }}</span>
                @if ($state === 'done')
                    <x-icon name="check" :size="14" class="shrink-0 text-win" /><span class="sr-only">{{ __('done') }}</span>
                @endif
                <b @class(['font-bold', 'text-ink-2 font-normal' => $state === 'next'])>{{ $label }}</b>
            </li>
        @endforeach
    </ol>
    <div class="flex flex-col gap-1 sm:hidden">
        <span class="rv-stepbar" aria-hidden="true">
            @foreach ($roomSteps as $index => [$key, $label, $done])
                <i class="is-{{ $disputed && $index === 3 ? 'bad' : ($done ? 'done' : ($index === $nowIndex ? 'now' : 'next')) }}"></i>
            @endforeach
        </span>
        <span class="rv-m text-[13px]" data-test="room-step-line">{{ $nowIndex === null ? __('All four steps done') : __('Step :n of 4: :label', ['n' => $nowIndex + 1, 'label' => $roomSteps[$nowIndex][1]]) }}</span>
    </div>
</nav>
