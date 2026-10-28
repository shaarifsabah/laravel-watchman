<?php

declare(strict_types=1);

namespace Watchman\Search;

use InvalidArgumentException;
use Watchman\StringScore\ScoringConfig;

/**
 * Name-matching algorithm selection (pkg/search/algorithm.go).
 */
final class Algorithm
{
    public const JARO_WINKLER = 'jaro-winkler';
    public const SOUNDEX = 'soundex';
    public const SOFT_BIDIST = 'soft-bidist';
    public const SOFT_BISIM = 'soft-bisim';
    public const EDITEX = 'editex';
    public const NSIM = 'nsim';
    public const NSIM3 = 'nsim-3';
    public const DOUBLE_METAPHONE = 'double-metaphone';
    public const BEIDER_MORSE = 'beider-morse';

    private const DEFAULT_BOOST = 0.12;
    public const SUPPORTED = 'jaro-winkler, soundex, soft-bidist, soft-bisim, editex, nsim, nsim-3, double-metaphone, beider-morse';

    /** @var array<string, ScoringConfig> */
    private static array $configs = [];

    public static function parse(?string $raw): string
    {
        $v = str_replace('_', '-', strtolower(trim((string) $raw)));

        return match ($v) {
            '' => '',
            self::JARO_WINKLER, 'jarowinkler', 'jw' => self::JARO_WINKLER,
            self::SOUNDEX => self::SOUNDEX,
            self::SOFT_BIDIST, 'soft-bigram', 'bidist' => self::SOFT_BIDIST,
            self::SOFT_BISIM, 'bisim' => self::SOFT_BISIM,
            self::EDITEX => self::EDITEX,
            self::NSIM, 'n-sim', 'kondrak' => self::NSIM,
            self::NSIM3, 'nsim3', 'trigram' => self::NSIM3,
            self::DOUBLE_METAPHONE, 'doublemetaphone', 'dmetaphone', 'metaphone' => self::DOUBLE_METAPHONE,
            self::BEIDER_MORSE, 'beidermorse', 'bmpm' => self::BEIDER_MORSE,
            default => throw new InvalidArgumentException('unknown algorithm (supported: ' . self::SUPPORTED . ')'),
        };
    }

    public static function name(string $algorithm): string
    {
        return $algorithm === '' ? self::JARO_WINKLER : $algorithm;
    }

    public static function scoringConfig(string $algorithm): ScoringConfig
    {
        if (isset(self::$configs[$algorithm])) {
            return self::$configs[$algorithm];
        }
        $cfg = ScoringConfig::default();
        switch ($algorithm) {
            case self::SOUNDEX:
                $cfg->useSoundexBoost = true;
                if ($cfg->soundexBoostWeight <= 0) {
                    $cfg->soundexBoostWeight = self::DEFAULT_BOOST;
                }
                break;
            case self::JARO_WINKLER:
                $cfg->useSoundexBoost = false;
                break;
            case self::SOFT_BIDIST:
                $cfg->useSoundexBoost = false;
                $cfg->tokenScorer = ScoringConfig::SCORER_SOFT_BIDIST;
                break;
            case self::SOFT_BISIM:
                $cfg->useSoundexBoost = false;
                $cfg->tokenScorer = ScoringConfig::SCORER_SOFT_BISIM;
                break;
            case self::EDITEX:
                $cfg->useSoundexBoost = false;
                $cfg->tokenScorer = ScoringConfig::SCORER_EDITEX;
                break;
            case self::NSIM:
                $cfg->useSoundexBoost = false;
                $cfg->tokenScorer = ScoringConfig::SCORER_NSIM;
                break;
            case self::NSIM3:
                $cfg->useSoundexBoost = false;
                $cfg->tokenScorer = ScoringConfig::SCORER_NSIM3;
                break;
            case self::DOUBLE_METAPHONE:
                $cfg->useSoundexBoost = false;
                $cfg->useDoubleMetaphoneBoost = true;
                if ($cfg->soundexBoostWeight <= 0) {
                    $cfg->soundexBoostWeight = self::DEFAULT_BOOST;
                }
                break;
            case self::BEIDER_MORSE:
                $cfg->useSoundexBoost = false;
                $cfg->useBeiderMorseBoost = true;
                if ($cfg->soundexBoostWeight <= 0) {
                    $cfg->soundexBoostWeight = self::DEFAULT_BOOST;
                }
                break;
        }

        return self::$configs[$algorithm] = $cfg;
    }

    /** Clear cached configs (after changing scoring Params). */
    public static function reset(): void
    {
        self::$configs = [];
    }
}
