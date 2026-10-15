<?php

namespace App\Support\TwentyOne\Stream;

/**
 * The card of the music on air for the slides (SceneRenderer, partials/now-playing-column): which file the music timeline
 * plays at a moment (MusicTimeline::tracks(), the file the daemon writes as it shuffles), its credit (MusicCredits),
 * the artist's avatar as the image builder cached it (StreamImages::musicAvatar()) and the code drawn for the target.
 * Null while nothing credited plays (the league's own songs, the instrumentals, a gap): the slide stays as it is.
 * The card of a file is built once and kept; a code that was never drawn means no card, never a broken box.
 */
final class NowPlaying
{
    /** @var array<string, array{artist: string, title: string|null, avatar: string|null, qr: string, kind: string}|null> */
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
        if (array_key_exists($file, $this->cards)) {
            return $this->cards[$file];
        }

        $credit = MusicCredits::forFile($file);
        $qrFile = $credit === null ? null : MusicCredits::qrFile($credit['target'], $credit['kind']);
        $qr = $qrFile !== null && is_file($qrFile) ? (string) file_get_contents($qrFile) : '';

        return $this->cards[$file] = $credit === null || $qr === '' ? null : [
            'artist' => $credit['artist'],
            'title' => $credit['title'],
            'avatar' => $this->images->musicAvatar($credit['picture']),
            'qr' => $qr,
            'kind' => $credit['kind'],
        ];
    }
}
