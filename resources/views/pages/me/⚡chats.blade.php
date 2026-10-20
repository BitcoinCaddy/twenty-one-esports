<?php

use App\Models\User;
use App\Support\Chat\ChatInbox;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Your chats", /me/chats (DerCaddy, 2026-10-18: "Nachrichten in Chats sollten auch noch besser zu sehen sein …
 * ein Reiter Deine Chats, wo relevante Chats angezeigt werden, in denen ich mich beteiligt habe bzw. jemand mir
 * geschrieben hat"), from the account menu beside "Your matches and events".
 *
 * The league names the chats (App\Support\Chat\ChatInbox: the open tournament desks, the chats of the player's
 * games and the rooms of their series, running or ended within two weeks); the browser fills in what it can read
 * (resources/js/chatInbox.js): the newest message, its time and how many are new. Without a signer it shows what
 * this device read before and offers to load the rest.
 *
 * Private: no PageMeta::describe(), so it stays noindex like the player's other pages.
 */
new #[Title('Your chats')] #[Layout('layouts::app', ['scripts' => ['resources/js/chatInbox.js']])] class extends Component {
    /** @return array<string, mixed> */
    public function config(): array
    {
        return [...ChatInbox::config($this->user()), 'labels' => [
            'you' => __('you'),
            'unread' => __(':count new'),
            'loading' => __('Loading…'),
            'none' => __('No messages in your chats yet.'),
            'quiet' => __('Chats without messages (:count)'),
        ]];
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
};
?>

@php
    $config = $this->config();
@endphp

{{--
    One row per chat somebody wrote in, like a messenger's list: what it is, the newest message with its time, and
    "new", newest first; each opens its chat. The chats without a message fold below. The rows come from the browser
    (resources/js/chatInbox.js).
--}}
<div class="flex min-w-0 grow flex-col gap-5 px-4 pt-6 pb-8 lg:mx-auto lg:w-full lg:max-w-[880px] lg:px-12 lg:pb-10" data-test="me-chats" x-data="chatInbox(@js($config))">
    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 rounded-[4px] border-l-4 border-btc bg-gradient-to-r from-card to-transparent px-4 py-3">
        <h1 class="m-0 font-display text-[28px] leading-tight font-bold lg:text-[34px]">{{ __('Your chats') }}</h1>
        <span class="rv-m text-[13px]">{{ __('Tournament desks, game chats and match rooms of the last :days days', ['days' => ChatInbox::DAYS]) }}</span>
    </div>

    @if ($config['chats'] === [])
        <p class="m-0 text-[13px] text-ink-2" data-test="chats-empty">{{ __('No chats in the last :days days. A game, a match or a tournament brings its chat here.', ['days' => ChatInbox::DAYS]) }}</p>
    @else
        <p x-show="['needs-signer', 'no-nip44'].includes(status)" x-cloak class="m-0 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[4px] bg-card px-4 py-3 text-[13px] text-ink-2" data-test="chats-signer">
            <span class="min-w-0 grow" x-text="status === 'no-nip44' ? @js(__('Your signer cannot decrypt NIP-44 messages, so only what this device read before shows here.')) : @js(__('Only what this device read before shows here. Connect your Nostr signer to load new messages.'))"></span>
            <button type="button" x-show="status === 'needs-signer'" x-on:click="connect()" class="rv-b2 shrink-0" data-test="chats-connect">{{ __('Load new messages') }}</button>
        </p>

        <ul x-show="active.length > 0" class="m-0 flex list-none flex-col p-0" data-test="chats-list">
            <template x-for="row in active" :key="row.key">
                <li class="border-t border-hairline first:border-t-0">
                    <a :href="row.href" x-on:click="open(row)" class="flex min-h-16 items-center gap-3 py-3 text-ink hover:text-ink" data-test="chat-row" :data-kind="row.kind">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-card text-btc" aria-hidden="true">
                            <span x-show="row.kind === 'desk'"><x-icon name="trophy" :size="18" /></span>
                            <span x-show="row.kind === 'game'"><x-icon name="pawn" :size="18" /></span>
                            <span x-show="row.kind === 'room'"><x-icon name="matches" :size="18" /></span>
                        </span>
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <span class="flex min-w-0 items-baseline gap-2">
                                <b class="min-w-0 grow truncate text-[15px]" x-text="row.title" data-test="chat-row-title"></b>
                                <time class="shrink-0 text-xs text-ink-3 tabular-nums" x-text="time(row.last.at)" data-test="chat-row-time"></time>
                            </span>
                            <span class="min-w-0 truncate text-xs text-ink-3" x-text="row.subtitle"></span>
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="min-w-0 grow truncate text-[13px]" :class="row.unread > 0 ? 'font-bold text-ink' : 'text-ink-2'" x-text="row.last.preview" data-test="chat-row-preview"></span>
                                <span x-show="row.unread > 0" class="inline-flex h-5 shrink-0 items-center rounded-[10px] bg-btc px-[7px] text-[11px] font-bold whitespace-nowrap text-on-btc"
                                      x-text="t.unread.replace(':count', row.unread)" data-test="chat-row-unread"></span>
                            </span>
                        </span>
                    </a>
                </li>
            </template>
        </ul>

        <p x-show="active.length === 0" x-cloak class="m-0 text-[13px] text-ink-2" x-text="searching ? t.loading : t.none" data-test="chats-none"></p>

        {{-- Every chat of a game is named, most stay silent: out of the way, a click away. --}}
        <details x-show="quiet.length > 0" x-cloak class="rounded-[4px] bg-card px-4 py-2" data-test="chats-quiet">
            <summary class="cursor-pointer py-1 text-[13px] text-ink-2" x-text="t.quiet.replace(':count', quiet.length)"></summary>
            <ul class="m-0 flex list-none flex-col p-0">
                <template x-for="row in quiet" :key="row.key">
                    <li class="border-t border-hairline first:border-t-0">
                        <a :href="row.href" class="flex min-h-11 items-center gap-3 py-2 text-ink-2 hover:text-ink" data-test="chat-row-quiet" :data-kind="row.kind">
                            <span class="flex size-7 shrink-0 items-center justify-center text-ink-3" aria-hidden="true">
                                <span x-show="row.kind === 'desk'"><x-icon name="trophy" :size="15" /></span>
                                <span x-show="row.kind === 'game'"><x-icon name="pawn" :size="15" /></span>
                                <span x-show="row.kind === 'room'"><x-icon name="matches" :size="15" /></span>
                            </span>
                            <span class="max-w-[60%] shrink-0 truncate text-[13px] font-bold" x-text="row.title" data-test="chat-row-quiet-title"></span>
                            <span class="min-w-0 grow truncate text-xs text-ink-3" x-text="row.subtitle"></span>
                        </a>
                    </li>
                </template>
            </ul>
        </details>
    @endif
</div>
