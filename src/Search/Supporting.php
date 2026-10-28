<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\StringScore\JaroWinkler;
use Watchman\Support\Go;

/** Port of pkg/search/similarity_supporting.go */
final class Supporting
{
    public static function compare(Entity $q, Entity $i, float $weight): ScorePiece
    {
        $fc = 0;
        $scores = [];
        if ($q->sanctionsInfo !== null && $i->sanctionsInfo !== null) {
            $fc++;
            $s = self::programs($q->sanctionsInfo, $i->sanctionsInfo);
            if ($s > 0) {
                $scores[] = $s;
            }
        }
        if ($q->historicalInfo !== [] && $i->historicalInfo !== []) {
            $fc++;
            $best = 0.0;
            foreach ($q->historicalInfo as $qh) {
                foreach ($i->historicalInfo as $ih) {
                    if (!Go::equalFold($qh->type, $ih->type)) {
                        continue;
                    }
                    $sim = JaroWinkler::jaroWinkler($qh->value, $ih->value);
                    if ($sim > $best) {
                        $best = $sim;
                    }
                }
            }
            if ($best > 0) {
                $scores[] = $best;
            }
        }
        if ($scores === []) {
            return new ScorePiece(0, $weight, false, false, false, 0, 'supporting');
        }
        $avg = Similarity::average($scores);

        return new ScorePiece($avg, $weight, $avg > 0.5, false, $avg > 0.99, $fc, 'supporting');
    }

    private static function programs(SanctionsInfo $q, SanctionsInfo $i): float
    {
        $score = 0.0;
        if ($q->programs !== [] && $i->programs !== []) {
            $matches = 0;
            foreach ($q->programs as $qp) {
                foreach ($i->programs as $ip) {
                    if (Go::equalFold($qp, $ip)) {
                        $matches++;
                        break;
                    }
                }
            }
            $score = $matches / count($q->programs);
        }
        if ($q->secondary !== $i->secondary) {
            $score *= 0.8;
        }

        return $score;
    }
}
