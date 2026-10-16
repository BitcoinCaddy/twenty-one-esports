<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Chromaprint fingerprints of the music library (`fpcalc -raw -json`, the first 120 s of a file): how they are stored
 * and how alike two of them are (plan "Musik-Katalog": duplicates under another name, e.g. "Alone In Valentine - Joe
 * Martin" and "Alone In Valentine - single - Joe Martin", or a PlebRap song copied under instrumental/ as "120__v1").
 *
 * Similarity is the share of equal bits over the aligned part of two fingerprints (1 − popcount(a XOR b) / bits), at
 * the best of a few offsets. The same recording, re-encoded or not, scores about 0.97 to 1.0; unrelated songs about
 * 0.45 to 0.6 (measured on the library 2026-10-16, see SAME_RECORDING).
 *
 * Comparing every pair at every offset is too slow in PHP for a library of 300 songs, so candidates come first from an
 * index of the fingerprints' upper bits (the AcoustID way): two copies of one recording share hundreds of exact values
 * at one offset, two unrelated songs a handful at random offsets.
 */
final class Chromaprint
{
    /**
     * At or above this similarity two fingerprints are the same recording. Measured on the 314 library files of the
     * prod mirror 2026-10-16, every one of the 49,141 pairs at offsets ±30 over the first 120 s: 42 pairs scored 0.967
     * to 1.0 (38 PlebRap songs copied under instrumental/, four Joe Martin and Under The Mulga Groove copies), the best
     * other pair 0.646 (two versions of one DJ Bitcoin title), median 0.515, 99th percentile 0.562; no pair at all
     * between 0.65 and 0.96. The threshold sits in the middle of that gap.
     */
    public const SAME_RECORDING = 0.85;

    /** Offsets (in fingerprint items, about 0.124 s each) a comparison tries on either side. */
    public const MAX_OFFSET = 30;

    /** Aligned items below which a comparison says nothing (a few seconds of overlap). */
    private const MIN_OVERLAP = 40;

    /** @var array<int, int>|null */
    private static ?array $bits = null;

    /**
     * The compact form stored next to the catalog: the items as little-endian uint32, base64.
     *
     * @param  list<int>  $items
     */
    public static function encode(array $items): string
    {
        return base64_encode(pack('V*', ...$items));
    }

    /**
     * @return list<int>
     */
    public static function decode(string $encoded): array
    {
        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '' || strlen($bytes) % 4 !== 0) {
            return [];
        }

        $items = unpack('V*', $bytes);

        return $items === false ? [] : array_values(array_map('intval', $items));
    }

    /**
     * The short hash the catalog carries per track (the items themselves live in fingerprints.json).
     *
     * @param  list<int>  $items
     */
    public static function hash(array $items): string
    {
        return substr(sha1(pack('V*', ...$items)), 0, 16);
    }

    /**
     * How alike two fingerprints are at their best offset within ±$maxOffset around $around, 0.0 without overlap.
     *
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @return array{similarity: float, offset: int}
     */
    public static function compare(array $a, array $b, int $maxOffset = self::MAX_OFFSET, int $around = 0): array
    {
        $best = ['similarity' => 0.0, 'offset' => 0];

        for ($offset = $around - $maxOffset; $offset <= $around + $maxOffset; $offset++) {
            $similarity = self::at($a, $b, $offset);

            if ($similarity > $best['similarity']) {
                $best = ['similarity' => $similarity, 'offset' => $offset];
            }
        }

        return $best;
    }

    /**
     * Pairs worth comparing: per pair of keys the offset (b against a) at which most upper-bit values agree, and how
     * many did. Only pairs with at least $minVotes at one offset are returned.
     *
     * @param  array<string, list<int>>  $fingerprints
     * @return list<array{0: string, 1: string, offset: int, votes: int}>
     */
    public static function candidates(array $fingerprints, int $minVotes = 8): array
    {
        // Postings as one int each (file number << 16 | position): an array per posting costs ~100 MB for the library.
        $keys = array_keys($fingerprints);
        $index = [];

        foreach ($keys as $number => $key) {
            foreach ($fingerprints[$key] as $position => $item) {
                $index[$item >> 12][] = ($number << 16) | $position;
            }
        }

        $votes = [];

        foreach ($index as $postings) {
            $count = count($postings);

            // A value half the library shares (silence, a fade) says nothing about any pair.
            if ($count < 2 || $count > 64) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $numberA = $postings[$i] >> 16;
                    $numberB = $postings[$j] >> 16;

                    if ($numberA === $numberB) {
                        continue;
                    }

                    // a[posA] equals b[posB]: b sits (posA - posB) items later against a, with a the lower number.
                    [$first, $second, $offset] = $numberA < $numberB
                        ? [$numberA, $numberB, ($postings[$i] & 0xFFFF) - ($postings[$j] & 0xFFFF)]
                        : [$numberB, $numberA, ($postings[$j] & 0xFFFF) - ($postings[$i] & 0xFFFF)];
                    $pair = $first * 100000 + $second;
                    $votes[$pair][$offset] = ($votes[$pair][$offset] ?? 0) + 1;
                }
            }
        }

        $candidates = [];

        foreach ($votes as $pair => $byOffset) {
            $best = max($byOffset);

            if ($best >= $minVotes) {
                $candidates[] = [(string) $keys[intdiv($pair, 100000)], (string) $keys[$pair % 100000], 'offset' => (int) array_search($best, $byOffset, true), 'votes' => $best];
            }
        }

        return $candidates;
    }

    /**
     * Similarity with b shifted by $offset items against a (a[i] against b[i - offset]).
     *
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private static function at(array $a, array $b, int $offset): float
    {
        $start = max(0, $offset);
        $end = min(count($a), count($b) + $offset);

        if ($end - $start < self::MIN_OVERLAP) {
            return 0.0;
        }

        $bits = self::$bits ??= self::bitTable();
        $differing = 0;

        for ($i = $start; $i < $end; $i++) {
            $x = $a[$i] ^ $b[$i - $offset];
            $differing += $bits[$x & 0xFFFF] + $bits[($x >> 16) & 0xFFFF];
        }

        return 1.0 - $differing / (32 * ($end - $start));
    }

    /**
     * Set bits per 16-bit value.
     *
     * @return array<int, int>
     */
    private static function bitTable(): array
    {
        $table = [0];

        for ($i = 1; $i < 65536; $i++) {
            $table[$i] = ($i & 1) + $table[$i >> 1];
        }

        return $table;
    }
}
