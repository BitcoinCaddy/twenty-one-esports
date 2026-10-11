<?php

use App\Games\GameRegistry;
use App\Enums\JoinRequestStatus;
use App\Models\Clan;
use App\Models\ClanJoinRequest;
use App\Models\Lineup;
use App\Models\User;
use App\Support\Clans\ClanPride;
use App\Support\Clans\ClanStats;
use App\Support\Engagement\ClanHashrate;
use App\Support\PageMeta;
use App\Support\Series\Ladders;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Clans (Clans.dc.html, P7 of plan "Refactor und Design-Revamp"): every clan in one ranked table with its proudest
 * moment (ClanPride) as a line under its name, Clan Rating and Hashrate (ClanStats) once the season runs. All of it
 * is the league's own records; before Block 0 the standings are one card, and a clan without a moment shows none.
 */
new #[Layout('layouts::app', ['section' => 'clans'])] class extends Component {
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Clans'));
        app(PageMeta::class)->describe(__('Clans'), __('All clans of the TWENTY ONE esports league: players, meetups on the map, Clan Rating and Hashrate.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('clans'));
    }

    /**
     * The search: it filters the cards in the browser (performance plan P7, no roundtrip per keystroke) and
     * follows with `$wire.$set(..., false)`, so a render and a shared `?q=` link start with the same cards hidden.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Hashrate window: `s` season, `w` last 7 days (design default). */
    public string $window = 'w';

    public function pickWindow(string $window): void
    {
        $this->window = $window === 's' ? 's' : 'w';
    }

    /**
     * Every clan with its lineups and its number of players, once per
     * request; the search filters this list in the browser.
     *
     * @return Collection<int, Clan>
     */
    #[Computed]
    public function directory(): Collection
    {
        return Clan::query()->with('lineups')->withCount('members')->orderBy('name')->get();
    }

    /**
     * The members a row needs (the Clan Rating reads their Elos, Block Height their games): the member rows and, of
     * each user, only the columns those read (performance plan P2, S8).
     *
     * @return array<string, \Closure>
     */
    public static function faces(): array
    {
        return [
            'members' => fn ($query) => $query->select(['id', 'clan_id', 'user_id', 'role', 'joined_at']),
            'members.user' => fn ($query) => $query->select(['id', 'pubkey', 'npub', 'name', 'picture', 'avatar_path', 'profile_checked_at']),
        ];
    }

    /**
     * Every clan, by name, with its members: the search hides rows in the browser.
     *
     * @return Collection<int, Clan>
     */
    #[Computed]
    public function clans(): Collection
    {
        return $this->directory->loadMissing(self::faces());
    }

    /**
     * What the search looks in, lower case: name, tag and meetup city, one per line (a query never spans two).
     */
    public static function haystack(Clan $clan): string
    {
        return mb_strtolower($clan->name."\n".$clan->clantag."\n".$clan->meetup_city);
    }

    /** Whether the clan matches the search as it stands on the server (the first paint before Alpine starts). */
    public function matches(Clan $clan): bool
    {
        $search = mb_strtolower(trim($this->search));

        return $search === '' || str_contains(self::haystack($clan), $search);
    }

    /**
     * Clan id => its proud moments, best first (ClanPride, cached a minute).
     *
     * @return array<int, list<array<string, mixed>>>
     */
    #[Computed]
    public function pride(): array
    {
        return app(ClanPride::class)->all();
    }

    /** The page's clan numbers, computed once per request. */
    #[Computed]
    public function stats(): ClanStats
    {
        return app(ClanStats::class);
    }

    /** The viewer's clan id, null for guests and players without a clan. */
    #[Computed]
    public function myClanId(): ?int
    {
        $user = auth()->user();

        return $user instanceof User ? $user->clanMember?->clan_id : null;
    }

    /**
     * The clans the viewer has an open application or join request with.
     *
     * @return list<int>
     */
    #[Computed]
    public function appliedTo(): array
    {
        $user = auth()->user();

        return $user instanceof User
            ? ClanJoinRequest::query()->where('user_id', $user->id)->whereIn('status', [JoinRequestStatus::Pending, JoinRequestStatus::Approved])->pluck('clan_id')->all()
            : [];
    }

    /**
     * "Apply" on a clan card (plan "Clan-Bewerbungen", P3): the clan page with
     * the form open, or "Applied" while the viewer's request is open. None on
     * the own clan or a clan with applications off.
     *
     * @return array{url: string, applied: bool}|null
     */
    public function applyFor(Clan $clan): ?array
    {
        $applied = in_array($clan->id, $this->appliedTo, true);

        if ($clan->id === $this->myClanId || (! $applied && ! $clan->applications_open)) {
            return null;
        }

        return ['url' => $applied ? route('clans.show', $clan) : route('clans.show', ['clan' => $clan, 'apply' => 1]), 'applied' => $applied];
    }

    /**
     * Clan id => "Challenge" link, as on the clan page (P16): the challenge
     * form with the clan's lineup of a series game picked (the first game
     * of the registry, its biggest mode first), in the game and mode of a
     * lineup the viewer captains when both have one. None for the own clan
     * and for clans without a lineup; a guest gets the link, the form asks
     * them to log in.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function challenges(): array
    {
        $user = auth()->user();
        $series = app(GameRegistry::class)->series();
        $order = array_keys($series);
        // Every series game in registry order, each game's biggest mode first.
        $sorted = fn (Collection $lineups): Collection => $lineups->filter(fn (Lineup $lineup): bool => isset($series[$lineup->game]))
            ->sortBy(fn (Lineup $lineup): array => [array_search($lineup->game, $order, true), -($series[$lineup->game]->mode($lineup->mode)->teamSize ?? 0)]);
        $mine = $user instanceof User && $this->myClanId !== null
            ? $sorted(Lineup::query()->with(['seats', 'clan'])->where('clan_id', $this->myClanId)->get())
                ->filter(fn (Lineup $lineup): bool => $lineup->isActingCaptain($user))->values()
            : collect();
        $links = [];

        foreach ($this->directory as $clan) {
            if ($clan->id === $this->myClanId) {
                continue;
            }

            $theirs = $sorted($clan->lineups)->keyBy(fn (Lineup $lineup): string => $lineup->game.'/'.$lineup->mode);

            if ($theirs->isEmpty()) {
                continue;
            }

            $own = $mine->first(fn (Lineup $lineup): bool => $theirs->has($lineup->game.'/'.$lineup->mode));
            $target = $own !== null ? $theirs[$own->game.'/'.$own->mode] : $theirs->first();
            $links[$clan->id] = route('challenges.create', array_filter(['lineup' => $own?->id, 'to' => $target->id]));
        }

        return $links;
    }

    /**
     * Meetup against meetup (P10): the live season's clan hashrate per meetup
     * city, from the league's own records (ClanHashrate). Empty before Block 0.
     *
     * @return list<array{city: string, hashrate: int, clans: int}>
     */
    #[Computed]
    public function cities(): array
    {
        return app(ClanHashrate::class)->cities(Ladders::season());
    }

    /**
     * Pins on the placeholder map: a plain projection of the meetup
     * coordinates onto the German-speaking area.
     *
     * @return list<array{clan: Clan, tag: string, city: string, x: string, y: string}>
     */
    #[Computed]
    public function pins(): array
    {
        return $this->directory->filter(fn (Clan $clan): bool => $clan->meetup_latitude !== null && $clan->meetup_longitude !== null)
            ->map(fn (Clan $clan) => [
                'clan' => $clan,
                'tag' => $clan->clantag,
                'city' => (string) $clan->meetup_city,
                'x' => max(1, min(80, round(((float) $clan->meetup_longitude - 7.0) / 12.3 * 100))).'%',
                'y' => max(4, min(86, round((52.3 - (float) $clan->meetup_latitude) / 5.85 * 100))).'%',
            ])->values()->all();
    }

    /**
     * The three strongest clans by Clan Rating (all clans, not the search).
     *
     * @return list<array{clan: Clan, rating: int, top: list<int>}>
     */
    #[Computed]
    public function byRating(): array
    {
        return $this->directory->map(fn (Clan $clan) => ['clan' => $clan, ...$this->stats->clanRating($clan)])
            ->filter(fn (array $row): bool => $row['rating'] !== null)
            ->sortByDesc('rating')->take(3)->values()->all();
    }

    /**
     * The three most active clans by Hashrate in the window, with their share of the leader for the bar.
     *
     * @return array{rows: list<array{clan: Clan, points: int, width: string}>, total: int}
     */
    #[Computed]
    public function byHash(): array
    {
        $season = $this->window === 's';
        $rows = $this->directory->map(function (Clan $clan) use ($season) {
            $hashrate = $this->stats->hashrate($clan);

            return ['clan' => $clan, 'points' => $season ? $hashrate['season'] : $hashrate['week']];
        })->sortByDesc('points')->values();

        $top = max(1, (int) $rows->max('points'));

        return [
            'total' => (int) $rows->sum('points'),
            'rows' => $rows->filter(fn (array $row): bool => $row['points'] > 0)->take(3)
                ->map(fn (array $row) => [...$row, 'width' => number_format($row['points'] / $top * 100, 1).'%'])->values()->all(),
        ];
    }
}; ?>

@php
    $directory = $this->directory;
    $clans = $this->clans;
    $pride = $this->pride;
    $live = $this->stats->seasonLive();
    $challenges = $this->challenges;
    $myClanId = $this->myClanId;
    $searching = trim($search) !== '';
    $players = (int) $directory->sum('members_count');
    $meetups = $directory->filter(fn ($clan) => filled($clan->meetup_name))->count();
    $myClan = $myClanId === null ? null : $directory->firstWhere('id', $myClanId);
    // The table's order (Clans.dc.html "#"): by Clan Rating once the season runs, then by Hashrate this week; before
    // Block 0 the directory's own order by name.
    $ranked = $live
        ? $clans->sortBy(fn ($clan) => [-(int) ($this->stats->clanRating($clan)['rating'] ?? 0), -(int) $this->stats->hashrate($clan)['week'], $clan->name])->values()
        : $clans->values();
@endphp

{{--
    Clans (Clans.dc.html; template T14; rules R8, R13): the head with the count and the way in, the search, every clan
    in one ranked table (Clan Rating, Hashrate, Apply) with its proudest moment as a line under its name; on the right
    how the numbers count and the meetup map. Once the season runs the three standings lead the right column.
--}}
<div class="grid grow grid-cols-1 gap-6 px-4 pt-4 pb-8 lg:grid-cols-12 lg:px-12 lg:pt-6"
     x-data="{ q: $wire.search, clans: @js($directory->mapWithKeys(fn ($clan) => [$clan->id => $this::haystack($clan)])->all()),
               term() { return this.q.trim().toLowerCase() }, shown(id) { return this.clans[id].includes(this.term()) },
               get count() { return Object.keys(this.clans).filter((id) => this.shown(Number(id))).length } }">
    <div class="flex min-w-0 flex-col gap-4 lg:col-span-8">
        {{-- Head: the name with the count, the league in one sentence, the way in. --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[4px] bg-[linear-gradient(90deg,#14110C,var(--color-ground)_70%)] p-4" data-test="clans-head">
            <h1 class="rv-h1 max-lg:text-[24px]">{{ __('Clans') }}</h1>
            <span class="font-display text-[30px] font-bold text-btc tabular-nums max-lg:text-[22px]" aria-hidden="true">{{ $directory->count() }}</span>
            @if ($directory->isNotEmpty())
                <p class="m-0 min-w-0 grow text-[12px] text-ink-2" data-test="clan-counters">
                    {{ __(':players in :clans.', ['players' => trans_choice(':count player|:count players', $players), 'clans' => trans_choice(':count clan|:count clans', $directory->count())]) }}
                    @if ($meetups > 0){{ trans_choice(':count clan meets at a portal meetup.|:count clans meet at a portal meetup.', $meetups) }}@endif
                </p>
            @endif
            @if ($myClan)
                <a href="{{ route('clans.show', $myClan) }}" class="rv-b2" data-test="clan-mine-link"><x-clan-tag :clan="$myClan" size="sm" /><span class="truncate">{{ __('Your clan') }}</span></a>
            @elseif ($directory->isNotEmpty())
                <a href="{{ route('clans.create') }}" class="rv-b" data-test="clan-start">{{ __('Start a clan') }}</a>
            @endif
        </div>

        @if ($directory->isEmpty())
            <x-empty-state :heading="__('No clan yet')" :text="__('Start the first one: your tag, your players, your meetup city.')" class="rv-card px-4 py-6 lg:px-6" data-test="clans-empty">
                <a href="{{ route('clans.create') }}" class="rv-b">{{ __('Start a clan') }}</a>
            </x-empty-state>
        @else
            <label for="clan-q" class="sr-only">{{ __('Search clans') }}</label>
            <span class="relative flex w-full items-center sm:w-[420px]">
                <x-icon name="search" :size="16" class="pointer-events-none absolute left-3 text-ink-3" />
                <input id="clan-q" type="search" x-model="q" x-on:input.debounce.300ms="$wire.$set('search', q, false)" data-test="clan-search" placeholder="{{ __('Clan, tag or meetup city') }}"
                       class="h-11 w-full rounded-[4px] border border-line bg-ground pr-3 pl-9 text-[13px] text-ink placeholder:text-ink-3">
            </span>

            @php($visible = $clans->filter(fn ($clan) => $this->matches($clan))->count())
            <p class="rv-card m-0 px-4 py-6 text-center text-[13px] text-ink-2" data-test="clans-no-match" x-show="count === 0 && term() !== ''" @style(['display: none' => ! ($visible === 0 && $searching)])>{{ __('No clan matches your search.') }}</p>
            <section aria-label="{{ __('All clans') }}" class="rv-card overflow-x-auto p-0" data-test="clan-grid" x-show="count > 0" @style(['display: none' => $visible === 0])>
                <table class="rv-tb">
                    <thead>
                        <tr>
                            <th class="w-10 max-sm:hidden">#</th>
                            <th>{{ __('Clan') }}</th>
                            <th class="is-r max-sm:hidden">{{ __('Clan Rating') }}</th>
                            <th class="is-r max-sm:hidden">{{ __('Hashrate') }}</th>
                            <th><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ranked as $index => $clan)
                            @php($apply = $this->applyFor($clan))
                            @php($moment = $pride[$clan->id][0] ?? null)
                            @php($numbers = $live ? ['rating' => $this->stats->clanRating($clan)['rating'], 'week' => $this->stats->hashrate($clan)['week']] : null)
                            <tr wire:key="clan-{{ $clan->id }}" x-show="shown({{ $clan->id }})" @style(['display: none' => ! $this->matches($clan)]) data-test="clan-card" data-clan="{{ $clan->slug }}">
                                <td class="text-ink-3 tabular-nums max-sm:hidden">{{ $index + 1 }}</td>
                                <td class="whitespace-normal">
                                    <span class="flex min-w-0 items-center gap-3">
                                        <x-clan-tag :clan="$clan" :tile="32" :loading="$index < 8 ? 'eager' : 'lazy'" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-raised text-[11px] font-bold text-ink-2" data-test="clan-card-mark" />
                                        <span class="flex min-w-0 flex-col">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <a href="{{ route('clans.show', $clan) }}" class="font-bold text-ink [overflow-wrap:anywhere] hover:text-btc-hi">{{ $clan->name }}</a>
                                                @if ($clan->id === $myClanId)
                                                    <span class="rv-tag is-btc" data-test="clan-card-mine">{{ __('Your clan') }}</span>
                                                @endif
                                                @if ($clan->isMemberClan())
                                                    <x-member-badge />
                                                @endif
                                            </span>
                                            @if ($moment)
                                                <x-clans.moment :moment="$moment" class="text-[12px] text-ink-2" />
                                            @endif
                                        </span>
                                    </span>
                                </td>
                                <td class="is-r tabular-nums max-sm:hidden @if (! ($numbers['rating'] ?? null)) text-ink-3 @endif" @if ($numbers) data-test="clan-card-numbers" @endif>{{ $numbers['rating'] ?? '–' }}</td>
                                <td class="is-r tabular-nums max-sm:hidden @if (! ($numbers['week'] ?? null)) text-ink-3 @endif">{{ $numbers ? $numbers['week'] : '–' }}</td>
                                <td class="is-r">
                                    <span class="inline-flex flex-wrap justify-end gap-2">
                                        @if ($apply)
                                            <a href="{{ $apply['url'] }}" class="rv-b2 is-sm h-11 max-sm:px-2" data-test="clan-card-apply" data-applied="{{ $apply['applied'] ? 'true' : 'false' }}">{{ $apply['applied'] ? __('Applied') : __('Apply') }}<span class="sr-only"> {{ $clan->name }}</span></a>
                                        @endif
                                        @if ($challenges[$clan->id] ?? null)
                                            <a href="{{ $challenges[$clan->id] }}" class="rv-b2 is-sm h-11 max-sm:px-2" data-test="clan-card-challenge">{{ __('Challenge') }}<span class="sr-only"> {{ $clan->name }}</span></a>
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    </div>

    <div class="flex min-w-0 flex-col gap-4 lg:col-span-4">
        {{-- The season standings, top three each, once Block 0 is mined. --}}
        @if ($live)
            @php($hash = $this->byHash)
            @php($cities = array_slice($this->cities, 0, 3))
            @php($topCity = max(1, $cities[0]['hashrate'] ?? 0))
            <section aria-label="{{ __('Season standings') }}" class="flex flex-col gap-4" data-test="clan-standings">
                <div class="rv-card flex flex-col gap-3 p-4">
                    <span class="flex flex-col gap-0.5">
                        <h2 id="cr-h" class="rv-h3">{{ __('Strongest clans') }}</h2>
                        <span class="rv-m">{{ __('Clan Rating: the average of the 3 best solo rapid Elos') }}</span>
                    </span>
                    <ol class="m-0 flex list-none flex-col gap-1 p-0">
                        @forelse ($this->byRating as $index => $row)
                            <li wire:key="cr-{{ $row['clan']->id }}" @class(['relative grid grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-3 rounded-[4px] px-2 py-2 text-[13px]', 'bg-btc-tint' => $index === 0]) data-test="rating-row">
                                <b @class(['font-display', 'text-lg text-btc' => $index === 0, 'text-ink-3' => $index > 0])>{{ $index + 1 }}</b>
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <a href="{{ route('clans.show', $row['clan']) }}" class="truncate text-ink after:absolute after:inset-0 hover:text-btc-hi">{{ $row['clan']->name }}</a>
                                    <span class="truncate text-[11px] text-ink-3">{{ implode(' · ', $row['top']) }}</span>
                                </span>
                                <span class="flex flex-col items-end gap-0.5"><b class="font-display text-[15px] tabular-nums">{{ $row['rating'] }}</b><span class="text-[11px] whitespace-nowrap text-ink-3">{{ __('avg top 3') }}</span></span>
                            </li>
                        @empty
                            <li class="py-3 text-[13px] text-ink-2">{{ __('No clan has 3 rapid Elos yet.') }}</li>
                        @endforelse
                    </ol>
                </div>
                <div class="rv-card flex flex-col gap-3 p-4">
                    <span class="flex flex-wrap items-center justify-between gap-3">
                        <span class="flex flex-col gap-0.5">
                            <h2 id="hs-h" class="rv-h3">{{ __('Most active clans') }}</h2>
                            <span class="rv-m">{{ __('Hashrate: points from every rated game') }}</span>
                        </span>
                        <div role="group" aria-label="{{ __('Time window') }}" class="flex shrink-0 gap-1" data-test="hashrate-window">
                            @foreach (['s' => $this->stats->seasonName(), 'w' => __('7 days')] as $key => $label)
                                <button type="button" wire:click="pickWindow('{{ $key }}')" aria-pressed="{{ $window === $key ? 'true' : 'false' }}" class="rv-chip h-11" data-test="hashrate-{{ $key }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </span>
                    <ol class="m-0 flex list-none flex-col gap-1 p-0">
                        @forelse ($hash['rows'] as $index => $row)
                            <li wire:key="hs-{{ $row['clan']->id }}" @class(['relative grid grid-cols-[24px_minmax(0,1fr)] items-center gap-3 rounded-[4px] px-2 py-2 text-[13px]', 'bg-btc-tint' => $index === 0]) data-test="hashrate-row">
                                <b @class(['font-display', 'text-lg text-btc' => $index === 0, 'text-ink-3' => $index > 0])>{{ $index + 1 }}</b>
                                <span class="flex min-w-0 flex-col gap-1.5">
                                    <a href="{{ route('clans.show', $row['clan']) }}" class="truncate text-ink after:absolute after:inset-0 hover:text-btc-hi">{{ $row['clan']->name }}</a>
                                    <span class="grid grid-cols-[minmax(0,1fr)_36px] items-center gap-2">
                                        <span class="block h-2 rounded-r bg-raised"><span class="block h-2 rounded-r bg-btc" style="width: {{ $row['width'] }}"></span></span>
                                        <b class="text-right">{{ $row['points'] }}</b>
                                    </span>
                                </span>
                            </li>
                        @empty
                            <li class="py-3 text-[13px] text-ink-2">{{ $window === 'w' ? __('No clan scored in the last 7 days.') : __('No clan scored this season yet.') }}</li>
                        @endforelse
                    </ol>
                </div>
                <div class="rv-card flex flex-col gap-3 p-4" data-test="city-ranking">
                    <span class="flex flex-col gap-0.5">
                        <h2 id="cities-h" class="rv-h3">{{ __('Meetup against meetup') }}</h2>
                        <span class="rv-m">{{ __('the Hashrate of all clans of a meetup city, this season') }}</span>
                    </span>
                    <ol class="m-0 flex list-none flex-col gap-1 p-0">
                        @forelse ($cities as $index => $row)
                            <li wire:key="city-{{ $index }}" @class(['grid grid-cols-[24px_minmax(0,1fr)] items-center gap-3 rounded-[4px] px-2 py-2 text-[13px]', 'bg-btc-tint' => $index === 0]) data-test="city-row">
                                <b @class(['font-display', 'text-lg text-btc' => $index === 0, 'text-ink-3' => $index > 0])>{{ $index + 1 }}</b>
                                <span class="flex min-w-0 flex-col gap-1.5">
                                    <span class="truncate">{{ $row['city'] }} <span class="text-[11px] text-ink-3">{{ trans_choice(':count clan|:count clans', $row['clans']) }}</span></span>
                                    <span class="grid grid-cols-[minmax(0,1fr)_40px] items-center gap-2">
                                        <span class="block h-2 rounded-r bg-raised"><span class="block h-2 rounded-r bg-btc" style="width: {{ intdiv(100 * $row['hashrate'], $topCity) }}%"></span></span>
                                        <b class="text-right tabular-nums">{{ $row['hashrate'] }}</b>
                                    </span>
                                </span>
                            </li>
                        @empty
                            <li class="py-3 text-[13px] text-ink-2">{{ __('No clan is linked to a meetup yet.') }}</li>
                        @endforelse
                    </ol>
                </div>
            </section>
        @else
            {{-- Before Block 0 the standings are one card; the rules fold out (Clans.dc.html, right column). --}}
            <div class="rv-card flex flex-col gap-2 border-btc-ring p-4 shadow-[inset_4px_0_0_var(--color-btc)]" data-test="standings-teaser">
                <p class="m-0 flex items-start gap-3 text-[13px] text-ink-2">
                    <x-icon name="mining" :size="16" class="mt-0.5 shrink-0 text-btc" />
                    <span data-test="rating-empty">{{ __('Clan Rating, Hashrate and the meetup ranking start at Block 0, with the first rated games.') }}</span>
                </p>
                <details class="group" open data-test="standings-rules">
                    <summary class="inline-flex h-11 cursor-pointer items-center gap-2 text-xs text-ink-2 hover:text-ink">
                        <span class="transition-transform duration-150 group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true">▸</span>{{ __('How it counts') }}
                    </summary>
                    <ul class="m-0 flex list-disc flex-col gap-2 pb-2 pl-5 text-xs leading-[1.6] text-ink-2" data-test="hashrate-empty">
                        <li>{{ __('Clan Rating: the average of the 3 best solo rapid Elos in the clan. Chess has no separate team Elo: every board of a team match is a rated solo game.') }}</li>
                        <li>{{ __('Hashrate: win 3, draw 2, loss 1 per rated game; a won team match or series adds +5 for the clan. Casual games don\'t count.') }}</li>
                        <li>{{ __('Meetup against meetup: a clan counts for the city of the portal meetup it is linked to.') }}</li>
                        <li><a href="{{ route('rules') }}" class="rv-lk">{{ __('All league rules') }}</a></li>
                    </ul>
                </details>
            </div>
        @endif

        {{-- The map only once a clan is linked to a meetup city: an empty placeholder map said nothing (P53). --}}
        @if (count($this->pins) > 0)
            <section aria-labelledby="map-h" class="rv-card flex flex-col gap-3 p-4">
                <h2 id="map-h" class="rv-h3">{{ __('Meetup map') }}</h2>
                <div role="img" aria-label="{{ trans_choice('Map placeholder with :count clan at its meetup city|Map placeholder with :count clans at their meetup cities', count($this->pins)) }}"
                     x-data="meetupMap" data-test="meetup-map"
                     class="relative h-[222px] overflow-hidden rounded-[4px] border border-line bg-ground [background-image:radial-gradient(#26262C_1.2px,transparent_1.2px)] [background-size:12px_12px]">
                    {{-- Dots stay on their city; meetupMap.js moves a label that would cover another dot or label (user, 2026-10-07). --}}
                    @foreach ($this->pins as $pin)
                        <span class="absolute block size-3" style="left: {{ $pin['x'] }}; top: {{ $pin['y'] }}" data-pin>
                            <span class="block size-3 rounded-full bg-btc shadow-[0_0_0_4px_rgba(247,147,26,.25)]" data-pin-dot></span>
                            <span class="absolute top-[-4px] left-5 flex items-center gap-1.5 rounded-[2px] bg-card px-1.5 py-0.5 text-xs whitespace-nowrap" data-pin-label>@if ($pin['clan']->localLogoUrl())<x-clan-tag :clan="$pin['clan']" :tile="16" class="block size-4 shrink-0 rounded-[2px]" aria-hidden="true" />@endif<b>{{ $pin['tag'] }}</b> <span class="text-ink-2">{{ $pin['city'] }}</span></span>
                        </span>
                    @endforeach
                </div>
                <span class="rv-m" data-map-note>{{ __('Clans by meetup city, from the EINUNDZWANZIG portal') }}</span>
            </section>
        @endif
    </div>
</div>
