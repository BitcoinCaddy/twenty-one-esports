{{--
    The radio slides' header line (r1..r3): the 21 mark, the station name as the jingles say it, the slide's $section
    in grey after it, up to x 1016. The viewer count stands in the stream's now-playing column (x 1040 on), not here.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php($sectionX = round(92 + K::width('TWENTY ONE ESPORTS RADIO', K::DISPLAY, 18) * 1.04 + 20))
<use href="#mark" xlink:href="#mark" x="40" y="30" width="36" height="36"/>
<text data-unit="station" x="92" y="56" font-family="Unbounded" font-weight="800" font-size="18" fill="#FFFFFF">TWENTY ONE ESPORTS RADIO</text>
<text data-unit="section" x="{{ $sectionX }}" y="56" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ K::fit($section, K::MONO, 20, 1016 - $sectionX) }}</text>
