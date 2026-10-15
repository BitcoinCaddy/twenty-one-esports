{{--
    TA2 · Arena · upcoming tournament, bracket preview. Dark left half over the game's blurred cover: the sharp cover
    beside the name and the stage note, the projected groups (or the first-round pairings of a bracket format), every
    seat a face and a name, an open seat an empty one, and the line that this is a preview. Orange right half:
    "Who plays" (seed, face, name, Elo), the invitation row "Your spot?" with the open spots, and a block with the
    call to sign up and a small countdown in fixed digit cells.
    The now-playing column covers x 1040..1280 (SceneRenderer::svg()).

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, cover, game,
    preview, roster, openSpots, taken, places, spotsLeft, countdown, countdownLabel, url) plus 'avatar' per roster row
    and preview seat ('logo' for a clan) and the backdrop ($backdrop, else $tournament['backdrop']) per
    docs/plans/2026-09-27T1811-stream-avatars-imagery.md. $stats is not used here.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    // The preview x 40..488 on the dark left, who plays on the orange right x 520..1040, its text within x 552..1016
    // (the now-playing column covers x 1040..1280).
    $title = K::headline(K::text($t, 'name', 'Tournament'), [26, 22, 20], 272, 2);
    $titleStep = round($title['size'] * 1.1);
    $titleY = 40 + round($title['size'] * 0.8);
    $pv = is_array($t['preview'] ?? null) ? $t['preview'] : null;
    $p = K::previewBoxes($pv, 40, 172, 448, 384, 2, 16, 30, 40);
    $faces = K::previewFaces($pv, $clanSeats);
    $sub = K::fit(K::previewHeading($p), K::MONO, 16, 272);
    $subY = $titleY + (count($title['lines']) - 1) * $titleStep + 30;
    $foot = K::fit(K::previewFoot($p), K::MONO, 18, 448);
    $roster = K::rosterFaces($t);
    $rows = [];
    foreach (K::whoPlays($t, 9) as $i => $r) {
        $rows[] = $r + ['y' => 214 + $i * 40, 'face' => $r['kind'] === 'player' ? ($roster[$i] ?? null) : null,
            'label' => $r['kind'] === 'player' ? K::name($r['name'], 'Player', 22, $r['rating'] === null ? 374 : 262) : null];
    }
    $cd = K::countdownParts($t['countdown'] ?? null);
    $days = $cd && $cd['days'] > 0 ? $cd['days'].'d' : null;
    // The small countdown ("6d 06:05:01") gets the room right of "Sign up" (24 px apart): at most 36 px, smaller only
    // for a day count too wide for it. 6.375 em = hh:mm:ss in digit cells; the size changes at most once a day.
    $cdEm = 6.375 + ($days ? K::width($days, K::DISPLAY, 1) * 1.04 + 10 / 36 : 0);
    $cdSize = floor(min(36, (992 - 576 - K::width('Sign up', K::DISPLAY, 36) * 1.04 - 24) / $cdEm));
    $cells = $cd ? K::digitCells($cd['hms'], 992, $cdSize, true) : null;
    $starts = K::text($t, 'startsAt');
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 18, 416);
    $game = K::wrap(K::text($t, 'game', 'Tournament'), K::MONO, 14, 140, 2);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.66])
<rect x="520" y="0" width="520" height="720" fill="#F7931A"/>

<g data-unit="cover" data-box="40 40 206 134">
<rect x="46" y="46" width="160" height="88" fill="#F7931A"/>
<rect x="40" y="40" width="160" height="88" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="40" width="160" height="88" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@else
@foreach ($game as $i => $line)
<text x="120" y="{{ 89 - (count($game) - 1) * 9 + $i * 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="215 {{ $titleY + $i * $titleStep - $title['size'] }} 489 {{ $titleY + $i * $titleStep + $title['size'] * 0.25 }}" x="216" y="{{ $titleY + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
<text data-unit="sub" data-box="215 {{ $subY - 18 }} 489 {{ $subY + 5 }}" x="216" y="{{ $subY }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $sub }}</text>
@if ($p['boxes'] === [])
<text data-unit="no-preview" x="40" y="210" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">{{ ($t['lobby'] ?? null) !== null ? 'The lobbies take shape as players sign up.' : 'The bracket takes shape as players sign up.' }}</text>
@else
@include('stream.rotation.partials.t-preview', ['p' => $p, 'faces' => $faces, 'panel' => '#16161A', 'rule' => '#2A2A30', 'titleFill' => '#F7931A', 'nameFill' => '#FFFFFF', 'openFill' => '#8B8B90', 'ring' => '#F7931A', 'pvId' => 'g'])
@endif
@if ($foot !== '')<text data-unit="foot" data-box="39 574 489 600" x="40" y="594" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $foot }}</text>@endif
<text data-unit="preview-1" x="40" y="636" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">A preview, not the draw.</text>

<text data-unit="who" x="552" y="164" font-family="Unbounded" font-weight="800" font-size="40" fill="#17120A">Who plays</text>
@foreach ($rows as $i => $r)
@if ($r['seed'] !== null)<text data-unit="seed-{{ $i }}" x="584" y="{{ $r['y'] }}" font-family="Unbounded" font-weight="800" font-size="24" fill="{{ $r['kind'] === 'open' ? '#4A3A20' : '#17120A' }}" text-anchor="end">{{ $r['seed'] }}</text>@endif
@if ($r['kind'] !== 'more')
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => 596, 'y' => $r['y'] - 26, 'd' => 34, 'id' => 'who-'.$i, 'fUnit' => 'who-face-'.$i,
    'fOpen' => $r['kind'] === 'player' ? null : ($r['kind'] === 'invite' ? 'invite' : 'open'), 'fOpenInk' => $r['kind'] === 'invite' ? '#17120A' : '#4A3A20', 'fRing' => '#17120A',
    'fGround' => '#17120A', 'fGlyph' => '#6B4A1A', 'fTagFill' => '#17120A', 'fTagInk' => '#F7931A'])
@endif
@if ($r['kind'] === 'player')
<text data-unit="player-{{ $i }}" data-box="641 {{ $r['y'] - 26 }} {{ $r['rating'] === null ? 1017 : 906 }} {{ $r['y'] + 8 }}" x="642" y="{{ $r['y'] }}" font-family="{{ $r['label']['font'] }}" font-weight="800" font-size="22" fill="#17120A">{{ $r['label']['text'] }}</text>
@if ($r['rating'] !== null)<text data-unit="elo-{{ $i }}" x="1016" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A" text-anchor="end">{{ $r['rating'] }} Elo</text>@endif
@elseif ($r['kind'] === 'invite')
<text data-unit="invite" x="642" y="{{ $r['y'] }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#17120A">Your spot?</text>
@if ($r['more'] > 0)<text data-unit="invite-more" x="1016" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A" text-anchor="end">{{ K::moreOpen($r['more']) }}</text>@endif
@elseif ($r['kind'] === 'more')
<text data-unit="more" x="642" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#4A3A20">{{ K::moreOpen($r['more']) }}</text>
@endif
@endforeach

<rect x="552" y="576" width="464" height="104" fill="#17120A"/>
<text data-unit="cta" data-box="575 590 868 644" x="576" y="626" font-family="Unbounded" font-weight="800" font-size="36" fill="#F7931A">{{ $spots['full'] ? 'Watch it live' : 'Sign up' }}</text>
<text data-unit="url" data-box="575 646 993 672" x="576" y="666" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ $url }}</text>
<g data-countdown="{{ $countdown }}">
@if ($spots['full'])
@if ($starts !== '')
<text data-unit="cd-label" x="992" y="600" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="end">Starts</text>
<text data-unit="starts" data-box="784 614 993 640" x="992" y="634" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A" text-anchor="end">{{ K::fit($starts, K::MONO, 20, 208) }}</text>
@endif
@elseif ($cells)
<text data-unit="cd-label" x="992" y="600" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="end">{{ K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 16, 250) }}</text>
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 636, 'fill' => '#F7931A'])
@if ($days)<text data-unit="cd-days" x="{{ $cells['x0'] - 10 }}" y="636" font-family="Unbounded" font-weight="800" font-size="{{ $cdSize }}" fill="#F7931A" text-anchor="end">{{ $days }}</text>@endif
@endif
</g>

</svg>
