{{--
    E4 · Broadcast desk · sats to win: the open tournament with the biggest pot. Channel frame; the pot as the big
    number, the tournament, game and start, then what each place wins (PrizePool::projection(): after the fee
    reserve) as podium tiles, and the sponsors by name. Without a pot: sats can be put on the line.

    Data contract:
      $pride     array{prizes: array{name: string, game: string, mode: string, pot: int, places: list<array{place: int,
                 sats: int}>, sponsors: list<string>, startsAt: ?string, url: string}|null, …} (PrideSlides::all())
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $p = is_array($pride['prizes'] ?? null) ? $pride['prizes'] : null;
    if ($p) {
        $pot = K::sats((int) $p['pot']);
        $potSize = min(96, 456 / max(1, K::width($pot, K::DISPLAY, 1) * 1.04));
        $nameLines = K::wrap($p['name'] ?? '', K::DISPLAY, 30, 456, 2) ?: ['Tournament'];
        $below = 424 + (count($nameLines) - 1) * 38;
        $url = K::fit(preg_replace('#^https?://#', '', (string) ($p['url'] ?? '')), K::MONO, 22, 456);
        $line = K::fit(implode(' · ', array_filter([K::clean($p['game'] ?? ''), K::clean($p['mode'] ?? ''), K::clean($p['startsAt'] ?? '')])), K::MONO, 20, 456);
        $places = array_slice(array_values(array_filter($p['places'] ?? [], 'is_array')), 0, 3);
        // The tiles (x 528..1016): the place number in its 88 px, the sum right-aligned in the 352 px after it, one
        // size for all three so they read as a column.
        $placeSize = 52;
        foreach ([52, 44, 38, 32] as $placeSize) {
            $widest = 0;
            foreach ($places as $pl) {
                $widest = max($widest, K::width(K::sats((int) ($pl['sats'] ?? 0)).' sats', K::DISPLAY, $placeSize) * 1.04);
            }
            if ($widest <= 352) {
                break;
            }
        }
        $sponsors = [];
        foreach ($p['sponsors'] ?? [] as $s) {
            if (is_string($s) && K::clean($s) !== '') {
                $sponsors[] = K::clean($s);
            }
        }
        $sponsorLine = $sponsors === [] ? null : K::fit('Sponsored by '.implode(', ', $sponsors), K::MONO, 22, 456);
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.76])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'sats to win'])
@if ($p)
<text x="40" y="170" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F7931A">UP FOR GRABS</text>
<text data-unit="prize-pot" data-box="39 {{ 280 - $potSize }} 496 300" x="40" y="280" font-family="Unbounded" font-weight="800" font-size="{{ $potSize }}" fill="#F7931A">{{ $pot }}</text>
<text x="40" y="340" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">sats</text>
@foreach ($nameLines as $li => $nl)
<text data-unit="prize-name-{{ $li }}" data-box="39 {{ 394 + $li * 38 }} 496 {{ 432 + $li * 38 }}" x="40" y="{{ 424 + $li * 38 }}" font-family="Unbounded" font-weight="800" font-size="30" fill="#FFFFFF">{{ $nl }}</text>
@endforeach
@if ($line !== '')<text data-unit="prize-line" data-box="39 {{ $below + 20 }} 496 {{ $below + 46 }}" x="40" y="{{ $below + 42 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $line }}</text>@endif
@if ($sponsorLine)<text data-unit="prize-sponsors" data-box="39 {{ $below + 64 }} 496 {{ $below + 92 }}" x="40" y="{{ $below + 86 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $sponsorLine }}</text>@endif
<text data-unit="prize-url" x="40" y="{{ $below + 150 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $url }}</text>
@foreach ($places as $i => $pl)
@php($py = 150 + $i * 160)
<rect x="528" y="{{ $py }}" width="488" height="144" fill="{{ $i === 0 ? '#F7931A' : '#121215' }}" fill-opacity="{{ $i === 0 ? 1 : 0.94 }}"/>
<text x="552" y="{{ $py + 92 }}" font-family="Unbounded" font-weight="800" font-size="64" fill="{{ $i === 0 ? '#17120A' : ($i === 1 ? '#C9CDD6' : '#CD7F32') }}">{{ (int) $pl['place'] }}.</text>
<text data-unit="prize-place-{{ $i }}" data-box="639 {{ $py + 40 }} 1000 {{ $py + 104 }}" x="992" y="{{ $py + 90 }}" font-family="Unbounded" font-weight="800" font-size="{{ $placeSize }}" fill="{{ $i === 0 ? '#17120A' : '#FFFFFF' }}" text-anchor="end">{{ K::sats((int) $pl['sats']) }} sats</text>
@endforeach
@else
<text x="40" y="170" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#F7931A">SATS TO WIN</text>
<text x="40" y="270" font-family="Unbounded" font-weight="800" font-size="56" fill="#FFFFFF">No pot open right now.</text>
<text x="40" y="330" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#ADADB0">Any tournament can carry a prize pot in sats.</text>
@endif
</svg>
