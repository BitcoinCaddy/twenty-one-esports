{{--
    The radio slides' header line (r1..r3): the 21 mark, the station name as the jingles say it, the slide's $section
    in grey after it, and the live viewer badge right-aligned to x 1024, clear of the client's LIVE badge
    (x >= 1040, y < 112). Optional $viewers.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php($sectionX = round(92 + K::width('TWENTY ONE ESPORTS RADIO', K::DISPLAY, 18) * 1.04 + 20))
@php($vb = K::viewerBadge($viewers ?? null, 1024, 56, K::MONO, 20, 18))
<use href="#mark" xlink:href="#mark" x="40" y="30" width="36" height="36"/>
<text data-unit="station" x="92" y="56" font-family="Unbounded" font-weight="800" font-size="18" fill="#FFFFFF">TWENTY ONE ESPORTS RADIO</text>
<text data-unit="section" x="{{ $sectionX }}" y="56" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ K::fit($section, K::MONO, 20, ($vb === null ? 1024 : $vb['x0'] - 24) - $sectionX) }}</text>
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#F7931A', 'countInk' => '#FFFFFF', 'wordInk' => '#ADADB0'])@endif
