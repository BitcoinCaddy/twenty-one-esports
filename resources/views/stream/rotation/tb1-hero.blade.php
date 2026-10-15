{{--
    TB1 · Broadcast desk · upcoming tournament, hero. Channel frame (bug with the status as note, orange ticker) over
    the game's blurred cover. Left: the sharp cover (or a neutral game panel), the name, the game line, the
    description as far as room is left. Right: a desk panel with the countdown to sign-up close in fixed digit cells
    (it re-renders every second), the start, the seats (one per place: the faces of who is in, empty seats dashed;
    more than 32 places: the bar), the places left and the orange call to sign up. A full tournament says
    "Sign-up is full" and "Watch it live".

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, description, status,
    game, mode, format, rated, where, startsAt, countdown, countdownLabel, taken, places, spotsLeft, cover, url, roster)
    plus 'avatar' per roster row ('logo' for a clan), 'seats' per roster row (the places it takes) and 'solos' (a team
    tournament's solo sign-ups, unseeded until the draw and so not in the roster: one seat each, 'name' and 'avatar'),
    and the backdrop ($backdrop, else $tournament['backdrop']) per docs/plans/2026-09-27T1811-stream-avatars-imagery.md;
    $stats for the ticker.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    // The cover and the title x 40..488, the sign-up panel x 520..1016 (the now-playing column covers x 1040..1280).
    $title = K::headline(K::text($t, 'name', 'Tournament'), [38, 32, 28], 448, 2);
    $titleY = 396 + $title['size'];
    $titleStep = round($title['size'] * 1.1);
    $metaY = $titleY + (count($title['lines']) - 1) * $titleStep + 34;
    $where = K::where($t);
    $meta = K::fit(K::tournamentMeta($t).($where === 'Online' ? '' : ', '.$where), K::MONO, 18, 448);
    $descY = $metaY + 30;
    $desc = K::wrap($t['description'] ?? '', K::MONO, 18, 448, max(0, min(2, intdiv(644 - $descY, 26) + 1)));
    $game = K::headline(K::text($t, 'game', 'Tournament'), [40, 32, 26], 400, 2);
    $cd = K::countdownParts($t['countdown'] ?? null);
    $note = K::countdownNote($cd, $spots['full']);
    $cells = $cd ? K::digitCells($cd['hms'], 544, 68) : null;
    $starts = K::text($t, 'startsAt');
    $seats = K::seats($t, 544, 368, 448, 40, $spots['places'] <= 16 ? 8 : 16);
    $bar = $seats ? [] : K::spotCells($spots['taken'], $spots['places'], 544, 448, 4);
    $leftY = $seats ? 368 + $seats['h'] + 30 : 430;
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 20, 400);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null)])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::fit(K::text($t, 'status', 'Sign-up open'), K::MONO, 18, 520)])

<g data-unit="cover" data-box="40 112 488 364">
<rect x="40" y="112" width="448" height="252" fill="#121215"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="112" width="448" height="252" preserveAspectRatio="xMidYMid meet" xlink:href="{{ $cover }}"/>
@else
@foreach ($game['lines'] as $i => $line)
<text x="264" y="{{ 238 - (count($game['lines']) - 1) * $game['size'] * 0.55 + $i * round($game['size'] * 1.1) + $game['size'] * 0.36 }}" font-family="{{ $game['font'] }}" font-weight="800" font-size="{{ $game['size'] }}" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="39 {{ $titleY + $i * $titleStep - $title['size'] }} 489 {{ $titleY + $i * $titleStep + $title['size'] * 0.25 }}" x="40" y="{{ $titleY + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($meta !== '')<text data-unit="meta" data-box="39 {{ $metaY - 20 }} 489 {{ $metaY + 6 }}" x="40" y="{{ $metaY }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $meta }}</text>@endif
@foreach ($desc as $i => $line)
<text data-unit="desc-{{ $i }}" data-box="39 {{ $descY + $i * 26 - 16 }} 489 {{ $descY + $i * 26 + 6 }}" x="40" y="{{ $descY + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach

<rect x="520" y="112" width="496" height="536" fill="#121215" fill-opacity="0.9"/>
@if ($countdown !== '')<text data-unit="cd-label" x="544" y="156" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 20, 448) }}</text>@endif
<g data-countdown="{{ $countdown }}">
@if ($cells)
@if ($note)<text data-unit="cd-note" data-box="543 180 993 216" x="544" y="208" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">{{ K::fit($note, K::DISPLAY, 28, 448) }}</text>@endif
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 296, 'fill' => '#F7931A'])
@else
<text data-unit="cd-raw" data-box="543 250 993 300" x="544" y="290" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ K::fit($countdown, K::DISPLAY, 40, 448) }}</text>
@endif
</g>
@if ($starts !== '')<text data-unit="starts" data-box="543 326 993 350" x="544" y="344" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit('Starts '.$starts, K::MONO, 20, 448) }}</text>@endif

@if ($seats)
@foreach ($seats['seats'] as $si => $seat)
@include('stream.rotation.partials.face', ['face' => $seat['face'], 'x' => $seat['x'], 'y' => $seat['y'], 'd' => $seats['d'], 'id' => 'seat-'.$si, 'fUnit' => 'seat-'.$si,
    'fOpen' => $seat['filled'] ? null : 'open', 'fRing' => '#F7931A'])
@endforeach
@else
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="368" width="{{ $cell['w'] }}" height="32" fill="#F7931A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="369" width="{{ $cell['w'] - 2 }}" height="30" fill="none" stroke="#6B6B72" stroke-width="2"/>@endif
@endforeach
</g>
@endif
<text data-unit="spots-left" x="544" y="{{ $leftY }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#F7931A">{{ $spots['leftText'] }}</text>

<rect x="544" y="504" width="448" height="112" fill="#F7931A"/>
<text data-unit="cta" data-box="567 514 969 564" x="568" y="554" font-family="Unbounded" font-weight="800" font-size="40" fill="#17120A">{{ $spots['full'] ? 'Watch it live' : 'Sign up' }}</text>
<text data-unit="url" data-box="567 576 969 602" x="568"
 y="596" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#17120A">{{ $url }}</text>
</svg>
