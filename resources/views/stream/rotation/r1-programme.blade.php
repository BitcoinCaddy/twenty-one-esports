{{--
    R1 · The radio programme of the next hours (plan "TWENTY ONE Radio", P19). Left the station's hour clock: the clock
    hour on air as a ring (the full hour at the top, as a station plans its hour; the part played darker), an arc per
    show of this hour in its family's colour (dimmed where it has played), the hand at now, the time in the middle.
    Right what is on air now (big), then the next shows: the time with its group under it ("Gleich", then "Heute
    noch"; at night "Danach", the loop's episodes sorted in after the one on air), the family glyph, the title, the
    tagline's first sentence and the countdown. At the bottom the news that always runs and the ready material between
    the slots. Only what
    the spool holds ready (ProgrammeSlides): no planned slot, no "in Vorbereitung". Nothing right of x 1016:
    the stream's now-playing column owns x 1040 on.

    Data contract (ProgrammeSlides::scene()):
      $programme  array{onAir: ?Item, upcoming: list<Item>, loop: list<Item>, stock: list<array{family, label, seconds}>,
                    newsLine: ?string, night: bool, voice: bool, now: int}
                  Item array{key, family, title, tagline, at: ?int, seconds: float, ready: bool, format: ?string,
                    part: ?string, left: ?int}
      $backdrop   ?string, the brand cover
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\ProgrammeSlides', 'PS')
@php
    $p = is_array($programme ?? null) ? $programme : ['onAir' => null, 'upcoming' => [], 'loop' => [], 'stock' => [], 'newsLine' => null, 'night' => false, 'now' => time()];
    $now = (int) $p['now'];
    // The clock left (centre 200, 340; its labels clear the 32 px safe margin), the programme right of it (x 376..1016): the stream's now-playing column owns
    // x 1040 on.
    $dial = PS::dial($p, 200, 340, 112);
    $air = $p['onAir'];
    $airFamily = $air['family'] ?? PS::MUSIC;
    $airRight = $air === null ? '' : PS::remaining((int) ($air['left'] ?? 0));
    $airTitle = K::headline($air === null ? 'Musik' : $air['title'], [34, 30, 26, 22], 1016 - 440, 1);
    $airSub = $air === null ? 'Bis zur nächsten Sendung läuft Musik.' : (($air['part'] ?? null) !== null ? $air['part'].'. '.PS::rest($air['tagline']) : $air['tagline']);
    $airSub = K::fit($airSub, K::MONO, 18, 1016 - 440);
    $rows = PS::rows($p, 4);
    // The ready material between the slots, as chips until the line is full.
    $chips = [];
    $chipX = 40 + K::width('Zwischendurch', K::MONO, 18) + 24;
    foreach ($p['stock'] as $chip) {
        $chipText = $chip['label'].' '.PS::length((float) $chip['seconds']);
        $chipW = K::width($chipText, K::MONO, 18) + 32;
        if ($chipX + $chipW > 1016) {
            break;
        }
        $chips[] = ['x' => $chipX, 'w' => $chipW, 'text' => $chipText, 'colour' => PS::COLOURS[$chip['family']] ?? '#FFFFFF'];
        $chipX += $chipW + 16;
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.86])
@include('stream.rotation.partials.r-chrome', ['section' => $p['night'] ? 'Die Nachtschleife' : 'Das Programm', 'voice' => $p['voice'] ?? null])
{{-- The hour clock. --}}
<g data-unit="dial" data-box="48 136 352 544">
<circle cx="200" cy="340" r="112" fill="none" stroke="#26262C" stroke-width="28"/>
@if ($dial['played'] !== null)<path data-unit="dial-played" d="{{ $dial['played'] }}" fill="none" stroke="#141417" stroke-width="28"/>@endif
@foreach ($dial['arcs'] as $arc)
<path data-unit="dial-arc" d="{{ $arc['d'] }}" fill="none" stroke="{{ $arc['colour'] }}" stroke-width="28" stroke-opacity="{{ $arc['past'] ? '0.32' : '1' }}"/>
@endforeach
@foreach ($dial['ticks'] as $tick)
<line x1="{{ $tick['x1'] }}" y1="{{ $tick['y1'] }}" x2="{{ $tick['x2'] }}" y2="{{ $tick['y2'] }}" stroke="#8B8B90" stroke-width="2"/>
<text x="{{ $tick['lx'] }}" y="{{ $tick['ly'] }}" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#8B8B90">{{ $tick['label'] }}</text>
@endforeach
<line data-unit="dial-hand" x1="{{ $dial['hand']['x1'] }}" y1="{{ $dial['hand']['y1'] }}" x2="{{ $dial['hand']['x2'] }}" y2="{{ $dial['hand']['y2'] }}" stroke="#FFFFFF" stroke-width="5"/>
<text data-unit="dial-time" x="200" y="352" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">{{ PS::time($now) }}</text>
<text x="200" y="384" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Sendeuhr</text>
</g>
{{-- On air now. --}}
<g data-unit="on-air" data-box="376 104 1016 216">
<text x="440" y="128" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Jetzt auf Sendung</text>
@if ($airRight !== '')<text data-unit="on-air-left" x="1016" y="128" text-anchor="end" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ PS::COLOURS[$airFamily] }}">{{ $airRight }}</text>@endif
@include('stream.rotation.partials.r-glyph', ['family' => $airFamily, 'gx' => 376, 'gy' => 138, 'gs' => 48])
<text data-unit="on-air-title" x="440" y="170" font-family="{{ $airTitle['font'] }}" font-weight="{{ $airTitle['font'] === 'Unbounded' ? 800 : 700 }}" font-size="{{ $airTitle['size'] }}" fill="#FFFFFF">{{ $airTitle['lines'][0] ?? '' }}</text>
<text data-unit="on-air-tagline" x="440" y="204" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $airSub }}</text>
</g>
<rect x="376" y="224" width="640" height="2" fill="#2A2A30"/>
{{-- What comes next: the time with its group under it, the glyph, the title, the tagline and the countdown. --}}
@foreach ($rows as $i => $row)
@php
    $y = 240 + $i * 80;
    $item = $row['item'];
    $rightW = K::width($row['right'], K::MONO, 18);
    $title = K::headline($item['title'], [26, 22, 20], 1016 - 552, 1);
@endphp
<g data-unit="row-{{ $i }}" data-box="376 {{ $y }} 1016 {{ $y + 72 }}">
@if ($row['when'] !== '')<text data-unit="row-time" x="376" y="{{ $y + 32 }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#FFFFFF">{{ $row['when'] }}</text>@endif
@if ($row['label'] !== '')<text data-unit="row-label" x="376" y="{{ $y + 60 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $row['label'] }}</text>@endif
@include('stream.rotation.partials.r-glyph', ['family' => $item['family'], 'gx' => 504, 'gy' => $y + 8, 'gs' => 32])
<text data-unit="row-title" x="552" y="{{ $y + 32 }}" font-family="{{ $title['font'] }}" font-weight="{{ $title['font'] === 'Unbounded' ? 800 : 700 }}" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $title['lines'][0] ?? '' }}</text>
<text data-unit="row-tagline" x="552" y="{{ $y + 60 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#8B8B90">{{ K::fit(PS::short($item['tagline']), K::MONO, 18, 1016 - 552 - $rightW - 24) }}</text>
<text data-unit="row-right" x="1016" y="{{ $y + 60 }}" text-anchor="end" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ PS::COLOURS[$item['family']] ?? '#FFFFFF' }}">{{ $row['right'] }}</text>
</g>
@endforeach
{{-- The news that always runs, and the ready material between the slots. --}}
<rect x="40" y="584" width="976" height="2" fill="#2A2A30"/>
@if (($p['newsLine'] ?? null) !== null)
<text data-unit="news-line" x="40" y="622" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit($p['newsLine'], K::MONO, 20, 976) }}</text>
@endif
@if ($chips !== [])
<text x="40" y="674" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Zwischendurch</text>
@foreach ($chips as $chip)
<rect x="{{ $chip['x'] }}" y="648" width="{{ $chip['w'] }}" height="38" rx="4" fill="#121215" stroke="{{ $chip['colour'] }}" stroke-width="2"/>
<text data-unit="stock-chip" x="{{ $chip['x'] + 16 }}" y="674" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $chip['text'] }}</text>
@endforeach
@endif
</svg>
