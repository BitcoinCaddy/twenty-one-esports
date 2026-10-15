{{--
    B4 · Broadcast desk · clans: one clan in the spotlight, proud. Channel frame over the backdrop; the pitch and the
    clan count on the left, the clan's card on the right: its logo big (176 px, orange ring, a glow behind it), else
    its tag or initial on an orange tile of the same size, then name and founding date, its proudest moment in orange
    (ClanPride: a tournament place, a won series, a streak, the week's wins or new players), the faces of its
    longest-standing members, and the counts. Remote logos (logoUrl) are never fetched while rendering.

    Data contract:
      $stats  array{clans?: int, clan?: array{name: string, members?: int, games?: int, founded?: string,
              tag?: string, logoUrl?: string, logo?: ?string, pride?: ?string, faces?: list<?string>}|null, …}
              (StreamStats::clanSpotlight) + the ticker counts. logoUrl is ignored, logo and faces are data URIs
              (RotationKit::avatarUri); founded is shown as given (e.g. "Sep 12, 2026").
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $clans = K::count($stats ?? [], 'clans');
    $clan = is_array($stats['clan'] ?? null) && K::clean($stats['clan']['name'] ?? '') !== '' ? $stats['clan'] : null;
    $tail = match (true) {
        $clans === null => null,
        $clans === 0 => 'No clans yet. Yours could be the first.',
        $clans === 1 => 'clan so far. Yours could be the second.',
        default => 'clans so far. Start yours or join one.',
    };
    $numW = $clans ? K::width((string) $clans, K::DISPLAY, 64) : 0;
    if ($clan) {
        // The clan's panel x 560..1016, centred on x 788 (the now-playing column covers x 1040..1280).
        $cname = K::name($clan['name'], 'Clan', 30, 420);
        // The logo, else a tile with the clan tag (at most 4 characters) or the name's first letter.
        $logo = ['uri' => K::avatarUri($clan['logo'] ?? null), 'tag' => K::clanTag(is_string($clan['tag'] ?? null) ? $clan['tag'] : '', (string) $clan['name'])];
        $foundedText = K::clean($clan['founded'] ?? '');
        $founded = $foundedText !== '' ? K::fit('founded '.$foundedText, K::MONO, 18, 420) : null;
        $moment = K::fit(is_string($clan['pride'] ?? null) ? $clan['pride'] : '', K::MONO, 22, 420);
        $faces = array_slice(array_values(array_filter(is_array($clan['faces'] ?? null) ? $clan['faces'] : [], 'is_string')), 0, 5);
        $facesX = 788 - (count($faces) * 56 + max(0, count($faces) - 1) * 12) / 2;
        $tiles = array_values(array_filter([
            is_int($clan['members'] ?? null) ? [$clan['members'], $clan['members'] === 1 ? 'member' : 'members'] : null,
            is_int($clan['games'] ?? null) ? [$clan['games'], $clan['games'] === 1 ? 'game played' : 'games played'] : null,
        ]));
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])

<text x="40" y="276" font-family="Unbounded" font-weight="800" font-size="38" fill="#FFFFFF">Play for your clan</text>
{{-- COPY-CHECK: every clan with an entry on a game's ladders has its value in the ladder's clan view (LadderBoard::clans(), P40). --}}
<text x="40" y="326" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Every win lifts your clan on the ladder.</text>
@if ($tail !== null)
@if ($clans > 0)<text data-unit="clan-count" x="40" y="452" font-family="Unbounded" font-weight="800" font-size="64" fill="#F7931A">{{ $clans }}</text>@endif
@php($inline = $clans === 0 || $numW <= 60)
@php($tailX = $clans > 0 && $inline ? 40 + $numW + 16 : 40)
@php($tailY = $inline ? 452 : 496)
<text data-unit="clan-tail" data-box="{{ $tailX - 1 }} {{ $tailY - 24 }} 536 {{ $tailY + 6 }}" x="{{ $tailX }}" y="{{ $tailY }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $tail }}</text>
@endif

@if ($clan)
<rect x="560" y="96" width="456" height="552" fill="#121215" fill-opacity="0.92"/>
{{-- The glow: one radial gradient behind the logo (a blur filter would cost rsvg far more per frame). --}}
<radialGradient id="clan-glow"><stop offset="0.5" stop-color="#F7931A" stop-opacity="0.55"/><stop offset="1" stop-color="#F7931A" stop-opacity="0"/></radialGradient>
<circle cx="788" cy="208" r="140" fill="url(#clan-glow)"/>
@include('stream.rotation.partials.face', ['face' => $logo + ['fit' => 'meet'], 'x' => 700, 'y' => 120, 'd' => 176, 'id' => 'clan', 'fUnit' => 'clan-logo', 'fShape' => 'square', 'fRing' => '#F7931A', 'fGround' => '#17120A'])
<text data-unit="clan-name" data-box="577 318 999 360" x="788" y="350" font-family="{{ $cname['font'] }}" font-weight="800" font-size="30" fill="#FFFFFF" text-anchor="middle">{{ $cname['text'] }}</text>
@if ($founded)<text data-unit="founded" data-box="577 364 999 388" x="788" y="382" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0" text-anchor="middle">{{ $founded }}</text>@endif
@if ($moment !== '')<text data-unit="clan-moment" data-box="577 404 999 434" x="788" y="426" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A" text-anchor="middle">{{ $moment }}</text>@endif
@foreach ($faces as $fi => $mate)
@include('stream.rotation.partials.face', ['face' => ['uri' => $mate, 'tag' => null], 'x' => $facesX + $fi * 68, 'y' => 448, 'd' => 56, 'id' => 'member-'.$fi, 'fUnit' => 'member-'.$fi, 'fRing' => '#F7931A'])
@endforeach
@foreach ($tiles as $ti => [$val, $label])
@php($tx = count($tiles) === 1 ? 688 : 580 + $ti * 216)
<rect x="{{ $tx }}" y="524" width="200" height="100" fill="#0A0A0B"/>
<text data-unit="tile-{{ $ti }}" data-box="{{ $tx + 15 }} 534 {{ $tx + 192 }} 576" x="{{ $tx + 16 }}" y="568" font-family="Unbounded" font-weight="800" font-size="{{ strlen((string) $val) > 5 ? 24 : 32 }}" fill="#F7931A">{{ $val }}</text>
<text x="{{ $tx + 16 }}" y="606" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $label }}</text>
@endforeach
@else
<rect x="560" y="180" width="456" height="220" fill="#121215" fill-opacity="0.92"/>
<text x="592" y="256" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">No clan to show yet.</text>
<text x="592" y="304" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Found the first one on</text>
<text x="592" y="336"
 font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">esports.einundzwanzig.space</text>
@endif
</svg>
