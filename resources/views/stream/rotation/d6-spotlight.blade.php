{{--
    D6 · Terminal ticker · spotlight: a series game on a slide of its own (GameSpotlight, Age of Empires II since
    813bf8f2). The C frame of d4: header line and stats bar. Left the game's cover art sharp and whole, edged in the
    game's colour, and under it who holds the top of its ladder (avatar crowned in the game's colour, name, ladder,
    Elo), else the open top spot. Right the game's name as the headline, its series lengths, a rule in the game's
    colour, then how it is played here as label and line (casual 1v1, the host's lobby, the cups' weekend slot, the
    ladders), and the call to play with the game's page. The league's orange stays on the action; the game's colour
    marks only the game.

    Data contract (SceneSource, GameSpotlight::data()):
      $spotlight  array{name: string, claim: string, cover: ?string, colour: string, colourDeep: string,
                    facts: list<array{label: string, line: string}>,
                    leader: array{name: string, elo: int, avatar: ?string, ladder: string}|null, url: string,
                    now?: array{state: 'running'|'finished'|'none', label: string, name: string, lines: list<string>}|null}|null
                  `now` (P8, a lobby game's match, GameSpotlight::now()): running or finished takes the leader's place
                  under the cover (label, tournament, its lobbies decided and a countdown, or the names on place 1);
                  none keeps the leader and adds one line, that no lobby match is on and the next start
                  null without a series game: the slide invites to every game instead
      $stats      array: the stats bar counts (c-chrome)
      $backdrop   ?string, optional: the game's blurred cover, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    // The cover and its game's state x 40..488, the text x 528..1016 (the now-playing column covers x 1040..1280).
    $s = is_array($spotlight ?? null) ? $spotlight : null;
    $accent = K::colour($s['colour'] ?? null, '#F7931A');
    $cover = K::coverUri($s['cover'] ?? null);
    // The game's name on one line when it fits there at 34 px or more, else in two.
    $title = K::headline(K::text($s ?? [], 'name', 'Every game'), [48, 44, 40, 34], 488, 1);
    if (implode(' ', $title['lines']) !== K::clean(K::text($s ?? [], 'name', 'Every game'))) {
        $title = K::headline(K::text($s ?? [], 'name', 'Every game'), [48, 40, 34], 488, 2);
    }

    $titleStep = round($title['size'] * 1.1);
    $claimY = 150 + (count($title['lines']) - 1) * $titleStep + 42;
    $claim = K::fit(K::text($s ?? [], 'claim'), K::MONO, 20, 488);
    $ruleY = $claimY + 28;
    $facts = [];
    $y = $ruleY + 34;
    foreach (array_slice(is_array($s['facts'] ?? null) ? $s['facts'] : [], 0, 4) as $i => $fact) {
        $lines = K::wrap(K::text($fact, 'line'), K::MONO, 18, 352, 2);
        if ($lines === []) {
            continue;
        }
        $facts[] = ['i' => $i, 'y' => $y, 'label' => K::fit(K::text($fact, 'label'), K::MONO, 18, 124), 'lines' => $lines, 'rule' => $y + 16 + (count($lines) - 1) * 24];
        $y += 42 + (count($lines) - 1) * 24;
    }
    $leader = is_array($s['leader'] ?? null) ? $s['leader'] : null;
    $leaderName = $leader ? K::nameFont(K::text($leader, 'name')) : K::DISPLAY;
    // Lines that carry facts shrink to fit whole (down to 12 px) before they are cut.
    $leaderText = $leader ? '#1 on the '.K::text($leader, 'ladder').', '.(int) ($leader['elo'] ?? 0).' Elo' : '';
    $leaderSize = K::monoSize($leaderText, 18, 332);
    $leaderLine = $leader ? K::fit($leaderText, K::MONO, $leaderSize, 332) : '';
    $urlText = K::text($s ?? [], 'url', 'esports.einundzwanzig.space');
    $urlSize = K::monoSize($urlText, 18, 488);
    $url = K::fit($urlText, K::MONO, $urlSize, 488);
    // The call right under the facts, never into the stats bar (rule at y 625).
    $ctaY = min($y - 4, 510);
    // A lobby game's match now (P8, GameSpotlight::now()): running or finished takes the leader's place, none adds a line.
    $now = is_array($s['now'] ?? null) ? $s['now'] : null;
    $nowState = $now['state'] ?? null;
    $nowLive = in_array($nowState, ['running', 'finished'], true);
    $nowName = $nowLive ? K::headline(K::text($now, 'name', 'Tournament'), [30, 26, 22], 448, 1) : null;
    $nowLines = [];
    if ($nowState === 'running') {
        foreach (array_slice(array_values(array_filter((array) ($now['lines'] ?? []), 'is_string')), 0, 2) as $line) {
            $nowLines[] = K::fit($line, K::MONO, 18, 448);
        }
    } elseif ($nowState === 'finished') {
        // The names on place 1, as many as three lines hold whole, the rest counted.
        $nowLines = K::namesInLines((array) ($now['lines'] ?? []), K::MONO, 18, 448, 3);
    }
    $noneSize = is_string($now['lines'][0] ?? null) ? K::monoSize($now['lines'][0], 16, 448) : 16;
    $noneLine = $nowState === 'none' && is_string($now['lines'][0] ?? null) ? K::fit($now['lines'][0], K::MONO, $noneSize, 448) : '';
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.82])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'spotlight'])

<g data-unit="cover" data-box="40 120 489 373">
<rect x="40" y="120" width="448" height="252" fill="{{ $accent }}"/>
<rect x="46" y="126" width="436" height="240" fill="#1C1C21"/>
@if ($cover)
<image x="46" y="126" width="436" height="240" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@endif
</g>
@if ($nowLive)
<text data-unit="now-label" data-box="39 450 489 474" x="40" y="468" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ K::fit(K::text($now, 'label'), K::MONO, 18, 448) }}</text>
<text data-unit="now-name" data-box="39 {{ 512 - $nowName['size'] }} 489 {{ 512 + $nowName['size'] * 0.3 }}" x="40" y="512" font-family="{{ $nowName['font'] }}" font-weight="800" font-size="{{ $nowName['size'] }}" fill="#FFFFFF">{{ $nowName['lines'][0] ?? '' }}</text>
@foreach ($nowLines as $li => $line)
<text data-unit="now-line-{{ $li }}" data-box="39 {{ 532 + $li * 26 }} 489 {{ 556 + $li * 26 }}" x="40" y="{{ 550 + $li * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $li === 0 || $nowState === 'finished' ? '#FFFFFF' : '#ADADB0' }}">{{ $line }}</text>
@endforeach
@elseif ($leader)
@include('stream.rotation.partials.face', ['face' => K::prideFace($leader), 'x' => 40, 'y' => 470, 'd' => 96, 'id' => 'spot', 'fUnit' => 'leader-face', 'fRing' => $accent, 'fCrown' => $accent])
<text data-unit="leader-name" data-box="155 482 489 526" x="156" y="518" font-family="{{ $leaderName }}" font-weight="800" font-size="28" fill="#FFFFFF">{{ K::fit(K::text($leader, 'name'), $leaderName, 28, 332) }}</text>
<text data-unit="leader-line" data-box="155 536 489 562" x="156" y="556" font-family="JetBrains Mono" font-weight="700" font-size="{{ $leaderSize }}" fill="#ADADB0">{{ $leaderLine }}</text>
@elseif ($s)
@include('stream.rotation.partials.face', ['face' => null, 'x' => 40, 'y' => 470, 'd' => 96, 'id' => 'spot', 'fUnit' => 'leader-face', 'fOpen' => 'open', 'fOpenInk' => $accent])
<text data-unit="leader-name" data-box="155 482 489 526" x="156" y="518" font-family="Unbounded" font-weight="800" font-size="26" fill="#FFFFFF">The top spot is open.</text>
<text data-unit="leader-line" data-box="155 536 489 562" x="156" y="556" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Win a game and it is yours.</text>
@endif
@if ($noneLine !== '')<text data-unit="now-none" data-box="39 586 489 608" x="40" y="602" font-family="JetBrains Mono" font-weight="700" font-size="{{ $noneSize }}" fill="#ADADB0">{{ $noneLine }}</text>@endif

@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="527 {{ 150 + $i * $titleStep - $title['size'] }} 1017 {{ 150 + $i * $titleStep + $title['size'] * 0.3 }}" x="528" y="{{ 150 + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($claim !== '')
<text data-unit="claim" data-box="527 {{ $claimY - 18 }} 1017 {{ $claimY + 6 }}" x="528" y="{{ $claimY }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $claim }}</text>
@endif
<rect x="528" y="{{ $ruleY }}" width="488" height="4" fill="{{ $accent }}"/>
@foreach ($facts as $f)
<text data-unit="fact-label-{{ $f['i'] }}" data-box="527 {{ $f['y'] - 16 }} 652 {{ $f['y'] + 6 }}" x="528" y="{{ $f['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $f['label'] }}</text>
@foreach ($f['lines'] as $li => $line)
<text data-unit="fact-{{ $f['i'] }}-{{ $li }}" data-box="663 {{ $f['y'] + $li * 24 - 16 }} 1017 {{ $f['y'] + $li * 24 + 6 }}" x="664" y="{{ $f['y'] + $li * 24 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $line }}</text>
@endforeach
<rect x="528" y="{{ $f['rule'] }}" width="488" height="1" fill="#2A2A30"/>
@endforeach
@if ($s)
<rect x="528" y="{{ $ctaY }}" width="120" height="44" fill="#F7931A"/>
<text data-unit="cta" data-box="528 {{ $ctaY }} 648 {{ $ctaY + 44 }}" x="588" y="{{ $ctaY + 29 }}" font-family="Unbounded" font-weight="800" font-size="20" fill="#17120A" text-anchor="middle">Play</text>
<text data-unit="cta-url" data-box="527 {{ $ctaY + 62 }} 1017 {{ $ctaY + 86 }}" x="528" y="{{ $ctaY + 80 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ $url }}</text>

@else
<text data-unit="claim" data-box="527 300 1017 330" x="528" y="322" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Every game of the league is on the site.</text>
@endif
</svg>
