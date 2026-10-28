<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Normalizer;
use Watchman\Support\Data;
use Watchman\Support\Go;

/**
 * Port of internal/prepare/pipeline_stopwords.go plus bbalet/stopwords v1.0.0.
 */
final class Stopwords
{
    private static ?bool $keep = null;

    /** @var array<string,string> */
    private static array $memo = [];

    /** Matches numbers that are never cleaned (numberRegex in Go). */
    private const NUMBER_RE = '/([\d\.\,\-]{1,}[\d]{1,})/';

    /** bbalet/stopwords default word segmenter: letters, Mc, Mn, '-', '_', '\''. */
    private const WORD_RE = "/[\\pL\\p{Mc}\\p{Mn}\\-_']+/u";

    public static function keepStopwords(): bool
    {
        if (self::$keep === null) {
            $raw = (string) getenv('KEEP_STOPWORDS');
            self::$keep = in_array($raw, ['1', 't', 'T', 'TRUE', 'true', 'True'], true);
        }

        return self::$keep;
    }

    /** For tests / config: override KEEP_STOPWORDS. */
    public static function setKeepStopwords(?bool $keep): void
    {
        self::$keep = $keep;
        self::$memo = [];
    }

    public static function remove(string $input): string
    {
        if (self::keepStopwords()) {
            return $input;
        }
        if (isset(self::$memo[$input])) {
            return self::$memo[$input];
        }
        $out = self::trivial($input);
        if ($out === null) {
            $info = LanguageDetector::detect($input);
            $out = self::removeForLanguage($input, $info['iso6391']);
        }
        if (count(self::$memo) > 50000) {
            self::$memo = [];
        }

        return self::$memo[$input] = $out;
    }

    /**
     * Returns the normalized text when no language could change the result.
     */
    private static function trivial(string $input): ?string
    {
        $union = Data::get('stopwords_union');
        $words = Go::fields(Go::lower($input));
        foreach ($words as $w) {
            if (preg_match(self::NUMBER_RE, $w)) {
                continue;
            }
            if (isset($union[$w])) {
                return null;
            }
            if (!Go::isAscii($w) && Normalizer::normalize($w, Normalizer::FORM_C) !== $w) {
                return null;
            }
            if (!preg_match_all(self::WORD_RE, $w, $m) || count($m[0]) !== 1 || $m[0][0] !== $w) {
                return null;
            }
        }

        return implode(' ', $words);
    }

    public static function removeForLanguage(string $input, string $iso6391): string
    {
        $dicts = Data::get('stopwords');
        // golang.org/x/text: language.Make("").Base() infers "en" for an undetermined language,
        // so names whose detected language has no ISO 639-1 code get English stopwords.
        $dict = $dicts[$iso6391 === '' ? 'en' : $iso6391] ?? null;
        $out = [];
        foreach (Go::fields(Go::lower($input)) as $word) {
            $cleaned = Go::trimSpace($word);
            if (!preg_match(self::NUMBER_RE, $cleaned)) {
                $cleaned = Go::trimSpace(self::clean($cleaned, $dict));
            }
            if ($cleaned !== '') {
                $out[] = $cleaned;
            }
        }

        return implode(' ', $out);
    }

    /**
     * stopwords.CleanString(content, lang, false)
     *
     * @param array<string,bool>|null $dict
     */
    private static function clean(string $content, ?array $dict): string
    {
        if ($dict !== null) {
            $nfc = Normalizer::normalize($content, Normalizer::FORM_C);
            $content = Go::lower($nfc === false ? $content : $nfc);
            $result = '';
            if (preg_match_all(self::WORD_RE, $content, $m)) {
                foreach ($m[0] as $w) {
                    $result .= isset($dict[$w]) ? ' ' : $w . ' ';
                }
            }
            $content = $result;
        }

        return (string) preg_replace('/\s{2,}/', ' ', $content);
    }
}
