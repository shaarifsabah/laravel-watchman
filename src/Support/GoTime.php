<?php

declare(strict_types=1);

namespace Watchman\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Minimal emulation of Go's time.Parse for the date layouts Watchman uses,
 * plus RFC 3339 JSON encoding identical to Go's time.Time MarshalJSON.
 */
final class GoTime
{
    private const SHORT_MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    private const LONG_MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    /** @var array<string, list<array{0:string,1:string}>> */
    private static array $compiled = [];

    private static ?DateTimeZone $utc = null;

    public static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }

    /** Go's zero time (0001-01-01T00:00:00Z). */
    public static function zero(): DateTimeImmutable
    {
        return new DateTimeImmutable('0001-01-01T00:00:00', self::utc());
    }

    public static function isZero(?DateTimeInterface $t): bool
    {
        return $t !== null && $t->format('Y-m-d H:i:s') === '0001-01-01 00:00:00';
    }

    public static function date(int $year, int $month, int $day): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', self::utc()))->setDate($year, $month, $day)->setTime(0, 0, 0, 0);
    }

    /**
     * Parse $value with a Go reference layout (e.g. "2006-01-02", "02 Jan 2006").
     * Returns null when Go would return an error.
     */
    public static function parse(string $layout, string $value): ?DateTimeImmutable
    {
        $tokens = self::compile($layout);
        $year = null;
        $month = 1;
        $day = 1;
        $pos = 0;
        $len = strlen($value);

        foreach ($tokens as [$kind, $lit]) {
            $rest = (string) substr($value, $pos);
            switch ('k' . $kind) {
                case 'klit':
                    // Go's skip(): runs of spaces in the layout match runs of spaces in the value.
                    $li = 0;
                    $llen = strlen($lit);
                    while ($li < $llen) {
                        if ($lit[$li] === ' ') {
                            if ($pos < $len && $value[$pos] !== ' ') {
                                return null;
                            }
                            while ($li < $llen && $lit[$li] === ' ') {
                                $li++;
                            }
                            while ($pos < $len && $value[$pos] === ' ') {
                                $pos++;
                            }
                            continue;
                        }
                        if ($pos >= $len || $value[$pos] !== $lit[$li]) {
                            return null;
                        }
                        $pos++;
                        $li++;
                    }
                    break;
                case 'k2006':
                    if (!preg_match('/^\d{4}/', $rest)) {
                        return null;
                    }
                    $year = (int) substr($rest, 0, 4);
                    $pos += 4;
                    break;
                case 'k01':
                case 'k02':
                    if (!preg_match('/^\d\d/', $rest)) {
                        return null;
                    }
                    $n = (int) substr($rest, 0, 2);
                    $pos += 2;
                    if ($kind === '01') {
                        $month = $n;
                    } else {
                        $day = $n;
                    }
                    break;
                case 'k1':
                case 'k2':
                    if (!preg_match('/^(\d\d?)/', $rest, $m)) {
                        return null;
                    }
                    $n = (int) $m[1];
                    $pos += strlen($m[1]);
                    if ($kind === '1') {
                        $month = $n;
                    } else {
                        $day = $n;
                    }
                    break;
                case 'kJanuary':
                    $found = false;
                    foreach (self::LONG_MONTHS as $i => $name) {
                        if (strlen($rest) >= strlen($name) && strcasecmp(substr($rest, 0, strlen($name)), $name) === 0) {
                            $month = $i + 1;
                            $pos += strlen($name);
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return null;
                    }
                    break;
                case 'kJan':
                    if (strlen($rest) < 3) {
                        return null;
                    }
                    $idx = array_search(strtolower(substr($rest, 0, 3)), self::SHORT_MONTHS, true);
                    if ($idx === false) {
                        return null;
                    }
                    $month = $idx + 1;
                    $pos += 3;
                    break;
            }
        }

        if ($pos !== $len) {
            return null; // extra text
        }
        if ($month < 1 || $month > 12) {
            return null;
        }
        $y = $year ?? 0;
        if ($day < 1 || $day > self::daysIn($month, $y)) {
            return null;
        }

        return self::date($y, $month, $day);
    }

    /**
     * Try each layout in order, returning the first successful parse.
     *
     * @param list<string> $layouts
     */
    public static function parseAny(array $layouts, string $value): ?DateTimeImmutable
    {
        foreach ($layouts as $layout) {
            if (($t = self::parse($layout, $value)) !== null) {
                return $t;
            }
        }

        return null;
    }

    /** Encode like Go's time.Time JSON marshalling (RFC 3339 with nanoseconds trimmed). */
    public static function toJson(?DateTimeInterface $t): ?string
    {
        if ($t === null) {
            return null;
        }
        $t = DateTimeImmutable::createFromInterface($t);
        $base = $t->format('Y-m-d\TH:i:s');
        $micro = (int) $t->format('u');
        if ($micro > 0) {
            $base .= '.' . rtrim(sprintf('%06d', $micro), '0');
        }
        $offset = $t->getOffset();

        return $base . ($offset === 0 ? 'Z' : $t->format('P'));
    }

    /** Parse an RFC 3339 timestamp (as produced by Go) back into a date. */
    public static function fromJson(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            $t = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return $t->setTimezone(self::utc());
    }

    public static function daysIn(int $month, int $year): int
    {
        if ($month === 2) {
            $leap = ($year % 4 === 0) && ($year % 100 !== 0 || $year % 400 === 0);

            return $leap ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function compile(string $layout): array
    {
        if (isset(self::$compiled[$layout])) {
            return self::$compiled[$layout];
        }
        $tokens = [];
        $lit = '';
        $i = 0;
        $n = strlen($layout);
        $std = ['January', '2006', 'Jan', '01', '02', '1', '2'];
        while ($i < $n) {
            $matched = null;
            foreach ($std as $s) {
                if (substr($layout, $i, strlen($s)) === $s) {
                    $matched = $s;
                    break;
                }
            }
            if ($matched === null) {
                $lit .= $layout[$i];
                $i++;
                continue;
            }
            if ($lit !== '') {
                $tokens[] = ['lit', $lit];
                $lit = '';
            }
            $tokens[] = [$matched, ''];
            $i += strlen($matched);
        }
        if ($lit !== '') {
            $tokens[] = ['lit', $lit];
        }

        return self::$compiled[$layout] = $tokens;
    }
}
