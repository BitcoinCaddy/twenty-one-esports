{{--
    TB5 · Broadcast desk · tournament pride (RotationKit::prideFaces: who is still standing, the top of a table, who
    goes through, or the podium once finished). Channel frame over the game's blurred cover. Left: the headline for the
    field and the cut they made, then their faces on the desk, big with names while eight or fewer are left. Right desk
    panel: the biggest upset (both faces, "Seed 7 beat seed 2", the result) and the latest results.

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, phase, board,
    standing, podium, upset, results, teamSize) and the backdrop ($backdrop); $stats for the ticker. Every key may be
    missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $clanSeats = is_int($t['teamSize'] ?? null) && $t['teamSize'] > 1;
    $pride = K::prideFaces($t, 16);
    $big = count($pride['faces']) <= 8;
    // The field x 40..632, the results x 656..1016 (the now-playing column covers x 1040..1280).
    $d = $big ? 96 : 52;
    $cols = $big ? 4 : 8;
    $cell = $big ? 136 : 68;
    $headline = K::headline($pride['headline'] === '' ? 'The field' : $pride['headline'], [38, 32, 28], 592, 1);
    $sub = K::fit($pride['sub'], K::MONO, 18, 592);
    $upset = is_array($t['upset'] ?? null) ? $t['upset'] : null;
    $upsetFaces = $upset ? K::entryFaces([$upset['winner'] ?? null, $upset['loser'] ?? null], 2, $clanSeats) : [];
    $upsetLine = $upset ? K::upsetLine($upset) : '';
    $upsetResult = $upset ? K::resultLine($upset, K::MONO, 16, 312) : '';
    $results = [];
    foreach (array_slice(is_array($t['results'] ?? null) ? $t['results'] : [], 0, $upset ? 4 : 8) as $r) {
        $line = K::resultLine($r, K::MONO, 16, 312);
        if ($line !== '') {
            $results[] = $line;
        }
    }
    $resultsY = $upsetLine !== '' ? 348 : 150;
    $note = K::fit(K::text($t, 'status', 'Live now').', '.K::text($t, 'name', 'Tournament'), K::MONO, 18, 520);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null)])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => $note])

@foreach ($headline['lines'] as $line)
<text data-unit="headline" data-box="39 {{ 140 - $headline['size'] }} 633 {{ 140 + $headline['size'] * 0.25 }}" x="40" y="140" font-family="{{ $headline['font'] }}" font-weight="800" font-size="{{ $headline['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($sub !== '')<text data-unit="sub" data-box="39 158 633 182" x="40" y="176" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $sub }}</text>@endif
<rect x="40" y="200" width="592" height="{{ $big ? 364 : 212 }}" fill="#121215" fill-opacity="0.92"/>
@foreach ($pride['faces'] as $i => $f)
@php($fx = 64 + ($i % $cols) * $cell)
@php($fy = 222 + intdiv($i, $cols) * ($big ? 164 : 80))
@include('stream.rotation.partials.face', ['face' => $f['face'], 'x' => $fx, 'y' => $fy, 'd' => $d, 'id' => 'tb5-'.$i, 'fUnit' => 'p-face-'.$i, 'fRing' => '#F7931A',
    'fRank' => $f['place'] ?? null, 'fCrown' => ($f['place'] ?? null) === 1 ? '#F7931A' : null])
@if ($big)
<text data-unit="p-name-{{ $i }}" data-box="{{ $fx - 1 }} {{ $fy + $d + 6 }} {{ $fx + $cell - 15 }} {{ $fy + $d + 30 }}" x="{{ $fx }}" y="{{ $fy + $d + 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ K::fit($f['name'] === '' ? 'Player' : $f['name'], K::MONO, 18, $cell - 16) }}</text>
@if ($f['note'] !== '')<text data-unit="p-note-{{ $i }}" data-box="{{ $fx - 1 }} {{ $fy + $d + 32 }} {{ $fx + $cell - 15 }} {{ $fy + $d + 52 }}" x="{{ $fx }}" y="{{ $fy + $d + 48 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#ADADB0">{{ K::fit($f['note'], K::MONO, 14, $cell - 16) }}</text>@endif
@endif
@endforeach
@if ($pride['faces'] === [])<text data-unit="no-faces" x="64" y="250" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">The field takes shape with the first results.</text>@endif
@if ($pride['more'] > 0)<text data-unit="more" x="64" y="{{ $big ? 548 : 400 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">+{{ $pride['more'] }} more still in it</text>@endif

<rect x="656" y="112" width="360" height="524" fill="#121215" fill-opacity="0.92"/>
@if ($upsetLine !== '')
<text data-unit="upset-label" x="680" y="148" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">Biggest upset</text>
@if (isset($upsetFaces[0]))@include('stream.rotation.partials.face', ['face' => $upsetFaces[0]['face'], 'x' => 680, 'y' => 164, 'd' => 72, 'id' => 'tb5-uw', 'fUnit' => 'upset-winner', 'fRing' => '#F7931A'])@endif
@if (isset($upsetFaces[1]))@include('stream.rotation.partials.face', ['face' => $upsetFaces[1]['face'], 'x' => 764, 'y' => 188, 'd' => 48, 'id' => 'tb5-ul', 'fUnit' => 'upset-loser'])@endif
<text data-unit="upset" data-box="679 {{ 278 - 24 }} 1001 {{ 278 + 7 }}" x="680" y="278" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">{{ K::fit($upsetLine, K::DISPLAY, 22, 320) }}</text>
@if ($upsetResult !== '')<text data-unit="upset-result" data-box="679 290 1001 314" x="680" y="308" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $upsetResult }}</text>@endif
@endif
<text data-unit="results-label" x="680" y="{{ $resultsY }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">Latest results</text>
@foreach ($results as $i => $line)
<text data-unit="result-{{ $i }}" data-box="679 {{ $resultsY + 16 + $i * 34 }} 1001 {{ $resultsY + 40 + $i * 34 }}" x="680" y="{{ $resultsY + 34 + $i * 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($results === [])<text data-unit="no-results" x="680" y="{{ $resultsY + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">First results land here.</text>@endif
</svg>
