<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\StringScore\Params;

/**
 * Entity similarity scoring. Port of pkg/search/similarity.go and its helpers.
 *
 * Scores are 0..1. Nine score pieces are computed (exact identifiers, crypto,
 * government IDs, contact info, name, titles, dates, addresses, supporting info)
 * and combined with weights, coverage penalties and conflict penalties.
 */
final class Similarity
{
    public const EXACT_MATCH_THRESHOLD = 0.99;
    public const HIGH_CONFIDENCE_THRESHOLD = 0.95;

    public const CRITICAL_ID_WEIGHT = 50.0;
    public const NAME_WEIGHT = 35.0;
    public const ADDRESS_WEIGHT = 25.0;
    public const SUPPORTING_INFO_WEIGHT = 15.0;

    private const CRITICAL_COV_THRESHOLD = 0.7;
    private const MIN_COVERAGE_THRESHOLD = 0.35;
    private const PERFECT_MATCH_BOOST = 1.15;
    private const CRITICAL_FIELD_MULTIPLIER = 1.2;

    /** Similarity: final score only (fast path used for ranking). */
    public static function score(Entity $query, Entity $index, ?SimilarityOpts $opts = null): float
    {
        $opts ??= new SimilarityOpts();

        if ($query->source !== '' && !SourceList::isRequestType($query->source) && $query->source !== $index->source) {
            return 0.0;
        }
        if ($query->sourceId !== '') {
            return $query->sourceId === $index->sourceId ? 1.0 : 0.0;
        }
        if ($query->type !== '' && $query->type !== $index->type) {
            $recast = Recast::toType($query, $index->type);
            if ($recast === null) {
                return 0.0;
            }
            $query = $recast;
        }

        $p0 = Exact::identifiers($query, $index, self::CRITICAL_ID_WEIGHT);
        $p1 = Exact::cryptoAddresses($query, $index, self::CRITICAL_ID_WEIGHT);
        $p2 = Exact::governmentIDs($query, $index, self::CRITICAL_ID_WEIGHT);
        $p3 = Exact::contactInfo($query, $index, self::CRITICAL_ID_WEIGHT);
        if (self::uniqueIdentityExact($p0) || self::uniqueIdentityExact($p1) || self::uniqueIdentityExact($p2)) {
            return 1.0;
        }

        $pieces = [
            $p0, $p1, $p2, $p3,
            Fuzzy::name($query, $index, self::NAME_WEIGHT, $opts),
            Fuzzy::titles($query, $index, self::NAME_WEIGHT),
            Dates::compare($query, $index, self::SUPPORTING_INFO_WEIGHT),
            AddressScore::compare($query, $index, self::ADDRESS_WEIGHT),
            Supporting::compare($query, $index, self::SUPPORTING_INFO_WEIGHT),
        ];

        return self::calculateFinalScore(null, $pieces, self::shouldExactOverride($pieces), $query, $index, Exact::hasIdentifierConflict($query, $index));
    }

    /**
     * DetailedSimilarity: every score piece plus the final score. When $debug is
     * given, a human-readable explanation is appended to it.
     */
    public static function detailed(Entity $query, Entity $index, ?SimilarityOpts $opts = null, ?DebugLog $debug = null): SimilarityScore
    {
        $opts ??= new SimilarityOpts();
        $out = new SimilarityScore();

        if ($query->source !== '' && !SourceList::isRequestType($query->source) && $query->source !== $index->source) {
            $out->pieces = self::emptyPieces();

            return self::finishDebug($out, $debug);
        }
        if ($query->sourceId !== '') {
            if ($query->sourceId === $index->sourceId) {
                $out->finalScore = 1.0;
                $out->pieces = [new ScorePiece(1.0, self::CRITICAL_ID_WEIGHT, true, true, true, 1, 'exact')];
            } else {
                $out->pieces = self::emptyPieces();
            }

            return self::finishDebug($out, $debug);
        }
        if ($query->type !== '' && $query->type !== $index->type) {
            $recast = Recast::toType($query, $index->type);
            if ($recast === null) {
                $out->pieces = self::emptyPieces();

                return self::finishDebug($out, $debug);
            }
            $query = $recast;
        }

        $pieces = [];
        $pieces[0] = Exact::identifiers($query, $index, self::CRITICAL_ID_WEIGHT);
        $pieces[1] = Exact::cryptoAddresses($query, $index, self::CRITICAL_ID_WEIGHT);
        $pieces[2] = Exact::governmentIDs($query, $index, self::CRITICAL_ID_WEIGHT);
        $pieces[3] = Exact::contactInfo($query, $index, self::CRITICAL_ID_WEIGHT);
        for ($i = 0; $i < 4; $i++) {
            if (is_nan($pieces[$i]->score)) {
                $pieces[$i]->score = 1.0;
            }
        }
        $pieces[4] = Fuzzy::name($query, $index, self::NAME_WEIGHT, $opts);
        $pieces[5] = Fuzzy::titles($query, $index, self::NAME_WEIGHT);
        $pieces[6] = Dates::compare($query, $index, self::SUPPORTING_INFO_WEIGHT);
        $pieces[7] = AddressScore::compare($query, $index, self::ADDRESS_WEIGHT, $debug);
        $pieces[8] = Supporting::compare($query, $index, self::SUPPORTING_INFO_WEIGHT);

        $out->finalScore = self::calculateFinalScore($debug, $pieces, self::shouldExactOverride($pieces), $query, $index, Exact::hasIdentifierConflict($query, $index));
        $out->pieces = $pieces;

        return self::finishDebug($out, $debug);
    }

    private static function finishDebug(SimilarityScore $out, ?DebugLog $debug): SimilarityScore
    {
        if ($debug === null) {
            return $out;
        }
        $labels = [
            'exact identifiers', 'crypto addresses', 'gov IDs', 'contact info', 'name', 'titles',
            'dates', 'addresses', 'supporting info',
        ];
        if (count($out->pieces) === 1) {
            $debug->write('one score piece found: ' . json_encode($out->pieces[0]->toArray()) . "\n");
        } else {
            $groups = [0 => 'Critical pieces', 4 => 'name comparison', 6 => 'supporting info'];
            foreach ($out->pieces as $i => $piece) {
                if (isset($groups[$i])) {
                    $debug->write($groups[$i] . "\n");
                }
                $debug->write(sprintf("  %s: %s\n", $labels[$i] ?? (string) $i, json_encode($piece->toArray())));
            }
        }
        $debug->write(sprintf('finalScore=%.2f', $out->finalScore));

        return $out;
    }

    /** @return list<ScorePiece> */
    private static function emptyPieces(): array
    {
        $out = [];
        for ($i = 0; $i < 9; $i++) {
            $out[] = new ScorePiece();
        }

        return $out;
    }

    private static function uniqueIdentityExact(ScorePiece $p): bool
    {
        return $p->fieldsCompared > 0 && $p->exact && $p->uniqueIdentity;
    }

    /** @param list<ScorePiece> $pieces */
    public static function shouldExactOverride(array $pieces): bool
    {
        foreach ($pieces as $p) {
            if ($p->fieldsCompared === 0 || !$p->exact) {
                continue;
            }
            if ($p->pieceType === 'crypto-exact') {
                return true;
            }
            if (($p->pieceType === 'identifiers' || $p->pieceType === 'gov-ids-exact') && $p->uniqueIdentity) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ScorePiece> $pieces */
    private static function calculateFinalScore(?DebugLog $w, array $pieces, bool $exactOverride, Entity $query, Entity $index, bool $idConflict): float
    {
        if ($pieces === []) {
            return 0.0;
        }
        $params = Params::get();
        $fields = self::countFieldsByImportance($pieces);
        $coverage = self::calculateCoverage($w, $pieces, $index);
        $baseScore = self::calculateBaseScore($pieces);
        $finalScore = self::applyPenaltiesAndBonuses($w, $baseScore, $coverage, $fields, $params);

        if ($w !== null) {
            $w->write("calculateFinalScore:\n");
            $w->write('  exactOverride=' . ($exactOverride ? 'true' : 'false') . "\n");
            $w->write('  idConflict=' . ($idConflict ? 'true' : 'false') . "\n");
            $w->write('  fields=' . json_encode($fields) . "\n");
            $w->write('  coverage=' . json_encode(['ratio' => $coverage[0], 'criticalRatio' => is_nan($coverage[1]) ? 'NaN' : $coverage[1]]) . "\n");
            $w->write("  baseScore=$baseScore\n");
            $w->write(sprintf("  finalScore=%.2f (overridden: %s)\n", $finalScore, $exactOverride ? 'true' : 'false'));
        }
        if ($exactOverride) {
            return 1.0;
        }
        $m = $params->idConflictPenaltyMultiplier;
        if ($idConflict && $m > 0 && $m < 1) {
            $finalScore *= $m;
            $w?->write(sprintf("  id conflict penalty = %.2f\n", $finalScore));
        }
        $m = $params->nameNumeralConflictPenaltyMultiplier;
        if (Numerals::hasConflict($query, $index) && $m > 0 && $m < 1) {
            $finalScore *= $m;
            $w?->write(sprintf("  name numeral conflict penalty = %.2f\n", $finalScore));
        }
        if (is_nan($finalScore)) {
            return 1.0;
        }

        return $finalScore;
    }

    /**
     * @param list<ScorePiece> $pieces
     * @return array{required:int,hasName:bool,hasID:bool,hasCritical:bool,hasAddress:bool}
     */
    private static function countFieldsByImportance(array $pieces): array
    {
        $f = ['required' => 0, 'hasName' => false, 'hasID' => false, 'hasCritical' => false, 'hasAddress' => false];
        foreach ($pieces as $p) {
            if ($p->weight <= 0 || $p->fieldsCompared === 0) {
                continue;
            }
            if ($p->required) {
                $f['required'] += $p->fieldsCompared;
            }
            if ($p->matched) {
                if ($p->pieceType === 'name') {
                    $f['hasName'] = true;
                }
                if ($p->exact && $p->uniqueIdentity && in_array($p->pieceType, ['identifiers', 'gov-ids-exact', 'crypto-exact'], true)) {
                    $f['hasID'] = true;
                }
                if ($p->pieceType === 'address') {
                    $f['hasAddress'] = true;
                }
                if ($p->exact) {
                    $f['hasCritical'] = true;
                }
            }
        }

        return $f;
    }

    /** @param list<ScorePiece> $pieces */
    private static function calculateBaseScore(array $pieces): float
    {
        $totalScore = 0.0;
        $totalWeight = 0.0;
        foreach ($pieces as $p) {
            if ($p->weight <= 0 || $p->fieldsCompared === 0) {
                continue;
            }
            $mult = $p->required ? self::CRITICAL_FIELD_MULTIPLIER : 1.0;
            $totalScore += $p->score * $p->weight * $mult;
            $totalWeight += $p->weight * $mult;
        }
        if ($totalWeight == 0.0) {
            return 0.0;
        }

        return $totalScore / $totalWeight;
    }

    /**
     * @param list<ScorePiece> $pieces
     * @return array{0:float,1:float} [ratio, criticalRatio] (criticalRatio may be NaN like Go)
     */
    private static function calculateCoverage(?DebugLog $w, array $pieces, Entity $index): array
    {
        $indexFields = self::countAvailableFields($index);
        if ($indexFields === 0) {
            return [1.0, 1.0];
        }
        $fieldsCompared = 0;
        $criticalCompared = 0;
        $criticalTotal = 0;
        foreach ($pieces as $p) {
            $fieldsCompared += $p->fieldsCompared;
            if ($p->required) {
                $criticalCompared += $p->fieldsCompared;
                $criticalTotal += $p->fieldsCompared;
            }
        }
        $w?->write("fieldsCompared=$fieldsCompared  indexFields=$indexFields  criticalFieldsCompared=$criticalCompared  criticalTotal=$criticalTotal\n");

        return [fdiv((float) $fieldsCompared, (float) $indexFields), fdiv((float) $criticalCompared, (float) $criticalTotal)];
    }

    /**
     * @param array{0:float,1:float} $cov
     * @param array{required:int,hasName:bool,hasID:bool,hasCritical:bool,hasAddress:bool} $fields
     */
    private static function applyPenaltiesAndBonuses(?DebugLog $w, float $score, array $cov, array $fields, Params $params): float
    {
        $w?->write("applyPenaltiesAndBonuses\n" . sprintf("  start: %.2f\n", $score));
        // NaN comparisons are false in both Go and PHP.
        if ($cov[0] < self::MIN_COVERAGE_THRESHOLD) {
            $score *= $params->lowCoveragePenaltyMultiplier;
            $w?->write(sprintf("  cov.ratio < minCoverageThreshold = %.2f\n", $score));
        }
        if ($cov[1] < self::CRITICAL_COV_THRESHOLD) {
            $score *= 0.90;
            $w?->write(sprintf("  cov.criticalRatio < criticalCovThreshold = %.2f\n", $score));
        }
        if ($fields['required'] < 2) {
            $score *= $params->minimumRequiredFieldsPenaltyMultiplier;
            $w?->write(sprintf("  fields.required < 2 = %.2f\n", $score));
        }
        if (!$fields['hasID'] && !$fields['hasAddress'] && $fields['hasName']) {
            $score *= $params->nameOnlyPenaltyMultiplier;
            $w?->write(sprintf("  reduced name-only match penalty = %.2f\n", $score));
        }
        if ($fields['hasName'] && $fields['hasID'] && $fields['hasCritical'] && $cov[0] > 0.70 && $score > self::HIGH_CONFIDENCE_THRESHOLD) {
            $score = min(1.0, $score * self::PERFECT_MATCH_BOOST);
            $w?->write(sprintf("  perfect match requirements = %.2f\n", $score));
        }

        return $score;
    }

    public static function countAvailableFields(Entity $index): int
    {
        $count = match ($index->type) {
            EntityType::PERSON => self::countPerson($index->person),
            EntityType::BUSINESS => self::countBusiness($index->business),
            EntityType::ORGANIZATION => self::countBusiness($index->organization),
            EntityType::VESSEL => self::countVessel($index->vessel),
            EntityType::AIRCRAFT => self::countAircraft($index->aircraft),
            default => 0,
        };
        if ($index->name !== '') {
            $count++;
        }
        if ($index->source !== '') {
            $count++;
        }
        foreach ([$index->contact->emailAddresses, $index->contact->phoneNumbers, $index->contact->faxNumbers, $index->contact->websites,
            $index->cryptoAddresses, $index->affiliations, $index->addresses] as $list) {
            if ($list !== []) {
                $count++;
            }
        }

        return $count;
    }

    private static function countPerson(?Person $p): int
    {
        if ($p === null) {
            return 0;
        }

        return (int) ($p->birthDate !== null) + (int) ($p->gender !== '') + (int) ($p->titles !== []) + (int) ($p->governmentIDs !== []);
    }

    private static function countBusiness(?Business $b): int
    {
        if ($b === null) {
            return 0;
        }

        return (int) ($b->name !== '') + (int) ($b->altNames !== []) + (int) ($b->created !== null) + (int) ($b->governmentIDs !== []);
    }

    private static function countVessel(?Vessel $v): int
    {
        if ($v === null) {
            return 0;
        }

        return (int) ($v->imoNumber !== '') + (int) ($v->callSign !== '') + (int) ($v->mmsi !== '') + (int) ($v->flag !== '')
            + (int) ($v->model !== '') + (int) ($v->owner !== '');
    }

    private static function countAircraft(?Aircraft $a): int
    {
        if ($a === null) {
            return 0;
        }

        return (int) ($a->icaoCode !== '') + (int) ($a->model !== '') + (int) ($a->flag !== '') + (int) ($a->serialNumber !== '');
    }

    /** @param list<float> $scores */
    public static function average(array $scores): float
    {
        return $scores === [] ? 0.0 : array_sum($scores) / count($scores);
    }
}
