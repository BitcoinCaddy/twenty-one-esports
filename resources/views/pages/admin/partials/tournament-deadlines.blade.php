@php
    use App\Support\Tournaments\TournamentDeadlines;

    /*
     * The tournament's own deadlines (P18): the first-move window of a chess
     * game or a board game (plan "Mühle und Dame"), and the no-show wait, report deadline and response deadline of a
     * series. Empty = the league default, shown as the placeholder. Shared by
     * pages::admin.tournament-create and pages::admin.tournament-edit (state:
     * App\Livewire\TournamentFormatChooser); saved with the page's button.
     */
    $defaults = TournamentDeadlines::defaults($this->profile()->mode);
    $bounds = TournamentDeadlines::BOUNDS;
    // Online and a minute game: one day on the round clock (P18), whose derived values are the defaults.
    $roundClock = $this->stationLimit() === null && ! $this->profile()->isDaily();
    $shown = $defaults;
    $reportDefault = null;

    if ($roundClock) {
        $clock = TournamentDeadlines::clock($this->profile()->mode);
        $shown = [...$defaults, 'noshow_minutes' => $clock->noshowMinutes, 'response_minutes' => $clock->responseMinutes, 'report_hours' => ''];
        $chooserOptions = $this->formatOptions();
        $reportDefault = [$clock->reportDueMinutes($this->profile(), $chooserOptions->bestOf), $clock->reportDueMinutes($this->profile(), $chooserOptions->finalBestOf)];
    }
    $deadlineField = 'h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $deadlines = [
        ['checkinMinutes', 'checkin_minutes', __('Chess and board games: first move within (minutes)'), __('A player who misses it loses by forfeit.')],
        ['noshowMinutes', 'noshow_minutes', __('Series: no-show report after (minutes)'), __('From the start until a captain can report the other side missing.')],
        ['reportHours', 'report_hours', __('Series: result due (hours after the start)'), __('Nobody reported by then: the match goes to the admin queue.')],
        ['responseMinutes', 'response_minutes', __('Series: answer within (minutes)'), __('An unanswered result is confirmed unrated; an unanswered no-show is a forfeit.')],
    ];
@endphp

@if (\App\Support\Tournaments\Lobbies::isLobbyGame($this->profile()->game))
    {{-- A lobby game (P10): no first move, no no-show, no series report; its players report each lobby by its own deadline. --}}
    <section class="flex flex-col gap-1 rounded-lg bg-card p-4 lg:px-6 lg:py-5" data-test="tournament-deadlines-lobby">
        <h2 class="m-0 text-[15px] font-bold">{{ __('Deadlines') }}</h2>
        <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('A lobby has no series deadlines: its players report the places within :time after the Time Limit, then a director decides.', ['time' => \App\Support\Pages\RulesPage::minutes((int) (\App\Support\Tournaments\Lobbies::config($this->profile()->game)['report_minutes'] ?? 60))]) }}</span>
    </section>
@else
<section aria-labelledby="deadlines-h" class="flex flex-col gap-3 rounded-lg bg-card p-4 lg:px-6 lg:py-5" data-test="tournament-deadlines">
    <span class="flex flex-col gap-1">
        <h2 id="deadlines-h" class="m-0 text-[15px] font-bold">{{ __('Deadlines') }}</h2>
        <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('The league applies them when the players report their results. Leave a field empty for the league default. A change while the tournament runs applies to matches paired from then on.') }}</span>
        @if ($reportDefault !== null)
            <span class="max-w-[80ch] text-xs leading-normal text-ink-2" data-test="round-clock">{{ __('Online on one day, every deadline runs from the start of the match: a no-show after :noshow min, the result :report min after the start (a final :final min), an answer within :response min.', [
                'noshow' => $shown['noshow_minutes'], 'report' => $reportDefault[0], 'final' => $reportDefault[1], 'response' => $shown['response_minutes'],
            ]) }}</span>
        @endif
    </span>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:gap-4">
        @foreach ($deadlines as [$property, $column, $label, $hint])
            <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2" wire:key="deadline-{{ $column }}">
                {{ $label }}
                <input type="number" inputmode="numeric" min="{{ $bounds[$column][0] }}" max="{{ $bounds[$column][1] }}" step="1"
                       wire:model="{{ $property }}" placeholder="{{ $shown[$column] }}" data-test="deadline-{{ $column }}"
                       class="{{ $deadlineField }}">
                <span class="text-ink-3">{{ $hint }} {{ $column === 'report_hours' && $reportDefault !== null
                    ? __(':min to :max; empty: :minutes min after the start of the match.', ['min' => $bounds[$column][0], 'max' => $bounds[$column][1], 'minutes' => $reportDefault[0]])
                    : __(':min to :max, default :default.', ['min' => $bounds[$column][0], 'max' => $bounds[$column][1], 'default' => $shown[$column]]) }}</span>
                @error($property)<span class="text-loss" role="alert">{{ $message }}</span>@enderror
            </label>
        @endforeach
    </div>
</section>
@endif

@if ($this->nightPauseApplies())
    @php
        // The night pause (NightPause): the league's default as the placeholder, the organizer's switch and times win.
        $nightDefaults = \App\Support\Tournaments\NightPause::defaults();
        $nightOn = $this->nightPauseOn();
        $nightTimes = $this->nightPauseTimes();
    @endphp
    <section aria-labelledby="night-pause-h" class="flex flex-col gap-3 rounded-lg bg-card p-4 lg:px-6 lg:py-5" data-test="tournament-night-pause">
        <div class="flex items-start gap-4">
            <span class="flex min-w-0 grow flex-col gap-1">
                <h2 id="night-pause-h" class="m-0 text-[15px] font-bold">{{ __('Night pause') }}</h2>
                <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('From :until to :resume (Berlin) no new match starts and no deadline runs. A match under way may be played to its end; its deadlines stop and go on at :resume.', [
                    'until' => \App\Support\Tournaments\NightPause::untilLabel($nightTimes['until']), 'resume' => $nightTimes['resume'],
                ]) }}</span>
                <span class="text-xs text-ink-3">{{ __('League default: :state, :until to :resume.', [
                    'state' => $nightDefaults['enabled'] ? __('on') : __('off'), 'until' => \App\Support\Tournaments\NightPause::untilLabel($nightDefaults['until']), 'resume' => $nightDefaults['resume'],
                ]) }}</span>
            </span>
            <span class="pt-1.5 text-xs whitespace-nowrap {{ $nightOn ? 'text-ink' : 'text-ink-3' }}" data-test="night-pause-state">{{ $nightOn ? __('on') : __('play through') }}</span>
            <button type="button" role="switch" aria-checked="{{ $nightOn ? 'true' : 'false' }}" aria-label="{{ __('Night pause') }}" wire:click="toggleNightPause" data-test="night-pause-switch"
                    @class(['relative h-8 w-[50px] shrink-0 cursor-pointer rounded-full transition-colors', 'bg-btc' => $nightOn, 'bg-ground shadow-[inset_0_0_0_1px_#63636A]' => ! $nightOn])>
                <span @class(['absolute top-1 size-6 rounded-full transition-all', 'left-[22px] bg-ground' => $nightOn, 'left-1 bg-edge' => ! $nightOn])></span>
            </button>
        </div>
        @if ($nightOn)
            <div class="grid grid-cols-2 gap-3 sm:max-w-md lg:gap-4">
                @foreach ([['nightPauseUntil', __('Pause from'), \App\Support\Tournaments\NightPause::untilLabel($nightDefaults['until'])], ['nightPauseResume', __('Go on at'), $nightDefaults['resume']]] as [$property, $label, $placeholder])
                    <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2" wire:key="night-{{ $property }}">
                        {{ $label }}
                        <input type="text" inputmode="numeric" maxlength="5" autocomplete="off" wire:model.blur="{{ $property }}" placeholder="{{ $placeholder }}"
                               data-test="night-pause-{{ $property === 'nightPauseUntil' ? 'until' : 'resume' }}" class="{{ $deadlineField }} tabular-nums">
                        <span class="text-ink-3">{{ __('HH:MM, Berlin; empty: :default.', ['default' => $placeholder]) }}</span>
                        @error($property)<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>
        @endif
    </section>
@endif
