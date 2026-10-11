<?php

namespace App\Support\Cards;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Nostr\NostrKeys;
use App\Support\Players\PlayerStats;
use App\Support\Rating\RankTiers;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The pride pictures of P4 (plan "KI-Stimme der Liga"): the player card in
 * the shape of a football trading card (one shield, the rating top left,
 * the picture top right, the name, a title and six real numbers) and the
 * tale of the tape before a final (both finalists, five rows side by side).
 * Drawn on the share cards' Canvas in the frame of ShareCards.dc.html (the
 * card's own ground, the 21 tile and the host bottom left), as a link
 * preview (`wide`, 1200 × 630) and a story (`story`, 1080 × 1920).
 *
 * The title of a player card and the line under a tape come from PrideVoice:
 * the model's line once it is written and switched on, else the template
 * from PrideMoments. Like ShareCard, a card is cached per format, locale and
 * fingerprint (facts, title, layout), so a new number or a new title is a
 * new file and a new `v`.
 */
final class PrideCard
{
    public const FORMATS = ShareCard::FORMATS;

    public const TYPES = ['player', 'tape'];

    /** Bump when a layout changes: every card gets a new file and URL. */
    private const LAYOUT = 1;

    private const CARD = '#121215';

    private const LINE = '#2A2A30';

    private const BTC_HI = '#F9B25F';

    private Canvas $c;

    /**
     * @param  'player'|'tape'  $type
     * @param  array<string, mixed>  $facts
     */
    private function __construct(public readonly string $type, public readonly string $key, public readonly array $facts, public readonly string $line) {}

    /** `$stats`: the player page's own record, so its ladders are read with the page's reader. */
    public static function player(User $user, ?PlayerStats $stats = null): self
    {
        $facts = PrideMoments::player($user, $stats);

        return new self('player', (string) $user->npub, $facts, app(PrideVoice::class)->line('player', (string) $user->npub, self::playerVoiceFacts($facts), PrideMoments::playerTitle($facts)));
    }

    public static function tape(Tournament $tournament, TournamentMatch $final): self
    {
        $facts = PrideMoments::tape($tournament, $final);

        return new self('tape', (string) $tournament->id, $facts, app(PrideVoice::class)->line('tape', $tournament->id.'-'.$final->id, self::tapeVoiceFacts($facts), PrideMoments::tapeLine($facts)));
    }

    /**
     * What the model may build a player card's title from: the card's numbers and the player behind {@player}.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function playerVoiceFacts(array $facts): array
    {
        return [
            'what' => 'Titel einer Spielerkarte: ein kurzer Satz, höchstens sechs Wörter',
            'ladder' => $facts['ladder'],
            'rating' => $facts['rating'],
            'wins' => $facts['wins'],
            'results' => $facts['results'],
            'winrate_percent' => $facts['winrate'],
            'peak' => $facts['peak'],
            'blocks_this_season' => $facts['blocks'],
            'tournament_wins' => $facts['tournaments'],
            'people' => [PrideVoice::person('player', $facts)],
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public static function tapeVoiceFacts(array $facts): array
    {
        [$a, $b] = $facts['sides'];
        $side = fn (array $s): array => ['rating' => $s['rating'], 'wins' => $s['wins'], 'losses' => $s['losses'], 'wins_in_this_tournament' => $s['path'], 'tournament_wins' => $s['titles']];

        return [
            'what' => 'Eine Zeile vor dem Finale, wer auf dem Papier vorn liegt',
            'tournament' => $facts['tournament'],
            'ladder' => $facts['ladder'],
            'left' => $side($a),
            'right' => $side($b),
            'people' => [PrideVoice::person('left', $a), PrideVoice::person('right', $b)],
        ];
    }

    /** The PNG bytes in the current locale, from the cache when unchanged. */
    public function png(string $format): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException("Unknown pride card format {$format}.");
        }

        $directory = 'pride-cards/'.$this->type.'/'.$this->key;
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
        return substr(hash('sha256', (string) json_encode([self::LAYOUT, $this->type, $this->key, $format, App::getLocale(), $this->facts, $this->line, config('app.url')])), 0, 16);
    }

    /** The public URL of the card in the current locale, with its fingerprint. */
    public function url(string $format): string
    {
        return rtrim((string) config('app.url'), '/').'/cards/'.App::getLocale().'/'.$this->type.'/'.$this->key.'-'.$format.'.png?v='.$this->fingerprint($format);
    }

    /** The card's path on this site, for the page's own previews and downloads. */
    public function path(string $format): string
    {
        return substr($this->url($format), strlen(rtrim((string) config('app.url'), '/')));
    }

    public function render(string $format): string
    {
        [$width, $height] = self::FORMATS[$format] ?? throw new InvalidArgumentException("Unknown pride card format {$format}.");
        $this->c = new Canvas($width, $height);
        $story = $format === 'story';

        match ($this->type) {
            'player' => $story ? $this->playerStory() : $this->playerWide(),
            'tape' => $story ? $this->tapeStory() : $this->tapeWide(),
        };

        if ($story) {
            $this->c->footer(72, 1780, 64, 1008);
        } else {
            $this->c->footer(64, 540, 44, 1136);
        }

        return $this->c->png();
    }

    /* ---------- Player card --------------------------------------------------------------------------------------- */

    private function playerWide(): void
    {
        $f = $this->facts;
        // The art on the right, the words on the left, the mark bottom left (ShareCards.dc.html's one frame).
        $this->shield(754, 26, 0.49);

        $x = 64;
        $max = 642;
        $this->c->text(__('Player card'), 'mono', 24, $x, 92, Canvas::INK_2);
        $size = $this->bestSize($this->line, 'display', [52, 46, 40, 34], $max, 2);
        $baseline = $this->c->paragraph($this->line, 'display', $size, $x, 92 + $size * 1.5, $max, 2, Canvas::INK, 1.15);
        $this->c->text($this->c->fit((string) $f['name'], 'mono-bold', 28, $max), 'mono-bold', 28, $x, $baseline + 20, Canvas::INK);
        $this->c->paragraph($this->recordLine(), 'mono', 24, $x, $baseline + 72, $max, 2, Canvas::INK_2);

        if ($f['season'] !== null) {
            $this->c->block($x, 430, 30);
            $this->c->text($this->c->fit($this->seasonLine(), 'mono', 24, $max - 52), 'mono', 24, $x + 52, 454, Canvas::INK_2);
        }
    }

    private function playerStory(): void
    {
        $this->c->text(__('Player card'), 'mono', 36, 72, 150, Canvas::INK_2);
        $this->shield(150, 220, 1.0);
        $this->c->paragraph($this->recordLine(), 'mono', 32, 72, 1490, 936, 2, Canvas::INK_2);

        if ($this->facts['season'] !== null) {
            $this->c->block(72, 1590, 40);
            $this->c->text($this->c->fit($this->seasonLine(), 'mono', 32, 860), 'mono', 32, 140, 1622, Canvas::INK_2);
        }
    }

    /**
     * The card itself, 780 × 1180 at scale 1: the shield in the tier's colour,
     * the rating, tier and ladder top left, the picture top right, the name,
     * the title, then six numbers in two columns (value and word, as on a
     * trading card) and a block at the tip.
     */
    private function shield(int|float $x, int|float $y, float $s): void
    {
        $f = $this->facts;
        [$w, $h] = [780 * $s, 1180 * $s];
        $colour = $f['tier'] !== null && ! $f['provisional'] ? RankTiers::colour((string) $f['tier']) : Canvas::INK_3;
        $p = fn (float $v): float => $v * $s;
        $px = fn (int $v): int => max(12, (int) round($v * $s));

        // Rim in the tier colour, the card inside, a lit top fading down over a third of the card.
        $this->c->polygon($this->shieldPoints($x, $y, $w, $h, 0), $colour);
        $this->c->polygon($this->shieldPoints($x, $y, $w, $h, $p(10)), self::CARD);
        $inset = $p(10);
        $shoulder = $y + 0.08 * $h + $inset * 0.6;
        $bands = 24;
        $band = 0.34 * $h / $bands;
        // The chamfered top first, then straight bands below it, each a step darker (GD has no gradient fill).
        $this->c->polygon([$x + 0.13 * $w + $inset * 0.4, $y + $inset, $x + 0.87 * $w - $inset * 0.4, $y + $inset, $x + $w - $inset, $shoulder, $x + $inset, $shoulder], $this->c->mix(self::CARD, $colour, 0.18));
        for ($i = 0; $i < $bands; $i++) {
            $this->c->rect($x + $inset, $shoulder + $i * $band, $w - 2 * $inset, $band + 1, $this->c->mix(self::CARD, $colour, 0.18 * (1 - $i / $bands)));
        }

        // Rating, tier, ladder: the column left of the picture.
        $left = $x + $p(72);
        $column = (int) round($p(360));
        $rating = $f['rating'] === null ? '–' : (string) $f['rating'];
        $this->c->text($rating, 'display', $this->c->fitSize($rating, 'display', [$px(140), $px(124), $px(108), $px(92)], $column), $left, $y + $p(230), Canvas::INK);
        $tier = $f['tier'] === null ? __('Casual') : ($f['provisional'] ? __('Provisional') : RankTiers::label((string) $f['tier']));
        $this->c->text($this->c->fit($tier, 'mono-bold', $px(34), (int) $p(330)), 'mono-bold', $px(34), $left, $y + $p(290), $colour);
        if ($f['ladder'] !== null) {
            $this->c->text($this->c->fit((string) $f['ladder'], 'mono', $px(28), (int) $p(330)), 'mono', $px(28), $left, $y + $p(336), Canvas::INK_2);
        }

        // The picture, in a frame of the ground colour (the avatar's rounded corners are filled with it).
        $side = (int) round($p(240));
        [$ax, $ay] = [$x + $w - $p(64) - $side, $y + $p(96)];
        $this->c->rect($ax - $p(8), $ay - $p(8), $side + $p(16), $side + $p(16), Canvas::GROUND);
        $this->c->avatar($this->drawable($f), $ax, $ay, $side);

        // Name and title, centred.
        $inner = (int) round($w - $p(144));
        $name = (string) $f['name'];
        $nameSize = $this->c->fitSize($name, 'display', [$px(64), $px(56), $px(48), $px(40)], $inner);
        $name = $this->c->fit($name, 'display', $nameSize, $inner);
        $this->c->text($name, 'display', $nameSize, $x + ($w - $this->c->width($name, 'display', $nameSize)) / 2, $y + $p(500), Canvas::INK);
        $titleSize = $px(30);
        $baseline = $y + $p(556);
        foreach ($this->c->lines($this->line, 'mono-bold', $titleSize, $inner, 2) as $titleLine) {
            $this->c->text($titleLine, 'mono-bold', $titleSize, $x + ($w - $this->c->width($titleLine, 'mono-bold', $titleSize)) / 2, $baseline, self::BTC_HI);
            $baseline += $titleSize * 1.3;
        }

        // Six numbers: value and word, two columns of three, a hairline between them.
        $this->c->rect($x + $p(72), $y + $p(650), $w - $p(144), max(1, $p(2)), self::LINE);
        $this->c->rect($x + $w / 2 - max(1, $p(1)), $y + $p(690), max(1, $p(2)), $p(300), self::LINE);
        $cells = [
            [$this->number($f['wins']), trans_choice('win|wins', (int) $f['wins'])],
            [$f['winrate'] === null ? '–' : $f['winrate'].'%', __('won')],
            [$f['peak'] === null ? '–' : (string) $f['peak'], __('peak')],
            [$this->number($f['blocks']), trans_choice('block|blocks', (int) $f['blocks'])],
            [$this->number($f['results']), trans_choice('game|games', (int) $f['results'])],
            [$this->number($f['tournaments']), trans_choice('title|titles', (int) $f['tournaments'])],
        ];
        $cell = (int) round($w / 2 - $p(72) - $p(24));
        foreach ($cells as $index => [$value, $word]) {
            $cx = $x + ($index < 3 ? $p(72) : $w / 2 + $p(36));
            $cy = $y + $p(770 + ($index % 3) * 96);
            $valueSize = $this->c->fitSize($value, 'display', [$px(50), $px(44), $px(38)], (int) round($cell * 0.6));
            $this->c->text($value, 'display', $valueSize, $cx, $cy, Canvas::INK);
            $gap = $this->c->width($value, 'display', $valueSize) + $p(14);
            $this->c->text($this->c->fit($word, 'mono', $px(26), (int) round($cell - $gap)), 'mono', $px(26), $cx + $gap, $cy, Canvas::INK_2);
        }

        // The tip: one mined block, the league's mark.
        $this->c->block($x + $w / 2 - $p(26), $y + $h - $p(150), $p(52));
    }

    /**
     * @return list<float>
     */
    private function shieldPoints(float $x, float $y, float $w, float $h, float $d): array
    {
        return [
            $x + 0.13 * $w + $d * 0.4, $y + $d,
            $x + 0.87 * $w - $d * 0.4, $y + $d,
            $x + $w - $d, $y + 0.08 * $h + $d * 0.6,
            $x + $w - $d, $y + 0.84 * $h - $d * 0.3,
            $x + 0.5 * $w, $y + $h - $d * 1.2,
            $x + $d, $y + 0.84 * $h - $d * 0.3,
            $x + $d, $y + 0.08 * $h + $d * 0.6,
        ];
    }

    private function recordLine(): string
    {
        $f = $this->facts;

        if ($f['results'] === 0) {
            return __('No rated or casual result yet.');
        }

        return implode(', ', array_filter([
            trans_choice(':count win|:count wins', $f['wins']),
            $f['draws'] > 0 ? trans_choice(':count draw|:count draws', $f['draws']) : null,
            trans_choice(':count loss|:count losses', $f['losses']),
        ]));
    }

    private function seasonLine(): string
    {
        $f = $this->facts;

        return __(':season: :blocks blocks, :sats sats', ['season' => BadgeCopy::season((string) $f['season']), 'blocks' => $f['blocks'], 'sats' => ShareCard::sats((int) $f['sats'])]);
    }

    /* ---------- Tale of the tape ---------------------------------------------------------------------------------- */

    private function tapeWide(): void
    {
        $f = $this->facts;
        $title = __('Final: :tournament', ['tournament' => $f['tournament']]);
        $this->c->text($this->c->fit($title, 'mono-bold', 26, 1072), 'mono-bold', 26, 64, 76, Canvas::ORANGE);
        $this->tapeHeads(64, 104, 1072, 88, 28);
        $this->tapeRows(64, 300, 1072, 46, 22, 32);
    }

    private function tapeStory(): void
    {
        $f = $this->facts;
        $this->c->text(__('Tale of the tape'), 'mono', 36, 72, 150, Canvas::INK_2);
        $this->c->paragraph(__('Final: :tournament', ['tournament' => $f['tournament']]), 'mono-bold', 40, 72, 216, 936, 2, Canvas::ORANGE);
        $this->tapeHeads(72, 320, 936, 200, 44);
        $this->tapeRows(72, 820, 936, 120, 30, 64);
        $this->c->paragraph($this->line, 'display', 44, 72, 1520, 936, 3, Canvas::INK, 1.2);
    }

    /** Both finalists: picture and name on their side, "VS" between them. */
    private function tapeHeads(int $x, int $y, int $width, int $avatar, int $px): void
    {
        $sides = $this->facts['sides'];
        $column = (int) round($width * 0.4);
        $this->c->avatar($this->drawable($sides[0]), $x, $y, $avatar);
        $this->c->avatar($this->drawable($sides[1]), $x + $width - $avatar, $y, $avatar);

        $nameY = $y + $avatar + $px * 1.6;
        foreach ([0, 1] as $i) {
            $size = $this->c->fitSize((string) $sides[$i]['name'], 'display', [$px, (int) round($px * 0.85), (int) round($px * 0.72)], $column);
            $name = $this->c->fit((string) $sides[$i]['name'], 'display', $size, $column);
            $i === 0 ? $this->c->text($name, 'display', $size, $x, $nameY, Canvas::INK) : $this->c->textRight($name, 'display', $size, $x + $width, $nameY, Canvas::INK);
        }

        $vs = (int) round($px * 2.2);
        $this->c->text('VS', 'display', $vs, $x + ($width - $this->c->width('VS', 'display', $vs)) / 2, $y + $avatar * 0.5 + $vs * 0.38, Canvas::ORANGE);
    }

    /**
     * Five rows: the word in the middle, each side's value on its side, the
     * leading value bright with a bar under it (not the colour alone).
     */
    private function tapeRows(int $x, int $y, int $width, int $step, int $label, int $value): void
    {
        foreach ($this->tapeTable() as $index => [$word, $values, $lead]) {
            $baseline = $y + $index * $step;
            $this->c->rect($x, $baseline + $step * 0.32, $width, 1, self::LINE);
            $this->c->text($word, 'mono', $label, $x + ($width - $this->c->width($word, 'mono', $label)) / 2, $baseline, Canvas::INK_2);

            foreach ([0, 1] as $i) {
                $colour = $lead === $i ? self::BTC_HI : ($lead === null ? Canvas::INK : Canvas::INK_2);
                $w = $this->c->width($values[$i], 'display', $value);
                $vx = $i === 0 ? $x : $x + $width - $w;
                $this->c->text($values[$i], 'display', $value, $vx, $baseline + $value * 0.18, $colour);

                if ($lead === $i) {
                    $this->c->rect($vx, $baseline + $value * 0.18 + $value * 0.22, $w, max(3, (int) round($value / 10)), Canvas::ORANGE);
                }
            }
        }
    }

    /**
     * The rows of the tape: word, both values, the leading side.
     *
     * @return list<array{0: string, 1: array{0: string, 1: string}, 2: int|null}>
     */
    public function tapeTable(): array
    {
        $f = $this->facts;
        [$a, $b] = $f['sides'];
        $record = fn (array $s): string => $s['results'] === 0 ? '–' : $s['wins'].'–'.$s['losses'];

        return [
            [__('Elo'), [(string) $a['rating'], (string) $b['rating']], PrideMoments::tapeLead($f, 'rating')],
            [__('Wins–losses'), [$record($a), $record($b)], PrideMoments::tapeLead($f, 'record')],
            [__('Wins in this tournament'), [(string) $a['path'], (string) $b['path']], PrideMoments::tapeLead($f, 'path')],
            [__('Tournaments won'), [$a['titles'] === null ? '–' : (string) $a['titles'], $b['titles'] === null ? '–' : (string) $b['titles']], PrideMoments::tapeLead($f, 'titles')],
            [__('Seed'), [$a['seed'] === null ? '–' : '#'.$a['seed'], $b['seed'] === null ? '–' : '#'.$b['seed']], PrideMoments::tapeLead($f, 'seed')],
        ];
    }

    /* ---------- Pieces -------------------------------------------------------------------------------------------- */

    /**
     * The largest size at which the text takes at most `$lines` lines without a cut.
     *
     * @param  list<int>  $sizes
     */
    private function bestSize(string $text, string $face, array $sizes, int $max, int $lines): int
    {
        foreach ($sizes as $size) {
            $count = $this->c->lineCount($text, $face, $size, $max);

            if ($count !== null && $count <= $lines) {
                return $size;
            }
        }

        return (int) end($sizes);
    }

    private function number(mixed $value): string
    {
        return ShareCard::sats((int) $value);
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function drawable(array $person): User
    {
        return (new User)->forceFill([
            'pubkey' => NostrKeys::isHexPubkey($person['pubkey'] ?? null) ? $person['pubkey'] : str_repeat('0', 64),
            'avatar_path' => $person['avatar_path'] ?? null,
        ]);
    }
}
