{{--
    G3 · Terminal ticker · The call to join TWENTY ONE, the league's own TMNF server (TmnfSlides::JOIN), over the field
    of cars on Stadium (the official hero, the scrim baked into the still). Top left the game's mark, beside it the week
    and the headline. Left the steps of How to join, numbered because they are a sequence: get the game, add the
    server to the favourites (the favourite link in the game's teal while the server's login is known; else find it
    in the server list), restart and join, link the login, drive the track. Right the QR code of How to join on the
    game page, the address under it.

    Data contract (SceneSource, TmnfSlides::scene()):
      $tmnf       array{week: string, track: string, server: string, favourite: ?string, url: string, …}|null
                  null while TMNF is switched off: the slide invites to every game instead
      $siteQrSvg  string: the QR code of How to join (resources/stream/qr/tmnf.svg; '' leaves it out)
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string: the slide's still (TMNF on), else the brand's blurred cover
      $cover      ?string: TMNF's cover as a data URI, the game's mark
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tmnf ?? null) ? $tmnf : null;
    $server = K::clean(K::text($t ?? [], 'server', 'TWENTY ONE')) ?: 'TWENTY ONE';
    $week = K::fit(K::text($t ?? [], 'week', 'TMNF this week'), K::MONO, 22, 440);
    $head = K::fit('Join '.$server, K::DISPLAY, 44, 760);
    $favourite = K::clean(K::text($t ?? [], 'favourite'));
    $track = K::clean(K::text($t ?? [], 'track', 'the track of the week'));
    $steps = $favourite !== ''
        ? [
            ['Get TrackMania Nations Forever, free on Steam', '', false],
            ['Add '.$server.' to your favourites', 'Paste '.$favourite.' into the Explorer bar', true],
            ['Restart TMNF, then join from your favourites', 'Internet, Favourites, '.$server, false],
            ['Link your login: type the code from the site in the server chat', '', false],
            ['Drive '.$track.'. Your best time of the week counts.', '', false],
        ]
        : [
            ['Get TrackMania Nations Forever, free on Steam', '', false],
            ['Find '.$server.' in the server list', 'Internet, then search for '.$server, false],
            ['Link your login: type the code from the site in the server chat', '', false],
            ['Drive '.$track.'. Your best time of the week counts.', '', false],
        ];
    $rows = [];
    foreach ($steps as $i => [$title, $detail, $link]) {
        $top = 252 + $i * 70;
        // The steps over x 104..776, the QR code right of them (800..1016).
        $rows[] = ['n' => $i + 1, 'top' => $top, 'lines' => K::wrap($title, K::MONO, 20, 672, $detail === '' ? 2 : 1),
            'detail' => $detail === '' ? '' : K::fit($detail, K::MONO, 18, 672), 'link' => $link];
    }
    // The address under the QR code's label, right-aligned to x 1016, in the gap between steps 4 and 5.
    $url = K::fit(K::text($t ?? [], 'url', 'esports.einundzwanzig.space/scores/tmnf'), K::MONO, 16, 380);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
{{-- The still centred on the content area (x 0..1040); the stream's now-playing column covers the rest. --}}
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => $t ? 0 : 0.86, 'bdW' => 1040])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'trackmania'])

@if ($t)
@include('stream.rotation.partials.game-mark', ['gmCover' => $cover ?? null, 'gmX' => 40, 'gmY' => 104, 'gmW' => 192, 'gmEdge' => '#5EEAD4'])
<text data-unit="week" data-box="255 112 697 138" x="256" y="132" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $week }}</text>
<text data-unit="title" data-box="254 146 1016 198" x="256" y="188" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">{{ $head }}</text>
<text data-unit="claim" data-box="255 202 1016 224" x="256" y="220" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#C9C9CE">Our own TrackMania Nations Forever server</text>

@foreach ($rows as $r)
<rect x="40" y="{{ $r['top'] }}" width="44" height="44" fill="#5EEAD4"/>
<text x="62" y="{{ $r['top'] + 31 }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#0A0A0B" text-anchor="middle">{{ $r['n'] }}</text>
@foreach ($r['lines'] as $j => $line)
<text data-unit="step-{{ $r['n'] }}-{{ $j }}" data-box="103 {{ $r['top'] + 2 + $j * 26 }} 776 {{ $r['top'] + 24 + $j * 26 }}" x="104" y="{{ $r['top'] + 19 + $j * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($r['detail'] !== '')
<text data-unit="step-{{ $r['n'] }}-detail" data-box="103 {{ $r['top'] + 28 }} 776 {{ $r['top'] + 50 }}" x="104" y="{{ $r['top'] + 45 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $r['link'] ? '#5EEAD4' : '#C9C9CE' }}">{{ $r['detail'] }}</text>
@endif
@endforeach

@include('stream.rotation.partials.qr', ['x' => 800, 'y' => 252, 'size' => 216, 'qr' => $siteQrSvg ?? ''])
<text data-unit="qr-label" data-box="800 488 1017 512" x="1016" y="506" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF" text-anchor="end">How to join</text>
<text data-unit="qr-url" data-box="635 514 1017 532" x="1016" y="528" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#C9C9CE" text-anchor="end">{{ $url }}</text>
@else
<text data-unit="title" data-box="38 150 1017 210" x="40" y="200" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">Every game</text>
<text data-unit="claim" data-box="39 240 1017 270" x="40" y="262" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
