{{--
    The commentary as a text lower third (plan "KI-Stimme der Liga", P6): only while the workstation that voices it is
    off, never next to spoken commentary (user 2026-10-11: no duplicate text). SceneRenderer::svg() appends it to every
    rotation scene whose data carries `voice_caption`; $lines are one or two wrapped lines.
    Geometry from the canvas board "TV-Sender" s01 (Bauchbinde y 742–906 of 1080, x 96–1824, #121215, orange bar,
    Unbounded 36), scaled to the stream's 1280 x 720. Without the board's "KI-Kommentar" label (user decision 4).
    One <g data-unit> so the pixel sonde measures it as one piece.
--}}
<g data-unit="voice-caption"><rect x="64" y="495" width="{{ $width ?? 1152 }}" height="109" rx="3" fill="#121215"/><rect x="64" y="495" width="6" height="109" fill="#F7931A"/>@foreach ($lines as $i => $line)<text x="88" y="{{ count($lines) === 1 ? 558 : 540 + 38 * $i }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">{{ $line }}</text>@endforeach</g>
