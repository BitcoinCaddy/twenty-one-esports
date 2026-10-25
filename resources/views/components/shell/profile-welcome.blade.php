{{--
    Google sign-ins start with a bare key: no Nostr profile, no name, no picture, no Lightning address, and no Nostr
    app to make one (user 2026-10-25). Until a profile of theirs is known, every page leads them to the profile page,
    where the league's client writes it with their own signer. Players with a Nostr profile never see this.
--}}
@php($viewer = auth()->user())
@if ($viewer !== null && $viewer->profile_event_at === null && ! request()->routeIs('settings.profile'))
    <aside class="border-b border-btc/40 bg-btc-tint px-4 py-3 lg:px-12" data-test="profile-welcome">
        <div class="mx-auto flex max-w-[1440px] flex-wrap items-center gap-x-4 gap-y-2">
            <x-icon name="user" :size="20" class="shrink-0 text-btc" />
            <p class="m-0 min-w-0 grow basis-64 text-[13px] leading-normal">
                <b>{{ __('Welcome! Give yourself a name and a picture.') }}</b>
                {{ __('Right now other players only see a code. Add a Lightning address too, so prizes can reach you. Takes two minutes, no extra app needed.') }}
            </p>
            <a href="{{ route('settings.profile') }}" class="rv-b h-11 shrink-0 px-5" data-test="profile-welcome-link">{{ __('Set up my profile') }}</a>
        </div>
    </aside>
@endif
