<?php

declare(strict_types=1);

namespace Watchman\StringScore;

/** Port of internal/stringscore/soundex.go */
final class Soundex
{
    private const DIGITS = [
        'B' => '1', 'F' => '1', 'P' => '1', 'V' => '1',
        'C' => '2', 'G' => '2', 'J' => '2', 'K' => '2', 'Q' => '2', 'S' => '2', 'X' => '2', 'Z' => '2',
        'D' => '3', 'T' => '3', 'L' => '4', 'M' => '5', 'N' => '5', 'R' => '6',
    ];

    public static function encode(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $s));
        if ($letters === '') {
            return '';
        }
        $first = $letters[0];
        $last = self::DIGITS[$first] ?? '';
        $code = '';
        for ($i = 1, $n = strlen($letters); $i < $n; $i++) {
            $ch = $letters[$i];
            if ($ch === 'H' || $ch === 'W') {
                continue;
            }
            $d = self::DIGITS[$ch] ?? '';
            if ($d === '') {
                $last = '';
                continue;
            }
            if ($d !== $last) {
                $code .= $d;
                $last = $d;
            }
            if (strlen($code) >= 3) {
                break;
            }
        }

        return $first . substr(str_pad($code, 3, '0'), 0, 3);
    }

    public static function match(string $s1, string $s2): bool
    {
        if ($s1 === $s2) {
            return true;
        }
        $c1 = self::encode($s1);

        return $c1 !== '' && $c1 === self::encode($s2);
    }
}
