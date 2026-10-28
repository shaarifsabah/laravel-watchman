<?php

declare(strict_types=1);

namespace Watchman\StringScore;

/**
 * Per-comparison scoring options. Mirrors stringscore.ScoringConfig.
 */
final class ScoringConfig
{
    public const SCORER_JARO_WINKLER = 0;
    public const SCORER_SOFT_BIDIST = 1;
    public const SCORER_SOFT_BISIM = 2;
    public const SCORER_EDITEX = 3;
    public const SCORER_NSIM = 4;
    public const SCORER_NSIM3 = 5;

    public function __construct(
        public bool $useSoundexBoost = false,
        public bool $useDoubleMetaphoneBoost = false,
        public bool $useBeiderMorseBoost = false,
        public float $soundexBoostWeight = 0.0,
        public int $tokenScorer = self::SCORER_JARO_WINKLER,
    ) {
    }

    /** DefaultScoringConfig(): process-wide env flags. */
    public static function default(): self
    {
        $p = Params::get();

        return new self($p->useSoundexMatching, false, false, $p->soundexBoostWeight);
    }
}
