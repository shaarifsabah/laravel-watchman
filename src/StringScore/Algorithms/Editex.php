<?php

declare(strict_types=1);

namespace Watchman\StringScore\Algorithms;

/**
 * Editex (Zobel & Dart, SIGIR 1996): Levenshtein with phonetic letter groups.
 * Match cost 0, same-group cost 1, mismatch cost 2.
 *
 * Port of github.com/PhonoGrams/editex v0.1.0 (Similarity), Apache License 2.0.
 *
 * Operates on Go-decoded runes; case folding is Go's unicode.ToUpper per rune
 * (so e.g. "ı" and "ſ" fold to "I" / "S" and join their letter groups).
 */
final class Editex
{
    private const MATCH_COST = 0;
    private const GROUP_COST = 1;
    private const MISMATCH_COST = 2;

    private const G_VOWEL = 1;
    private const G_BP = 2;
    private const G_CKQ = 4;
    private const G_DT = 8;
    private const G_LR = 16;
    private const G_MN = 32;
    private const G_GJ = 64;
    private const G_FPV = 128;
    private const G_SXZ = 256;
    private const G_CSZ = 512;

    /** Group bit mask per letter A..Z (a letter may be in several groups). */
    private const GROUP_OF = [
        'A' => self::G_VOWEL, 'B' => self::G_BP, 'C' => self::G_CKQ | self::G_CSZ, 'D' => self::G_DT,
        'E' => self::G_VOWEL, 'F' => self::G_FPV, 'G' => self::G_GJ, 'H' => 0, 'I' => self::G_VOWEL,
        'J' => self::G_GJ, 'K' => self::G_CKQ, 'L' => self::G_LR, 'M' => self::G_MN, 'N' => self::G_MN,
        'O' => self::G_VOWEL, 'P' => self::G_BP | self::G_FPV, 'Q' => self::G_CKQ, 'R' => self::G_LR,
        'S' => self::G_SXZ | self::G_CSZ, 'T' => self::G_DT, 'U' => self::G_VOWEL, 'V' => self::G_FPV,
        'W' => 0, 'X' => self::G_SXZ, 'Y' => self::G_VOWEL, 'Z' => self::G_SXZ | self::G_CSZ,
    ];

    private function __construct()
    {
    }

    /** 1 - Distance / (2 * max(len(a), len(b))), in [0, 1]. */
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
        $d = (float) self::distance($s, $t);
        $denom = (float) (self::MISMATCH_COST * ($m > $n ? $m : $n));
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
    private static function distance(array $s, array $t): int
    {
        $n = \count($s);
        $m = \count($t);
        $prev = [];
        for ($j = 0; $j <= $m; $j++) {
            $prev[$j] = self::MISMATCH_COST * $j;
        }
        $curr = $prev;
        // insAdd depends only on j: precompute it.
        $insAdd = [0, self::MISMATCH_COST];
        for ($j = 2; $j <= $m; $j++) {
            $insAdd[$j] = self::dCost($t[$j - 2], $t[$j - 1]);
        }
        for ($i = 1; $i <= $n; $i++) {
            $curr[0] = self::MISMATCH_COST * $i;
            $ai = $s[$i - 1];
            $delAdd = $i >= 2 ? self::dCost($s[$i - 2], $ai) : self::MISMATCH_COST;
            for ($j = 1; $j <= $m; $j++) {
                $del = $prev[$j] + $delAdd;
                $ins = $curr[$j - 1] + $insAdd[$j];
                $sub = $prev[$j - 1] + self::rCost($ai, $t[$j - 1]);
                $curr[$j] = ($del <= $ins && $del <= $sub) ? $del : ($ins <= $sub ? $ins : $sub);
            }
            [$prev, $curr] = [$curr, $prev];
        }

        return $prev[$m];
    }

    private static function rCost(int $a, int $b): int
    {
        $a = GoUnicode::toUpper($a);
        $b = GoUnicode::toUpper($b);
        if ($a === $b) {
            return self::MATCH_COST;
        }
        if (self::grouped($a, $b)) {
            return self::GROUP_COST;
        }

        return self::MISMATCH_COST;
    }

    private static function dCost(int $a, int $b): int
    {
        $a = GoUnicode::toUpper($a);
        $b = GoUnicode::toUpper($b);
        if ($a !== $b && ($a === 0x48 || $a === 0x57)) { // 'H', 'W'
            return self::GROUP_COST;
        }

        return self::rCost($a, $b);
    }

    private static function grouped(int $a, int $b): bool
    {
        $ga = self::groupOf($a);
        $gb = self::groupOf($b);
        if ($ga === null || $gb === null) {
            return false;
        }

        return ($ga & $gb) !== 0;
    }

    /** Group mask for an ASCII letter (either case), null otherwise (Go alphaIndex). */
    private static function groupOf(int $r): ?int
    {
        if ($r >= 0x41 && $r <= 0x5A) {
            return self::GROUP_OF[\chr($r)];
        }
        if ($r >= 0x61 && $r <= 0x7A) {
            return self::GROUP_OF[\chr($r - 32)];
        }

        return null;
    }
}
