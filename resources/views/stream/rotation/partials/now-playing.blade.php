{{--
    The music on air, over every slide (SceneRenderer::svg(); user 2026-10-15: "auf den Live Stream Slides immer
    anzeigen, welches Musikstück gerade läuft. Aber nur den Artist Namen mit ihrem Nostr Profil Avatar und ihrem Nostr
    Zapp Adresse, damit man ihnen Sats schicken kann als QR Code"): a card at the right, above the slides' footers
    (y 512 to 608, measured free on every look; the commentary's lower third shortens beside it), with the artist's avatar, their name, what the code does, and the code.
    $np: NowPlaying::at() — array{artist: string, avatar: ?string (data URI), qr: string (trusted SVG), kind: 'lud16'|'njump'|'youtube'|'wavlake'}.
    $at: the card's top left [x, y] (SceneRenderer::nowPlayingAt(): 816, 512, or a spot measured free on a slide that
    holds something there); the drawing below is laid out for 816, 512 and moved as a whole.
    One <g data-unit> so the pixel sonde measures it as one piece.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $npName = (string) $np['artist'];
    $npLength = mb_strlen($npName);
    $npSize = $npLength <= 13 ? 20 : ($npLength <= 17 ? 17 : 15);
    $npName = $npLength > 22 ? mb_substr($npName, 0, 21).'…' : $npName;
    $npAction = match ($np['kind']) {
        'lud16' => 'Zap den Artist',
        'youtube' => 'Artist auf YouTube',
        'wavlake' => 'Zap auf Wavlake',
        default => 'Artist auf Nostr',
    };
    $npAvatar = K::avatarUri($np['avatar'] ?? null);
@endphp
@php([$npX, $npY] = $at ?? [816, 512])
<g data-unit="now-playing" data-box="{{ $npX }} {{ $npY }} 400 96"><g transform="translate({{ $npX - 816 }} {{ $npY - 512 }})">
    <rect x="816" y="512" width="400" height="96" rx="4" fill="#121215" fill-opacity="0.94"/>
    <rect x="816" y="512" width="6" height="96" fill="#F7931A"/>
    <defs><clipPath id="np-face"><circle cx="866" cy="560" r="28"/></clipPath></defs>
    @if ($npAvatar)
        <image href="{{ $npAvatar }}" x="838" y="532" width="56" height="56" preserveAspectRatio="xMidYMid slice" clip-path="url(#np-face)"/>
    @else
        <circle cx="866" cy="560" r="28" fill="#1E1E24"/>
        <path d="M858 572 V550 L876 546 V568" fill="none" stroke="#F7931A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="855" cy="572" r="4" fill="#F7931A"/><circle cx="873" cy="568" r="4" fill="#F7931A"/>
    @endif
    <circle cx="866" cy="560" r="28" fill="none" stroke="#F7931A" stroke-width="2"/>
    <text x="908" y="538" font-family="JetBrains Mono" font-weight="700" font-size="12" fill="#F9B25F" letter-spacing="1">JETZT LÄUFT</text>
    <text x="908" y="568" font-family="Unbounded" font-weight="800" font-size="{{ $npSize }}" fill="#FFFFFF">{{ $npName }}</text>
    {{-- A drawn bolt, not the emoji: the stream's private fontconfig knows only the brand fonts. --}}
    @if ($np['kind'] === 'lud16')
        <path d="M914 581 L909 591 H913 L911 599 L918 588 H914 L916 581 Z" fill="#F7931A"/>
    @endif
    <text x="{{ $np['kind'] === 'lud16' ? 924 : 908 }}" y="594" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="#ADADB0">{{ $npAction }}</text>
    @include('stream.rotation.partials.qr', ['x' => 1124, 'y' => 518, 'size' => 84, 'qr' => $np['qr']])
</g></g>
