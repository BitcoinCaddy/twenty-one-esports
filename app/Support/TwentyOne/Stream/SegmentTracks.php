<?php

namespace App\Support\TwentyOne\Stream;

/**
 * How many tracks an fMP4 file carries (the audio watchdog, TwentyOneStreamCommand):
 * an init file declares one `trex` per track in `moov/mvex`; a media segment
 * carries one `traf` with samples per track in its `moof`. Only the box
 * headers are walked, nothing is decoded.
 */
final class SegmentTracks
{
    /** More than any init or moof needs; the mdat behind the moof is never read. */
    private const MAX_BYTES = 1_048_576;

    /** Tracks declared (init) or carried with samples (segment); null when the file has neither box or cannot be read. */
    public static function count(string $path): ?int
    {
        $data = @file_get_contents($path, false, null, 0, self::MAX_BYTES);

        if (! is_string($data) || $data === '') {
            return null;
        }

        foreach (self::boxes($data, 0, strlen($data)) as [$type, $start, $end]) {
            if ($type === 'moov') {
                foreach (self::boxes($data, $start, $end) as [$inner, $innerStart, $innerEnd]) {
                    if ($inner === 'mvex') {
                        return count(array_filter(self::boxes($data, $innerStart, $innerEnd), fn (array $box): bool => $box[0] === 'trex'));
                    }
                }
            }

            if ($type === 'moof') {
                $tracks = 0;

                foreach (self::boxes($data, $start, $end) as [$inner, $innerStart, $innerEnd]) {
                    if ($inner === 'traf' && self::samples($data, $innerStart, $innerEnd) > 0) {
                        $tracks++;
                    }
                }

                return $tracks;
            }
        }

        return null;
    }

    /** The sample count of a traf's trun boxes. */
    private static function samples(string $data, int $start, int $end): int
    {
        $samples = 0;

        foreach (self::boxes($data, $start, $end) as [$type, $boxStart, $boxEnd]) {
            if ($type === 'trun' && $boxEnd - $boxStart >= 8) {
                // version and flags (4 bytes), then sample_count.
                $samples += self::uint32($data, $boxStart + 4);
            }
        }

        return $samples;
    }

    /**
     * The boxes between `$start` and `$end`: type, payload start, payload end.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function boxes(string $data, int $start, int $end): array
    {
        $boxes = [];
        $offset = $start;

        while ($offset + 8 <= $end) {
            $size = self::uint32($data, $offset);
            $type = substr($data, $offset + 4, 4);
            $header = 8;

            if ($size === 1 && $offset + 16 <= $end) {
                // A large box: the 64-bit size follows the header.
                $size = (int) (((array) unpack('J', $data, $offset + 8))[1] ?? 0);
                $header = 16;
            } elseif ($size === 0) {
                $size = $end - $offset;
            }

            if ($size < $header) {
                break;
            }

            $boxes[] = [$type, $offset + $header, min($offset + $size, $end)];
            $offset += $size;
        }

        return $boxes;
    }

    private static function uint32(string $data, int $offset): int
    {
        return (int) (((array) unpack('N', $data, $offset))[1] ?? 0);
    }
}
