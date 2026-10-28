<?php

declare(strict_types=1);

namespace Watchman\StringScore;

use Watchman\Support\Go;

/**
 * Port of internal/stringscore/jaro_winkler.go and github.com/xrash/smetrics.
 *
 * All length / character comparisons operate on bytes, exactly like Go.
 */
final class JaroWinkler
{
    /** smetrics.Jaro */
    public static function jaro(string $a, string $b): float
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la === 0 && $lb === 0) {
            return 1.0;
        }
        if ($la === 0 || $lb === 0) {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $matchRange = (int) max(0, floor(max($la, $lb) / 2.0) - 1);
        $matchesA = array_fill(0, $la, false);
        $matchesB = array_fill(0, $lb, false);
        $matches = 0;

        for ($i = 0; $i < $la; $i++) {
            $start = max(0, $i - $matchRange);
            $end = min($lb - 1, $i + $matchRange);
            $c = $a[$i];
            for ($j = $start; $j <= $end; $j++) {
                if ($matchesB[$j]) {
                    continue;
                }
                if ($c === $b[$j]) {
                    $matchesA[$i] = true;
                    $matchesB[$j] = true;
                    $matches++;
                    break;
                }
            }
        }
        if ($matches === 0) {
            return 0.0;
        }

        $unaligned = 0;
        $j = 0;
        for ($i = 0; $i < $la; $i++) {
            if (!$matchesA[$i]) {
                continue;
            }
            while (!$matchesB[$j]) {
                $j++;
            }
            if ($a[$i] !== $b[$j]) {
                $unaligned++;
            }
            $j++;
        }
        $transpositions = floor($unaligned / 2);
        $m = (float) $matches;

        return (($m / $la) + ($m / $lb) + (($m - $transpositions) / $m)) / 3.0;
    }

    /** smetrics.JaroWinkler */
    public static function smetrics(string $a, string $b, float $boostThreshold, int $prefixSize): float
    {
        $j = self::jaro($a, $b);
        if ($j <= $boostThreshold) {
            return $j;
        }
        $prefixSize = min(strlen($a), $prefixSize, strlen($b));
        $prefixMatch = 0.0;
        for ($i = 0; $i < $prefixSize; $i++) {
            if ($a[$i] === $b[$i]) {
                $prefixMatch++;
            } else {
                break;
            }
        }

        return $j + 0.1 * $prefixMatch * (1.0 - $j);
    }

    /** Default-parameter Jaro-Winkler (boost 0.7, prefix 4 unless overridden). */
    public static function score(string $a, string $b): float
    {
        $p = Params::get();

        return self::smetrics($a, $b, $p->boostThreshold, $p->prefixSize);
    }

    /**
     * BestPairsJaroWinkler with an explicit config.
     *
     * @param list<string> $searchTokens
     * @param list<string> $indexedTokens
     */
    public static function bestPairs(array $searchTokens, array $indexedTokens, ?ScoringConfig $cfg = null): float
    {
        $cfg ??= ScoringConfig::default();
        $p = Params::get();
        $searchLen = 0;
        foreach ($searchTokens as $t) {
            $searchLen += strlen($t);
        }
        $indexLen = 0;
        foreach ($indexedTokens as $t) {
            $indexLen += strlen($t);
        }

        $scores = self::pairScores($searchTokens, $indexedTokens, $cfg, $p);

        $matchedSearch = [];
        $matchedIndex = [];
        $matchedIndexTokensLength = 0;
        $totalWeighted = 0.0;
        foreach ($scores as [$score, $si, $ii]) {
            if (!isset($matchedSearch[$si]) && !isset($matchedIndex[$ii])) {
                $totalWeighted += $score * (strlen($searchTokens[$si]) + strlen($indexedTokens[$ii]));
                $matchedSearch[$si] = true;
                $matchedIndex[$ii] = true;
                $matchedIndexTokensLength += strlen($indexedTokens[$ii]);
            }
        }
        $avg = fdiv($totalWeighted, (float) ($searchLen + $matchedIndexTokensLength));
        if (is_nan($avg)) {
            $avg = 0.0;
        }
        $matchedIndexLength = 0;
        foreach ($indexedTokens as $i => $str) {
            if (isset($matchedIndex[$i])) {
                $matchedIndexLength += strlen($str);
            }
        }
        $fraction = fdiv((float) $matchedIndexLength, (float) $indexLen);
        if (is_nan($fraction)) {
            $fraction = 0.0;
        }

        return $avg * self::scalingFactor($fraction, $p->unmatchedIndexPenaltyWeight);
    }

    /**
     * BestPairsJaroWinklerWeighted (TF-IDF weights).
     *
     * @param list<string> $searchTokens
     * @param list<string> $indexedTokens
     * @param list<float>  $searchWeights
     * @param list<float>  $indexWeights
     */
    public static function bestPairsWeighted(array $searchTokens, array $indexedTokens, array $searchWeights, array $indexWeights, ?ScoringConfig $cfg = null): float
    {
        $cfg ??= ScoringConfig::default();
        if (count($searchWeights) !== count($searchTokens) || count($indexWeights) !== count($indexedTokens)) {
            return self::bestPairs($searchTokens, $indexedTokens, $cfg);
        }
        $p = Params::get();
        $scores = self::pairScores($searchTokens, $indexedTokens, $cfg, $p);

        $matchedSearch = [];
        $matchedIndex = [];
        $totalWeighted = 0.0;
        $totalWeight = 0.0;
        foreach ($scores as [$score, $si, $ii]) {
            if (!isset($matchedSearch[$si]) && !isset($matchedIndex[$ii])) {
                $pairWeight = ($searchWeights[$si] + $indexWeights[$ii]) / 2.0;
                $totalWeighted += $score * $pairWeight;
                $totalWeight += $pairWeight;
                $matchedSearch[$si] = true;
                $matchedIndex[$ii] = true;
            }
        }
        if ($totalWeight == 0.0) {
            return 0.0;
        }
        $avg = $totalWeighted / $totalWeight;
        $matchedWeight = 0.0;
        $totalIndexWeight = 0.0;
        foreach ($indexedTokens as $i => $_) {
            $totalIndexWeight += $indexWeights[$i];
            if (isset($matchedIndex[$i])) {
                $matchedWeight += $indexWeights[$i];
            }
        }
        if ($totalIndexWeight > 0) {
            return $avg * self::scalingFactor($matchedWeight / $totalIndexWeight, $p->unmatchedIndexPenaltyWeight);
        }

        return $avg;
    }

    /**
     * Pairwise token scores sorted by score descending (stable).
     *
     * @return list<array{0:float,1:int,2:int}>
     */
    private static function pairScores(array $searchTokens, array $indexedTokens, ScoringConfig $cfg, Params $p): array
    {
        $scores = [];
        $skipPhonetic = $p->disablePhoneticFiltering;
        foreach ($searchTokens as $si => $searchToken) {
            foreach ($indexedTokens as $ii => $indexedToken) {
                if ($skipPhonetic || Phonetics::firstCharacterSoundexMatch($indexedToken, $searchToken)) {
                    $scores[] = [self::tokenSimilarity($indexedToken, $searchToken, $cfg, $p), $si, $ii];
                }
            }
        }
        if (count($scores) > 1) {
            usort($scores, static fn ($x, $y) => $y[0] <=> $x[0]);
        }

        return $scores;
    }

    public static function tokenSimilarity(string $s1, string $s2, ScoringConfig $cfg, ?Params $p = null): float
    {
        $p ??= Params::get();
        $score = match ($cfg->tokenScorer) {
            ScoringConfig::SCORER_SOFT_BIDIST => Algorithms\SoftBigram::similarity($s1, $s2),
            ScoringConfig::SCORER_SOFT_BISIM => Algorithms\SoftBisim::similarity($s1, $s2),
            ScoringConfig::SCORER_EDITEX => Algorithms\Editex::similarity($s1, $s2),
            ScoringConfig::SCORER_NSIM => Algorithms\NGram::nsim($s1, $s2, 2),
            ScoringConfig::SCORER_NSIM3 => Algorithms\NGram::nsim($s1, $s2, 3),
            default => self::smetrics($s1, $s2, $p->boostThreshold, $p->prefixSize),
        };

        $l1 = strlen($s1);
        $l2 = strlen($s2);
        $lengthMetric = fdiv((float) min($l1, $l2), (float) max($l1, $l2));
        if ($lengthMetric < $p->lengthDifferenceCutoffFactor) {
            $score *= self::scalingFactor($lengthMetric, $p->lengthDifferencePenaltyWeight);
        }
        if ($l1 > 0 && $l2 > 0 && !Phonetics::firstCharacterSoundexMatch($s1, $s2)) {
            $score *= $p->differentLetterPenaltyWeight;
        }
        if ($cfg->soundexBoostWeight > 0) {
            $boost = false;
            if ($cfg->useSoundexBoost && Soundex::match($s1, $s2)) {
                $boost = true;
            }
            if ($cfg->useDoubleMetaphoneBoost && Algorithms\DoubleMetaphone::match($s1, $s2)) {
                $boost = true;
            }
            if ($cfg->useBeiderMorseBoost && Algorithms\BeiderMorse::match($s1, $s2)) {
                $boost = true;
            }
            if ($boost) {
                $score *= (1.0 + $cfg->soundexBoostWeight);
                if ($score > 1.0) {
                    $score = 1.0;
                }
            }
        }

        return $score;
    }

    public static function scalingFactor(float $metric, float $weight): float
    {
        return 1.0 - (1.0 - $metric) * $weight;
    }

    /**
     * stringscore.JaroWinkler (adjacent-term matching with favoritism).
     */
    public static function jaroWinkler(string $s1, string $s2): float
    {
        return self::withFavoritism($s1, $s2, Params::get()->exactMatchFavoritism);
    }

    public static function withFavoritism(string $indexedTerm, string $query, float $favoritism): float
    {
        $p = Params::get();
        $indexedWords = Go::fields($indexedTerm);
        $queryWords = Go::fields($query);
        $ni = count($indexedWords);
        $nq = count($queryWords);
        if ($ni === 0 || $nq === 0) {
            return 0.0;
        }
        $scores = [];
        foreach ($indexedWords as $i => $word) {
            $max = 0.0;
            $term = '';
            if ($word !== '') {
                $start = $i - $p->adjacentSimilarityPositions;
                $end = $i + $p->adjacentSimilarityPositions;
                for ($k = $start; $k < $end; $k++) {
                    if ($k >= 0 && $nq > $k) {
                        $s = self::smetrics($word, $queryWords[$k], $p->boostThreshold, $p->prefixSize);
                        if ($s > $max) {
                            $max = $s;
                            $term = $queryWords[$k];
                        }
                    }
                }
            }
            if ($max >= 1.0) {
                if ($nq > $ni && ($ni > 3 || $nq > 3)) {
                    $max *= ($ni / $nq);
                } elseif ($ni === 1 && $nq > 1) {
                    $max *= 0.9;
                } else {
                    $max += $favoritism;
                }
                $scores[] = $max;
            } else {
                if ($nq > $ni) {
                    $scores[] = $max * $ni / $nq;
                    continue;
                }
                $s1 = (float) strlen($word);
                $t = (float) strlen($term) - 1;
                $weight = min(abs(fdiv($s1, $t)), 1.0);
                $scores[] = $max * $weight;
            }
        }
        sort($scores);
        if ($ni > $nq && $nq > 5) {
            $scores = array_slice($scores, $ni - $nq);
        }

        return min(array_sum($scores) / count($scores), 1.0);
    }

    /**
     * GenerateWordCombinations: original tokens, short words joined forward, and backward.
     *
     * @param list<string> $tokens
     * @return list<list<string>>
     */
    public static function wordCombinations(array $tokens): array
    {
        static $memo = [];
        $key = implode("\x00", $tokens);
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        if (count($memo) > 20000) {
            $memo = [];
        }

        return $memo[$key] = self::computeWordCombinations($tokens);
    }

    /**
     * @param list<string> $tokens
     * @return list<list<string>>
     */
    private static function computeWordCombinations(array $tokens): array
    {
        $n = count($tokens);
        if ($n <= 1) {
            return [$tokens];
        }
        $result = [$tokens];
        $forward = [];
        $skip = false;
        for ($i = 0; $i < $n; $i++) {
            if ($skip) {
                $skip = false;
                continue;
            }
            if ($i < $n - 1 && strlen($tokens[$i]) <= 3) {
                $forward[] = $tokens[$i] . $tokens[$i + 1];
                $skip = true;
            } else {
                $forward[] = $tokens[$i];
            }
        }
        if (count($forward) < $n) {
            $result[] = $forward;
            $backward = [];
            for ($i = 0; $i < $n; $i++) {
                if ($i > 0 && strlen($tokens[$i]) <= 3) {
                    if ($backward !== []) {
                        $backward[count($backward) - 1] .= $tokens[$i];
                    }
                } else {
                    $backward[] = $tokens[$i];
                }
            }
            if (count($backward) < $n && $backward !== $forward) {
                $result[] = $backward;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $tokens
     * @param list<float>  $weights
     * @return list<list<float>>
     */
    public static function weightCombinations(array $tokens, array $weights): array
    {
        $n = count($tokens);
        if ($n <= 1 || count($weights) !== $n) {
            return [$weights];
        }
        $result = [$weights];
        $forward = [];
        $skip = false;
        for ($i = 0; $i < $n; $i++) {
            if ($skip) {
                $skip = false;
                continue;
            }
            if ($i < $n - 1 && strlen($tokens[$i]) <= 3) {
                $forward[] = $weights[$i] + $weights[$i + 1];
                $skip = true;
            } else {
                $forward[] = $weights[$i];
            }
        }
        if (count($forward) < $n) {
            $result[] = $forward;
            $backward = [];
            for ($i = 0; $i < $n; $i++) {
                if ($i > 0 && strlen($tokens[$i]) <= 3) {
                    if ($backward !== []) {
                        $backward[count($backward) - 1] += $weights[$i];
                    }
                } else {
                    $backward[] = $weights[$i];
                }
            }
            if (count($backward) < $n && !self::floatsEqual($backward, $forward)) {
                $result[] = $backward;
            }
        }

        return $result;
    }

    private static function floatsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $v) {
            if (abs($v - $b[$i]) > 0.001) {
                return false;
            }
        }

        return true;
    }

    /**
     * BestPairCombinationJaroWinkler
     *
     * @param list<string> $searchTokens
     * @param list<string> $indexedTokens
     */
    public static function bestPairCombination(array $searchTokens, array $indexedTokens, ?ScoringConfig $cfg = null): float
    {
        $cfg ??= ScoringConfig::default();
        $max = 0.0;
        foreach (self::wordCombinations($searchTokens) as $sv) {
            foreach (self::wordCombinations($indexedTokens) as $iv) {
                $score = self::bestPairs($sv, $iv, $cfg);
                if ($score > $max) {
                    $max = $score;
                }
            }
        }

        return $max;
    }

    /**
     * BestPairCombinationJaroWinklerWeighted
     */
    public static function bestPairCombinationWeighted(array $searchTokens, array $indexedTokens, array $searchWeights, array $indexWeights, ?ScoringConfig $cfg = null): float
    {
        $cfg ??= ScoringConfig::default();
        $sc = self::wordCombinations($searchTokens);
        $ic = self::wordCombinations($indexedTokens);
        $sw = self::weightCombinations($searchTokens, $searchWeights);
        $iw = self::weightCombinations($indexedTokens, $indexWeights);
        $max = 0.0;
        foreach ($sc as $si => $sv) {
            foreach ($ic as $ii => $iv) {
                $score = self::bestPairsWeighted($sv, $iv, $sw[$si] ?? [], $iw[$ii] ?? [], $cfg);
                if ($score > $max) {
                    $max = $score;
                }
            }
        }

        return $max;
    }
}
