<?php

use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Cards\PrideCard;
use App\Support\Cards\PrideMoments;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The player card on the player page (plan "KI-Stimme der Liga", P4, board PlayerPrideCard.dc.html; PrideCard::player()): the trading-card picture of
 * the player's real numbers, its title from PrideVoice (the model's once written and switched on, else
 * PrideMoments::playerTitle()). On the own page: post it (share-button, `player-card`) and the downloads. For
 * everybody: the season's Wrapped story, when the player played the season. Nothing for a player without a result, a
 * block or a title.
 *
 * Lazy (rule R7, a secondary feature loaded after the page): reading the card's numbers costs the ladders, the season
 * chain and the tournament wins again, which the player page's query budget (PlayerTrophiesTest, PlayerStatsTest) has
 * no room for. The placeholder keeps the card's height, so nothing jumps when it arrives.
 */
new #[Lazy] class extends Component {
    #[Locked]
    public User $player;

    #[Computed]
    public function card(): PrideCard
    {
        return PrideCard::player($this->player);
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="mx-4 lg:mx-0" data-test="player-pride-card-placeholder" aria-hidden="true">
                <div class="rv-card flex flex-col gap-4 p-4 sm:flex-row lg:p-6">
                    <div class="aspect-[1200/630] w-full shrink-0 rounded-[4px] bg-well sm:w-[320px]"></div>
                    <div class="flex grow flex-col gap-2"><div class="h-6 w-40 rounded-[4px] bg-well"></div><div class="h-4 w-64 max-w-full rounded-[4px] bg-well"></div></div>
                </div>
            </div>
            HTML;
    }
}; ?>

@php
    $prideCard = $this->card;
    $isMe = Auth::id() === $player->id;
    $name = $player->displayName();
@endphp
{{-- Without a card the root stays (Livewire needs one) but takes no place: an empty box would be the page's edge-flush element. --}}
<div @class(['mx-4 lg:mx-0', 'hidden' => ! PrideMoments::hasCard($prideCard->facts)])>
    @if (PrideMoments::hasCard($prideCard->facts))
        <section aria-labelledby="pride-card-h" class="rv-card flex flex-col gap-4 p-4 sm:flex-row sm:items-start lg:p-6" data-test="player-pride-card">
            <img src="{{ $prideCard->path('wide') }}" width="1200" height="630" decoding="async" data-test="player-pride-card-image"
                 alt="{{ __(':name’s player card: :title', ['name' => $name, 'title' => $prideCard->line]) }}"
                 class="aspect-[1200/630] h-auto w-full shrink-0 rounded-[4px] sm:w-[320px]">
            <div class="flex min-w-0 flex-col gap-2">
                <h2 id="pride-card-h" class="rv-h2">{{ __('Player card') }}</h2>
                <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2">{{ __('Rating, wins, peak, blocks and titles from the league’s records, on one card.') }}</p>
                @if ($isMe)
                    <livewire:share-button type="player-card" :moment="$player->npub" :label="__('Post my card')" :wire:key="'player-card-share-'.$player->id" />
                @endif
                @if ($prideCard->facts['wrapped'] && $prideCard->facts['season'] !== null)
                    <a href="{{ route('season.wrapped', [$prideCard->facts['season'], $player->npub]) }}" data-test="player-wrapped-link"
                       class="inline-flex min-h-11 items-center gap-2 self-start text-[14px] font-bold whitespace-nowrap">
                        <x-icon name="play" :size="16" />{{ __('Watch :season, wrapped', ['season' => BadgeCopy::season($prideCard->facts['season'])]) }}
                    </a>
                @endif
            </div>
        </section>
    @endif
</div>
