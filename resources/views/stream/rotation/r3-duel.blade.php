{{--
    R3 · The talk show's duel card (plan "TWENTY ONE Radio", P19; the show is P16's "Turm und Genossin, der Talk"),
    only while its script is voiced and ready in the spool, or on air (ProgrammeSlides::duel()). The title and when it
    runs on top; two corners, Markus Turm in orange against Genossin Gisela in red, each with one line on where they
    stand on money; "gegen" between them; Sirius as the host under them. Nothing right of x 1016: the stream's
    now-playing column owns x 1040 on.

    Data contract (ProgrammeSlides::scene()): $programme as r1 describes it; $backdrop ?string, the brand cover.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\ProgrammeSlides', 'PS')
@php
    $p = is_array($programme ?? null) ? $programme : ['onAir' => null, 'upcoming' => [], 'loop' => [], 'stock' => [], 'newsLine' => null, 'night' => false, 'now' => time()];
    $now = (int) $p['now'];
    $show = PS::duel($p);
    $onAir = $show !== null && ($p['onAir']['key'] ?? null) === $show['key'];
    $when = $show === null ? '' : ($onAir ? 'Läuft jetzt, '.PS::remaining((int) ($show['left'] ?? 0)) : (PS::at((int) $show['at'], $now) === '' ? 'Gleich im Programm' : 'Heute um '.PS::time((int) $show['at']).', '.PS::countdown((int) $show['at'], $now)));
    // Two corners of 408 px over x 40..1016, "gegen" on the centre line x 528 between them.
    $left = K::headline('Markus Turm', [44, 38, 32, 28], 344, 1);
    $right = K::headline('Genossin Gisela', [44, 38, 32, 28], 344, 1);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.86])
@include('stream.rotation.partials.r-chrome', ['section' => 'Die Talkshow'])
<g data-unit="title" data-box="40 112 1016 216">
@include('stream.rotation.partials.r-glyph', ['family' => PS::SHOW, 'gx' => 40, 'gy' => 116, 'gs' => 48])
<text x="104" y="158" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Turm und Genossin</text>
@if ($when !== '')<text data-unit="when" x="104" y="200" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#5EEAD4">{{ K::fit($when, K::MONO, 24, 912) }}</text>@endif
</g>
{{-- The two corners, "gegen" in the gap between them. --}}
<g data-unit="corner-left" data-box="40 248 448 456">
<rect x="40" y="248" width="408" height="208" rx="4" fill="#121215" stroke="#F7931A" stroke-width="3"/>
<rect x="40" y="248" width="408" height="12" fill="#F7931A"/>
<text x="72" y="328" font-family="{{ $left['font'] }}" font-weight="800" font-size="{{ $left['size'] }}" fill="#FFFFFF">{{ $left['lines'][0] ?? '' }}</text>
<text x="72" y="380" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F7931A">Hält Gelddrucken</text>
<text x="72" y="414" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F7931A">für das Problem.</text>
</g>
<text data-unit="versus" x="528" y="364" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="30" fill="#FFFFFF">gegen</text>
<g data-unit="corner-right" data-box="608 248 1016 456">
{{-- 406 wide: its 3 px stroke ends by x 1016. --}}
<rect x="608" y="248" width="406" height="208" rx="4" fill="#121215" stroke="#F87171" stroke-width="3"/>
<rect x="608" y="248" width="406" height="12" fill="#F87171"/>
<text x="640" y="328" font-family="{{ $right['font'] }}" font-weight="800" font-size="{{ $right['size'] }}" fill="#FFFFFF">{{ $right['lines'][0] ?? '' }}</text>
<text x="640" y="380" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F87171">Hält Gelddrucken</text>
<text x="640" y="414" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F87171">für die Lösung.</text>
</g>
{{-- The host. --}}
<g data-unit="host" data-box="40 512 1016 620">
<rect x="40" y="504" width="976" height="2" fill="#2A2A30"/>
<text x="528" y="564" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="30" fill="#FFFFFF">Moderation: Sirius</text>
<text x="528" y="606" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Unterbricht beide. Das ist sein Job.</text>
</g>
</svg>
