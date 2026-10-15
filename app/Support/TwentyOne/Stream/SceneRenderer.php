<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Scene data → SVG (resources/views/stream/scene.blade.php, or a rotation
 * scene under resources/views/stream/rotation) → PNG bytes.
 *
 * `rsvg-convert` reads the SVG on stdin and writes the PNG to stdout, with a
 * private fontconfig that knows only the committed brand fonts
 * (resources/fonts/stream), so the server needs no font installed and renders
 * like any other machine. The child gets no secrets from the environment.
 * The last SVG and its PNG are kept: a second with nothing changed costs no
 * render; so are the last PNG_CACHE renders by their SVG's hash.
 */
final class SceneRenderer
{
    private ?string $lastSvg = null;

    private ?string $lastPng = null;

    /**
     * Recent renders by the hash of their SVG (the SVG is a pure function of the scene's data): a slide that comes back
     * in the rotation unchanged (the radio programme between two minutes, a teaser) costs no render. At most
     * PNG_CACHE of them, the oldest dropped first.
     *
     * @var array<string, string>
     */
    private array $pngs = [];

    /** PNGs kept by SVG hash (a 1280x720 slide is 50 to 300 kB). */
    public const PNG_CACHE = 8;

    public function __construct(
        private string $rsvgConvert,
        private string $fontsDir,
        private string $workDir,
    ) {}

    /**
     * The last PNG that rendered, to keep the picture when a render fails.
     */
    public function lastPng(): ?string
    {
        return $this->lastPng;
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('twentyone.stream.scene.rsvg_convert'),
            (string) config('twentyone.stream.scene.fonts_dir'),
            (string) config('twentyone.stream.scene.work_dir'),
        );
    }

    /**
     * @param  array<string, mixed>  $scene  the view's data contract
     * @param  view-string  $view
     */
    public function svg(array $scene, string $view = 'stream.scene'): string
    {
        $svg = view($view, $scene)->render();
        $caption = $scene['voice_caption'] ?? null;
        $np = $scene['now_playing'] ?? null;
        $np = is_array($np) && is_string($np['artist'] ?? null) && is_string($np['qr'] ?? null) ? $np : null;

        // The commentary as a lower third while its voice is off (plan "KI-Stimme der Liga", P6): on top of the scene.
        if (is_string($caption) && trim($caption) !== '' && ($end = strrpos($svg, '</svg>')) !== false) {
            // Beside a now-playing card at its height on the right (x 816 on) the lower third ends at x 800 and takes fewer characters a line.
            [$npX, $npY] = self::nowPlayingAt($view);
            $beside = $np !== null && $npX >= 816 && $npY < 604 && $npY + 96 > 495;
            $chars = $beside ? self::CAPTION_CHARS_BESIDE_CARD : self::CAPTION_CHARS;
            $lines = array_slice(explode("\n", wordwrap(trim(preg_replace('/\s+/u', ' ', $caption) ?? ''), $chars, "\n", true)), 0, 2);
            $svg = substr($svg, 0, $end).view('stream.rotation.partials.voice-caption', ['lines' => $lines, 'width' => $beside ? 736 : 1152])->render().substr($svg, $end);
        }

        // The music on air, on every slide (NowPlaying, user 2026-10-15): the artist, their avatar and the code to tip them.
        if ($np !== null && ($end = strrpos($svg, '</svg>')) !== false) {
            $svg = substr($svg, 0, $end).view('stream.rotation.partials.now-playing', ['np' => $np, 'at' => self::nowPlayingAt($view)])->render().substr($svg, $end);
        }

        return $svg;
    }

    /**
     * Where the now-playing card sits on a slide, and in which form: at the top right under the viewer badge (816,
     * 112; user 2026-10-15: "Ist auf den meisten Slides nicht eher oben rechts mehr Platz?"), out of the corner the
     * players keep for their LIVE badge (x >= 1040, y < 112). Rendering every look and measuring the pixels under the
     * card (2026-10-15) found it free on 32 of 42 slides; the others get a spot measured free there: the invitation,
     * the cups and the spotlight (d2, d3, d6) at the right above the footers, TMNF's lines (g2 to g4) bottom left,
     * Pong's call to play (p1) top middle. The tournament TV (tv1 to tv4, a dense page) gets the compact form under
     * its header's right corner.
     *
     * @return array{0: int, 1: int, 2: 'card'|'compact'}
     */
    public static function nowPlayingAt(string $view): array
    {
        return match ($view) {
            'stream.rotation.d2-cups', 'stream.rotation.d3-invite', 'stream.rotation.d6-spotlight' => [816, 512, 'card'],
            'stream.rotation.g2-tmnf-race', 'stream.rotation.g3-tmnf-join', 'stream.rotation.g4-tmnf-leader' => [40, 608, 'card'],
            'stream.rotation.p1-pong' => [416, 72, 'card'],
            'stream.rotation.tv1-bracket', 'stream.rotation.tv2-up-now', 'stream.rotation.tv3-standings', 'stream.rotation.tv4-pot' => [1040, 112, 'compact'],
            default => [816, 112, 'card'],
        };
    }

    /** Characters per line of the commentary lower third (Unbounded 800 at 24 px, about 14.6 px a character, on 1128 px; wordwrap counts bytes, so umlauts only shorten a line). */
    public const CAPTION_CHARS = 70;

    /** The same beside the now-playing card: 712 px of text instead of 1128. */
    public const CAPTION_CHARS_BESIDE_CARD = 44;

    /**
     * @param  array<string, mixed>  $scene
     * @param  view-string  $view
     *
     * @throws RuntimeException when rsvg-convert fails
     */
    public function png(array $scene, string $view = 'stream.scene'): string
    {
        $svg = $this->svg($scene, $view);

        if ($svg === $this->lastSvg && $this->lastPng !== null) {
            return $this->lastPng;
        }

        $hash = sha1($svg);

        if (isset($this->pngs[$hash])) {
            $this->lastSvg = $svg;

            return $this->lastPng = $this->pngs[$hash];
        }

        // A render takes 0.07-0.2 s; a hanging one must not hold the supervisor loop.
        $result = Process::timeout(max(1, (int) config('twentyone.stream.scene.render_timeout_seconds', 2)))
            ->env([...ChildEnvironment::withoutSecrets(), 'FONTCONFIG_FILE' => $this->fontconfig()])
            ->input($svg)
            ->run([$this->rsvgConvert, '--format', 'png']);

        if ($result->failed() || ! str_starts_with($result->output(), "\x89PNG")) {
            throw new RuntimeException('rsvg-convert failed: '.trim(substr($result->errorOutput(), 0, 300)));
        }

        $this->lastSvg = $svg;
        $this->pngs[$hash] = $result->output();

        if (count($this->pngs) > self::PNG_CACHE) {
            unset($this->pngs[array_key_first($this->pngs)]);
        }

        return $this->lastPng = $result->output();
    }

    /**
     * Write (once) and return the private fontconfig file: only the brand
     * fonts, no /etc/fonts include, a cache of its own.
     */
    public function fontconfig(): string
    {
        $path = rtrim($this->workDir, '/').'/fonts.conf';
        $contents = '<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "fonts.dtd"><fontconfig>'
            .'<dir>'.htmlspecialchars(realpath($this->fontsDir) ?: $this->fontsDir, ENT_XML1).'</dir>'
            .'<cachedir>'.htmlspecialchars(rtrim($this->workDir, '/').'/fontcache', ENT_XML1).'</cachedir>'
            .'</fontconfig>'."\n";

        if (! is_file($path) || file_get_contents($path) !== $contents) {
            File::ensureDirectoryExists($this->workDir);
            PlaylistWriter::writeAtomically($path, $contents);
        }

        return $path;
    }
}
