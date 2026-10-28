<?php

declare(strict_types=1);

namespace Watchman\Search;

use DateTimeImmutable;

/** Port of pkg/search/similarity_close.go */
final class Dates
{
    public static function compare(Entity $q, Entity $i, float $weight): ScorePiece
    {
        [$score, $matched, $fc] = match ($q->type) {
            EntityType::PERSON => self::person($q->person, $i->person),
            EntityType::BUSINESS => self::business($q->business, $i->business),
            EntityType::ORGANIZATION => self::business($q->organization, $i->organization),
            EntityType::VESSEL => self::single($q->vessel?->built, $i->vessel?->built, $q->vessel !== null && $i->vessel !== null),
            EntityType::AIRCRAFT => self::single($q->aircraft?->built, $i->aircraft?->built, $q->aircraft !== null && $i->aircraft !== null),
            default => [0.0, false, 0],
        };

        return new ScorePiece($score, $weight, $matched, $fc > 0, $score > 0.99, $fc, 'dates');
    }

    /** @return array{0:float,1:bool,2:int} */
    private static function person(?Person $q, ?Person $i): array
    {
        if ($q === null || $i === null) {
            return [0.0, false, 0];
        }
        $fc = 0;
        $scores = [];
        if ($q->birthDate !== null && $i->birthDate !== null) {
            $fc++;
            $scores[] = self::compareDates($q->birthDate, $i->birthDate);
        }
        if ($q->deathDate !== null && $i->deathDate !== null) {
            $fc++;
            $scores[] = self::compareDates($q->deathDate, $i->deathDate);
        }
        if ($scores === []) {
            return [0.0, false, $fc];
        }
        $avg = Similarity::average($scores);
        if ($fc === 2 && !self::areDatesLogical($q, $i)) {
            $avg *= 0.5;
        }

        return [$avg, $avg > 0.7, $fc];
    }

    /** @return array{0:float,1:bool,2:int} */
    private static function business(?Business $q, ?Business $i): array
    {
        if ($q === null || $i === null) {
            return [0.0, false, 0];
        }
        $fc = 0;
        $scores = [];
        if ($q->created !== null && $i->created !== null) {
            $fc++;
            $scores[] = self::compareDates($q->created, $i->created);
        }
        if ($q->dissolved !== null && $i->dissolved !== null) {
            $fc++;
            $scores[] = self::compareDates($q->dissolved, $i->dissolved);
        }
        if ($scores === []) {
            return [0.0, false, $fc];
        }
        $avg = Similarity::average($scores);

        return [$avg, $avg > 0.7, $fc];
    }

    /** @return array{0:float,1:bool,2:int} */
    private static function single(?DateTimeImmutable $a, ?DateTimeImmutable $b, bool $both): array
    {
        if (!$both || $a === null || $b === null) {
            return [0.0, false, 0];
        }
        $s = self::compareDates($a, $b);

        return [$s, $s > 0.7, 1];
    }

    public static function compareDates(?DateTimeImmutable $d1, ?DateTimeImmutable $d2): float
    {
        if ($d1 === null || $d2 === null) {
            return 0.0;
        }
        // Truncate(24h) on UTC-based dates keeps the calendar date.
        [$y1, $m1, $dd1] = array_map('intval', explode('-', $d1->format('Y-n-j')));
        [$y2, $m2, $dd2] = array_map('intval', explode('-', $d2->format('Y-n-j')));

        $yearDiff = abs($y1 - $y2);
        $yearScore = $yearDiff <= 5 ? 1.0 - (0.1 * $yearDiff) : 0.2;

        $monthDiff = abs($m1 - $m2);
        if ($monthDiff === 0) {
            $monthScore = 1.0;
        } elseif ($monthDiff <= 1) {
            $monthScore = 0.9;
        } elseif (($m1 === 1 && $m2 >= 10 && $m2 <= 12) || ($m2 === 1 && $m1 >= 10 && $m1 <= 12)) {
            $monthScore = 0.7;
        } else {
            $monthScore = 0.3;
        }

        $dayDiff = abs($dd1 - $dd2);
        if ($dayDiff === 0) {
            $dayScore = 1.0;
        } elseif ($dayDiff <= 3) {
            $dayScore = 0.95 - (0.05 * $dayDiff / 3);
        } else {
            $dayScore = self::areDaysSimilar($dd1, $dd2) ? 0.7 : 0.3;
        }

        $overall = (0.4 * $yearScore) + (0.3 * $monthScore) + (0.3 * $dayScore);

        return max(0.0, min(1.0, $overall));
    }

    private static function areDaysSimilar(int $d1, int $d2): bool
    {
        $s1 = (string) $d1;
        $s2 = (string) $d2;
        if ($s1 === $s2[0] || $s2 === $s1[0]) {
            return true;
        }
        if (strlen($s1) === 2 && strlen($s2) === 2 && $s1[0] === $s2[1] && $s1[1] === $s2[0]) {
            return true;
        }

        return false;
    }

    private static function areDatesLogical(Person $p, Person $i): bool
    {
        if ($p->birthDate !== null && $p->deathDate !== null && $i->birthDate !== null && $i->deathDate !== null) {
            if (!($p->birthDate < $p->deathDate) || !($i->birthDate < $i->deathDate)) {
                return false;
            }
            $ps = ($p->deathDate->getTimestamp() - $p->birthDate->getTimestamp()) / 3600;
            $is = ($i->deathDate->getTimestamp() - $i->birthDate->getTimestamp()) / 3600;
            $ratio = max($ps, $is) / max(1, min($ps, $is));

            return $ratio <= 1.2;
        }

        return true;
    }
}
