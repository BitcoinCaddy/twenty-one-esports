@props(['label' => null])

{{--
    The secondary slot of every game page (rule R7, GameLobby.dc.html): share, zap, follows and Nostr as one line of
    icons, closed by default; what it holds (follows here, comments, share) sits behind the disclosure and loads only
    when it is opened (Livewire `lazy` inside a closed <details> is not in view until then). Fixed height while
    closed, never above the page's primary content.
--}}
<details {{ $attributes->class('gp-sec') }} data-test="game-secondary">
    <summary>
        <x-icon name="link" :size="16" class="shrink-0 text-ink-2" />
        <x-icon name="bolt" :size="16" class="shrink-0 text-bolt" />
        <x-icon name="clans" :size="16" class="shrink-0 text-ink-2" />
        <x-icon name="matches" :size="16" class="shrink-0 text-proof" />
        <span class="min-w-0 grow truncate">{{ $label ?? __('Follows, comments') }}</span>
        <x-icon name="chevron-down" :size="14" class="gp-sec-chev shrink-0 transition-transform duration-150 ease-out" />
    </summary>
    <div class="gp-sec-body">{{ $slot }}</div>
</details>
