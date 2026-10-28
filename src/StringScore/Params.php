<?php

declare(strict_types=1);

namespace Watchman\StringScore;

use Watchman\Support\Go;

/**
 * Tunable scoring parameters. Defaults and environment variable names match
 * Watchman (JARO_WINKLER_BOOST_THRESHOLD, UNMATCHED_INDEX_TOKEN_WEIGHT, …).
 * Laravel users can override them in config/watchman.php (scoring section).
 */
final class Params
{
    public float $boostThreshold = 0.7;
    public int $prefixSize = 4;
    public float $lengthDifferenceCutoffFactor = 0.9;
    public float $lengthDifferencePenaltyWeight = 0.3;
    public float $differentLetterPenaltyWeight = 0.9;
    public float $exactMatchFavoritism = 0.0;
    public float $unmatchedIndexPenaltyWeight = 0.15;
    public int $adjacentSimilarityPositions = 3;
    public bool $disablePhoneticFiltering = false;
    public bool $useSoundexMatching = false;
    public float $soundexBoostWeight = 0.0;

    // pkg/search final-score multipliers
    public float $lowCoveragePenaltyMultiplier = 0.95;
    public float $minimumRequiredFieldsPenaltyMultiplier = 0.90;
    public float $nameOnlyPenaltyMultiplier = 0.95;
    public float $idConflictPenaltyMultiplier = 0.70;
    public float $nameNumeralConflictPenaltyMultiplier = 0.70;

    private static ?self $instance = null;

    public static function get(): self
    {
        return self::$instance ??= self::fromEnvironment();
    }

    public static function set(?self $params): void
    {
        self::$instance = $params;
    }

    public static function fromEnvironment(array $overrides = []): self
    {
        $p = new self();
        $env = static function (string $key) use ($overrides): ?string {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null && $overrides[$key] !== '') {
                return is_bool($overrides[$key]) ? ($overrides[$key] ? 'true' : 'false') : (string) $overrides[$key];
            }
            $v = getenv($key);

            return $v === false || $v === '' ? null : $v;
        };
        $f = static fn (string $k, float $d) => ($v = $env($k)) !== null && is_numeric($v) ? (float) $v : $d;
        $i = static fn (string $k, int $d) => ($v = $env($k)) !== null && is_numeric($v) ? (int) $v : $d;

        $p->boostThreshold = $f('JARO_WINKLER_BOOST_THRESHOLD', 0.7);
        $p->prefixSize = $i('JARO_WINKLER_PREFIX_SIZE', 4);
        $p->lengthDifferenceCutoffFactor = $f('LENGTH_DIFFERENCE_CUTOFF_FACTOR', 0.9);
        $p->lengthDifferencePenaltyWeight = $f('LENGTH_DIFFERENCE_PENALTY_WEIGHT', 0.3);
        $p->differentLetterPenaltyWeight = $f('DIFFERENT_LETTER_PENALTY_WEIGHT', 0.9);
        $p->exactMatchFavoritism = $f('EXACT_MATCH_FAVORITISM', 0.0);
        $p->unmatchedIndexPenaltyWeight = $f('UNMATCHED_INDEX_TOKEN_WEIGHT', 0.15);
        $p->adjacentSimilarityPositions = $i('ADJACENT_SIMILARITY_POSITIONS', 3);
        $p->disablePhoneticFiltering = Go::yes($env('DISABLE_PHONETIC_FILTERING'));
        $p->useSoundexMatching = Go::yes($env('USE_SOUNDEX_MATCHING'));
        $p->soundexBoostWeight = $f('SOUNDEX_BOOST_WEIGHT', 0.0);

        $p->lowCoveragePenaltyMultiplier = $f('FINAL_SCORE_LOW_COVERAGE_MULTIPLIER', 0.95);
        $p->minimumRequiredFieldsPenaltyMultiplier = $f('FINAL_SCORE_MIN_REQUIRED_FIELDS_MULTIPLIER', 0.90);
        $p->nameOnlyPenaltyMultiplier = $f('FINAL_SCORE_NAME_ONLY_MULTIPLIER', 0.95);
        $p->idConflictPenaltyMultiplier = $f('ID_CONFLICT_PENALTY_MULTIPLIER', 0.70);
        $p->nameNumeralConflictPenaltyMultiplier = $f('NAME_NUMERAL_CONFLICT_PENALTY_MULTIPLIER', 0.70);

        return $p;
    }
}
