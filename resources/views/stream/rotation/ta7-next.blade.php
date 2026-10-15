{{--
    TA7 · Arena · the call to sign up for the next tournament, after a tournament past sign-up. Dark left half over the
    next one's blurred cover: what the tournament on show is doing ("… is live."), the headline "Don't watch
    the next one. Play it.", then the next tournament: its sharp cover, name, game line and how it runs in one line.
    Orange right half as the sign-up hero (ta1): the countdown to sign-up close in fixed digit cells, the seats with
    the faces already in, the spots left and the call to sign up. The planner only schedules this slide while a next
    tournament is open; without one (a sign-up frame, the scene test) it says where every tournament is listed.
    The now-playing column covers x 1040..1280 (SceneRenderer::svg()).

    Data contract: $tournament (TournamentLiveSlides: name, phase) and $next (a TournamentSlides frame: name, game, mode,
    format, cover, backdrop, countdown, countdownLabel, taken, places, spotsLeft, roster, solos, howItRuns, url), per
    catalog-tournaments.md. Every key may be missing. $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $n = is_array($next ?? null) ? $next : null;
    $nt = $n ?? [];
    // The call and the next tournament x 40..488 on the dark left, its sign-up on the orange right x 520..1040, the
    // text within x 552..1016 (the now-playing column covers x 1040..1280).
    $context = K::nextContext($t, K::MONO, 20, 404);
    $cover = K::coverUri($nt['cover'] ?? null);
    $name = K::headline(K::text($nt, 'name', 'Tournament'), [26, 22, 20], 272, 2);
    $nameStep = round($name['size'] * 1.1);
    $meta = K::wrap(K::tournamentMeta($nt), K::MONO, 16, 272, 2);
    $metaY = 440 + (count($name['lines']) - 1) * $nameStep + 30;
    $how = is_array($nt['howItRuns'] ?? null) ? $nt['howItRuns'] : [];
    $howLine = K::wrap(trim(K::text($how, 'short').(K::text($how, 'matches') !== '' ? '. '.K::text($how, 'matches').'.' : '')), K::MONO, 18, 448, 2);
    $spots = K::spots($nt);
    $cd = K::countdownParts($nt['countdown'] ?? null);
    $note = K::countdownNote($cd, $spots['full']);
    $cells = $cd ? K::digitCells($cd['hms'], 552, 70) : null;
    $cdLabel = K::fit(K::text($nt, 'countdownLabel', 'Sign-up closes in'), K::MONO, 20, 464);
    $seats = $n ? K::seats($nt, 552, 380, 464, 40, $spots['places'] <= 16 ? 8 : 16) : null;
    $bar = $n && ! $seats ? K::spotCells($spots['taken'], $spots['places'], 552, 464, 4) : [];
    $leftY = $seats ? 380 + $seats['h'] + 32 : 448;
    $url = $n ? K::tournamentUrl($nt) : 'esports.einundzwanzig.space/tournaments';
    $urlSize = K::monoSize($url, 20, 416);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $nt['backdrop'] ?? $backdrop ?? null, 'bdDim' => 0.62])
<rect x="520" y="0" width="520" height="720" fill="#F7931A"/>

<use href="#mark" xlink:href="#mark" x="40" y="36" width="32" height="32"/>
<text data-unit="context" data-box="83 40 489 68" x="84" y="60" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $context }}</text>
<text data-unit="headline-0" data-box="39 {{ 164 - 50 }} 489 {{ 164 + 12 }}" x="40" y="164" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">Don't watch</text>
<text data-unit="headline-1" data-box="39 {{ 222 - 50 }} 489 {{ 222 + 12 }}" x="40" y="222" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">the next one.</text>
<text data-unit="headline-2" data-box="39 {{ 280 - 50 }} 489 {{ 280 + 12 }}" x="40" y="280" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">Play it.</text>

@if ($n)
<g data-unit="cover" data-box="40 356 206 450">
<rect x="46" y="362" width="160" height="88" fill="#F7931A"/>
<rect x="40" y="356" width="160" height="88" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg). --}}
<image x="40" y="356" width="160" height="88" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@endif
</g>
<text data-unit="next-label" x="216" y="374" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">Up next</text>
@foreach ($name['lines'] as $i => $line)
<text data-unit="next-name-{{ $i }}" data-box="215 {{ 408 + $i * $nameStep - $name['size'] }} 489 {{ 408 + $i * $nameStep + $name['size'] * 0.25 }}" x="216" y="{{ 408 + $i * $nameStep }}" font-family="{{ $name['font'] }}" font-weight="800" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@foreach ($meta as $i => $line)
<text data-unit="next-meta-{{ $i }}" data-box="215 {{ $metaY - 32 + $i * 22 - 16 }} 489 {{ $metaY - 32 + $i * 22 + 5 }}" x="216" y="{{ $metaY - 32 + $i * 22 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $line }}</text>
@endforeach
@foreach ($howLine as $i => $line)
<text data-unit="how-{{ $i }}" data-box="39 {{ 540 + $i * 26 - 18 }} 489 {{ 540 + $i * 26 + 6 }}" x="40" y="{{ 540 + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach

@if ($cells)
<text data-unit="cd-label" x="552" y="150" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">{{ $cdLabel }}</text>
<g data-countdown="{{ $nt['countdown'] }}">
@if ($note)<text data-unit="cd-note" data-box="551 180 1017 224" x="552" y="214" font-family="Unbounded" font-weight="800" font-size="30" fill="#17120A">{{ K::fit($note, K::DISPLAY, 30, 464) }}</text>@endif
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 310, 'fill' => '#17120A'])
</g>
@endif
@if ($seats)
@foreach ($seats['seats'] as $si => $seat)
@include('stream.rotation.partials.face', ['face' => $seat['face'], 'x' => $seat['x'], 'y' => $seat['y'], 'd' => $seats['d'], 'id' => 'ta7-seat-'.$si, 'fUnit' => 'seat-'.$si,
    'fOpen' => $seat['filled'] ? null : 'open', 'fOpenInk' => '#17120A', 'fRing' => '#17120A', 'fGround' => '#17120A', 'fGlyph' => '#6B4A1A', 'fTagFill' => '#17120A', 'fTagInk' => '#F7931A'])
@endforeach
@else
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="380" width="{{ $cell['w'] }}" height="32" fill="#17120A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="381" width="{{ $cell['w'] - 2 }}" height="30" fill="none" stroke="#17120A" stroke-width="2"/>@endif
@endforeach
</g>
@endif
<text data-unit="spots-left" x="552" y="{{ $leftY }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#17120A">{{ $spots['leftText'] }}</text>
@else
<text data-unit="no-next" x="40" y="400" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">Every tournament and cup is listed on the site.</text>
@endif

<rect x="552" y="540" width="464" height="112" fill="#17120A"/>
<text data-unit="cta" data-box="575 550 993 606" x="576" y="592" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ $n ? 'Sign up' : 'Find yours' }}</text>
<text data-unit="url" data-box="575 610 993 636" x="576" y="630" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ $url }}</text>
</svg>
