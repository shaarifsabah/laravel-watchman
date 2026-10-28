<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\Support\Go;

/** Port of pkg/search/similarity_numeral.go */
final class Numerals
{
    private const SPELLED = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
        'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
        'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20,
        'i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9,
        'x' => 10, 'xi' => 11, 'xii' => 12,
    ];

    /** @var array<string, array<int,bool>> */
    private static array $memo = [];

    public static function hasConflict(Entity $q, Entity $i): bool
    {
        $qn = self::forName($q->preparedFields->name);
        if ($qn === []) {
            return false;
        }
        $in = self::forName($i->preparedFields->name);
        if ($qn === [] || $in === []) {
            return false;
        }
        foreach ($qn as $n => $_) {
            if (isset($in[$n])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int,bool> */
    private static function forName(string $name): array
    {
        if (isset(self::$memo[$name])) {
            return self::$memo[$name];
        }
        if (count(self::$memo) > 20000) {
            self::$memo = [];
        }

        return self::$memo[$name] = self::numerals(Go::fields($name));
    }

    /** @return array<int,bool> */
    private static function numerals(array $fields): array
    {
        $out = [];
        foreach ($fields as $tok) {
            $tok = Go::lower(Go::trimSpace($tok));
            if ($tok === '' || $tok === 'no') {
                continue;
            }
            if (isset(self::SPELLED[$tok])) {
                $out[self::SPELLED[$tok]] = true;
                continue;
            }
            // unicode.IsDigit accepts any Nd digit; strconv.Atoi only parses ASCII digits.
            if (preg_match('/^[0-9]+$/', $tok) && strlen(ltrim($tok, '0')) <= 18) {
                $out[(int) $tok] = true;
            }
        }

        return $out;
    }
}
