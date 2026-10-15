{{--
    TA3 · Arena · how a tournament runs (drawing, running). Dark left half over the game's blurred cover: status, the
    sharp cover beside the name, the format in one line, then "How it runs" and its stages as a numbered sequence (a
    real one: stage 1 is played before stage 2). Orange right half: the facts (matches, showing up, start, end), and a
    dark block at the bottom with what happens now: the round in play, or the Bitcoin block that draws the bracket and
    the time to the start. The now-playing column covers x 1040..1280 (SceneRenderer::svg()).

    Data contract: $tournament as TournamentLiveSlides builds it (catalog-tournaments.md: name, status, phase, cover,
    game, mode, format, howItRuns {short, steps, matches, showUp, starts, ends}, now, drawBlock, countdown, url) and the
    backdrop ($backdrop). Every key may be missing (a sign-up frame renders too). $stats unused.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $how = is_array($t['howItRuns'] ?? null) ? $t['howItRuns'] : [];
    $cover = K::coverUri($t['cover'] ?? null);
    // The steps x 40..488 on the dark left, the facts on the orange right x 520..1040, their text within x 552..1016
    // (the now-playing column covers x 1040..1280).
    $title = K::headline(K::text($t, 'name', 'Tournament'), [26, 22, 20], 272, 2);
    $titleStep = round($title['size'] * 1.1);
    $titleY = 40 + round($title['size'] * 0.8);
    $short = K::wrap(K::text($how, 'short', K::text($t, 'format')), K::MONO, 16, 272, 2);
    $shortY = $titleY + (count($title['lines']) - 1) * $titleStep + 30;
    $game = K::wrap(K::text($t, 'game', 'Tournament'), K::MONO, 14, 140, 2);
    $steps = K::howSteps($how, 3);
    $stepRows = [];
    $sy = 316;
    foreach ($steps as $step) {
        $line = K::wrap($step['line'], K::MONO, 18, 392, 2);
        $stepRows[] = ['n' => $step['n'], 'title' => K::fit($step['title'], K::DISPLAY, 24, 392), 'lines' => $line, 'y' => $sy];
        $sy += 48 + count($line) * 28 + 30;
    }
    $facts = [];
    $fy = 150;
    foreach (K::howFacts($how) as [$label, $value]) {
        $lines = K::wrap($value, K::DISPLAY, 24, 464, 2);
        $facts[] = ['label' => $label, 'lines' => $lines, 'y' => $fy];
        $fy += 30 + count($lines) * 32 + 22;
    }
    $drawing = ($t['phase'] ?? null) === 'drawing';
    // A sign-up frame (TournamentSlides: no phase) shows where to sign up and the time to the close.
    $signup = ! isset($t['phase']);
    $block = is_int($t['drawBlock'] ?? null) ? $t['drawBlock'] : null;
    $nowLine = $drawing ? ($block !== null ? 'Bitcoin block '.number_format($block).' draws the bracket' : 'The draw is on') : K::text($t, 'now');
    $nowText = K::wrap($nowLine, K::DISPLAY, 24, 416, 2);
    $cd = $drawing || $signup ? K::countdownParts($t['countdown'] ?? null) : null;
    $cdLabel = $signup ? K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 18, 300) : 'Starts in';
    $url = K::tournamentUrl($t);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.7])
<rect x="520" y="0" width="520" height="720" fill="#F7931A"/>

<g data-unit="cover" data-box="40 40 206 134">
<rect x="46" y="46" width="160" height="88" fill="#F7931A"/>
<rect x="40" y="40" width="160" height="88" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the data URI. --}}
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
@foreach ($short as $i => $line)
<text data-unit="short-{{ $i }}" data-box="215 {{ $shortY + $i * 22 - 18 }} 489 {{ $shortY + $i * 22 + 5 }}" x="216" y="{{ $shortY + $i * 22 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $line }}</text>
@endforeach

<text data-unit="how" x="40" y="248" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">How it runs</text>
@foreach ($stepRows as $i => $st)
<text data-unit="step-n-{{ $i }}" x="40" y="{{ $st['y'] + 8 }}" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ $st['n'] }}</text>
<text data-unit="step-title-{{ $i }}" data-box="95 {{ $st['y'] - 24 }} 489 {{ $st['y'] + 8 }}" x="96" y="{{ $st['y'] }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">{{ $st['title'] }}</text>
@foreach ($st['lines'] as $li => $line)
<text data-unit="step-line-{{ $i }}-{{ $li }}" data-box="95 {{ $st['y'] + 14 + $li * 28 }} 489 {{ $st['y'] + 40 + $li * 28 }}" x="96" y="{{ $st['y'] + 34 + $li * 28 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach
@endforeach
@if ($stepRows === [])<text data-unit="no-steps" x="40" y="300" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">The format is on the tournament page.</text>@endif

@foreach ($facts as $i => $f)
<text data-unit="fact-label-{{ $i }}" x="552" y="{{ $f['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#4A3A20">{{ $f['label'] }}</text>
@foreach ($f['lines'] as $li => $line)
<text data-unit="fact-{{ $i }}-{{ $li }}" data-box="551 {{ $f['y'] + 8 + $li * 32 }} 1017 {{ $f['y'] + 40 + $li * 32 }}" x="552" y="{{ $f['y'] + 34 + $li * 32 }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#17120A">{{ $line }}</text>
@endforeach
@endforeach

<rect x="552" y="{{ $cd ? 520 : 556 }}" width="464" height="{{ $cd ? 160 : 124 }}" fill="#17120A"/>
@if ($nowText !== [])
<text data-unit="now-label" x="576" y="{{ $cd ? 552 : 588 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $drawing ? 'Draw pending' : 'Now' }}</text>
@foreach ($nowText as $i => $line)
<text data-unit="now-{{ $i }}" data-box="575 {{ ($cd ? 560 : 596) + $i * 32 }} 993 {{ ($cd ? 592 : 628) + $i * 32 }}" x="576" y="{{ ($cd ? 586 : 622) + $i * 32 }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#F7931A">{{ $line }}</text>
@endforeach
@else
<text data-unit="now-label" x="576" y="{{ $cd ? 552 : 588 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $signup ? 'Sign up at' : 'Everything about it' }}</text>
<text data-unit="url" data-box="575 {{ $cd ? 568 : 604 }} 993 {{ $cd ? 596 : 632 }}" x="576" y="{{ $cd ? 590 : 626 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize($url, 20, 416) }}" fill="#FFFFFF">{{ $url }}</text>
@endif
@if ($cd)
<g data-countdown="{{ $t['countdown'] }}">
<text data-unit="cd-label" x="576" y="660" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $cdLabel }}</text>
<text data-unit="cd" x="992" y="662" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#FFFFFF" text-anchor="end">{{ ($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'] }}</text>
</g>
@endif

</svg>
