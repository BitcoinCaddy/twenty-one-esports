{{--
    "Turn off these DMs" (NotificationDmOptOutController): reached from the
    signed link at the end of every notification DM, no login needed. Shows
    what goes out by DM now and offers the two switches; each is a POST to
    the same signed URL.
--}}
@php
    $dmOff = $settings->dm === false;
    $challengesOff = ! $settings->wants('challenge');
@endphp

<x-layouts::app :title="__('Nostr DMs from the league')">
    <div class="flex grow flex-col justify-center gap-5 px-4 py-10 lg:px-12 lg:py-16" data-test="dm-off">
        {{-- SystemPages.dc.html "DMs abbestellt": the bell, the display heading, the state and the switches in one card. --}}
        <section aria-labelledby="dm-off-title" class="rv-card rv-hero mx-auto flex w-full max-w-[640px] flex-col gap-4 p-6 lg:p-8">
            <x-icon name="bell" :size="32" class="text-btc" />
            <h1 id="dm-off-title" class="m-0 font-display text-[22px] leading-[1.25] font-bold lg:text-[24px]">{{ __('Nostr DMs from the league') }}</h1>
            <h2 id="dm-off-h" class="rv-h3 break-words">{{ __('For :name', ['name' => $user->displayName()]) }}</h2>

            @if ($done === 'all')
                <p role="status" class="m-0 flex items-start gap-2 rounded-lg bg-win-tint px-4 py-3 text-[13px] text-win" data-test="dm-off-done"><x-icon name="check" :size="16" class="mt-0.5 shrink-0" />{{ __('Done. The league sends you no more DMs.') }}</p>
            @elseif ($done === 'challenge')
                <p role="status" class="m-0 flex items-start gap-2 rounded-lg bg-win-tint px-4 py-3 text-[13px] text-win" data-test="dm-off-done"><x-icon name="check" :size="16" class="mt-0.5 shrink-0" />{{ __('Done. Challenges no longer notify you.') }}</p>
            @endif

            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('The league sends these DMs from its own notification key, not from other players. They are on by default for challenges, your daily moves and deadline reminders, and clan join requests.') }}</p>

            <p class="m-0 text-[13px] leading-normal" data-test="dm-off-state">
                @if ($dmOff)
                    {{ __('Nostr DMs are off for you.') }}
                @elseif ($challengesOff)
                    {{ __('Nostr DMs are on. Challenges are switched off.') }}
                @else
                    {{ __('Nostr DMs are on.') }}
                @endif
            </p>

            @unless ($dmOff)
                <div class="flex flex-col gap-3 border-t border-hairline pt-4 sm:flex-row sm:flex-wrap">
                    <form method="POST" action="{{ $action }}">
                        @csrf
                        <input type="hidden" name="scope" value="all">
                        <x-button type="submit" class="w-full sm:w-auto" data-test="dm-off-all">{{ __('Turn off all DMs') }}</x-button>
                    </form>
                    @unless ($challengesOff)
                        <form method="POST" action="{{ $action }}">
                            @csrf
                            <input type="hidden" name="scope" value="challenge">
                            <x-button type="submit" variant="quiet" class="w-full sm:w-auto" data-test="dm-off-challenge">{{ __('Only turn off challenges') }}</x-button>
                        </form>
                    @endunless
                </div>
                <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Turning off challenges also stops their bell entry and browser push. Open challenges still show on your daily games page.') }}</p>
            @endunless

            <p class="m-0 border-t border-hairline pt-4 text-xs leading-normal text-ink-2">
                {{ __('Every notification, and turning DMs back on:') }}
                <a href="{{ route('settings.notifications') }}" class="rv-lk font-bold" data-test="dm-off-settings">{{ __('Notification settings') }}</a>
                {{ __('(after logging in)') }}
            </p>
        </section>
    </div>
</x-layouts::app>
