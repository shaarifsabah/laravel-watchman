<?php

declare(strict_types=1);

namespace Watchman\StringScore\Algorithms;

/**
 * Generic Beider-Morse Phonetic Matching (Beider & Morse, 2008), v1 generic
 * approximate rewrite rules. Match is true when the phonetic key sets overlap.
 *
 * Port of github.com/PhonoGrams/beider_morse v0.1.0 (Encode, Match),
 * Apache License 2.0 (rules distilled from Apache Commons Codec, Apache-2.0).
 *
 * Semantics mirrored from Go: normalisation is rune-wise (Go strings.ToLower,
 * accent folding, unicode.IsLetter filter); rule rewriting is byte-wise on the
 * normalised UTF-8 string; key collapsing is rune-wise. Go maps are used only
 * as sets, so map iteration order never affects the result.
 */
final class BeiderMorse
{
    private const MAX_KEYS = 8;

    /** Upstream rule table in source order: [pattern, left ('' or '^'), end, alternatives]. */
    private const RULES = [
        ['tsch', '', false, ['tS']],
        ['chsch', '', false, ['xS']],
        ['ssch', '', false, ['S']],
        ['sch', '', false, ['S', 'StS']],
        ['cz', '', false, ['tS', 'tsz']],
        ['sz', '', false, ['S', 's']],
        ['tz', '', false, ['ts', 'tS']],
        ['ph', '', false, ['f']],
        ['kh', '', false, ['x', 'kh']],
        ['th', '', false, ['t']],
        ['sh', '', false, ['S']],
        ['zh', '', false, ['Z', 'zh']],
        ['ch', '', false, ['x', 'tS', 'S', 'k']],
        ['ck', '', false, ['k']],
        ['qu', '', false, ['k', 'kv']],
        ['eau', '', false, ['o']],
        ['tsch', '', false, ['tS']],
        ['witz', '', false, ['vits', 'ts']],
        ['wicz', '', false, ['vits', 'vix']],
        ['ova', '', true, ['ova', 'of']],
        ["ov\u{E1}", '', true, ['ova']], // "ová" (4 bytes)
        ['eva', '', true, ['eva', 'ef']],
        ['ski', '', true, ['ski', 'sky']],
        ['sky', '', true, ['ski', 'sky']],
        ['enko', '', true, ['enko', 'enka']],
        ['enka', '', true, ['enko', 'enka']],
        ['ae', '', false, ['e', 'Y']],
        ['oe', '', false, ['e', 'Y', 'o']],
        ['ue', '', false, ['e', 'Q']],
        ['ei', '', false, ['aj', 'ej']],
        ['ey', '', false, ['aj', 'ej']],
        ['au', '', false, ['au', 'o']],
        ['eu', '', false, ['oj', 'Y']],
        ['ow', '', false, ['ov', 'ow']],
        ['ks', '', false, ['ks', 'x']],
        ['pf', '', false, ['pf', 'p', 'f']],
        ['dt', '', false, ['t']],
        ['gh', '', false, ['g', 'gh']],
        ['kn', '^', false, ['n']],
        ['gn', '^', false, ['n']],
        ['wr', '^', false, ['r']],
        ['ps', '^', false, ['s']],
        ['h', '^', false, ['h', 'x']],
    ];

    /** Accent folding applied after lower-casing (code point => replacement). */
    private const FOLD = [
        0xE4 => 'a', 0xE1 => 'a', 0xE0 => 'a', 0xE2 => 'a', 0xE3 => 'a', 0x103 => 'a', // ä á à â ã ă
        0xE9 => 'e', 0xE8 => 'e', 0xEA => 'e', 0xEB => 'e', 0x11B => 'e',              // é è ê ë ě
        0xED => 'i', 0xEE => 'i', 0xEF => 'i', 0xEC => 'i',                            // í î ï ì
        0xF6 => 'o', 0xF3 => 'o', 0xF4 => 'o', 0xF2 => 'o', 0xF5 => 'o', 0x151 => 'o', // ö ó ô ò õ ő
        0xFC => 'u', 0xFA => 'u', 0xF9 => 'u', 0xFB => 'u', 0x171 => 'u', 0x16F => 'u', // ü ú ù û ű ů
        0xFD => 'y',                                                                    // ý
        0xF1 => 'n',                                                                    // ñ
        0xE7 => 'c',                                                                    // ç
        0xDF => 'ss',                                                                   // ß
        0x142 => 'l',                                                                   // ł
    ];

    /** @var list<array{0: string, 1: string, 2: bool, 3: list<string>}>|null */
    private static ?array $sortedRules = null;

    private function __construct()
    {
    }

    /** True when a and b share a phonetic key. */
    public static function match(string $s1, string $s2): bool
    {
        $kb = self::encode($s2);
        if ($kb === []) {
            return false;
        }
        $set = array_fill_keys(self::encode($s1), true);
        foreach ($kb as $k) {
            if (isset($set[$k])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generic BMPM phonetic keys (at most 8, in upstream order).
     *
     * @return list<string>
     */
    public static function encode(string $s): array
    {
        $n = self::normalize($s);
        if ($n === '') {
            return [];
        }
        $keys = self::rewrite($n);
        $out = [];
        $seen = [];
        foreach ($keys as $k) {
            $k = self::collapse($k);
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $k;
            if (\count($out) >= self::MAX_KEYS) {
                break;
            }
        }

        return $out;
    }

    private static function normalize(string $s): string
    {
        $b = '';
        foreach (GoUnicode::runes($s) as $r) {
            $r = GoUnicode::toLower($r);
            if (isset(self::FOLD[$r])) {
                $b .= self::FOLD[$r]; // all replacements are ASCII letters
                continue;
            }
            if (GoUnicode::isLetter($r)) {
                $b .= GoUnicode::chr($r);
            }
        }

        return $b;
    }

    /** @return list<string> */
    private static function rewrite(string $s): array
    {
        // Upstream rewrite() over a single input: apply() then dedupe, capped at MAX_KEYS.
        $out = [];
        $seen = [];
        foreach (self::apply($s) as $k) {
            if (isset($seen[$k]) || \count($out) >= self::MAX_KEYS) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $k;
        }

        return $out;
    }

    /** @return list<string> */
    private static function apply(string $s): array
    {
        $rules = self::rules();
        $len = \strlen($s);
        $cur = [['', 0]]; // queue of [prefix, byte offset]
        $head = 0;
        $done = [];
        while (\count($cur) - $head > 0 && \count($done) + (\count($cur) - $head) <= self::MAX_KEYS) {
            [$pre, $i] = $cur[$head];
            $head++;
            if ($i >= $len) {
                $done[] = $pre;
                continue;
            }
            $matched = false;
            foreach ($rules as [$pat, $left, $end, $alts]) {
                $pl = \strlen($pat);
                if ($i + $pl > $len || substr_compare($s, $pat, $i, $pl) !== 0) {
                    continue;
                }
                if ($left === '^' && $i !== 0) {
                    continue;
                }
                if ($end && $i + $pl !== $len) {
                    continue;
                }
                $matched = true;
                $next = $i + $pl;
                foreach ($alts as $alt) {
                    $cur[] = [$pre . $alt, $next];
                    if (\count($cur) - $head >= self::MAX_KEYS) {
                        break;
                    }
                }
                break;
            }
            if (!$matched) {
                $cur[] = [$pre . $s[$i], $i + 1];
            }
        }
        for ($q = $head, $c = \count($cur); $q < $c; $q++) {
            [$pre, $i] = $cur[$q];
            $done[] = $i >= $len ? $pre : $pre . substr($s, $i);
        }

        return $done;
    }

    private static function collapse(string $s): string
    {
        $b = '';
        $prev = 0;
        foreach (GoUnicode::runes($s) as $r) {
            switch ($r) {
                case 0x61: case 0x65: case 0x69: case 0x6F: case 0x75: case 0x79: // a e i o u y
                case 0x41: case 0x45: case 0x49: case 0x4F: case 0x55: case 0x59: // A E I O U Y
                case 0x51: // Q
                    if ($b === '') {
                        $b .= 'a';
                        $prev = 0x61;
                    }
                    continue 2;
                case 0x77: // w
                    $r = 0x76; // v
                    break;
                case 0x63: // c
                case 0x71: // q
                    $r = 0x6B; // k
                    break;
                case 0x78: // x
                    if ($prev !== 0x6B) {
                        $b .= 'k';
                    }
                    $r = 0x73; // s
                    break;
            }
            if ($r === $prev) {
                continue;
            }
            $b .= GoUnicode::chr($r);
            $prev = $r;
        }

        return $b;
    }

    /**
     * Rules in the order produced by upstream's init(): an in-place selection-
     * style swap pass on byte length (not a stable sort), replicated verbatim.
     *
     * @return list<array{0: string, 1: string, 2: bool, 3: list<string>}>
     */
    private static function rules(): array
    {
        if (self::$sortedRules !== null) {
            return self::$sortedRules;
        }
        $rules = self::RULES;
        $n = \count($rules);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (\strlen($rules[$j][0]) > \strlen($rules[$i][0])) {
                    [$rules[$i], $rules[$j]] = [$rules[$j], $rules[$i]];
                }
            }
        }

        return self::$sortedRules = $rules;
    }
}
