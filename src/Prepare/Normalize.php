<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use IntlChar;
use Normalizer;
use Watchman\Support\Go;

/**
 * Port of internal/prepare/pipeline_normalize.go.
 */
final class Normalize
{
    /** @var array<string, string> */
    private static array $memo = [];

    /**
     * Lowercase, turn punctuation/symbols into single spaces, collapse whitespace,
     * then strip diacritics (NFD → remove Mn → NFC).
     */
    public static function lowerAndRemovePunctuation(string $s): string
    {
        if ($s === '') {
            return '';
        }
        if (isset(self::$memo[$s])) {
            return self::$memo[$s];
        }

        $input = $s;
        $s = strtr($s, ['.' => ' ', ',' => ' ', '-' => ' ']);

        if (Go::isAscii($s)) {
            $s = strtolower($s);
            // Control characters are neither letters, numbers, spaces nor punctuation: Go drops them.
            $s = (string) preg_replace('/[\x00-\x08\x0E-\x1F\x7F]/', '', $s);
            $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
            $out = trim($s, ' ');
        } else {
            $trimmed = Go::trimSpace(Go::lower($s));
            $buf = '';
            $lastWasSpace = true;
            foreach (Go::runes($trimmed) as $ch) {
                $cp = IntlChar::ord($ch);
                if ($cp === null) {
                    continue;
                }
                if (self::isPunct($cp) || self::isSymbol($cp) || self::isSpace($cp)) {
                    if (!$lastWasSpace) {
                        $buf .= ' ';
                        $lastWasSpace = true;
                    }
                    continue;
                }
                if (self::isLetter($cp) || self::isNumber($cp)) {
                    $buf .= $ch;
                    $lastWasSpace = false;
                }
            }
            $out = self::stripMarks(Go::trimSpace($buf));
        }

        if (count(self::$memo) > 50000) {
            self::$memo = [];
        }

        return self::$memo[$input] = $out;
    }

    /** NFD, remove nonspacing marks (Mn), NFC. */
    public static function stripMarks(string $s): string
    {
        if ($s === '' || Go::isAscii($s)) {
            return $s;
        }
        $nfd = Normalizer::normalize($s, Normalizer::FORM_D);
        if ($nfd === false) {
            return $s;
        }
        $nfd = (string) preg_replace('/\p{Mn}+/u', '', $nfd);
        $nfc = Normalizer::normalize($nfd, Normalizer::FORM_C);

        return $nfc === false ? $nfd : $nfc;
    }

    public static function isPunct(int $cp): bool
    {
        $t = IntlChar::charType($cp);

        return in_array($t, [
            IntlChar::CHAR_CATEGORY_CONNECTOR_PUNCTUATION, IntlChar::CHAR_CATEGORY_DASH_PUNCTUATION,
            IntlChar::CHAR_CATEGORY_START_PUNCTUATION, IntlChar::CHAR_CATEGORY_END_PUNCTUATION,
            IntlChar::CHAR_CATEGORY_INITIAL_PUNCTUATION, IntlChar::CHAR_CATEGORY_FINAL_PUNCTUATION,
            IntlChar::CHAR_CATEGORY_OTHER_PUNCTUATION,
        ], true);
    }

    public static function isSymbol(int $cp): bool
    {
        $t = IntlChar::charType($cp);

        return in_array($t, [
            IntlChar::CHAR_CATEGORY_MATH_SYMBOL, IntlChar::CHAR_CATEGORY_CURRENCY_SYMBOL,
            IntlChar::CHAR_CATEGORY_MODIFIER_SYMBOL, IntlChar::CHAR_CATEGORY_OTHER_SYMBOL,
        ], true);
    }

    public static function isLetter(int $cp): bool
    {
        $t = IntlChar::charType($cp);

        return in_array($t, [
            IntlChar::CHAR_CATEGORY_UPPERCASE_LETTER, IntlChar::CHAR_CATEGORY_LOWERCASE_LETTER,
            IntlChar::CHAR_CATEGORY_TITLECASE_LETTER, IntlChar::CHAR_CATEGORY_MODIFIER_LETTER,
            IntlChar::CHAR_CATEGORY_OTHER_LETTER,
        ], true);
    }

    public static function isNumber(int $cp): bool
    {
        $t = IntlChar::charType($cp);

        return in_array($t, [
            IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER, IntlChar::CHAR_CATEGORY_LETTER_NUMBER,
            IntlChar::CHAR_CATEGORY_OTHER_NUMBER,
        ], true);
    }

    public static function isDigit(int $cp): bool
    {
        return IntlChar::charType($cp) === IntlChar::CHAR_CATEGORY_DECIMAL_DIGIT_NUMBER;
    }

    /** Go unicode.IsSpace */
    public static function isSpace(int $cp): bool
    {
        if ($cp <= 0xFF) {
            return in_array($cp, [0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20, 0x85, 0xA0], true);
        }

        return IntlChar::charType($cp) === IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR
            || $cp === 0x2028 || $cp === 0x2029;
    }
}
