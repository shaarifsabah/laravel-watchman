<?php

declare(strict_types=1);

namespace Watchman\Support;

/**
 * Helpers that reproduce Go standard library string semantics used by Watchman
 * (strings.Fields, strings.TrimSpace, strings.ToLower, strings.EqualFold, …).
 *
 * Go operates on UTF-8 strings with Unicode-aware whitespace and simple case
 * mapping; these helpers keep the PHP port byte-for-byte compatible.
 */
final class Go
{
    /** Characters for which Go's unicode.IsSpace returns true. */
    public const SPACE_CLASS = '\x{09}-\x{0D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    /**
     * strings.Fields: split around runs of Unicode whitespace.
     *
     * @return list<string>
     */
    public static function fields(string $s): array
    {
        if ($s === '') {
            return [];
        }
        if (self::isAscii($s)) {
            $parts = preg_split('/[\t\n\v\f\r ]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $parts = preg_split('/[' . self::SPACE_CLASS . ']+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false) { // invalid UTF-8: fall back to ASCII whitespace
                $parts = preg_split('/[\t\n\v\f\r ]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        return $parts === false ? [] : array_values($parts);
    }

    /** strings.TrimSpace */
    public static function trimSpace(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (self::isAscii($s)) {
            return trim($s, " \t\n\r\v\f");
        }
        $out = preg_replace('/^[' . self::SPACE_CLASS . ']+|[' . self::SPACE_CLASS . ']+$/u', '', $s);

        return $out ?? trim($s, " \t\n\r\v\f");
    }

    /** strings.ToLower (simple per-rune mapping). */
    public static function lower(string $s): string
    {
        if (self::isAscii($s)) {
            return strtolower($s);
        }

        return mb_convert_case($s, MB_CASE_LOWER_SIMPLE, 'UTF-8');
    }

    /** strings.ToUpper (simple per-rune mapping). */
    public static function upper(string $s): string
    {
        if (self::isAscii($s)) {
            return strtoupper($s);
        }

        return mb_convert_case($s, MB_CASE_UPPER_SIMPLE, 'UTF-8');
    }

    /** strings.EqualFold */
    public static function equalFold(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (self::isAscii($a) && self::isAscii($b)) {
            return strlen($a) === strlen($b) && strcasecmp($a, $b) === 0;
        }

        return mb_convert_case($a, MB_CASE_FOLD_SIMPLE, 'UTF-8') === mb_convert_case($b, MB_CASE_FOLD_SIMPLE, 'UTF-8');
    }

    public static function isAscii(string $s): bool
    {
        return !preg_match('/[\x80-\xFF]/', $s);
    }

    /** strings.HasPrefix */
    public static function hasPrefix(string $s, string $prefix): bool
    {
        return $prefix === '' || strncmp($s, $prefix, strlen($prefix)) === 0;
    }

    /** strings.HasSuffix */
    public static function hasSuffix(string $s, string $suffix): bool
    {
        return $suffix === '' || (strlen($s) >= strlen($suffix) && substr_compare($s, $suffix, -strlen($suffix)) === 0);
    }

    /** strings.TrimPrefix */
    public static function trimPrefix(string $s, string $prefix): string
    {
        return ($prefix !== '' && self::hasPrefix($s, $prefix)) ? (string) substr($s, strlen($prefix)) : $s;
    }

    /** strings.TrimSuffix */
    public static function trimSuffix(string $s, string $suffix): string
    {
        return ($suffix !== '' && self::hasSuffix($s, $suffix)) ? (string) substr($s, 0, strlen($s) - strlen($suffix)) : $s;
    }

    /**
     * strings.Trim(s, cutset) where cutset holds ASCII characters.
     */
    public static function trim(string $s, string $cutset): string
    {
        return trim($s, $cutset);
    }

    /**
     * strings.NewReplacer(old1, new1, …).Replace(s) — at each position the first
     * matching pair (argument order) wins, matches never overlap.
     *
     * @param list<string> $oldnew
     */
    public static function replace(string $s, array $oldnew): string
    {
        $pairs = [];
        $allSingle = true;
        for ($i = 0, $n = count($oldnew); $i + 1 < $n; $i += 2) {
            $pairs[] = [$oldnew[$i], $oldnew[$i + 1]];
            if (strlen($oldnew[$i]) !== 1) {
                $allSingle = false;
            }
        }
        if ($allSingle) {
            $map = [];
            foreach ($pairs as [$old, $new]) {
                if (!array_key_exists($old, $map)) {
                    $map[$old] = $new;
                }
            }

            return strtr($s, $map);
        }

        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $matched = false;
            foreach ($pairs as [$old, $new]) {
                $ol = strlen($old);
                if ($ol > 0 && $ol <= $len - $i && substr_compare($s, $old, $i, $ol) === 0) {
                    $out .= $new;
                    $i += $ol;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $out .= $s[$i];
                $i++;
            }
        }

        return $out;
    }

    /**
     * cmp.Or for strings: first non-empty value.
     */
    public static function or(string ...$values): string
    {
        foreach ($values as $v) {
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * Split a UTF-8 string into code points (Go range over string). Invalid bytes
     * become U+FFFD like Go.
     *
     * @return list<string>
     */
    public static function runes(string $s): array
    {
        if ($s === '') {
            return [];
        }
        if (self::isAscii($s)) {
            return str_split($s);
        }
        $out = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if ($out === false) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            $out = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return $out;
    }

    /** strx.Yes from moov-io/base. */
    public static function yes(?string $in): bool
    {
        $in = self::trimSpace((string) $in);
        if (strcasecmp($in, 'yes') === 0) {
            return true;
        }

        return in_array($in, ['1', 't', 'T', 'TRUE', 'true', 'True'], true);
    }

    /** strx.Or from moov-io/base: first value that is non-empty after trimming. */
    public static function strxOr(string ...$values): string
    {
        foreach ($values as $v) {
            $t = self::trimSpace($v);
            if ($t !== '') {
                return $t;
            }
        }

        return '';
    }

    /** Float division that mirrors Go float64 semantics (NaN / ±Inf instead of exceptions). */
    public static function fdiv(float $a, float $b): float
    {
        return fdiv($a, $b);
    }

    /** strconv.Atoi-like parse; returns null on error. */
    public static function atoi(string $s): ?int
    {
        if ($s === '' || !preg_match('/^[+-]?[0-9]+\z/', $s)) {
            return null;
        }
        $n = (int) $s;
        if ((string) abs($n) !== ltrim(ltrim($s, '+-'), '0') && ltrim(ltrim($s, '+-'), '0') !== '') {
            return null; // overflow
        }

        return $n;
    }
}
