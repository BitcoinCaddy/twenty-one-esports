<?php

namespace App\Support\Memes;

use App\Models\User;
use App\Support\Cards\Canvas;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The meme card of a match (plan "KI-Stimme der Liga", P5): the one frame of every share card (ShareCards.dc.html,
 * drawn like App\Support\Cards\ShareCard: the motif on the left, kicker, player, the score big, the brand bottom left)
 * with the meme on it: the motif of its MemeLibrary template, the canon's wording of that template in quotation marks
 * and the line under it (MemeShares: the league voice's line, else the template's sentence). A link preview (`wide`,
 * 1200 × 630) and a story (`story`, 1080 × 1920).
 *
 * Cached per card, format, locale and facts like the share cards: an unchanged card is never redrawn.
 */
final class MemeCard
{
    public const FORMATS = ['wide' => [1200, 630], 'story' => [1080, 1920]];

    /** Bump when a layout changes: every card gets a new file and URL. */
    private const LAYOUT = 1;

    private Canvas $c;

    /**
     * @param  string  $key  the match's ULID: its part of the file name and URL
     * @param  array{moment: string, template: string, motif: string, quote: string|null, line: string, score: array{0: int, 1: int}, winner: string, loser: string, pubkey: string|null, avatar_path: string|null}  $facts
     */
    public function __construct(public readonly string $key, public readonly array $facts) {}

    /** The PNG bytes in the current locale, from the cache when unchanged. */
    public function png(string $format): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException("Unknown meme card format {$format}.");
        }

        $directory = 'share-cards/meme/'.$this->key;
        $prefix = $format.'-'.App::getLocale().'-';
        $path = $directory.'/'.$prefix.$this->fingerprint($format).'.png';
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $png = $this->render($format);

        foreach ($disk->files($directory) as $old) {
            if (str_starts_with(basename($old), $prefix)) {
                $disk->delete($old);
            }
        }

        $disk->put($path, $png);

        return $png;
    }

    public function fingerprint(string $format): string
    {
        return substr(hash('sha256', (string) json_encode([self::LAYOUT, $this->key, $format, App::getLocale(), $this->facts, config('app.url')])), 0, 16);
    }

    /** The public URL of the card in the current locale, with its fingerprint. */
    public function url(string $format): string
    {
        return rtrim((string) config('app.url'), '/').'/cards/'.App::getLocale().'/meme/pong/'.$this->key.'-'.$format.'.png?v='.$this->fingerprint($format);
    }

    /** The card's path on this site, for the page's own previews and downloads. */
    public function path(string $format): string
    {
        return substr($this->url($format), strlen(rtrim((string) config('app.url'), '/')));
    }

    public function render(string $format): string
    {
        [$width, $height] = self::FORMATS[$format] ?? throw new InvalidArgumentException("Unknown meme card format {$format}.");
        $this->c = new Canvas($width, $height);

        if ($format === 'story') {
            $this->story();
            $this->c->footer(72, 1780, 64, 1008);
        } else {
            $this->wide();
            $this->c->footer(64, 540, 44, 1136);
        }

        return $this->c->png();
    }

    /** The moment's name above the card's score. */
    public static function kicker(string $moment): string
    {
        return match ($moment) {
            'upset' => __('Upset'),
            'comeback' => __('Comeback'),
            'pong_deuce' => __('Into overtime'),
            'first_win' => __('First win'),
            default => __('Close game'),
        };
    }

    private function wide(): void
    {
        $this->motif(64, 112, 330);

        $x = 440;
        $max = 1136 - $x;
        $this->c->text($this->c->fit(self::kicker($this->facts['moment']), 'mono-bold', 28, $max), 'mono-bold', 28, $x, 112, Canvas::ORANGE);
        $this->person($x, 136, 44, 28, $max);
        $score = $this->score();
        $this->c->text($score, 'display', $this->c->fitSize($score, 'display', [112, 96, 80], $max), $x, 300, Canvas::INK);
        $after = $this->quote($x, 362, 28, $max, 2);
        $this->c->paragraph($this->facts['line'], 'mono', 22, $x, $after + 8, $max, 2, Canvas::INK_2);
    }

    private function story(): void
    {
        $this->c->text(self::kicker($this->facts['moment']), 'mono', 36, 72, 150, Canvas::INK_2);
        $this->motif(290, 260, 500);
        $this->person(72, 900, 64, 40, 936);
        $score = $this->score();
        $this->c->text($score, 'display', $this->c->fitSize($score, 'display', [200, 170, 140], 936), 72, 1180, Canvas::INK);
        $after = $this->quote(72, 1300, 44, 936, 3);
        $this->c->paragraph($this->facts['line'], 'mono', 32, 72, $after + 16, 936, 3, Canvas::INK_2);
    }

    /** "22 : 20", the winner's score first. */
    private function score(): string
    {
        return $this->facts['score'][0].' : '.$this->facts['score'][1];
    }

    /** The canon's wording in German quotation marks; returns the baseline after it. */
    private function quote(int $x, int $baseline, int $px, int $max, int $lines): float
    {
        if ($this->facts['quote'] === null) {
            return $baseline - $px;
        }

        return $this->c->paragraph('„'.$this->facts['quote'].'“', 'mono-bold', $px, $x, $baseline, $max, $lines, Canvas::INK);
    }

    /** Avatar, the winner's name and "vs <loser>" in one row. */
    private function person(int $x, int $y, int $avatar, int $px, int $max): void
    {
        $this->c->avatar($this->drawable(), $x, $y, $avatar);
        $textX = $x + $avatar + 16;
        $baseline = $y + $avatar * 0.72;
        $room = $max - $avatar - 16;
        $name = $this->c->fit($this->facts['winner'], 'mono-bold', $px, (int) round($room * 0.55));
        $this->c->text($name, 'mono-bold', $px, $textX, $baseline, Canvas::INK);
        $restX = $textX + $this->c->width($name, 'mono-bold', $px) + (int) round($px * 0.5);
        $rest = $this->c->fit(__('vs :name', ['name' => $this->facts['loser']]), 'mono', $px, (int) max(40, $x + $max - $restX));
        $this->c->text($rest, 'mono', $px, $restX, $baseline, Canvas::INK_2);
    }

    private function drawable(): User
    {
        return (new User)->forceFill([
            'pubkey' => NostrKeys::isHexPubkey($this->facts['pubkey']) ? $this->facts['pubkey'] : str_repeat('0', 64),
            'avatar_path' => $this->facts['avatar_path'],
        ]);
    }

    /* ---------- The motifs of the library: drawn here, no foreign artwork --------------------------------------- */

    private function motif(int $x, int $y, int $size): void
    {
        $this->c->rect($x, $y, $size, $size, '#121215');

        match ($this->facts['motif']) {
            'flip' => $this->flip($x, $y, $size),
            'laser' => $this->laser($x, $y, $size),
            'dip' => $this->bars($x, $y, $size, [0.7, 0.5, 0.3, 0.2, 0.45, 0.75, 0.95]),
            'diamond' => $this->diamond($x, $y, $size),
            'hodl' => $this->hodl($x, $y, $size),
            'scoreboard' => $this->scoreboard($x, $y, $size),
            'first' => $this->first($x, $y, $size),
            default => $this->bars($x, $y, $size, [0.2, 0.3, 0.42, 0.55, 0.7, 0.85, 0.95]),
        };
    }

    /**
     * Bars along the bottom, heights as parts of the box; the last one orange.
     *
     * @param  list<float>  $heights
     */
    private function bars(int $x, int $y, int $size, array $heights): void
    {
        $pad = $size * 0.12;
        $slot = ($size - 2 * $pad) / count($heights);

        foreach ($heights as $index => $part) {
            $h = ($size - 2 * $pad) * $part;
            $colour = $index === array_key_last($heights) ? Canvas::ORANGE : '#3A3A40';
            $this->c->rect($x + $pad + $index * $slot + $slot * 0.15, $y + $size - $pad - $h, $slot * 0.7, $h, $colour);
        }
    }

    /** Two cubes that traded places: the small orange one now stands above the big grey one. */
    private function flip(int $x, int $y, int $size): void
    {
        $this->c->block($x + $size * 0.16, $y + $size * 0.46, $size * 0.42, '#3A3A40');
        $this->c->block($x + $size * 0.52, $y + $size * 0.2, $size * 0.3);
        $this->c->polygon([$x + $size * 0.3, $y + $size * 0.36, $x + $size * 0.42, $y + $size * 0.18, $x + $size * 0.46, $y + $size * 0.3], Canvas::INK_2);
    }

    /** An orange cube with two red beams to the right edge. */
    private function laser(int $x, int $y, int $size): void
    {
        $this->c->block($x + $size * 0.14, $y + $size * 0.3, $size * 0.46);

        foreach ([0.42, 0.52] as $row) {
            $this->c->rect($x + $size * 0.32, $y + $size * $row, $size * 0.04, $size * 0.04, '#FF3B3B');
            $this->c->polygon([$x + $size * 0.36, $y + $size * $row, $x + $size * 0.96, $y + $size * ($row - 0.06), $x + $size * 0.96, $y + $size * ($row + 0.08), $x + $size * 0.36, $y + $size * ($row + 0.04)], '#FF3B3B');
        }
    }

    private function diamond(int $x, int $y, int $size): void
    {
        $cx = $x + $size / 2;
        $this->c->polygon([$cx - $size * 0.3, $y + $size * 0.38, $cx - $size * 0.16, $y + $size * 0.22, $cx + $size * 0.16, $y + $size * 0.22, $cx + $size * 0.3, $y + $size * 0.38, $cx, $y + $size * 0.8], '#ADADB0');
        $this->c->polygon([$cx - $size * 0.3, $y + $size * 0.38, $cx + $size * 0.3, $y + $size * 0.38, $cx, $y + $size * 0.8], '#FFFFFF');
        $this->c->polygon([$cx - $size * 0.08, $y + $size * 0.38, $cx + $size * 0.08, $y + $size * 0.38, $cx, $y + $size * 0.8], Canvas::ORANGE);
    }

    /** A stack of four blocks held. */
    private function hodl(int $x, int $y, int $size): void
    {
        foreach ([0, 1, 2, 3] as $level) {
            $this->c->block($x + $size * 0.3, $y + $size * (0.68 - 0.17 * $level), $size * 0.36, $level === 3 ? Canvas::ORANGE : '#3A3A40');
        }
    }

    /** The two scores as two blocks, the winner's orange. */
    private function scoreboard(int $x, int $y, int $size): void
    {
        $this->c->block($x + $size * 0.1, $y + $size * 0.3, $size * 0.36);
        $this->c->block($x + $size * 0.52, $y + $size * 0.3, $size * 0.36, '#3A3A40');
        $px = (int) round($size * 0.16);

        foreach ([[0, 0.1, '#17120A'], [1, 0.52, Canvas::INK]] as [$side, $left, $colour]) {
            $value = (string) $this->facts['score'][$side];
            $this->c->text($value, 'display', $px, $x + $size * $left + ($size * 0.36 - $this->c->width($value, 'display', $px)) / 2, $y + $size * 0.54, $colour);
        }
    }

    /** A row of grey blocks with the first one lit. */
    private function first(int $x, int $y, int $size): void
    {
        foreach ([0, 1, 2, 3] as $index) {
            $this->c->block($x + $size * (0.1 + 0.21 * $index), $y + $size * 0.44, $size * 0.17, $index === 0 ? Canvas::ORANGE : '#3A3A40');
        }
    }
}
