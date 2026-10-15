{{--
    A1 · Arena · single match. Board on the dark left half, the two players as fighter cards on the orange right half:
    a big face (the side's king on its corner), the name, the clock; the side to move sits in a near-black block. The
    scene's backdrop lies full-bleed under both halves. The now-playing column covers x 1040..1280 (SceneRenderer::svg()).

    Data contract:
      $game      array{
                   white: array{name: string, clockMs: int, toMove: bool, avatar?: ?string, clanLogo?: ?string},
                   black: array{name: string, clockMs: int, toMove: bool, avatar?: ?string, clanLogo?: ?string},
                   fen: string, lastMove: array{from: string, to: string}|null,
                   mode: string,            e.g. "LIVE · CHESS BLITZ 5+3 · CASUAL"
                   result?: string|null,    after the game; replaces the mode line
                   moves?: list<string>,    unused here
                 }
                 avatar: an inline data URI (RotationKit::avatarUri), anything else draws a neutral face;
                 clanLogo?: the player's clan logo (data URI), a badge on the face when present
      $backdrop  ?string, optional: the scene's blurred backdrop (RotationKit::backdropUri)
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    // The content is 1040 px wide (the now-playing column covers the rest): the board on the dark left (x 0..576), the
    // fighter cards on the orange right (x 576..1040), their text within x 600..1016.
    $split = 576;
    $cardX = $split + 24;
    $cardW = K::CONTENT_RIGHT - $cardX;
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 40, 112, 62);
    $blocks = [];
    $y = 160;
    foreach ([['black', 'bk', 'Black player'], ['white', 'wk', 'White player']] as [$side, $king, $fallback]) {
        $p = $game[$side] ?? [];
        $active = (bool) ($p['toMove'] ?? false);
        // Fighter card: the name across the card's width on top, the face and the clock below; the side to move gets
        // the bigger face and clock.
        $h = $active ? 212 : 168;
        $d = $active ? 120 : 84;
        $pad = $active ? 24 : 12;
        $nx = $cardX + $pad;
        $nameMax = K::CONTENT_RIGHT - $pad - $nx;
        // One line, the largest size at which the whole name fits; a name too long even at 18 px is cut with "…".
        $sizes = $active ? [32, 28, 24, 20, 18] : [28, 24, 20, 18];
        $size = end($sizes);
        $clean = K::clean($p['name'] ?? '');
        foreach ($sizes as $try) {
            if ($clean !== '' && K::width($clean, K::nameFont($clean), $try) * 1.04 <= $nameMax) {
                $size = $try;
                break;
            }
        }
        $name = K::name($p['name'] ?? '', $fallback, $size, $nameMax);
        $nameY = round($y + $pad + $size * 0.8, 1);
        $faceY = $y + $pad + 44;
        $cx = $nx + $d + 24;
        $blocks[] = [
            'y' => $y, 'h' => $h, 'd' => $d, 'faceY' => $faceY, 'active' => $active, 'low' => (int) ($p['clockMs'] ?? 0) < 10000,
            'king' => $king, 'avatar' => $p['avatar'] ?? null, 'clanLogo' => $p['clanLogo'] ?? null, 'nx' => $nx,
            'name' => $name, 'nameSize' => $size, 'nameY' => $nameY,
            'clock' => K::clock((int) ($p['clockMs'] ?? 0), $cx, $active ? 64 : 44, K::CONTENT_RIGHT - $pad - $cx),
            'clockY' => round($faceY + $d / 2 + ($active ? 24 : 17), 1),
        ];
        $y += $h + 16;
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
<rect x="{{ $split }}" y="0" width="{{ K::CONTENT_WIDTH - $split }}" height="720" fill="#F7931A"/>
@include('stream.rotation.partials.board', ['b' => $b])

<text data-unit="mode" data-box="{{ $cardX - 1 }} 112 {{ K::CONTENT_RIGHT }} 144" x="{{ $cardX }}" y="136" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize(K::modeLabel($game), 22, $cardW) }}" fill="#17120A">{{ K::fit(K::modeLabel($game), K::MONO, K::monoSize(K::modeLabel($game), 22, $cardW), $cardW) }}</text>

@foreach ($blocks as $i => $blk)
@if ($blk['active'])<rect x="{{ $cardX }}" y="{{ $blk['y'] }}" width="{{ $cardW }}" height="{{ $blk['h'] }}" fill="#17120A"/>@endif
@include('stream.rotation.partials.face', ['face' => ['uri' => $blk['avatar'], 'tag' => null], 'x' => $blk['nx'], 'y' => $blk['faceY'], 'd' => $blk['d'], 'id' => 'p'.$i,
    'fUnit' => 'face-'.$i, 'fRing' => $blk['active'] ? '#F7931A' : '#17120A', 'fKing' => $blk['king'], 'fKingRim' => '#17120A', 'fClan' => $blk['clanLogo']])
<text data-unit="name-{{ $i }}" data-box="{{ $blk['nx'] - 1 }} {{ $blk['y'] }} {{ K::CONTENT_RIGHT }} {{ $blk['faceY'] - 4 }}" x="{{ $blk['nx'] }}" y="{{ $blk['nameY'] }}" font-family="{{ $blk['name']['font'] }}" font-weight="800" font-size="{{ $blk['nameSize'] }}" fill="{{ $blk['active'] ? '#FFFFFF' : '#17120A' }}">{{ $blk['name']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $blk['clock'], 'y' => $blk['clockY'], 'fill' => $blk['active'] ? ($blk['low'] ? '#F87171' : '#F7931A') : '#17120A'])
@endforeach

<use href="#mark-dark" xlink:href="#mark-dark" x="{{ $cardX }}" y="632" width="40" height="40"/>
<text x="{{ $cardX + 56 }}" y="659" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">esports.einundzwanzig.space</text>
</svg>
