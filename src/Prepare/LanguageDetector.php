<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use IntlChar;
use Watchman\Support\Data;
use Watchman\Support\Go;

/**
 * Port of github.com/abadojack/whatlanggo v1.0.1 (trigram language detection),
 * as used by Watchman to pick the stopword list for a name.
 */
final class LanguageDetector
{
    private const MAX_TRIGRAM_DISTANCE = 300;
    private const MAX_TOTAL_DISTANCE = 90000;
    public const RELIABLE_CONFIDENCE_THRESHOLD = 0.8;

    /** Script order used by whatlanggo's DetectScript (counter list, initial order). */
    private const SCRIPTS = [
        'latin', 'cyrillic', 'arabic', 'devanagari', 'hirakata', 'ethiopic', 'hebrew', 'bengali', 'georgian',
        'han', 'hangul', 'greek', 'kannada', 'tamil', 'thai', 'gujarati', 'gurmukhi', 'telugu', 'malayalam',
        'oriya', 'myanmar', 'sinhala', 'khmer',
    ];

    /** Scripts that map straight to a single language (ISO 639-3). */
    private const SINGLE = [
        'han' => 'cmn', 'bengali' => 'ben', 'hangul' => 'kor', 'georgian' => 'kat', 'greek' => 'ell',
        'kannada' => 'kan', 'tamil' => 'tam', 'thai' => 'tha', 'gujarati' => 'guj', 'gurmukhi' => 'pan',
        'telugu' => 'tel', 'malayalam' => 'mal', 'oriya' => 'ori', 'myanmar' => 'mya', 'sinhala' => 'sin',
        'khmer' => 'khm', 'hirakata' => 'jpn',
    ];

    /** @var array<string,int>|null */
    private static ?array $icuScripts = null;

    /**
     * @return array{lang: string, iso6391: string, confidence: float}
     */
    public static function detect(string $text): array
    {
        $script = self::detectScript($text);
        if ($script === null) {
            return ['lang' => '', 'iso6391' => '', 'confidence' => 0.0];
        }
        if (isset(self::SINGLE[$script])) {
            $lang = self::SINGLE[$script];
            $conf = 1.0;
        } else {
            $profiles = Data::get('whatlanggo')['profiles'][$script] ?? null;
            if ($profiles === null) {
                return ['lang' => '', 'iso6391' => '', 'confidence' => 0.0];
            }
            [$lang, $conf] = self::detectInProfiles($text, $profiles, $script);
        }
        $iso1 = $lang === '' ? '' : (Data::get('whatlanggo')['langs'][$lang] ?? '');

        return ['lang' => $lang, 'iso6391' => $iso1, 'confidence' => $conf];
    }

    /**
     * @param array<string, list<string>> $profiles
     * @return array{0:string,1:float}
     */
    /** @var array<string, array<string, array{0:int, 1:array<string, list<int>>}>> */
    private static array $index = [];

    /**
     * Per script: lang => [profile length, trigram => positions].
     *
     * @return array<string, array{0:int, 1:array<string, list<int>>}>
     */
    private static function profileIndex(string $script, array $profiles): array
    {
        if (isset(self::$index[$script])) {
            return self::$index[$script];
        }
        $out = [];
        foreach ($profiles as $lang => $trigrams) {
            $pos = [];
            foreach ($trigrams as $i => $tg) {
                $pos[$tg][] = $i;
            }
            $out[$lang] = [count($trigrams), $pos];
        }

        return self::$index[$script] = $out;
    }

    /**
     * @param array<string, list<string>> $profiles
     * @return array{0:string,1:float}
     */
    private static function detectInProfiles(string $text, array $profiles, string $script = ''): array
    {
        $trigrams = self::trigramsWithPositions($text);
        $dists = [];
        // distance = sum over profile trigrams of |textPos - i|, or MAX_TRIGRAM_DISTANCE when absent;
        // computed from the (few) text trigrams instead of all profile trigrams.
        foreach (self::profileIndex($script, $profiles) as $lang => [$len, $positions]) {
            $total = $len * self::MAX_TRIGRAM_DISTANCE;
            foreach ($trigrams as $tg => $tpos) {
                if (isset($positions[$tg])) {
                    foreach ($positions[$tg] as $i) {
                        $total += abs($tpos - $i) - self::MAX_TRIGRAM_DISTANCE;
                    }
                }
            }
            $dists[] = [(string) $lang, $total];
        }
        $n = count($dists);
        if ($n === 0) {
            return ['', 0.0];
        }
        if ($n === 1) {
            return [$dists[0][0], 1.0];
        }
        // Go breaks exact ties by random map-iteration order; we prefer English, then ISO code order.
        usort($dists, static fn ($a, $b) => $a[1] <=> $b[1] ?: (($b[0] === 'eng') <=> ($a[0] === 'eng')));
        $score1 = self::MAX_TOTAL_DISTANCE - $dists[0][1];
        $score2 = self::MAX_TOTAL_DISTANCE - $dists[1][1];
        if ($score1 === 0) {
            return ['', 0.0];
        }
        if ($score2 === 0) {
            return [$dists[0][0], min(1.0, (float) intdiv($score1, 500))];
        }
        $rate = ($score1 - $score2) / $score2;
        $confidentRate = (12.0 / count($trigrams)) + 0.05;
        $confidence = $rate > $confidentRate ? 1.0 : $rate / $confidentRate;

        return [$dists[0][0], $confidence];
    }

    /** @return array<string,int> */
    private static function trigramsWithPositions(string $text): array
    {
        $txt = [];
        foreach (Go::runes($text) as $ch) {
            $cp = IntlChar::ord($ch) ?? 0xFFFD;
            $txt[] = self::isStopChar($cp) ? ' ' : mb_convert_case($ch, MB_CASE_LOWER_SIMPLE, 'UTF-8');
        }
        $txt[] = ' ';
        $counts = [];
        $r1 = ' ';
        $r2 = $txt[0];
        for ($i = 1, $n = count($txt); $i < $n; $i++) {
            $r3 = $txt[$i];
            if (!($r2 === ' ' && ($r1 === ' ' || $r3 === ' '))) {
                $tg = $r1 . $r2 . $r3;
                $counts[$tg] = ($counts[$tg] ?? 0) + 1;
            }
            $r1 = $r2;
            $r2 = $r3;
        }
        // sort ascending by count then trigram (byte order), then assign positions from the end
        $list = [];
        foreach ($counts as $tg => $c) {
            $list[] = [(string) $tg, $c];
        }
        usort($list, static fn ($a, $b) => $a[1] === $b[1] ? strcmp($a[0], $b[0]) : $a[1] <=> $b[1]);
        $out = [];
        $j = 0;
        for ($i = count($list) - 1; $i >= 0; $i--) {
            $out[$list[$i][0]] = $j++;
        }

        return $out;
    }

    private static function isStopChar(int $cp): bool
    {
        if ($cp < 0x80) {
            // ASCII: digits, whitespace, punctuation and symbols are stop characters
            return !(($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A) || $cp < 0x09 || ($cp > 0x0D && $cp < 0x20) || $cp === 0x7F);
        }
        return Normalize::isSymbol($cp) || Normalize::isSpace($cp) || Normalize::isPunct($cp) || Normalize::isDigit($cp);
    }

    private static function detectScript(string $text): ?string
    {
        $halfLen = intdiv(strlen($text), 2);
        $order = self::SCRIPTS;
        $counts = array_fill_keys(self::SCRIPTS, 0);
        foreach (Go::runes($text) as $ch) {
            $cp = IntlChar::ord($ch) ?? 0xFFFD;
            if (self::isStopChar($cp)) {
                continue;
            }
            $script = self::scriptOf($cp);
            if ($script === null) {
                continue;
            }
            $idx = array_search($script, $order, true);
            $counts[$script]++;
            if ($counts[$script] > $halfLen) {
                return $script;
            }
            if ($idx > 0) {
                [$order[$idx - 1], $order[$idx]] = [$order[$idx], $order[$idx - 1]];
            }
        }
        $max = 0;
        $maxScript = null;
        $jp = 0;
        foreach ($order as $s) {
            if ($counts[$s] > $max) {
                $max = $counts[$s];
                $maxScript = $s;
                if ($s === 'hirakata') {
                    $jp = $max;
                }
            }
        }
        if ($max === 0) {
            return null;
        }
        if ($maxScript === 'han' && $jp > 0) {
            return 'hirakata';
        }

        return $maxScript;
    }

    private static function scriptOf(int $cp): ?string
    {
        if (self::$icuScripts === null) {
            // ICU UScriptCode values (stable across ICU versions)
            self::$icuScripts = [
                25 => 'latin', 8 => 'cyrillic', 2 => 'arabic', 10 => 'devanagari', 20 => 'hirakata', 22 => 'hirakata',
                11 => 'ethiopic', 19 => 'hebrew', 4 => 'bengali', 12 => 'georgian', 17 => 'han', 18 => 'hangul',
                14 => 'greek', 21 => 'kannada', 35 => 'tamil', 38 => 'thai', 15 => 'gujarati', 16 => 'gurmukhi',
                36 => 'telugu', 26 => 'malayalam', 31 => 'oriya', 28 => 'myanmar', 33 => 'sinhala', 23 => 'khmer',
            ];
        }
        $script = IntlChar::getIntPropertyValue($cp, IntlChar::PROPERTY_SCRIPT);

        return self::$icuScripts[$script] ?? null;
    }
}
