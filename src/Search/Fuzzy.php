<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\StringScore\JaroWinkler;
use Watchman\StringScore\ScoringConfig;
use Watchman\Support\Go;

/** Port of pkg/search/similarity_fuzzy.go (names and titles). */
final class Fuzzy
{
    private const MIN_MATCHING_TERMS = 2;
    private const TERM_MATCH_THRESHOLD = 0.90;

    private const TITLE_MATCH_THRESHOLD = 0.85;
    private const MIN_TITLE_TERM_LENGTH = 2;
    private const ABBREVIATION_THRESHOLD = 0.92;

    private const TITLE_ABBREVIATIONS = [
        'ceo' => 'chief executive officer', 'cfo' => 'chief financial officer', 'coo' => 'chief operating officer',
        'pres' => 'president', 'vp' => 'vice president', 'dir' => 'director', 'exec' => 'executive',
        'mgr' => 'manager', 'sr' => 'senior', 'jr' => 'junior', 'asst' => 'assistant', 'assoc' => 'associate',
        'tech' => 'technical', 'admin' => 'administrator', 'eng' => 'engineer', 'dev' => 'developer',
    ];

    public static function name(Entity $query, Entity $index, float $weight, SimilarityOpts $opts): ScorePiece
    {
        $qp = $query->preparedFields;
        $ip = $index->preparedFields;
        if ($qp->name === '') {
            return new ScorePiece(0, 0, false, false, false, 0, 'name');
        }
        if ($qp->name === $ip->name) {
            return new ScorePiece(1.0, $weight, true, true, true, 1, 'name');
        }

        $queryTerms = $qp->nameFields;
        $queryWeights = $qp->nameWeights;
        $cfg = Algorithm::scoringConfig($opts->algorithm);
        $tfidf = $opts->tfidf;

        $best = self::compareTerms($queryTerms, $ip->nameFields, $queryWeights, $ip->nameWeights, $tfidf, $cfg);
        $take = static function (array $candidate, bool $historical) use (&$best): void {
            if ($historical) {
                $candidate['score'] *= 0.95;
                $candidate['isHistorical'] = true;
            }
            if ($candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        };

        if ($best['score'] < Similarity::EXACT_MATCH_THRESHOLD) {
            foreach ($ip->altNameFields as $idx => $alt) {
                $take(self::compareTerms($queryTerms, $alt, $queryWeights, $ip->altNameWeights[$idx] ?? [], $tfidf, $cfg), false);
                if ($best['score'] >= Similarity::EXACT_MATCH_THRESHOLD) {
                    break;
                }
            }
        }
        if ($best['score'] < Similarity::EXACT_MATCH_THRESHOLD) {
            foreach ($ip->historicalNameFields as $idx => $hist) {
                $take(self::compareTerms($queryTerms, $hist, $queryWeights, $ip->historicalNameWeights[$idx] ?? [], $tfidf, $cfg), true);
                if ($best['score'] >= Similarity::EXACT_MATCH_THRESHOLD) {
                    break;
                }
            }
        }
        if ($best['score'] < Similarity::EXACT_MATCH_THRESHOLD) {
            foreach ($qp->historicalNameFields as $qIdx => $qHist) {
                $qHistWeights = $qp->historicalNameWeights[$qIdx] ?? [];
                $take(self::compareTerms($qHist, $ip->nameFields, $qHistWeights, $ip->nameWeights, $tfidf, $cfg), true);
                if ($best['score'] >= Similarity::EXACT_MATCH_THRESHOLD) {
                    break;
                }
                foreach ($ip->altNameFields as $idx => $alt) {
                    $take(self::compareTerms($qHist, $alt, $qHistWeights, $ip->altNameWeights[$idx] ?? [], $tfidf, $cfg), true);
                    if ($best['score'] >= Similarity::EXACT_MATCH_THRESHOLD) {
                        break;
                    }
                }
                if ($best['score'] >= Similarity::EXACT_MATCH_THRESHOLD) {
                    break;
                }
                foreach ($ip->historicalNameFields as $idx => $hist) {
                    $take(self::compareTerms($qHist, $hist, $qHistWeights, $ip->historicalNameWeights[$idx] ?? [], $tfidf, $cfg), true);
                    if ($best['score'] >= Similarity::EXACT_MATCH_THRESHOLD) {
                        break;
                    }
                }
            }
        }

        $termCount = count($queryTerms);
        if ($best['isHistorical'] && $termCount < self::MIN_MATCHING_TERMS) {
            $termCount = $best['totalTerms'];
        }
        if ($best['matchingTerms'] < self::MIN_MATCHING_TERMS && $termCount >= self::MIN_MATCHING_TERMS) {
            $best['score'] *= 0.8;
        }
        if (!$best['isHistorical'] && !self::isNameCloseEnough($qp, $ip)) {
            $best['score'] *= 0.85;
        }

        return new ScorePiece($best['score'], $weight, $best['score'] > 0.6, true, $best['isExact'], 1, 'name');
    }

    /**
     * compareNameTermsWeighted
     *
     * @return array{score:float,matchingTerms:int,totalTerms:int,isExact:bool,isHistorical:bool}
     */
    private static function compareTerms(array $queryTerms, array $indexTerms, array $queryWeights, array $indexWeights, ?\Watchman\Index\TfIdf $tfidf, ScoringConfig $cfg): array
    {
        $score = 0.0;
        if ($indexTerms !== []) {
            $useWeighted = false;
            if (count($queryWeights) === count($queryTerms) && count($indexWeights) === count($indexTerms)) {
                $useWeighted = true;
            } elseif ($tfidf !== null && $tfidf->enabled()) {
                $queryWeights = $tfidf->weights($queryTerms);
                $indexWeights = $tfidf->weights($indexTerms);
                $useWeighted = count($queryWeights) === count($queryTerms) && count($indexWeights) === count($indexTerms);
            }
            $score = $useWeighted
                ? JaroWinkler::bestPairCombinationWeighted($queryTerms, $indexTerms, $queryWeights, $indexWeights, $cfg)
                : JaroWinkler::bestPairCombination($queryTerms, $indexTerms, $cfg);
        }

        return [
            'score' => $score,
            'matchingTerms' => $score > self::TERM_MATCH_THRESHOLD ? count($queryTerms) : 0,
            'totalTerms' => count($queryTerms),
            'isExact' => $score > Similarity::EXACT_MATCH_THRESHOLD,
            'isHistorical' => false,
        ];
    }

    private static function isNameCloseEnough(PreparedFields $query, PreparedFields $index): bool
    {
        $qTermCount = substr_count($query->name, ' ') + 1;
        $iTermCount = substr_count($index->name, ' ') + 1;
        $ratio = $qTermCount / $iTermCount;
        if ($ratio < 0.3 || $ratio > 3.0) {
            return false;
        }
        $matchCount = 0;
        foreach ($query->nameFields as $q) {
            foreach ($index->nameFields as $i) {
                if (str_contains($i, $q) || str_contains($q, $i)) {
                    $matchCount++;
                    break;
                }
            }
        }

        return $matchCount / $qTermCount >= 0.4;
    }

    public static function titles(Entity $query, Entity $index, float $weight): ScorePiece
    {
        if ($query->person === null || $index->person === null) {
            return new ScorePiece(0, 0, false, false, false, 0, 'titles');
        }
        $indexTitles = [];
        foreach ($index->person->titles as $t) {
            $n = self::normalizeTitle($t);
            if ($n !== '') {
                $indexTitles[] = $n;
            }
        }
        if ($indexTitles === []) {
            return new ScorePiece(0, 0, false, false, false, 0, 'titles');
        }
        $fc = 0;
        $matches = 0;
        $total = 0;
        foreach ($query->person->titles as $qTitle) {
            $nq = self::normalizeTitle($qTitle);
            if ($nq === '') {
                continue;
            }
            $fc++;
            $total++;
            $score = self::findBestTitleMatch($nq, $indexTitles);
            if ($score > 0) {
                if ($score > self::TITLE_MATCH_THRESHOLD) {
                    $matches++;
                }
                continue;
            }
            if (self::findBestTitleMatch(self::expandAbbreviations($nq), $indexTitles) > self::TITLE_MATCH_THRESHOLD) {
                $matches++;
                continue;
            }
            $bestScore = 0.0;
            foreach ($indexTitles as $iTitle) {
                $s = self::titleSimilarity($nq, self::expandAbbreviations($iTitle));
                if ($s > $bestScore) {
                    $bestScore = $s;
                }
            }
            if ($bestScore > self::TITLE_MATCH_THRESHOLD) {
                $matches++;
            }
        }
        $final = $total > 0 ? $matches / $total : 0.0;

        return new ScorePiece($final, $weight, $final > 0.5, false, $final > Similarity::EXACT_MATCH_THRESHOLD, $fc, 'titles');
    }

    public static function normalizeTitle(string $title): string
    {
        $title = Go::trimSpace(Go::lower($title));
        // Go's \w and \s are ASCII-only.
        $title = (string) preg_replace('/[^0-9A-Za-z_\t\n\f\r \-]/u', ' ', $title);
        $title = (string) preg_replace('/[\t\n\f\r ]+/', ' ', $title);

        return Go::trimSpace($title);
    }

    private static function expandAbbreviations(string $title): string
    {
        $out = [];
        foreach (Go::fields($title) as $w) {
            $out[] = self::TITLE_ABBREVIATIONS[$w] ?? $w;
        }

        return implode(' ', $out);
    }

    private static function titleSimilarity(string $t1, string $t2): float
    {
        if ($t1 === $t2) {
            return 1.0;
        }
        $a = array_values(array_filter(Go::fields($t1), static fn ($t) => strlen($t) >= self::MIN_TITLE_TERM_LENGTH));
        $b = array_values(array_filter(Go::fields($t2), static fn ($t) => strlen($t) >= self::MIN_TITLE_TERM_LENGTH));
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $score = JaroWinkler::bestPairCombination($a, $b);
        $diff = abs(count($a) - count($b));
        if ($diff > 0) {
            $score *= (1.0 - ($diff * 0.1));
        }

        return $score;
    }

    private static function findBestTitleMatch(string $queryTitle, array $indexTitles): float
    {
        $best = 0.0;
        foreach ($indexTitles as $t) {
            $s = self::titleSimilarity($queryTitle, $t);
            if ($s > $best) {
                $best = $s;
                if ($s > self::ABBREVIATION_THRESHOLD) {
                    break;
                }
            }
        }

        return $best;
    }
}
