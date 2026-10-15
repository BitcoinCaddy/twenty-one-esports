{{--
    B1 · Broadcast desk · single match. Channel frame (bug + stats ticker) over the scene's backdrop, board left, two
    player strips on the right, each led by the player's face with the side's king on its corner (the side to move in
    orange, red below 10 s), the last moves, a call to action.

    Data contract:
      $game   Game as in a1-match; optional moves: list<string> (the last up to 10 SAN moves, oldest first; numbered
              from the FEN's move number) — without it the move block is left out
      $stats     array for the ticker: players?, clans?, gamesPlayed?, gamesToday? (missing ones are left out)
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    // The content is 1040 px wide (the now-playing column covers x 1040..1280): the board x 40..504, the strips
    // x 536..1016 with the name on top across the strip and the clock under it.
    $sx = 536;
    $sw = K::CONTENT_RIGHT - $sx;
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 40, 104, 58);
    $strips = [];
    foreach ([['black', 'bk', 'Black player', 156], ['white', 'wk', 'White player', 292]] as [$side, $king, $fallback, $y]) {
        $p = $game[$side] ?? [];
        $ms = (int) ($p['clockMs'] ?? 0);
        $active = (bool) ($p['toMove'] ?? false);
        $clock = K::clock($ms, $sx + 120, 44, 200);
        $strips[] = [
            'y' => $y, 'king' => $king, 'fill' => $active ? ($ms < 10000 ? '#F87171' : '#F7931A') : '#121215',
            'ink' => $active ? '#17120A' : '#FFFFFF', 'clockInk' => $active ? '#17120A' : ($ms < 10000 ? '#F87171' : '#FFFFFF'),
            'name' => K::headline(K::clean($p['name'] ?? '') === '' ? $fallback : $p['name'], [26, 22, 20, 18], K::CONTENT_RIGHT - 16 - ($sx + 120), 1), 'clock' => $clock,
            'avatar' => $p['avatar'] ?? null, 'clanLogo' => $p['clanLogo'] ?? null, 'active' => $active,
        ];
    }
    $rows = K::moveRows($game['moves'] ?? null, $game['fen'] ?? null);
    $cells = [];
    foreach ($rows as $r) {
        $cells[] = ['text' => $r[0].'. '.($r[1] ?? '...').($r[2] !== null ? ' '.$r[2] : '')];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])
@include('stream.rotation.partials.board', ['b' => $b])

<text data-unit="mode" data-box="{{ $sx - 1 }} 124 {{ K::CONTENT_RIGHT }} 146" x="{{ $sx }}" y="140" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ K::fit(K::modeLabel($game), K::MONO, 18, $sw) }}</text>
@foreach ($strips as $i => $s)
<rect x="{{ $sx }}" y="{{ $s['y'] }}" width="{{ $sw }}" height="120" fill="{{ $s['fill'] }}"/>
@include('stream.rotation.partials.face', ['face' => ['uri' => $s['avatar'], 'tag' => null], 'x' => $sx + 16, 'y' => $s['y'] + 16, 'd' => 88, 'id' => 'p'.$i,
    'fUnit' => 'face-'.$i, 'fRing' => $s['active'] ? '#17120A' : '#F7931A', 'fKing' => $s['king'], 'fKingRim' => $s['active'] ? '#17120A' : '#121215', 'fClan' => $s['clanLogo']])
@foreach ($s['name']['lines'] as $li => $line)
<text data-unit="name-{{ $i }}-{{ $li }}" data-box="{{ $sx + 119 }} {{ $s['y'] + 8 }} {{ K::CONTENT_RIGHT - 15 }} {{ $s['y'] + 52 }}" x="{{ $sx + 120 }}" y="{{ $s['y'] + 46 }}" font-family="{{ $s['name']['font'] }}" font-weight="800" font-size="{{ $s['name']['size'] }}" fill="{{ $s['ink'] }}">{{ $line }}</text>
@endforeach
@include('stream.rotation.partials.clock', ['c' => $s['clock'], 'y' => $s['y'] + 100, 'fill' => $s['clockInk']])
@endforeach

@if ($cells !== [])
@foreach ($cells as $i => $cell)
<text data-unit="move-{{ $i }}" data-box="{{ $sx - 1 + ($i % 3) * 160 }} {{ 444 + intdiv($i, 3) * 30 }} {{ $sx - 4 + ($i % 3 + 1) * 160 }} {{ 468 + intdiv($i, 3) * 30 }}" x="{{ $sx + ($i % 3) * 160 }}" y="{{ 462 + intdiv($i, 3) * 30 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $loop->last ? '#F7931A' : '#FFFFFF' }}">{{ K::fit($cell['text'], K::MONO, 18, 152) }}</text>
@endforeach
@endif

<text x="{{ $sx }}" y="628" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">Your game could be next.</text>

</svg>
