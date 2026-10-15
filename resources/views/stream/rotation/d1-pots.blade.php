{{--
    D1 · Broadcast desk · prize pots. Channel frame over the brand backdrop; on the left the sum of every open
    tournament's pot as the big number, then how a pot works; on the right the pots themselves, biggest first (at most
    three: tournament, game and start, sats). Without a pot the left says "Put sats on the line." and the right stays
    empty.

    Data contract:
      $upcoming  list<array>: TournamentSlides::frames() of every upcoming tournament; read are name, game,
                 startsAt and pot (int sats or null, PrizePool::shownPotSats: only a real pot)
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $pots = K::pots($upcoming ?? [], 3);
    $total = $pots['total'] > 0 ? K::sats($pots['total']) : null;
    // The left column x 40..520, the pots' panel x 560..1016 (the now-playing column covers x 1040..1280).
    $totalSize = $total === null ? 0 : min(104, 470 / max(1, K::width($total, K::DISPLAY, 1)));
    $inLine = $pots['count'] === 1 ? 'in an open prize pot' : 'in '.$pots['count'].' open prize pots';
    // COPY-CHECK: pots take zaps and sponsor invoices on the tournament page, sit in the tournament's own wallet and
    // pay each prize exactly once (2f393b9, 0ac1a9e: "Add tournament prize pots with zaps, own NWC wallets and
    // exactly-once payouts").
    $facts = ['Zap a pot on its tournament page.', 'Every pot sits in its own wallet.', 'Each prize is paid out exactly once.'];
    $rows = [];
    foreach ($pots['rows'] as $i => $row) {
        // One line when the whole name fits there at 20 px or more, else two lines at 26 px.
        $one = K::headline($row['name'], [26, 22, 20], 392, 1, K::DISPLAY);
        $whole = implode(' ', $one['lines']) === K::clean($row['name']);
        $lines = $whole ? $one['lines'] : K::wrap($row['name'], K::DISPLAY, 26, 392, 2);
        $nameSize = $whole ? $one['size'] : 26;
        $rows[] = [
            'y' => 112 + $i * 176,
            'name' => $lines === [] ? ['Tournament'] : $lines, 'nameSize' => $nameSize,
            'line' => K::fit(implode(' · ', array_filter([$row['line'], $row['starts'] !== '' ? 'starts '.$row['starts'] : null])), K::MONO, 18, 392),
            'sats' => K::sats($row['sats']).' sats',
        ];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.74])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'prize pots'])

@if ($total !== null)
<text x="40" y="170" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">PLAY FOR SATS</text>
<text data-unit="pot-total" data-box="39 {{ 290 - $totalSize }} 520 310" x="40" y="290" font-family="Unbounded" font-weight="800" font-size="{{ $totalSize }}" fill="#F7931A">{{ $total }}</text>
<text data-unit="pot-unit" x="40" y="352" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">sats</text>
<text data-unit="pot-in" x="{{ 40 + K::width('sats', K::DISPLAY, 40) + 20 }}" y="350" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ $inLine }}</text>
@else
<text x="40" y="170" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">PRIZE POTS</text>
<text x="40" y="260" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Put sats</text>
<text x="40" y="324" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">on the line.</text>
@endif
@foreach ($facts as $i => $fact)
<rect x="40" y="{{ 424 + $i * 64 }}" width="10" height="10" fill="#F7931A"/>
<text data-unit="fact-{{ $i }}" data-box="67 {{ 414 + $i * 64 }} 520 {{ 442 + $i * 64 }}" x="68" y="{{ 436 + $i * 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $fact }}</text>
@endforeach

@if ($rows !== [])
<rect x="560" y="112" width="456" height="{{ 16 + count($rows) * 176 }}" fill="#121215" fill-opacity="0.92"/>
@foreach ($rows as $i => $r)
@if ($i > 0)<rect x="592" y="{{ $r['y'] + 8 }}" width="392" height="1" fill="#2A2A30"/>@endif
@foreach ($r['name'] as $li => $nameLine)
<text data-unit="pot-name-{{ $i }}-{{ $li }}" data-box="591 {{ $r['y'] + 26 + $li * 34 }} 986 {{ $r['y'] + 60 + $li * 34 }}" x="592" y="{{ $r['y'] + 54 + $li * 34 }}" font-family="Unbounded" font-weight="800" font-size="{{ $r['nameSize'] }}" fill="#FFFFFF">{{ $nameLine }}</text>

@endforeach
@php($below = $r['y'] + 54 + (count($r['name']) - 1) * 34)
@if ($r['line'] !== '')<text data-unit="pot-line-{{ $i }}" data-box="591 {{ $below + 14 }} 986 {{ $below + 38 }}" x="592" y="{{ $below + 32 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $r['line'] }}</text>@endif
<text data-unit="pot-sats-{{ $i }}" x="592"
 y="{{ $below + 80 }}" font-family="Unbounded" font-weight="800" font-size="34" fill="#F7931A">{{ $r['sats'] }}</text>
@endforeach
@endif
</svg>
