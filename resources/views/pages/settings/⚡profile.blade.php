<?php

use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Lightning\LightningAddress;
use App\Support\Lightning\LightningAddressFailure;
use App\Support\Nostr\ProfileCache;
use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The player's own Nostr profile: name and Lightning address (user 2026-10-25: players who signed in with Google
 * had no profile, so their prize could not be paid). The player's own signer signs the kind 0 in the browser
 * (resources/js/profileEditor.js); the league only checks the address the way a payout does and caches the result.
 * Every other field of the profile stays as it is.
 */
new #[Title('Nostr profile')] class extends Component {
    public string $error = '';

    /**
     * The kind 0 template with `$name` and `$lud16` merged into the profile's current content, or null with
     * `$error` set. The address is checked with a real invoice request, as a payout makes it.
     *
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}|null
     */
    public function prepareProfile(string $name, string $lud16, string $current): ?array
    {
        $this->error = '';
        $name = trim($name);
        $lud16 = mb_strtolower(trim($lud16));

        if ($name === '' || mb_strlen($name) > 50) {
            $this->error = __('Pick a name of 1 to 50 characters.');

            return null;
        }

        if ($lud16 !== '') {
            if (preg_match('/^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z]{2,}$/', $lud16) !== 1) {
                $this->error = __('A Lightning address looks like an email address, for example name@coinos.io.');

                return null;
            }

            try {
                app(LightningAddress::class)->invoice($lud16, 1000);
            } catch (LightningAddressFailure $failure) {
                $this->error = match ($failure->reason) {
                    'invoice_mismatch' => __('This wallet answers with invoices the league cannot check, so it cannot pay prizes to it. Please use another wallet, for example one from the list.'),
                    'amount_out_of_range' => __('This address does not take payments of the league’s size. Please use another wallet.'),
                    default => __('This address did not answer. Check the spelling, or copy it from your wallet again.'),
                };

                return null;
            }
        }

        $content = json_decode($current, true);
        $content = is_array($content) ? $content : [];
        $content['name'] = $name;
        $content['display_name'] = $name;

        if ($lud16 !== '') {
            $content['lud16'] = $lud16;
        }

        return ['kind' => 0, 'tags' => [], 'content' => (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'created_at' => time()];
    }

    /** The signed profile, published by the browser, into the league's cache. */
    public function submitProfile(string $signed): bool
    {
        $user = $this->user();
        $before = $user->profile_event_at?->getTimestamp();
        ProfileCache::apply($user, json_decode($signed, true));

        return $user->refresh()->profile_event_at?->getTimestamp() !== $before;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $user = auth()->user();
    $field = 'h-11 min-w-0 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    // Checked with the league's payout check on 2026-10-25 (an invoice was asked of each, nothing paid); free to use.
    $wallets = [
        ['Blink', 'https://www.blink.sv', 'name@blink.sv'],
        ['Coinos', 'https://coinos.io', 'name@coinos.io'],
        ['Rizful', 'https://rizful.com', 'name@rizful.com'],
    ];
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="profile-settings">
    <x-settings.header current="profile" />

    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section aria-labelledby="pr-h" class="flex min-w-0 flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="profile-editor"
                 x-data="profileEditor({ pubkey: @js($user->pubkey), relays: @js(ProfileBadges::browserRelays()), indexers: @js(array_values((array) config('esports.stream_chat.indexer_relays', []))), messages: @js([...SignerMessages::labels(), 'notPosted' => __('None of your relays took the profile. Try again later.')]) })">
            <h2 id="pr-h" class="m-0 text-[15px] font-bold">{{ __('Your name and Lightning address') }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Prizes go to the Lightning address in your Nostr profile. You confirm the change with your own login (Google or your Nostr signer); everything else in your profile stays as it is.') }}</p>

            <form x-on:submit.prevent="save()" class="@container flex min-w-0 flex-col gap-3">
                <div class="grid grid-cols-1 items-start gap-3 @min-[40rem]:grid-cols-2">
                <label class="flex min-w-0 flex-col gap-1.5 text-sm">{{ __('Name') }}
                    <input type="text" x-model="name" maxlength="50" autocomplete="nickname" class="{{ $field }}" data-test="profile-name" :disabled="step === 'loading'">
                </label>
                <label class="flex min-w-0 flex-col gap-1.5 text-sm">{{ __('Lightning address') }}
                    <input type="text" x-model="lud16" inputmode="email" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="name@coinos.io" class="{{ $field }} font-mono" data-test="profile-lud16" :disabled="step === 'loading'">
                    <span class="text-xs leading-normal text-ink-2">{{ __('It looks like an email address. Copy it from your wallet; below you find wallets that give you one for free.') }}</span>
                </label>
                </div>
                <span class="flex flex-wrap items-center gap-3">
                    <x-button type="submit" data-test="profile-save" x-bind:disabled="step === 'loading' || step === 'saving'" class="disabled:cursor-default disabled:opacity-60">
                        <span x-show="step !== 'saving'">{{ __('Save to my Nostr profile') }}</span>
                        <span x-show="step === 'saving'" x-cloak>{{ __('Checking and signing…') }}</span>
                    </x-button>
                    <span x-show="step === 'loading'" class="text-xs text-ink-2">{{ __('Reading your profile…') }}</span>
                </span>
                @if ($error !== '')
                    <span class="text-xs text-loss" role="alert" data-test="profile-error">{{ $error }}</span>
                @endif
                <span x-show="error" x-text="error" x-cloak class="text-xs text-loss" role="alert"></span>
                <span x-show="step === 'done'" x-cloak role="status" class="flex items-center gap-1.5 text-[13px] text-win" data-test="profile-saved"><x-icon name="check" :size="16" />{{ __('Saved. Your profile is on Nostr, and the league uses the new address.') }}</span>
            </form>
        </section>

        <section aria-labelledby="pr-help-h" class="flex min-w-0 flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="profile-help">
            <h2 id="pr-help-h" class="m-0 text-[15px] font-bold">{{ __('No Lightning address yet?') }}</h2>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Lightning is the fast way to receive bitcoin. A free wallet gives you an address in a few minutes:') }}</p>
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5 text-[13px] leading-normal">
                <li>{{ __('Install one of these wallets, or open it in the browser, and create an account.') }}</li>
                <li>{{ __('Find your Lightning address in the wallet (often under “Receive” or in the profile).') }}</li>
                <li>{{ __('Copy it into the field on this page and save.') }}</li>
            </ol>
            <ul class="m-0 flex list-none flex-col gap-2 p-0" data-test="profile-wallets">
                @foreach ($wallets as [$wallet, $url, $example])
                    <li class="flex min-w-0 items-center justify-between gap-3 rounded-md bg-well px-3 py-2">
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="min-w-0 truncate text-sm font-bold text-ink hover:text-btc-hi">{{ $wallet }}</a>
                        <span class="shrink-0 font-mono text-xs text-ink-2">{{ $example }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="m-0 text-xs leading-normal text-ink-3">{{ __('Each of these passed the league’s payout check. Keep only small amounts in an app wallet.') }}</p>
        </section>
    </div>
</div>
