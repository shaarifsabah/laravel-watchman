<?php

declare(strict_types=1);

namespace Watchman\StringScore\Algorithms;

/**
 * Soft-Bisim (Millán-Hernández et al., MCPR 2019): Kondrak BI-SIM with a
 * nine-case bigram similarity scale, using DefaultWeights.
 *
 * Port of github.com/PhonoGrams/soft-bisim v0.1.0 (package soft_bisim,
 * Similarity), Apache License 2.0.
 *
 * Go scores ASCII input byte-wise and anything else over []rune; both reduce to
 * scoring Go-decoded runes, which is what this port does.
 */
final class SoftBisim
{
    // DefaultWeights (Millán-Hernández Table 1 with Exact/AllEqual raised to 1).
    private const SECOND_MATCH = 0.0;
    private const ALL_DIFF = 0.1;
    private const REPEAT3 = 0.4;
    private const TRANSPOSE = 0.0;
    private const CROSS = 0.0;
    private const REPEAT6 = 0.2;
    private const FIRST_MATCH = 0.4;
    private const ALL_EQUAL = 1.0;
    private const EXACT = 1.0;

    private function __construct()
    {
    }

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

        $prev = array_fill(0, $m + 1, 0.0);
        $curr = $prev;
        for ($i = 1; $i <= $n; $i++) {
            $curr[0] = 0.0;
            // Kondrak first-letter affix: bigram 1 is (s[0], s[0]).
            $a1 = $i === 1 ? $s[0] : $s[$i - 2];
            $a2 = $s[$i - 1];
            for ($j = 1; $j <= $m; $j++) {
                $b1 = $j === 1 ? $t[0] : $t[$j - 2];
                $b2 = $t[$j - 1];
                $diag = $prev[$j - 1] + self::bigramSim($a1, $a2, $b1, $b2);
                $x = $prev[$j];
                $y = $curr[$j - 1];
                $curr[$j] = ($x >= $y && $x >= $diag) ? $x : ($y >= $diag ? $y : $diag);
            }
            [$prev, $curr] = [$curr, $prev];
        }
        $denom = (float) ($m > $n ? $m : $n);
        $sim = $prev[$m] / $denom;
        if ($sim < 0) {
            return 0.0;
        }
        if ($sim > 1) {
            return 1.0;
        }

        return $sim;
    }

    /** Classifies (a1 a2) vs (b1 b2) into the nine-case scale (upstream order). */
    private static function bigramSim(int $a1, int $a2, int $b1, int $b2): float
    {
        $eq11 = $a1 === $b1;
        $eq22 = $a2 === $b2;
        $eq12 = $a1 === $b2;
        $eq21 = $a2 === $b1;
        $sameA = $a1 === $a2;
        $sameB = $b1 === $b2;

        if ($eq11 && $eq22 && $sameA) {
            return self::ALL_EQUAL;
        }
        if ($eq11 && $eq22) {
            return self::EXACT;
        }
        if ($eq12 && $eq21) {
            return self::TRANSPOSE;
        }
        if (($sameA && $eq11 && !$eq22) || ($eq11 && $sameB && $a1 !== $a2)) {
            return self::REPEAT3;
        }
        if (($sameA && $eq12 && !$eq11) || ($eq21 && $sameB && !$eq11)) {
            return self::REPEAT6;
        }
        if ($eq11 && !$eq22) {
            return self::FIRST_MATCH;
        }
        if ($eq22 && !$eq11) {
            return self::SECOND_MATCH;
        }
        if (($eq12 && !$eq21) || ($eq21 && !$eq12)) {
            return self::CROSS;
        }

        return self::ALL_DIFF;
    }
}
