{{--
    TA5 · Arena · tournament pride (running: who is still standing, the top of a table, who goes through; finished: the
    podium; RotationKit::prideFaces). Dark left half over the game's blurred cover: status, the name, the headline for
    the field ("The last eight"), the cut they made ("Made the top 8"), and their faces, big with names while eight or
    fewer are left. Orange right half: the biggest upset (both faces, "Seed 7 beat seed 2") and the latest results.
    The now-playing column covers x 1040..1280 (SceneRenderer::svg()).

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, phase, board,
    standing, podium, upset, results, teamSize) and the backdrop ($backdrop). Every key may be missing. $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $pride = K::prideFaces($t, 16);
    $big = count($pride['faces']) <= 8;
    // The field x 40..488 on the dark left, the results on the orange right x 520..1040, their text within x 552..1016
    // (the now-playing column covers x 1040..1280).
    $d = $big ? 88 : 48;
    $cols = $big ? 4 : 8;
    $cell = $big ? 112 : 56;
    $title = K::fit(K::text($t, 'name', 'Tournament'), K::nameFont(K::text($t, 'name', 'Tournament')), 26, 448);
    $headline = K::headline($pride['headline'] === '' ? 'The field' : $pride['headline'], [40, 34, 28], 448, 1);
    $sub = K::fit($pride['sub'], K::MONO, 18, 448);
    $upset = is_array($t['upset'] ?? null) ? $t['upset'] : null;
    $upsetFaces = $upset ? K::entryFaces([$upset['winner'] ?? null, $upset['loser'] ?? null], 2, $clanSeats) : [];
    $upsetLine = $upset ? K::upsetLine($upset) : '';
    $upsetResult = $upset ? K::resultLine($upset, K::MONO, 18, 464) : '';
    $results = [];
    foreach (array_slice(is_array($t['results'] ?? null) ? $t['results'] : [], 0, $upset ? 4 : 8) as $r) {
        $line = K::resultLine($r, K::MONO, 18, 464);
        if ($line !== '') {
            $results[] = $line;
        }
    }
    $resultsY = $upsetLine !== '' ? 408 : 150;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.66])
<rect x="520" y="0" width="520" height="720" fill="#F7931A"/>

<use href="#mark" xlink:href="#mark" x="40" y="36" width="32" height="32"/>
<text data-unit="status" data-box="83 40 488 68" x="84" y="60" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ K::fit(K::text($t, 'status', 'Live now'), K::MONO, 20, 404) }}</text>
<text data-unit="title" data-box="39 {{ 108 - 26 }} 489 {{ 108 + 7 }}" x="40" y="108" font-family="{{ K::nameFont($title) }}" font-weight="800" font-size="26" fill="#FFFFFF">{{ $title }}</text>
@foreach ($headline['lines'] as $line)
<text data-unit="headline" data-box="39 {{ 176 - $headline['size'] }} 489 {{ 176 + $headline['size'] * 0.25 }}" x="40" y="176" font-family="{{ $headline['font'] }}" font-weight="800" font-size="{{ $headline['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($sub !== '')<text data-unit="sub" data-box="39 196 489 222" x="40" y="216" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $sub }}</text>@endif

@foreach ($pride['faces'] as $i => $f)
@php($fx = 40 + ($i % $cols) * $cell)
@php($fy = 252 + intdiv($i, $cols) * ($big ? 156 : 72))
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => $fx, 'y' => $fy, 'd' => $d, 'id' => 'ta5-'.$i, 'fUnit' => 'p-face-'.$i, 'fRing' => '#F7931A',
    'fRank' => $f['place'] ?? null, 'fRankFill' => '#F7931A', 'fRankInk' => '#17120A', 'fCrown' => ($f['place'] ?? null) === 1 ? '#F7931A' : null])
@if ($big)
<text data-unit="p-name-{{ $i }}" data-box="{{ $fx - 1 }} {{ $fy + $d + 6 }} {{ $fx + $cell - 7 }} {{ $fy + $d + 30 }}" x="{{ $fx }}" y="{{ $fy + $d + 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">{{ K::fit($f['name'] === '' ? 'Player' : $f['name'], K::MONO, 16, $cell - 8) }}</text>
@if ($f['note'] !== '')<text data-unit="p-note-{{ $i }}" data-box="{{ $fx - 1 }} {{ $fy + $d + 32 }} {{ $fx + $cell - 7 }} {{ $fy + $d + 52 }}" x="{{ $fx }}" y="{{ $fy + $d + 48 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0">{{ K::fit($f['note'], K::MONO, 14, $cell - 8) }}</text>@endif
@endif
@endforeach
@if ($pride['faces'] === [])<text data-unit="no-faces" x="40" y="290" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">The field takes shape with the first results.</text>@endif
@if ($pride['more'] > 0)<text data-unit="more" x="40" y="{{ $big ? 620 : 452 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">+{{ $pride['more'] }} more still in it</text>@endif

@if ($upsetLine !== '')
<text data-unit="upset-label" x="552" y="150" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#4A3A20">Biggest upset</text>
@if (isset($upsetFaces[0]))@include('stream.rotation.partials.face', ['face' => $upsetFaces[0]['face'], 'x' => 552, 'y' => 170, 'd' => 88, 'id' => 'ta5-uw', 'fUnit' => 'upset-winner', 'fRing' => '#17120A', 'fGround' => '#17120A', 'fGlyph' => '#6B4A1A', 'fTagFill' => '#17120A', 'fTagInk' => '#F7931A'])@endif
@if (isset($upsetFaces[1]))@include('stream.rotation.partials.face', ['face' => $upsetFaces[1]['face'], 'x' => 652, 'y' => 202, 'd' => 56, 'id' => 'ta5-ul', 'fUnit' => 'upset-loser', 'fGround' => '#17120A', 'fGlyph' => '#6B4A1A', 'fTagFill' => '#17120A', 'fTagInk' => '#F7931A'])@endif
<text data-unit="upset" data-box="551 {{ 316 - 28 }} 1017 {{ 316 + 8 }}" x="552" y="316" font-family="Unbounded" font-weight="800" font-size="28" fill="#17120A">{{ K::fit($upsetLine, K::DISPLAY, 26, 464) }}</text>
@if ($upsetResult !== '')<text data-unit="upset-result" data-box="551 332 1017 358" x="552" y="352" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ $upsetResult }}</text>@endif
@endif
<text data-unit="results-label" x="552" y="{{ $resultsY }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#4A3A20">Latest results</text>
@foreach ($results as $i => $line)
<text data-unit="result-{{ $i }}" data-box="551 {{ $resultsY + 18 + $i * 36 }} 1017 {{ $resultsY + 44 + $i * 36 }}" x="552" y="{{ $resultsY + 38 + $i * 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ $line }}</text>
@endforeach
@if ($results === [])<text data-unit="no-results" x="552" y="{{ $resultsY + 38 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">First results land here.</text>@endif
</svg>
