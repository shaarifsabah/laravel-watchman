<?php

declare(strict_types=1);

namespace Watchman\StringScore\Algorithms;

/**
 * Kondrak N-SIM (SPIRE 2005): strings are affixed by repeating the first
 * character n-1 times; positional n-gram credit is the fraction of matching
 * character positions; normalised by max(len) in runes.
 *
 * Port of github.com/PhonoGrams/ngram v0.1.0 (NSim), Apache License 2.0.
 * Always operates on Go-decoded runes, as upstream does.
 */
final class NGram
{
    private function __construct()
    {
    }

    /** Kondrak n-gram similarity in [0, 1]. n < 1 is treated as 1. */
    public static function nsim(string $s1, string $s2, int $n): float
    {
        if ($n < 1) {
            $n = 1;
        }
        $s = GoUnicode::runes($s1);
        $t = GoUnicode::runes($s2);
        $k = \count($s);
        $l = \count($t);
        if ($k === 0 && $l === 0) {
            return 1.0;
        }
        if ($k === 0 || $l === 0) {
            return 0.0;
        }

        $nf = (float) $n;
        $prev = array_fill(0, $l + 1, 0.0);
        $curr = $prev;
        for ($i = 1; $i <= $k; $i++) {
            $curr[0] = 0.0;
            for ($j = 1; $j <= $l; $j++) {
                $hits = 0;
                for ($p = 0; $p < $n; $p++) {
                    $si = $i - $n + $p; // i - 1 - (n - 1) + p
                    $ti = $j - $n + $p;
                    if (($si < 0 ? $s[0] : $s[$si]) === ($ti < 0 ? $t[0] : $t[$ti])) {
                        $hits++;
                    }
                }
                $diag = $prev[$j - 1] + $hits / $nf;
                $x = $prev[$j];
                $y = $curr[$j - 1];
                $curr[$j] = ($x >= $y && $x >= $diag) ? $x : ($y >= $diag ? $y : $diag);
            }
            [$prev, $curr] = [$curr, $prev];
        }
        $denom = (float) ($l > $k ? $l : $k);
        $sim = $prev[$l] / $denom;
        if ($sim < 0) {
            return 0.0;
        }
        if ($sim > 1) {
            return 1.0;
        }

        return $sim;
    }
}
