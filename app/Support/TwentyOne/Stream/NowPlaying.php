<?php

namespace App\Support\TwentyOne\Stream;

/**
 * The card of the music on air for the slides (SceneRenderer, partials/now-playing-column): which file the music timeline
 * plays at a moment (MusicTimeline::tracks(), the file the daemon writes as it shuffles), its credit (MusicCredits),
 * the artist's avatar as the image builder cached it (StreamImages::musicAvatar()) and the code drawn for the target.
 * Null while nothing credited plays (a gap, a track without a credit): the slide stays as it is. The card of a file
 * is built once and kept, its avatar asked anew each time (the image builder fetches it within ten minutes of a new
 * credit; a card kept without it would never show it, user 2026-10-15: "man sieht den Avatar … nicht"); a code that
 * was never drawn means no card, never a broken box.
 */
final class NowPlaying
{
    /** @var array<string, array{artist: string, title: string|null, picture: string|null, qr: string, kind: string}|null> */
    private array $cards = [];

    public function __construct(private StreamImages $images, private string $timelineFile) {}

    public static function fromConfig(): self
    {
        return new self(app(StreamImages::class), (string) config('twentyone.stream.music.timeline_file'));
    }

    /**
     * The card at `$now` (unix seconds), null without a credited track.
     *
     * @return array{artist: string, title: string|null, avatar: string|null, qr: string, kind: string}|null
     */
    public function at(float $now): ?array
    {
        $file = MusicTimeline::tracks($this->timelineFile, $now, $now + 0.001)[0]['file'] ?? null;

        return $file === null ? null : $this->card($file);
    }

    /**
     * @return array{artist: string, title: string|null, avatar: string|null, qr: string, kind: string}|null
     */
    public function card(string $file): ?array
    {
        if (! array_key_exists($file, $this->cards)) {
            $credit = MusicCredits::forFile($file);
            $qrFile = $credit === null ? null : MusicCredits::qrFile($credit['target'], $credit['kind']);
            $qr = $qrFile !== null && is_file($qrFile) ? (string) file_get_contents($qrFile) : '';
            $this->cards[$file] = $credit === null || $qr === '' ? null : ['artist' => $credit['artist'], 'title' => $credit['title'], 'picture' => $credit['picture'], 'qr' => $qr, 'kind' => $credit['kind']];
        }

        $card = $this->cards[$file];

        return $card === null ? null : ['artist' => $card['artist'], 'title' => $card['title'], 'avatar' => $this->images->musicAvatar($card['picture']), 'qr' => $card['qr'], 'kind' => $card['kind']];
    }
}
