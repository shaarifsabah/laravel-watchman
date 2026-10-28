<?php

declare(strict_types=1);

namespace Watchman\StringScore;

/** Port of internal/stringscore/phonetics.go (first-letter phonetic class filter). */
final class Phonetics
{
    private const MAP = [
        'A' => 'A', 'E' => 'A', 'I' => 'A', 'O' => 'A', 'U' => 'A', 'Y' => 'A',
        'B' => 'B', 'F' => 'B', 'P' => 'B', 'V' => 'B',
        'C' => 'C', 'G' => 'C', 'J' => 'C', 'K' => 'C', 'Q' => 'C', 'S' => 'C', 'X' => 'C', 'Z' => 'C',
        'D' => 'D', 'T' => 'D',
        'L' => 'L',
        'M' => 'M', 'N' => 'M',
        'R' => 'R',
        'H' => 'H', 'W' => 'H',
    ];

    /** @var array<string,string> */
    private static array $classes = [];

    public static function phoneticClass(string $s): string
    {
        if ($s === '') {
            return ' ';
        }
        $c = $s[0];
        if (ord($c) < 0x80) {
            $u = strtoupper($c);

            return self::MAP[$u] ?? $u;
        }
        if (isset(self::$classes[$s[0] . ($s[1] ?? '') . ($s[2] ?? '') . ($s[3] ?? '')])) {
            return self::$classes[$s[0] . ($s[1] ?? '') . ($s[2] ?? '') . ($s[3] ?? '')];
        }
        $first = mb_substr($s, 0, 1, 'UTF-8');
        $upper = mb_convert_case($first, MB_CASE_UPPER_SIMPLE, 'UTF-8');
        $out = self::MAP[$upper] ?? $upper;
        self::$classes[$s[0] . ($s[1] ?? '') . ($s[2] ?? '') . ($s[3] ?? '')] = $out;

        return $out;
    }

    public static function firstCharacterSoundexMatch(string $s1, string $s2): bool
    {
        if ($s1 === '' || $s2 === '') {
            return false;
        }
        $a = $s1[0];
        $b = $s2[0];
        if (ord($a) < 0x80 && ord($b) < 0x80) {
            $a = strtoupper($a);
            $b = strtoupper($b);

            return (self::MAP[$a] ?? $a) === (self::MAP[$b] ?? $b);
        }

        return self::phoneticClass($s1) === self::phoneticClass($s2);
    }
}
