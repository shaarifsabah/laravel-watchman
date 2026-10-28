<?php

declare(strict_types=1);

namespace Watchman\StringScore\Algorithms;

/**
 * Soft-Bidist (Hadwan et al., PeerJ CS 2021): Kondrak BI-DIST with a nine-case
 * cost scale over adjacent character pairs, using DefaultWeights.
 *
 * Port of github.com/PhonoGrams/soft_bigram v0.2.0 (Similarity), Apache License 2.0.
 *
 * Go scores ASCII input byte-wise and anything else over []rune; both reduce to
 * scoring Go-decoded runes, which is what this port does (invalid UTF-8 bytes
 * become U+FFFD, as in Go).
 */
final class SoftBigram
{
    /** NUL sentinel prepended so the first character forms a bigram. */
    private const PREFIX_SENTINEL = 0;

    // DefaultWeights (paper Table 2 row 16).
    private const MATCH = 0.0;
    private const ALL_DIFF = 1.0;
    private const TRANSPOSE = 0.0;
    private const SECOND_MATCH = 0.2;
    private const FIRST_MATCH = 0.2;
    private const CROSS_SECOND = 1.0;
    private const CROSS_FIRST = 1.0;
    private const INSERT = 0.5;
    private const DELETE = 0.5;

    private function __construct()
    {
    }

    /** 1 - Distance / max(len(a), len(b)) in runes, clamped to [0, 1]. */
    public static function similarity(string $s1, string $s2): float
    {
        $s = GoUnicode::runes($s1);
        $t = GoUnicode::runes($s2);
        $n = \count($s);
        $m = \count($t);
        if ($n === 0 && $m === 0) {
            return 1.0;
        }
        if ($n === 0 || $m === 0) {
            return 0.0;
        }
        $d = self::distance($s, $t);
        $denom = (float) ($m > $n ? $m : $n);
        $sim = 1.0 - $d / $denom;
        if ($sim < 0) {
            return 0.0;
        }
        if ($sim > 1) {
            return 1.0;
        }

        return $sim;
    }

    /**
     * @param list<int> $s
     * @param list<int> $t
     */
    private static function distance(array $s, array $t): float
    {
        $n = \count($s);
        $m = \count($t);
        // Callers guarantee n > 0 and m > 0.
        $prev = [];
        for ($j = 0; $j <= $m; $j++) {
            $prev[$j] = $j * self::INSERT;
        }
        $curr = $prev;
        for ($i = 1; $i <= $n; $i++) {
            $curr[0] = $i * self::DELETE;
            $a1 = $i === 1 ? self::PREFIX_SENTINEL : $s[$i - 2];
            $a2 = $s[$i - 1];
            for ($j = 1; $j <= $m; $j++) {
                $b1 = $j === 1 ? self::PREFIX_SENTINEL : $t[$j - 2];
                $b2 = $t[$j - 1];
                $del = $prev[$j] + self::DELETE;
                $ins = $curr[$j - 1] + self::INSERT;
                $sub = $prev[$j - 1] + self::substCost($a1, $a2, $b1, $b2);
                $curr[$j] = ($del <= $ins && $del <= $sub) ? $del : ($ins <= $sub ? $ins : $sub);
            }
            [$prev, $curr] = [$curr, $prev];
        }

        return $prev[$m];
    }

    /** Paper Eq. (6), evaluated in the upstream order. */
    private static function substCost(int $a1, int $a2, int $b1, int $b2): float
    {
        if ($a1 === $b1 && $a2 === $b2) {
            return self::MATCH;
        }
        if ($a1 !== $b1 && $a2 !== $b2 && $a1 !== $b2 && $a2 !== $b1) {
            return self::ALL_DIFF;
        }
        if ($a1 === $b2 && $a2 === $b1) {
            return self::TRANSPOSE;
        }
        if ($a1 !== $b1 && $a2 === $b2) {
            return self::SECOND_MATCH;
        }
        if ($a1 === $b1 && $a2 !== $b2) {
            return self::FIRST_MATCH;
        }
        if ($a1 !== $b2 && $a2 === $b1) {
            return self::CROSS_SECOND;
        }
        if ($a1 === $b2 && $a2 !== $b1) {
            return self::CROSS_FIRST;
        }

        return 1.0;
    }
}
