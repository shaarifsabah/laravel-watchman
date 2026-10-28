<?php

declare(strict_types=1);

namespace Watchman\Index;

use Watchman\Support\Go;

/** Port of internal/index/qwerty.go + normalizeID */
final class Qwerty
{
    private const NEIGHBORS = [
        '1' => '2q', '2' => '13qw', '3' => '24we', '4' => '35er', '5' => '46rt',
        '6' => '57ty', '7' => '68yu', '8' => '79ui', '9' => '80io', '0' => '9op',
        'q' => '12wa', 'w' => '23qesa', 'e' => '34wrsd', 'r' => '45etdf', 't' => '56ryfg',
        'y' => '67tugh', 'u' => '78yihj', 'i' => '89uojk', 'o' => '90ipkl', 'p' => '0ol',
        'a' => 'qwsz', 's' => 'weadzx', 'd' => 'erfcxs', 'f' => 'rtgvcd', 'g' => 'tyhbvf',
        'h' => 'yujnbg', 'j' => 'uikmnh', 'k' => 'iolmj', 'l' => 'opk',
        'z' => 'asx', 'x' => 'zsdc', 'c' => 'xdfv', 'v' => 'cfgb', 'b' => 'vghn',
        'n' => 'bhjm', 'm' => 'njk',
    ];

    public static function normalizeId(string $s): string
    {
        return str_replace([' ', '-', '.'], '', Go::lower(Go::trimSpace($s)));
    }

    /** @return list<string> q first, then single-key substitutions and adjacent transpositions */
    public static function variants(string $q): array
    {
        if ($q === '') {
            return [];
        }
        $seen = [$q => true];
        $out = [$q];
        $runes = Go::runes($q);
        $n = count($runes);
        foreach ($runes as $i => $r) {
            foreach (str_split(self::NEIGHBORS[$r] ?? '') as $nb) {
                if ($nb === '') {
                    continue;
                }
                $copy = $runes;
                $copy[$i] = $nb;
                $s = implode('', $copy);
                if (!isset($seen[$s])) {
                    $seen[$s] = true;
                    $out[] = $s;
                }
            }
        }
        for ($i = 0; $i < $n - 1; $i++) {
            $copy = $runes;
            [$copy[$i], $copy[$i + 1]] = [$copy[$i + 1], $copy[$i]];
            $s = implode('', $copy);
            if (!isset($seen[$s])) {
                $seen[$s] = true;
                $out[] = $s;
            }
        }

        return $out;
    }
}
