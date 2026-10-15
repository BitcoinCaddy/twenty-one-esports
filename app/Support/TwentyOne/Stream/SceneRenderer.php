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

        // The commentary as a lower third while its voice is off (plan "KI-Stimme der Liga", P6): on top of the
        // scene, x 64..1016, so it ends before the now-playing column.
        if (is_string($caption) && trim($caption) !== '' && ($end = strrpos($svg, '</svg>')) !== false) {
            $lines = array_slice(explode("\n", wordwrap(trim(preg_replace('/\s+/u', ' ', $caption) ?? ''), self::CAPTION_CHARS, "\n", true)), 0, 2);
            $svg = substr($svg, 0, $end).view('stream.rotation.partials.voice-caption', ['lines' => $lines, 'width' => self::CAPTION_WIDTH])->render().substr($svg, $end);
        }

        // The music on air in a fixed column on every slide (plan "Stream: feste Jetzt-läuft-Spalte rechts"): the
        // artist, their avatar and the code to tip them, else the league; the live viewer count below. Last, so it
        // covers x 1040..1280 of whatever the slide drew.
        if ($view !== self::GAME_SCENE && ($end = strrpos($svg, '</svg>')) !== false) {
            $np = $scene['now_playing'] ?? null;
            $np = is_array($np) && is_string($np['artist'] ?? null) && is_string($np['qr'] ?? null) ? $np : null;
            $viewers = $scene['viewers'] ?? null;
            $column = view('stream.rotation.partials.now-playing-column', ['np' => $np, 'viewers' => is_int($viewers) ? $viewers : null, 'siteQr' => $this->siteQr()])->render();
            $svg = substr($svg, 0, $end).$column.substr($svg, $end);
        }

        return $svg;
    }

    /** The game scene the stream falls back to when a rotation slide fails: a full frame of its own, without the column. */
    public const GAME_SCENE = 'stream.scene';

    /** Width of the commentary lower third: x 64 to 1016, before the now-playing column (RotationKit::CONTENT_RIGHT). */
    public const CAPTION_WIDTH = 952;

    /** Characters per line of the commentary lower third (Unbounded 800 at 24 px, about 14.6 px a character plus the spaces' slack, on 904 px of text; wordwrap counts bytes, so umlauts only shorten a line). */
    public const CAPTION_CHARS = 56;

    private ?string $siteQr = null;

    /** The site's QR code (resources/stream/qr/site.svg) for the column without a card, '' when the file is missing. */
    private function siteQr(): string
    {
        $path = resource_path('stream/qr/site.svg');

        return $this->siteQr ??= is_file($path) ? (string) file_get_contents($path) : '';
    }

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
