{{--
    The music on air, over every slide (SceneRenderer::svg(); user 2026-10-15: "auf den Live Stream Slides immer
    anzeigen, welches Musikstück gerade läuft. Aber nur den Artist Namen mit ihrem Nostr Profil Avatar und ihrem Nostr
    Zapp Adresse, damit man ihnen Sats schicken kann als QR Code"): the artist's avatar, their name, what the code does,
    and the code.
    $np: NowPlaying::at() — array{artist: string, avatar: ?string (data URI), qr: string (trusted SVG), kind: 'lud16'|'njump'|'youtube'|'wavlake'}.
    $at: SceneRenderer::nowPlayingAt() — [x, y, variant]: 'card' (400 x 96) or 'compact' (200 x 96, the tournament TV,
    whose dense pages leave only the corner under their header free).
    A long name wraps to two lines by its estimated width (Unbounded 800, about 0.72 em a character; user 2026-10-15:
    "Da überdeckt es auch wegen dem langen namen"), never under the code.
    One <g data-unit> so the pixel sonde measures it as one piece.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    [$npX, $npY, $npVariant] = $at ?? [816, 112, 'card'];
    $npCompact = $npVariant === 'compact';
    $npAction = match ($np['kind']) {
        'lud16' => 'Zap den Artist',
        'youtube' => 'Artist auf YouTube',
        'wavlake' => 'Zap auf Wavlake',
        default => 'Artist auf Nostr',
    };
    $npAvatar = K::avatarUri($np['avatar'] ?? null);
    // The name in at most two lines within $npWidth px: the largest size that fits one line, else two lines of the small size.
    $npWidth = $npCompact ? 100 : 208;
    $npName = trim((string) $np['artist']);
    $npSizes = $npCompact ? [13, 12, 11] : [20, 17, 15];
    $npLines = null;
    foreach ($npSizes as $size) {
        if (mb_strlen($npName) * 0.72 * $size <= $npWidth) {
            [$npLines, $npSize] = [[$npName], $size];
            break;
        }
    }
    if ($npLines === null) {
        $npSize = end($npSizes);
        $perLine = max(4, (int) floor($npWidth / (0.72 * $npSize)));
        $npLines = array_slice(explode("\n", wordwrap($npName, $perLine, "\n", true)), 0, 2);
        if (mb_strlen(implode(' ', $npLines)) < mb_strlen($npName)) {
            $npLines[1] = mb_substr($npLines[1], 0, $perLine - 1).'…';
        }
    }
    $npTwo = count($npLines) === 2;
@endphp
@if ($npCompact)
<g data-unit="now-playing" data-box="{{ $npX }} {{ $npY }} 200 96"><g transform="translate({{ $npX }} {{ $npY }})">
    <rect width="200" height="96" rx="4" fill="#121215" fill-opacity="0.94"/>
    <rect width="4" height="96" fill="#F7931A"/>
    <defs><clipPath id="np-face"><circle cx="26" cy="22" r="14"/></clipPath></defs>
    @if ($npAvatar)
        <image href="{{ $npAvatar }}" x="12" y="8" width="28" height="28" preserveAspectRatio="xMidYMid slice" clip-path="url(#np-face)"/>
    @else
        <circle cx="26" cy="22" r="14" fill="#1E1E24"/>
        <path d="M22 28 V17 L31 15 V25" fill="none" stroke="#F7931A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="20" cy="28" r="2.2" fill="#F7931A"/><circle cx="29" cy="25" r="2.2" fill="#F7931A"/>
    @endif
    <circle cx="26" cy="22" r="14" fill="none" stroke="#F7931A" stroke-width="1.5"/>
    <text x="46" y="26" font-family="JetBrains Mono" font-weight="700" font-size="9" fill="#F9B25F" letter-spacing="0.5">JETZT LÄUFT</text>
    @foreach ($npLines as $i => $line)
        <text x="12" y="{{ $npTwo ? 52 + 15 * $i : 58 }}" font-family="Unbounded" font-weight="800" font-size="{{ $npSize }}" fill="#FFFFFF">{{ $line }}</text>
    @endforeach
    <text x="12" y="88" font-family="JetBrains Mono" font-weight="700" font-size="10" fill="#ADADB0">{{ $npAction }}</text>
    @include('stream.rotation.partials.qr', ['x' => 118, 'y' => 10, 'size' => 76, 'qr' => $np['qr']])
</g></g>
@else
<g data-unit="now-playing" data-box="{{ $npX }} {{ $npY }} 400 96"><g transform="translate({{ $npX }} {{ $npY }})">
    <rect width="400" height="96" rx="4" fill="#121215" fill-opacity="0.94"/>
    <rect width="6" height="96" fill="#F7931A"/>
    <defs><clipPath id="np-face"><circle cx="50" cy="48" r="28"/></clipPath></defs>
    @if ($npAvatar)
        <image href="{{ $npAvatar }}" x="22" y="20" width="56" height="56" preserveAspectRatio="xMidYMid slice" clip-path="url(#np-face)"/>
    @else
        <circle cx="50" cy="48" r="28" fill="#1E1E24"/>
        <path d="M42 60 V38 L60 34 V56" fill="none" stroke="#F7931A" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="39" cy="60" r="4" fill="#F7931A"/><circle cx="57" cy="56" r="4" fill="#F7931A"/>
    @endif
    <circle cx="50" cy="48" r="28" fill="none" stroke="#F7931A" stroke-width="2"/>
    <text x="92" y="{{ $npTwo ? 22 : 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="12" fill="#F9B25F" letter-spacing="1">JETZT LÄUFT</text>
    @foreach ($npLines as $i => $line)
        <text x="92" y="{{ $npTwo ? 44 + 18 * $i : 56 }}" font-family="Unbounded" font-weight="800" font-size="{{ $npSize }}" fill="#FFFFFF">{{ $line }}</text>
    @endforeach
    {{-- A drawn bolt, not the emoji: the stream's private fontconfig knows only the brand fonts. --}}
    @if ($np['kind'] === 'lud16')
        <path d="M98 {{ $npTwo ? 73 : 69 }} l-5 10 h4 l-2 8 l7 -11 h-4 l2 -7 z" fill="#F7931A"/>
    @endif
    <text x="{{ $np['kind'] === 'lud16' ? 108 : 92 }}" y="{{ $npTwo ? 86 : 82 }}" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="#ADADB0">{{ $npAction }}</text>
    @include('stream.rotation.partials.qr', ['x' => 308, 'y' => 6, 'size' => 84, 'qr' => $np['qr']])
</g></g>
@endif
