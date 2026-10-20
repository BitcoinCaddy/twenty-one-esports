@props([
    'active' => null,
    'title',
    'lead' => null,
    'crumbs' => [],
    'current' => null,
    'notice' => null,
    'error' => null,
    'noticeTest' => null,
    'errorTest' => null,
    'flashTest' => 'admin-flash',
])

{{--
    The one frame of every admin page (P17, redrawn in revamp P8 from the boards Admin*,
    canvas/gen/pages_more.py `admin()`): from 1024 px the admin map as a sidebar of 240 px
    (<x-admin.nav side>), beside it the page: the breadcrumb bar as a console line with the
    season state and the page's one live readout on the right, the title (display, 30 px)
    with the page's actions on the right, one lead line, the outcome of the last action
    (the session `status`, or `notice`/`error` of the page) and the page itself.

    Breadcrumbs: Admin (the status page, for an organizer their tournaments), the group of
    the active page as text, then `crumbs`, a list of [label, href] pages above this one,
    then the page on screen (`current`, else its label in the admin map, else the title).

    Slots: `badge` (beside the title), `actions`, `readout` (the right end of the
    breadcrumb bar: one real figure of the page, per mood_b.py ADMIN_READOUT).
--}}
@php
    $nav = \App\Support\Navigation\AdminNavigation::forCurrentUser();
    $groups = $nav->groups();
    $group = \App\Support\Navigation\AdminNavigation::PAGES[$active] ?? null;
    $here = $current ?? ($crumbs !== [] ? $title : null) ?? collect($groups)->flatMap(fn (array $g): array => $g['items'])->firstWhere('key', $active)['label'] ?? $title;
    $flash = session('status');
    $seasonState = \App\Support\SeasonChain\Seasons::state();
    $seasonLabel = match ($seasonState) {
        'live' => __('Season running'),
        'between' => __('Between seasons'),
        default => __('Before Block 0'),
    };
@endphp

{{-- Under the header without the page-top gap: the sidebar starts at the header's edge. --}}
<div {{ $attributes->class(['-mt-page-top flex grow flex-col lg:-mt-page-top-lg', 'lg:grid lg:grid-cols-[240px_minmax(0,1fr)]' => $groups !== []]) }}>
    <x-admin.nav :active="$active" :groups="$groups" side />

    {{-- A named container (`admin`): a page's layout switches on the width its column really has (1024 px leaves 720 beside the
         sidebar, 1280 leaves 976), not on the viewport's. The tournament editor squeezed facts into 53 px and help into 156 px boxes
         at 1024 and 1280 on `lg:`/`xl:` (E1, 2026-10-20). --}}
    <div class="@container/admin flex min-w-0 flex-col gap-6 px-4 pt-4 pb-10 lg:px-8 lg:pt-8">
        <nav aria-label="{{ __('Breadcrumb') }}" data-test="admin-crumbs" class="rv-console flex min-h-10 flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2 text-xs">
            <ol class="m-0 flex min-w-0 list-none flex-wrap items-center gap-x-2 p-0">
                <li><a href="{{ $nav->homeHref() }}" class="inline-flex min-h-6 items-center text-ink-2 hover:text-ink">{{ __('Admin') }}</a></li>
                @if ($group && $nav->isAdmin())
                    <li class="flex items-center gap-2 text-ink-2"><span class="text-ink-3" aria-hidden="true">/</span><span>{{ \App\Support\Navigation\AdminNavigation::groupLabel($group) }}</span></li>
                @endif
                @foreach ($crumbs as [$label, $href])
                    <li class="flex min-w-0 items-center gap-2"><span class="text-ink-3" aria-hidden="true">/</span><a href="{{ $href }}" class="inline-flex min-h-6 min-w-0 items-center text-ink-2 [overflow-wrap:anywhere] hover:text-ink">{{ $label }}</a></li>
                @endforeach
                <li class="flex min-w-0 items-center gap-2"><span class="text-ink-3" aria-hidden="true">/</span><span aria-current="page" class="min-w-0 text-ink [overflow-wrap:anywhere]">{{ $here }}</span></li>
            </ol>
            <span class="inline-flex items-center gap-1 whitespace-nowrap text-ink-3" data-test="admin-season-state" data-state="{{ $seasonState }}"><x-icon name="matches" :size="12" class="text-btc" />{{ $seasonLabel }}</span>
            @isset($readout)
                <span class="flex flex-wrap items-center gap-x-3 gap-y-1 sm:ml-auto" data-test="admin-readout">{{ $readout }}</span>
            @endisset
        </nav>

        <header class="flex flex-col gap-2">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between xl:gap-8">
                <div class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2">
                    <h1 class="rv-h1s min-w-0 [overflow-wrap:anywhere] max-lg:text-[24px]">{{ $title }}</h1>
                    @isset($badge){{ $badge }}@endisset
                </div>
                @isset($actions)
                    <div class="flex flex-wrap items-center gap-3 xl:justify-end" data-test="admin-actions">{{ $actions }}</div>
                @endisset
            </div>
            @if ($lead)
                <p class="m-0 max-w-[72ch] text-[13px] leading-normal text-ink-2">{{ $lead }}</p>
            @endif
        </header>

        @if (is_string($flash) && $flash !== '')
            <x-admin.flash :message="$flash" :data-test="$flashTest" />
        @endif
        @if ($notice)
            <x-admin.flash :message="$notice" :data-test="$noticeTest ?? 'admin-notice'" />
        @endif
        @if ($error)
            <x-admin.flash tone="error" :message="$error" :data-test="$errorTest ?? 'admin-error'" />
        @endif

        {{ $slot }}
    </div>
</div>
