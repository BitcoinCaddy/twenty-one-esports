{{--
    P1 · Proof of Pong result (plan "Proof of Pong", P4). A match won within the last day: the winner's victory pose on
    the left, in the middle the winner with their figure, the final score in the orange block, the loser with their
    portrait, and both Elo changes; on the right a still of the arena (a real three.js frame) and the call to play with
    the short URL. No live gameplay (the encoder takes no live frame rate). Without the match (the game is off): the
    plate, the title and one line. Everything stays left of x 1016: the stream's now-playing column owns x 1040 on.

    Data contract (PongScene::data()):
      $pong      array{id: int, rated: bool, reason: ?string ('resign'|'forfeit'), ago: ?int (minutes),
                   sides: list<array{name: string, avatar: ?string, figure: ?string, picture: ?string, points: int,
                     elo: array{before: int, after: int}|null}> (winner first)}|null
      $art       array{plate: ?string, still: ?string} data URIs
      $url       string, the short way to play
      $backdrop  ?string, the game's blurred cover, under the plate when the plate is missing
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $result = is_array($pong ?? null) ? $pong : null;
    $plate = K::coverUri($art['plate'] ?? null);
    $still = K::coverUri($art['still'] ?? null);
    if ($result) {
        [$w, $l] = [$result['sides'][0] ?? [], $result['sides'][1] ?? []];
        $pose = K::coverUri($w['picture'] ?? null);
        $loserPicture = K::coverUri($l['picture'] ?? null);
        // Three columns over x 32..1016: the pose (32..312), the result (336..680), the arena and the call (704..1016).
        // A long name steps down a size before it loses letters.
        foreach ([30, 26, 22] as $winnerSize) {
            $winnerName = K::fit(K::clean($w['name'] ?? ''), K::DISPLAY, $winnerSize, 264);
            if ($winnerName === K::clean($w['name'] ?? '')) {
                break;
            }
        }
        $winnerFigure = ($w['figure'] ?? null) === null ? '' : K::fit('as '.K::clean($w['figure']), K::MONO, 20, 264);
        foreach ([20, 18, 16] as $loserSize) {
            $loserName = K::fit(K::clean($l['name'] ?? ''), K::DISPLAY, $loserSize, 232);
            if ($loserName === K::clean($l['name'] ?? '')) {
                break;
            }
        }
        $loserFigure = ($l['figure'] ?? null) === null ? '' : K::fit('as '.K::clean($l['figure']), K::MONO, 16, 232);
        $score = (int) ($w['points'] ?? 0).':'.(int) ($l['points'] ?? 0);
        // No closure in a stream view (it leaks on every render without the CLI opcache): PongScene words the change.
        $winnerElo = \App\Support\TwentyOne\Stream\PongScene::eloLine($w['elo'] ?? null);
        $loserElo = \App\Support\TwentyOne\Stream\PongScene::eloLine($l['elo'] ?? null);
        $ago = $result['ago'] ?? null;
        $when = $ago === null ? '' : ($ago < 1 ? 'just now' : ($ago < 60 ? $ago.' min ago' : intdiv($ago, 60).' h ago'));
        $how = ['resign' => 'by resignation', 'forfeit' => 'by forfeit'][$result['reason'] ?? ''] ?? null;
        $sub = K::fit(implode(' · ', array_filter(['Live 1v1 result', ($result['rated'] ?? false) ? 'rated' : 'casual', $how, $when])), K::MONO, 18, 940);
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#05070F"/>
@if ($plate)
<image data-unit="plate" image-rendering="optimizeSpeed" x="0" y="0" width="1040" height="720" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $plate }}"/>
<rect width="1280" height="720" fill="#05070F" fill-opacity="{{ $result ? 0.66 : 0.45 }}"/>
@else
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.72, 'bdW' => 1040])
@endif
<text data-unit="title" data-box="31 22 700 60" x="32" y="54" font-family="Unbounded" font-weight="800" font-size="30" fill="#F7931A">PROOF OF PONG</text>
@if ($result)
<text data-unit="sub" data-box="31 66 1016 88" x="32" y="84" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $sub }}</text>
{{-- The winner's victory pose (the figure's own art; a face when no figure was picked). --}}
<g data-unit="pose" data-box="24 112 320 600">
<rect x="32" y="120" width="280" height="472" rx="16" fill="#0B1428" fill-opacity="0.82" stroke="#4ADE80" stroke-width="3"/>
@if ($pose)
<image data-unit="pose-art" x="40" y="150" width="264" height="400" preserveAspectRatio="xMidYMax meet" xlink:href="{{ $pose }}"/>
@else
@include('stream.rotation.partials.face', ['face' => ['uri' => $w['avatar'] ?? null, 'tag' => null], 'x' => 92, 'y' => 230, 'd' => 160, 'id' => 'pw', 'fUnit' => 'pose-face', 'fRing' => '#4ADE80'])
@endif
<rect x="92" y="566" width="160" height="40" rx="8" fill="#4ADE80"/>
<text x="172" y="594" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="20" fill="#05070F">WINNER</text>
</g>
{{-- The winner, the score, the loser. --}}
<g data-unit="winner" data-box="336 120 680 230">
@include('stream.rotation.partials.face', ['face' => ['uri' => $w['avatar'] ?? null, 'tag' => null], 'x' => 336, 'y' => 128, 'd' => 64, 'id' => 'pfw', 'fUnit' => 'winner-face', 'fRing' => '#4ADE80'])
<text data-unit="winner-name" x="416" y="166" font-family="Unbounded" font-weight="800" font-size="{{ $winnerSize }}" fill="#FFFFFF">{{ $winnerName }}</text>
@if ($winnerFigure !== '')<text data-unit="winner-figure" x="416" y="196" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $winnerFigure }}</text>@endif
@if ($winnerElo !== '')<text data-unit="winner-elo" x="336" y="226" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#4ADE80">{{ $winnerElo }}</text>@endif
</g>
<g data-unit="score" data-box="336 248 664 384">
<rect x="336" y="248" width="328" height="136" rx="12" fill="#F7931A"/>
<text x="500" y="352" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="84" fill="#17120A">{{ $score }}</text>
</g>
<g data-unit="loser" data-box="336 404 680 520">
<clipPath id="pl"><rect x="336" y="412" width="96" height="96" rx="10"/></clipPath>
@if ($loserPicture)<image data-unit="loser-art" x="336" y="412" width="96" height="96" preserveAspectRatio="xMidYMid slice" clip-path="url(#pl)" xlink:href="{{ $loserPicture }}"/>@else @include('stream.rotation.partials.face', ['face' => ['uri' => $l['avatar'] ?? null, 'tag' => null], 'x' => 336, 'y' => 412, 'd' => 96, 'id' => 'pfl', 'fUnit' => 'loser-face']) @endif
<text x="448" y="434" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1AA">AGAINST</text>
<text data-unit="loser-name" x="448" y="462" font-family="Unbounded" font-weight="800" font-size="{{ $loserSize }}" fill="#FFFFFF">{{ $loserName }}</text>
@if ($loserFigure !== '')<text data-unit="loser-figure" x="448" y="486" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $loserFigure }}</text>@endif
@if ($loserElo !== '')<text data-unit="loser-elo" x="448" y="510" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F87171">{{ $loserElo }}</text>@endif
</g>
@endif
{{-- How the game looks: a still of the arena, and the way to play. --}}
<g data-unit="still" data-box="704 120 1016 360">
<rect x="705" y="121" width="310" height="230" rx="12" fill="#0B1428" stroke="#57A6FF" stroke-width="2"/>
@if ($still)<image data-unit="still-art" x="712" y="128" width="296" height="191" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $still }}"/>@endif
<text x="860" y="342" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="15" fill="#FFFFFF">Classic Pong to 21, meme events</text>
</g>
<g data-unit="cta" data-box="704 384 1016 520">
<rect x="704" y="384" width="312" height="136" rx="12" fill="#F7931A"/>
<text x="860" y="430" text-anchor="middle" font-family="Unbounded" font-weight="800" font-size="24" fill="#17120A">PLAY NOW</text>
<text x="860" y="458" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#17120A">a bot or a friend</text>
{{-- The short way to play whole: one line while it fits the card, else the host over the path. --}}
@php($ctaUrl = (string) ($url ?? ''))
@php($ctaCut = strpos($ctaUrl, '/'))
@php($ctaLines = K::width($ctaUrl, K::MONO, 14) <= 280 || $ctaCut === false ? [$ctaUrl] : [substr($ctaUrl, 0, $ctaCut), substr($ctaUrl, $ctaCut)])
@foreach ($ctaLines as $cli => $cl)
<text data-unit="cta-url{{ $cli > 0 ? '-path' : '' }}" x="860" y="{{ (count($ctaLines) > 1 ? 488 : 498) + $cli * 18 }}" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#17120A">{{ K::fit($cl, K::MONO, 14, 280) }}</text>
@endforeach
</g>
@unless ($result)
<text data-unit="none" data-box="31 300 680 340" x="32" y="330" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">No result yet today.</text>
<text data-unit="none-sub" data-box="31 356 680 380" x="32" y="374" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">Classic Pong to 21 in Bitcoin meme culture.</text>
@endunless
</svg>
