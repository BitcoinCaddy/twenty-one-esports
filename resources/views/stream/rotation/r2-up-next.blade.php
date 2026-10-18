{{--
    R2 · Gleich auf Sendung (plan "TWENTY ONE Radio", P19): the next show that is ready in the spool, in the spotlight.
    Left its family (glyph and word in its colour), the title as big as it fits in three lines, a bar in the family
    colour and the format's tagline; right the countdown as a solid block in the family colour (the minutes huge,
    "um 14:45" under them; from an hour on the time is the big number). Under it what follows the show. The next show
    is the first that is not a bulletin, else the next bulletin, at night the loop's next episode
    (ProgrammeSlides::spotlight()). Nothing right of x 1016: the stream's now-playing column owns x 1040 on.

    Data contract (ProgrammeSlides::scene()): $programme as r1 describes it; $backdrop ?string, the brand cover.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\ProgrammeSlides', 'PS')
@php
    $p = is_array($programme ?? null) ? $programme : ['onAir' => null, 'upcoming' => [], 'loop' => [], 'stock' => [], 'newsLine' => null, 'night' => false, 'now' => time()];
    $now = (int) $p['now'];
    $show = PS::spotlight($p);
    if ($show !== null) {
        $family = $show['family'];
        $ink = PS::COLOURS[$family] ?? '#FFFFFF';
        // The title, the bar and two lines of tagline stay above the rule at y 584, left of the countdown block
        // (x 728..1016; the stream's now-playing column owns x 1040 on).
        $title = PS::spotTitle($show['title'], 640, 224);
        $pitch = round($title['size'] * 1.12);
        $titleTop = 196;
        $bars = $titleTop + count($title['lines']) * $pitch + 8;
        $tagline = K::wrap($show['tagline'], K::MONO, 26, 640, 2);
        $seconds = (int) $show['at'] - $now;
        $minutes = (int) ceil(max(0, $seconds) / 60);
        $countdown = PS::countdown((int) $show['at'], $now);
        $at = PS::time((int) $show['at']);
        // What follows the show (the next item after it in the programme).
        $after = null;
        foreach ($p['upcoming'] as $item) {
            if ($item['at'] !== null && $item['at'] > $show['at'] && $item['key'] !== $show['key']) {
                $after = $item;
                break;
            }
        }
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.86])
@include('stream.rotation.partials.r-chrome', ['section' => 'Gleich auf Sendung', 'voice' => $p['voice'] ?? null])
@if ($show !== null)
<g data-unit="family" data-box="40 112 680 168">
@include('stream.rotation.partials.r-glyph', ['family' => $family, 'gx' => 40, 'gy' => 112, 'gs' => 56])
<text x="120" y="152" font-family="Unbounded" font-weight="800" font-size="28" fill="{{ $ink }}">{{ PS::FAMILY_NAMES[$family] ?? '' }}</text>
</g>
<g data-unit="title" data-box="40 {{ $titleTop }} 680 {{ $bars }}">
@foreach ($title['lines'] as $i => $line)
<text x="40" y="{{ round($titleTop + ($i + 1) * $pitch - $title['size'] * 0.2, 1) }}" font-family="{{ $title['font'] }}" font-weight="{{ $title['font'] === 'Unbounded' ? 800 : 700 }}" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
</g>
<rect data-unit="bar" x="40" y="{{ $bars + 8 }}" width="144" height="12" fill="{{ $ink }}"/>
@foreach ($tagline as $i => $line)
<text data-unit="tagline" x="40" y="{{ $bars + 68 + $i * 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#FFFFFF">{{ $line }}</text>
@endforeach
{{-- The countdown block. --}}
<g data-unit="countdown" data-box="728 120 1016 520">
<rect x="728" y="120" width="288" height="400" rx="4" fill="{{ $ink }}"/>
@if ($countdown === 'jetzt')
<text x="872" y="326" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="72" fill="#0A0A0B">jetzt</text>
<text x="872" y="400" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#0A0A0B">um {{ $at }}</text>
@elseif ($minutes < 60)
<text x="760" y="184" font-family="Unbounded" font-weight="800" font-size="32" fill="#0A0A0B">in</text>
<text data-unit="countdown-minutes" x="872" y="366" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="152" fill="#0A0A0B">{{ $minutes }}</text>
<text x="872" y="432" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="40" fill="#0A0A0B">min</text>
<rect x="760" y="456" width="224" height="3" fill="#0A0A0B"/>
<text x="872" y="496" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#0A0A0B">um {{ $at }}</text>
@else
<text x="760" y="184" font-family="Unbounded" font-weight="800" font-size="32" fill="#0A0A0B">um</text>
<text data-unit="countdown-time" x="872" y="340" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="66" fill="#0A0A0B">{{ $at }}</text>
<rect x="760" y="456" width="224" height="3" fill="#0A0A0B"/>
<text x="872" y="496" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#0A0A0B">{{ $countdown }}</text>
@endif
</g>
@if ($after !== null)
<rect x="40" y="584" width="976" height="2" fill="#2A2A30"/>
@php($afterText = K::fit('Danach um '.PS::time((int) $after['at']).': '.$after['title'], K::MONO, 22, 932))
@include('stream.rotation.partials.r-glyph', ['family' => $after['family'], 'gx' => 40, 'gy' => 616, 'gs' => 28])
<text data-unit="after" x="84" y="638" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ $afterText }}</text>
@endif
@else
<text data-unit="none" x="40" y="330" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">Gerade läuft Musik.</text>
@endif
</svg>
