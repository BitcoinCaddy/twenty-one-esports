{{--
    Direction C (terminal ticker): header line (mark, wordmark, $section in grey) and the stats bar at the bottom
    (rule at y 625 over x 40..1016, cells of equal width over x 40..656, the site URL in the last 360 px). Counts come
    from $stats; a missing one drops its cell and the others share the width. The content ends at x 1016
    (RotationKit::CONTENT_RIGHT): the now-playing column covers x 1040..1280 and carries the live viewer count.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php($urlX = K::CONTENT_RIGHT - 360)
@php($cells = K::statCells($stats ?? []))
@php($cellW = $cells === [] ? 0 : ($urlX - 40) / count($cells))
@php($valueSize = $cellW >= 150 ? 28 : 24)
<use href="#mark" xlink:href="#mark" x="40" y="32" width="32" height="32"/>
<text x="84" y="54" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">TWENTY ONE ESPORTS</text>
<text data-unit="section" data-box="289 36 {{ K::CONTENT_RIGHT }} 60" x="290" y="54" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ K::fit($section, K::MONO, 18, K::CONTENT_RIGHT - 290) }}</text>
<rect x="40" y="625" width="{{ K::CONTENT_RIGHT - 40 }}" height="1" fill="#2A2A30"/>
@foreach ($cells as $i => [$label, $value])
@php($cx = 40 + $i * $cellW + ($i > 0 ? 16 : 0))
@if ($i > 0)<rect x="{{ 40 + $i * $cellW }}" y="626" width="1" height="66" fill="#2A2A30"/>@endif
<text data-unit="stat-label-{{ $i }}" x="{{ $cx }}" y="650" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">{{ $label }}</text>
<text data-unit="stat-value-{{ $i }}" data-box="{{ $cx - 1 }} 656 {{ 40 + ($i + 1) * $cellW - 8 }} 692" x="{{ $cx }}" y="684" font-family="JetBrains Mono" font-weight="700" font-size="{{ $valueSize }}" fill="#F7931A">{{ $value }}</text>
@endforeach
<rect x="{{ $urlX }}" y="626" width="1" height="66" fill="#2A2A30"/>
<text x="{{ $urlX + 16 }}" y="650" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">play</text>
<text data-unit="stat-url" data-box="{{ $urlX + 15 }} 664 {{ K::CONTENT_RIGHT }} 690" x="{{ $urlX + 16 }}" y="682" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">esports.einundzwanzig.space</text>
