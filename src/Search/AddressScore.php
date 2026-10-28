<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\StringScore\JaroWinkler;
use Watchman\Support\Go;

/** Port of pkg/search/similarity_address.go */
final class AddressScore
{
    private const LINE1 = 5.0;
    private const LINE2 = 2.0;
    private const CITY = 4.0;
    private const STATE = 2.0;
    private const POSTAL = 3.0;
    private const COUNTRY = 4.0;

    public static function compare(Entity $q, Entity $i, float $weight, ?DebugLog $w = null): ScorePiece
    {
        $w?->write(sprintf("address comparison details: query=%d  index=%d\n", count($q->addresses), count($i->addresses)));
        if ($q->addresses !== [] && $i->addresses !== []) {
            $score = self::best($q->preparedFields->addresses, $i->preparedFields->addresses, $w);
            if ($score > 0) {
                return new ScorePiece($score, $weight, $score > 0.5, false, $score > 0.99, 1, 'address');
            }
        }

        return new ScorePiece(0, $weight, false, false, false, 0, 'address');
    }

    /**
     * @param list<PreparedAddress> $qa
     * @param list<PreparedAddress> $ia
     */
    private static function best(array $qa, array $ia, ?DebugLog $w): float
    {
        $best = 0.0;
        foreach ($qa as $x => $q) {
            foreach ($ia as $y => $i) {
                $w?->write(sprintf("Comparing Query Address %d with Index Address %d:\n", $x + 1, $y + 1));
                $s = self::one($q, $i, $w);
                if ($s > $best) {
                    $best = $s;
                    if ($s > Similarity::HIGH_CONFIDENCE_THRESHOLD) {
                        $w?->write(sprintf("Found high confidence match (%.2f), stopping search\n", $s));

                        return $s;
                    }
                }
            }
        }

        return $best;
    }

    private static function one(PreparedAddress $q, PreparedAddress $i, ?DebugLog $w): float
    {
        $total = 0.0;
        $weight = 0.0;
        $fuzzy = [['Line1', 'line1Fields', 'line1', self::LINE1], ['Line2', 'line2Fields', 'line2', self::LINE2], ['City', 'cityFields', 'city', self::CITY]];
        foreach ($fuzzy as [$label, $fields, $raw, $wt]) {
            if ($q->{$fields} !== [] && $i->{$fields} !== []) {
                $sim = JaroWinkler::bestPairCombination($q->{$fields}, $i->{$fields});
                $total += $sim * $wt;
                $weight += $wt;
                $w?->write(sprintf("  %s: %.3f (weight: %.1f) [%s] vs [%s]\n", $label, $sim, $wt, $q->{$raw}, $i->{$raw}));
            }
        }
        $exact = [['State', 'state', self::STATE], ['Postal', 'postalCode', self::POSTAL], ['Country', 'country', self::COUNTRY]];
        foreach ($exact as [$label, $field, $wt]) {
            if ($q->{$field} !== '' && $i->{$field} !== '') {
                $s = Go::equalFold($q->{$field}, $i->{$field}) ? 1.0 : 0.0;
                $total += $s * $wt;
                $weight += $wt;
                $w?->write(sprintf("  %s: %.3f (weight: %.1f) [%s] vs [%s]\n", $label, $s, $wt, $q->{$field}, $i->{$field}));
            }
        }
        if ($weight == 0.0) {
            $w?->write("  No fields compared\n");

            return 0.0;
        }
        $final = $total / $weight;
        $w?->write(sprintf("  Final Score: %.3f (total score: %.2f / total weight: %.2f)\n", $final, $total, $weight));

        return $final;
    }
}
