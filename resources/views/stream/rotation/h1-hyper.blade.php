{{--
    H1 · Hyperbitcoinization live (plan "Hyperbitcoinization", P6). A running match: the world map with who owns what
    (territories in their owner's faction colour, the central banks as gold pins) on the left, the chronicle of the
    latest battles under it; on the right the tension card (on the generated header plate, with the clash icon) and one
    card per faction: its portrait, the player's face, name, banks and territories, who moves, who leads, who
    is out. Without the match (it just ended, or the game is off): the plate, the title and one line. Everything
    stays left of x 1016 (the map at 3/4): the stream's now-playing column owns x 1040 on.

    Data contract (HyperScene::data()):
      $hyper     array{id: int, over: bool, round: int, limit: int, mode: string, phase: string,
                   seats: list<array{seat: int, name: string, faction: string, color: string, portrait: ?string,
                     avatar: ?string, territories: int, banks: int, units: int, out: bool, bot: bool, toMove: bool,
                     leader: bool}>,
                   owners: array<string, int|null> (territory id => seat), colors: array<int, string> (seat => colour),
                   chronicle: list<array{key: string, seat: int, text: string, kind: string}> (newest first),
                   tension: array{tense: bool, reason: ?string, caption: string, ...}}|null
      $map       array{width: int, height: int, neutral: string, territories: array<string, array{d: string, cx: float,
                   cy: float, bank: bool, name: string}>} (resources/stream/hyper/map.json, 832 x 368)
      $art       array{plate: ?string, ribbon: ?string, clash: ?string} data URIs (resources/stream/hyper)
      $stats     array (unused here)
      $backdrop  ?string, the game's blurred cover, under the plate when the plate is missing
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $live = is_array($hyper ?? null) ? $hyper : null;
    $mapData = is_array($map ?? null) ? $map : ['width' => 832, 'height' => 368, 'neutral' => '', 'territories' => []];
    $plate = K::coverUri($art['plate'] ?? null);
    $ribbon = K::coverUri($art['ribbon'] ?? null);
    $clash = K::coverUri($art['clash'] ?? null);
    // The map at 3/4 on the left (x 24..648), the tension card and one card per faction right of it (x 672..1016);
    // the stream's now-playing column owns x 1040 on.
    $mx = 24;
    $my = 100;
    $ms = 0.75;
    $mw = round($mapData['width'] * $ms);
    $mh = round($mapData['height'] * $ms);
    $cx = 672;
    $cr = 1016;
    $cw = $cr - $cx;
    $cards = [];
    $rows = [];
    if ($live) {
        $seatsShown = array_slice($live['seats'] ?? [], 0, 6);
        $n = max(1, count($seatsShown));
        $cardH = $n <= 4 ? 84 : 66;
        $gap = 8;
        $y = 240;
        foreach ($seatsShown as $i => $s) {
            $d = $cardH - 16;
            $nameX = $cx + 12 + 6 + $d + 14;
            $cards[] = [
                'y' => $y, 'h' => $cardH, 'd' => $d, 'nameX' => $nameX,
                'color' => K::colour($s['color'] ?? null, '#A1A1AA'),
                'portrait' => K::coverUri($s['portrait'] ?? null),
                'avatar' => $s['avatar'] ?? null,
                // The name ends before the LEAD / OUT chip (60 px from the card's right edge).
                'name' => K::fit(K::clean($s['name'] ?? ''), K::DISPLAY, $cardH > 70 ? 19 : 17, $cr - 68 - $nameX),
                'nameSize' => $cardH > 70 ? 19 : 17,
                // The faction is the portrait and the colour; the line counts what the faction holds.
                'line' => K::fit(($s['banks'] ?? 0).' '.(($s['banks'] ?? 0) === 1 ? 'bank' : 'banks').' · '.($s['territories'] ?? 0).' '.(($s['territories'] ?? 0) === 1 ? 'territory' : 'territories'), K::MONO, 15, $cr - 12 - $nameX),
                'out' => (bool) ($s['out'] ?? false),
                'toMove' => (bool) ($s['toMove'] ?? false),
                'leader' => (bool) ($s['leader'] ?? false),
            ];
            $y += $cardH + $gap;
        }
        foreach (array_slice($live['chronicle'] ?? [], 0, 4) as $i => $row) {
            $rows[] = ['y' => 448 + $i * 44, 'color' => K::colour($live['colors'][$row['seat'] ?? -1] ?? null, '#A1A1AA'), 'text' => K::fit(K::clean($row['text'] ?? ''), K::MONO, 19, $mw - 32), 'kind' => $row['kind'] ?? 'conquest'];
        }
        $tension = $live['tension'] ?? [];
        $caption = K::wrap($tension['caption'] ?? '', K::MONO, 17, $cw - 112, 2);
        $phase = ['buy' => 'recruiting', 'attack' => 'attacking', 'fortify' => 'fortifying'][$live['phase'] ?? ''] ?? '';
        $sub = K::fit(($live['mode'] ?? 'Live').' · Round '.($live['round'] ?? 1).(($live['limit'] ?? 0) > 0 ? ' of '.$live['limit'] : '').($live['over'] ?? false ? ' · match over' : ($phase !== '' ? ' · '.$phase : '')), K::MONO, 18, 640);
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#070B16"/>
@if ($plate)
<image data-unit="plate" image-rendering="optimizeSpeed" x="0" y="0" width="1040" height="720" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $plate }}"/>
<rect width="1280" height="720" fill="#070B16" fill-opacity="{{ $live ? 0.62 : 0.5 }}"/>
@else
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.72, 'bdW' => 1040])
@endif
<text data-unit="title" data-box="31 22 700 60" x="32" y="54" font-family="Unbounded" font-weight="800" font-size="30" fill="#F7931A">HYPERBITCOINIZATION</text>
@if ($live)
<text data-unit="sub" data-box="31 66 680 88" x="32" y="84" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $sub }}</text>
{{-- The world map: neutral land, then every territory in its owner's colour, then the central banks. --}}
<g data-unit="map" data-box="{{ $mx }} {{ $my }} {{ $mx + $mw }} {{ $my + $mh }}">
<rect x="{{ $mx - 4 }}" y="{{ $my - 4 }}" width="{{ $mw + 8 }}" height="{{ $mh + 8 }}" fill="#0B1428" fill-opacity="0.82" stroke="#F7931A" stroke-opacity="0.55" stroke-width="1.5"/>
<g transform="translate({{ $mx }} {{ $my }}) scale({{ $ms }})">
@if (($mapData['neutral'] ?? '') !== '')<path d="{{ $mapData['neutral'] }}" fill="#1E2638"/>@endif
@foreach ($mapData['territories'] as $tid => $terr)
@php($owner = $live['owners'][$tid] ?? null)
<path data-territory="{{ $tid }}" data-owner="{{ $owner ?? '' }}" d="{{ $terr['d'] }}" fill="{{ $owner === null ? '#3A4258' : K::colour($live['colors'][$owner] ?? null, '#A1A1AA') }}" fill-opacity="{{ $owner === null ? 0.9 : 0.86 }}" stroke="#070B16" stroke-width="0.7"/>
@endforeach
@foreach ($mapData['territories'] as $tid => $terr)
@if ($terr['bank'] ?? false)
@php($owner = $live['owners'][$tid] ?? null)
<circle data-bank="{{ $tid }}" cx="{{ $terr['cx'] }}" cy="{{ $terr['cy'] }}" r="{{ round(6 / $ms, 1) }}" fill="#FFD27A" stroke="{{ $owner === null ? '#070B16' : '#FFFFFF' }}" stroke-width="2"/>
@endif
@endforeach
</g>
</g>
{{-- The chronicle of the latest battles, newest first. --}}
<text data-unit="chronicle-head" data-box="23 394 400 414" x="24" y="410" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">LATEST BATTLES</text>
@forelse ($rows as $i => $row)
<rect x="24" y="{{ $row['y'] - 24 }}" width="{{ $mw }}" height="36" fill="#0B1428" fill-opacity="{{ $i === 0 ? 0.92 : 0.7 }}"/>
<rect x="24" y="{{ $row['y'] - 24 }}" width="6" height="36" fill="{{ $row['color'] }}"/>
<text data-unit="battle-{{ $i }}" data-box="43 {{ $row['y'] - 20 }} {{ $mw + 13 }} {{ $row['y'] + 6 }}" x="44" y="{{ $row['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="19" fill="{{ $row['kind'] === 'bank' || $row['kind'] === 'win' ? '#FFD27A' : '#FFFFFF' }}">{{ $row['text'] }}</text>
@empty
<text data-unit="battle-none" data-box="23 430 {{ $mx + $mw }} 454" x="24" y="448" font-family="JetBrains Mono" font-weight="700" font-size="19" fill="#A1A1AA">{{ K::fit('No battle yet. The armies are still being raised.', K::MONO, 19, $mw) }}</text>
@endforelse
{{-- Tension: the generated header plate, the clash icon, how tense and why. --}}
<g data-unit="tension" data-box="{{ $cx }} 124 {{ $cr }} 226">
@if ($ribbon)<image x="{{ $cx }}" y="124" width="{{ $cw }}" height="101" preserveAspectRatio="none" xlink:href="{{ $ribbon }}"/>@else<rect x="{{ $cx + 1 }}" y="125" width="{{ $cw - 2 }}" height="99" rx="8" fill="#151B2C" stroke="#F7931A" stroke-width="1.5"/>@endif
@if ($clash)<image x="{{ $cx + 16 }}" y="140" width="68" height="68" xlink:href="{{ $clash }}"/>@endif
<text x="{{ $cx + 96 }}" y="157" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="{{ ($tension['tense'] ?? false) ? '#FF6B4A' : '#F7931A' }}">{{ ($tension['tense'] ?? false) ? 'TENSION: HIGH' : 'TENSION: STEADY' }}</text>
@foreach ($caption as $i => $line)
<text data-unit="caption-{{ $i }}" data-box="{{ $cx + 95 }} {{ 166 + $i * 22 }} {{ $cr - 16 }} {{ 188 + $i * 22 }}" x="{{ $cx + 96 }}" y="{{ 182 + $i * 22 }}" font-family="JetBrains Mono" font-weight="700" font-size="17" fill="#FFFFFF">{{ $line }}</text>
@endforeach
</g>
{{-- One card per faction. --}}
@foreach ($cards as $i => $c)
<g data-unit="seat-{{ $i }}" data-box="{{ $cx }} {{ $c['y'] }} {{ $cr }} {{ $c['y'] + $c['h'] }}" @if ($c['out']) opacity="0.45" @endif>
<rect x="{{ $cx + 1.5 }}" y="{{ $c['y'] + 1.5 }}" width="{{ $cw - 3 }}" height="{{ $c['h'] - 3 }}" rx="6" fill="#0B1428" fill-opacity="0.9" stroke="{{ $c['toMove'] ? '#F7931A' : '#1F2A44' }}" stroke-width="{{ $c['toMove'] ? 2.5 : 1 }}"/>
<rect x="{{ $cx }}" y="{{ $c['y'] }}" width="6" height="{{ $c['h'] }}" fill="{{ $c['color'] }}"/>
<clipPath id="hp-{{ $i }}"><rect x="{{ $cx + 18 }}" y="{{ $c['y'] + 8 }}" width="{{ $c['d'] }}" height="{{ $c['d'] }}" rx="6"/></clipPath>
@if ($c['portrait'])<image x="{{ $cx + 18 }}" y="{{ $c['y'] + 8 }}" width="{{ $c['d'] }}" height="{{ $c['d'] }}" preserveAspectRatio="xMidYMid slice" clip-path="url(#hp-{{ $i }})" xlink:href="{{ $c['portrait'] }}"/>@else<rect x="{{ $cx + 18 }}" y="{{ $c['y'] + 8 }}" width="{{ $c['d'] }}" height="{{ $c['d'] }}" rx="6" fill="{{ $c['color'] }}"/>@endif
@if ($c['avatar'])
@include('stream.rotation.partials.face', ['face' => ['uri' => $c['avatar'], 'tag' => null], 'x' => $cx + 18 + $c['d'] - 22, 'y' => $c['y'] + $c['d'] - 14, 'd' => 28, 'id' => 'hs'.$i, 'fUnit' => 'seat-face-'.$i, 'fRing' => $c['color']])
@endif
<text data-unit="seat-name-{{ $i }}" x="{{ $c['nameX'] }}" y="{{ $c['y'] + ($c['h'] > 70 ? 36 : 30) }}" font-family="Unbounded" font-weight="800" font-size="{{ $c['nameSize'] }}" fill="#FFFFFF">{{ $c['name'] }}</text>
<text data-unit="seat-line-{{ $i }}" x="{{ $c['nameX'] }}" y="{{ $c['y'] + ($c['h'] > 70 ? 62 : 52) }}" font-family="JetBrains Mono" font-weight="700" font-size="15" fill="{{ $c['color'] }}">{{ $c['line'] }}</text>
@if ($c['leader'] && ! $c['out'])<rect x="{{ $cr - 60 }}" y="{{ $c['y'] + 6 }}" width="54" height="20" rx="4" fill="#F7931A"/><text x="{{ $cr - 33 }}" y="{{ $c['y'] + 21 }}" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="#17120A">LEAD</text>@endif
@if ($c['out'])<rect x="{{ $cr - 60 }}" y="{{ $c['y'] + 6 }}" width="54" height="20" rx="4" fill="#3A4258"/><text x="{{ $cr - 33 }}" y="{{ $c['y'] + 21 }}" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="#FFFFFF">OUT</text>@endif
@if ($c['toMove'] && ! $c['out'])<polygon data-unit="to-move" points="{{ $cr - 12 }},{{ $c['y'] + $c['h'] - 22 }} {{ $cr - 12 }},{{ $c['y'] + $c['h'] - 8 }} {{ $cr - 22 }},{{ $c['y'] + $c['h'] - 15 }}" fill="#F7931A"/>@endif
</g>
@endforeach
@else
<text data-unit="none" data-box="31 300 1016 340" x="32" y="330" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">No match on right now.</text>
<text data-unit="none-sub" data-box="31 356 1016 380" x="32" y="374" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ K::fit('Risk with currency spaces: Bitcoiners against the central banks.', K::MONO, 20, 976) }}</text>
@endif
<text data-unit="url" data-box="639 690 1016 712" x="1016" y="706" text-anchor="end" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">esports.einundzwanzig.space</text>
</svg>
