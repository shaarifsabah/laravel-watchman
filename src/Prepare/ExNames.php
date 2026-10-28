<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use IntlChar;
use Watchman\Support\Go;

/**
 * Port of internal/prepare/pipeline_ex_names.go: extracts former names such as
 * "(ex-Cape Diamond)", "f.k.a. …", "formerly known as …".
 */
final class ExNames
{
    private const FORMER_MARKERS = ['formerly known as', 'f.k.a.', 'f/k/a', 'f.k.a', 'formerly'];
    private const EX_CHAIN_SPLIT = '/[,;][\t\n\f\r ]*ex[\-\t\n\f\r \x{2013}\x{2014}]+/iu';
    private const FORMER_DATE = '/(?:,[\t\n\f\r ]*|[\t\n\f\r ]+until[\t\n\f\r ]+)[0-9]{4}[\t\n\f\r ]*\z/iu';
    private const FORMER_LEAD_SEP = '/^[\t\n\f\r \-\x{2013}\x{2014}:,]+/u';

    /**
     * @return array{0:string,1:list<string>} [cleaned, former names]
     */
    public static function split(string $s): array
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return ['', []];
        }
        $former = [];
        $b = '';
        $i = 0;
        $len = strlen($s);
        while ($i < $len) {
            $start = strpos($s, '(', $i);
            if ($start === false) {
                $b .= substr($s, $i);
                break;
            }
            $b .= substr($s, $i, $start - $i);
            $end = strpos($s, ')', $start);
            if ($end === false) {
                $b .= substr($s, $start);
                break;
            }
            $inner = Go::trimSpace(substr($s, $start + 1, $end - $start - 1));
            $names = self::cutFormerInner($inner);
            if ($names !== null) {
                array_push($former, ...$names);
                $i = $end + 1;
                continue;
            }
            $b .= substr($s, $start, $end - $start + 1);
            $i = $end + 1;
        }

        $cleaned = implode(' ', Go::fields($b));
        [$cleaned, $extra] = self::splitInlineFormer($cleaned);
        array_push($former, ...$extra);

        return [$cleaned, $former];
    }

    /** @return list<string> */
    public static function exNames(string $s): array
    {
        return self::split($s)[1];
    }

    /** @return list<string>|null */
    public static function cutFormerInner(string $inner): ?array
    {
        $inner = Go::trimSpace($inner);
        if ($inner === '') {
            return null;
        }
        $rest = self::splitExPrefix($inner);
        if ($rest !== null) {
            if ($rest === '') {
                return null;
            }

            return self::splitFormerList($rest);
        }
        $lower = Go::lower($inner);
        foreach (self::FORMER_MARKERS as $m) {
            if (Go::hasPrefix($lower, $m)) {
                $rest = Go::trimSpace((string) substr($inner, strlen($m)));
                $rest = Go::trimSpace((string) preg_replace(self::FORMER_LEAD_SEP, '', $rest));
                if ($rest === '') {
                    return null;
                }

                return self::splitFormerList($rest);
            }
        }

        return null;
    }

    /** @return array{0:string,1:list<string>} */
    private static function splitInlineFormer(string $s): array
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return ['', []];
        }
        $idx = self::indexFormerMarker($s);
        if ($idx === null) {
            return [$s, []];
        }
        [$start, $end] = $idx;
        $current = Go::trimSpace(substr($s, 0, $start));
        $current = Go::trimSpace(rtrim($current, ',;'));
        $rest = Go::trimSpace((string) preg_replace(self::FORMER_LEAD_SEP, '', (string) substr($s, $end)));
        if ($rest === '') {
            return [$s, []];
        }

        return [$current, self::splitFormerList($rest)];
    }

    /** @return array{0:int,1:int}|null */
    private static function indexFormerMarker(string $s): ?array
    {
        $lower = Go::lower($s);
        $bestStart = -1;
        $bestEnd = 0;
        foreach (self::FORMER_MARKERS as $m) {
            $i = strpos($lower, $m);
            if ($i === false) {
                continue;
            }
            $j = $i + strlen($m);
            if ($bestStart < 0 || $i < $bestStart || ($i === $bestStart && $j > $bestEnd)) {
                $bestStart = $i;
                $bestEnd = $j;
            }
        }
        $ex = self::indexExToken($s);
        if ($ex !== null && ($bestStart < 0 || $ex[0] < $bestStart)) {
            return $ex;
        }
        if ($bestStart < 0) {
            return null;
        }

        return [$bestStart, $bestEnd];
    }

    /** @return array{0:int,1:int}|null */
    private static function indexExToken(string $s): ?array
    {
        $lower = Go::lower($s);
        $len = strlen($lower);
        for ($i = 0; $i + 2 <= $len;) {
            $j = strpos($lower, 'ex', $i);
            if ($j === false) {
                return null;
            }
            if ($j > 0) {
                $prev = self::lastRune(substr($s, 0, $j));
                if ($prev !== null && (self::isLetterOrDigit($prev))) {
                    $i = $j + 2;
                    continue;
                }
            }
            $rest = (string) substr($s, $j + 2);
            if ($rest === '') {
                return null;
            }
            if (self::isExSeparatorPrefix($rest)) {
                return [$j, $j + 2];
            }
            $i = $j + 2;
        }

        return null;
    }

    private static function lastRune(string $s): ?int
    {
        if ($s === '') {
            return null;
        }
        $last = ord($s[strlen($s) - 1]);
        if ($last < 0x80) {
            return $last;
        }
        if (preg_match('/(.)$/su', $s, $m)) {
            return IntlChar::ord($m[1]);
        }

        return 0xFFFD;
    }

    private static function isLetterOrDigit(int $cp): bool
    {
        if ($cp < 0x80) {
            return ctype_alnum(chr($cp));
        }

        return Normalize::isLetter($cp) || Normalize::isDigit($cp);
    }

    /** @return list<string> */
    private static function splitFormerList(string $s): array
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return [];
        }
        $raw = preg_split(self::EX_CHAIN_SPLIT, $s) ?: [$s];
        $out = [];
        foreach ($raw as $p) {
            $p = self::stripFormerDate($p);
            $p = Go::trimSpace(trim(Go::trimSpace($p), ',;'));
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    private static function stripFormerDate(string $s): string
    {
        return Go::trimSpace((string) preg_replace(self::FORMER_DATE, '', Go::trimSpace($s)));
    }

    private static function splitExPrefix(string $inner): ?string
    {
        $inner = Go::trimSpace($inner);
        if (strlen($inner) < 3) {
            return null;
        }
        if (strcasecmp(substr($inner, 0, 2), 'ex') !== 0) {
            return null;
        }
        $c = $inner[2];
        if ($c === '-' || $c === ' ' || $c === "\t") {
            return Go::trimSpace(substr($inner, 3));
        }
        foreach (["\u{2013}", "\u{2014}"] as $dash) {
            if (str_starts_with(substr($inner, 2), $dash)) {
                return Go::trimSpace(substr($inner, 2 + strlen($dash)));
            }
        }

        return null;
    }

    private static function isExSeparatorPrefix(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        $c = $s[0];
        if ($c === '-' || $c === ' ' || $c === "\t") {
            return true;
        }

        return str_starts_with($s, "\u{2013}") || str_starts_with($s, "\u{2014}");
    }
}
