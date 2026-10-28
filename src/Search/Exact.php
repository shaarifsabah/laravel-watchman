<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\Support\Go;

/** Port of pkg/search/similarity_exact.go */
final class Exact
{
    public static function identifiers(Entity $q, Entity $i, float $weight): ScorePiece
    {
        return match ($q->type) {
            EntityType::PERSON => self::govIdBlock($q->person?->governmentIDs, $i->person?->governmentIDs, $q->person === null || $i->person === null, $weight),
            EntityType::BUSINESS => self::govIdBlock($q->business?->governmentIDs, $i->business?->governmentIDs, $q->business === null || $i->business === null, $weight),
            EntityType::ORGANIZATION => self::govIdBlock($q->organization?->governmentIDs, $i->organization?->governmentIDs, $q->organization === null || $i->organization === null, $weight),
            EntityType::VESSEL => self::vessel($q->vessel, $i->vessel, $weight),
            EntityType::AIRCRAFT => self::aircraft($q->aircraft, $i->aircraft, $weight),
            default => new ScorePiece(0, 0, false, false, false, 0, 'identifiers'),
        };
    }

    public static function normalizeIdentifier(string $id): string
    {
        return str_replace('-', '', $id);
    }

    /**
     * person / business / organization exact government ID block (identical logic in Go).
     */
    private static function govIdBlock(?array $qIds, ?array $iIds, bool $nil, float $weight): ScorePiece
    {
        if ($nil) {
            return new ScorePiece(0, 0, false, false, false, 0, 'identifiers');
        }
        $fieldsCompared = 0;
        $totalWeight = 0.0;
        $score = 0.0;
        $hasMatch = false;
        $unique = false;
        if ($qIds !== [] && $iIds !== []) {
            $fieldsCompared++;
            $totalWeight += 15.0;
            foreach ($qIds as $qID) {
                foreach ($iIds as $iID) {
                    if (Go::equalFold($qID->type, $iID->type)
                        && Go::equalFold($qID->country, $iID->country)
                        && Go::equalFold(self::normalizeIdentifier($qID->identifier), self::normalizeIdentifier($iID->identifier))) {
                        $score += 15.0;
                        $hasMatch = true;
                        $unique = self::isUniqueIdentityType($qID->type);
                        break 2;
                    }
                }
            }
        }
        $final = $totalWeight > 0 ? $score / $totalWeight : 0.0;

        return new ScorePiece($final, $weight, $hasMatch, $fieldsCompared > 0, $final > 0.99, $fieldsCompared, 'identifiers', $unique);
    }

    private static function vessel(?Vessel $q, ?Vessel $i, float $weight): ScorePiece
    {
        if ($q === null || $i === null) {
            return new ScorePiece(0, 0, false, false, false, 0, 'identifiers');
        }
        $fc = 0;
        $tw = 0.0;
        $score = 0.0;
        $hasMatch = false;
        $unique = false;
        if ($q->imoNumber !== '') {
            $fc++;
            $tw += 15.0;
            if (Go::equalFold($q->imoNumber, $i->imoNumber)) {
                $score += 15.0;
                $hasMatch = true;
                $unique = true;
            }
        }
        if ($q->callSign !== '') {
            $fc++;
            $tw += 12.0;
            if (Go::equalFold($q->callSign, $i->callSign)) {
                $score += 12.0;
                $hasMatch = true;
            }
        }
        if ($q->mmsi !== '') {
            $fc++;
            $tw += 12.0;
            if (Go::equalFold($q->mmsi, $i->mmsi)) {
                $score += 12.0;
                $hasMatch = true;
                $unique = true;
            }
        }
        $final = $tw > 0 ? $score / $tw : 0.0;

        return new ScorePiece($final, $weight, $hasMatch, $fc > 0, $final > 0.99, $fc, 'identifiers', $unique);
    }

    private static function aircraft(?Aircraft $q, ?Aircraft $i, float $weight): ScorePiece
    {
        if ($q === null || $i === null) {
            return new ScorePiece(0, 0, false, false, false, 0, 'identifiers');
        }
        $fc = 0;
        $tw = 0.0;
        $score = 0.0;
        $hasMatch = false;
        $unique = false;
        if ($q->serialNumber !== '') {
            $fc++;
            $tw += 15.0;
            if (Go::equalFold($q->serialNumber, $i->serialNumber)) {
                $score += 15.0;
                $hasMatch = true;
                $unique = true;
            }
        }
        if ($q->icaoCode !== '') {
            $fc++;
            $tw += 12.0;
            if (Go::equalFold($q->icaoCode, $i->icaoCode)) {
                $score += 12.0;
                $hasMatch = true;
            }
        }
        $final = $tw > 0 ? $score / $tw : 0.0;

        return new ScorePiece($final, $weight, $hasMatch, $fc > 0, $final > 0.99, $fc, 'identifiers', $unique);
    }

    public static function cryptoAddresses(Entity $q, Entity $i, float $weight): ScorePiece
    {
        if ($q->cryptoAddresses === [] || $i->cryptoAddresses === []) {
            return new ScorePiece(0, $weight, false, false, false, 0, 'crypto-exact');
        }
        $score = 0.0;
        $hasMatch = false;
        foreach ($q->cryptoAddresses as $qa) {
            if ($qa->address === '') {
                continue;
            }
            foreach ($i->cryptoAddresses as $ia) {
                if ($qa->currency !== '' && $ia->currency !== '' && !Go::equalFold($qa->currency, $ia->currency)) {
                    continue;
                }
                if (Go::equalFold($qa->address, $ia->address)) {
                    $score = 1.0;
                    $hasMatch = true;
                    break 2;
                }
            }
        }

        return new ScorePiece($score, $weight, $hasMatch, false, $score > 0.99, 1, 'crypto-exact', $hasMatch);
    }

    public static function isUniqueIdentityType(string $t): bool
    {
        if ($t === GovernmentIDType::TAX || $t === GovernmentIDType::BUSINESS_REGISTRATION || $t === GovernmentIDType::COMMERCIAL_REGISTRY) {
            return false;
        }

        return $t !== '';
    }

    public static function hasIdentifierConflict(Entity $q, Entity $i): bool
    {
        if (self::conflictingGovernmentIDs($q->governmentIDs(), $i->governmentIDs())) {
            return true;
        }
        if ($q->vessel !== null && $i->vessel !== null) {
            if ($q->vessel->imoNumber !== '' && $i->vessel->imoNumber !== '' && !Go::equalFold($q->vessel->imoNumber, $i->vessel->imoNumber)) {
                return true;
            }
            if ($q->vessel->mmsi !== '' && $i->vessel->mmsi !== '' && !Go::equalFold($q->vessel->mmsi, $i->vessel->mmsi)) {
                return true;
            }
        }
        if ($q->aircraft !== null && $i->aircraft !== null) {
            if ($q->aircraft->serialNumber !== '' && $i->aircraft->serialNumber !== '' && !Go::equalFold($q->aircraft->serialNumber, $i->aircraft->serialNumber)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<GovernmentID> $queryIds
     * @param list<GovernmentID> $indexIds
     */
    private static function conflictingGovernmentIDs(array $queryIds, array $indexIds): bool
    {
        if ($queryIds === [] || $indexIds === []) {
            return false;
        }
        $byType = [];
        foreach ($indexIds as $id) {
            if ($id->type === '' || $id->identifier === '') {
                continue;
            }
            $byType[$id->type][] = $id;
        }
        $seen = [];
        foreach ($queryIds as $qID) {
            if ($qID->type === '' || $qID->identifier === '' || isset($seen[$qID->type])) {
                continue;
            }
            $iIds = $byType[$qID->type] ?? [];
            if ($iIds === []) {
                continue;
            }
            $seen[$qID->type] = true;
            if (!self::sameTypeIdentifierMatch($qID->type, $queryIds, $iIds)) {
                return true;
            }
        }

        return false;
    }

    private static function sameTypeIdentifierMatch(string $type, array $queryIds, array $indexIds): bool
    {
        foreach ($queryIds as $qID) {
            if ($qID->type !== $type) {
                continue;
            }
            foreach ($indexIds as $iID) {
                if ($qID->country !== '' && $iID->country !== '' && !Go::equalFold($qID->country, $iID->country)) {
                    continue;
                }
                if (Go::equalFold(self::normalizeIdentifier($qID->identifier), self::normalizeIdentifier($iID->identifier))) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function governmentIDs(Entity $q, Entity $i, float $weight): ScorePiece
    {
        [$qIds, $iIds, $nil] = match ($q->type) {
            EntityType::PERSON => [$q->person?->governmentIDs, $i->person?->governmentIDs, $q->person === null || $i->person === null],
            EntityType::BUSINESS => [$q->business?->governmentIDs, $i->business?->governmentIDs, $q->business === null || $i->business === null],
            EntityType::ORGANIZATION => [$q->organization?->governmentIDs, $i->organization?->governmentIDs, $q->organization === null || $i->organization === null],
            default => [null, null, null],
        };
        if ($nil === null || $nil || $qIds === [] || $iIds === []) {
            return new ScorePiece(0, $weight, false, false, false, 0, 'gov-ids-exact');
        }
        $best = ['score' => 0.0, 'found' => false, 'exact' => false, 'unique' => false];
        foreach ($qIds as $qID) {
            foreach ($iIds as $iID) {
                $m = self::compareIdentifiers($qID->identifier, $iID->identifier, $qID->country, $iID->country);
                if ($m['found']) {
                    $m['unique'] = self::isUniqueIdentityType($qID->type) && self::isUniqueIdentityType($iID->type);
                }
                if ($m['found'] && $m['score'] > $best['score']) {
                    $best = $m;
                }
                if ($best['exact']) {
                    break 2;
                }
            }
        }

        return new ScorePiece($best['score'], $weight, $best['found'], false, $best['exact'], 1, 'gov-ids-exact', $best['unique']);
    }

    /** @return array{score:float,found:bool,exact:bool,unique:bool} */
    private static function compareIdentifiers(string $qId, string $iId, string $qCountry, string $iCountry): array
    {
        if (!Go::equalFold($qId, $iId)) {
            return ['score' => 0.0, 'found' => false, 'exact' => false, 'unique' => false];
        }
        if ($qCountry === '' && $iCountry === '') {
            return ['score' => 1.0, 'found' => true, 'exact' => true, 'unique' => false];
        }
        if ($qCountry === '' || $iCountry === '') {
            return ['score' => 0.9, 'found' => true, 'exact' => false, 'unique' => false];
        }
        if (Go::equalFold($qCountry, $iCountry)) {
            return ['score' => 1.0, 'found' => true, 'exact' => true, 'unique' => false];
        }

        return ['score' => 0.7, 'found' => true, 'exact' => false, 'unique' => false];
    }

    public static function contactInfo(Entity $q, Entity $i, float $weight): ScorePiece
    {
        $fc = 0;
        $matches = [];
        if ($q->contact->emailAddresses !== [] && $i->contact->emailAddresses !== []) {
            $fc++;
            $matches[] = self::compareContactField($q->contact->emailAddresses, $i->contact->emailAddresses);
        }
        if ($q->preparedFields->contact->phoneNumbers !== [] && $i->preparedFields->contact->phoneNumbers !== []) {
            $fc++;
            $matches[] = self::compareContactField($q->preparedFields->contact->phoneNumbers, $i->preparedFields->contact->phoneNumbers);
        }
        if ($q->preparedFields->contact->faxNumbers !== [] && $i->preparedFields->contact->faxNumbers !== []) {
            $fc++;
            $matches[] = self::compareContactField($q->preparedFields->contact->faxNumbers, $i->preparedFields->contact->faxNumbers);
        }
        $qw = $q->preparedFields->contact->websites !== [] ? $q->preparedFields->contact->websites : $q->contact->websites;
        $iw = $i->preparedFields->contact->websites !== [] ? $i->preparedFields->contact->websites : $i->contact->websites;
        if ($qw !== [] && $iw !== []) {
            $fc++;
            $matches[] = self::compareContactField($qw, $iw);
        }
        if ($q->preparedFields->domains !== [] && $i->preparedFields->domains !== []) {
            $fc++;
            $set = array_flip($i->preparedFields->domains);
            $hit = false;
            foreach ($q->preparedFields->domains as $d) {
                if (isset($set[$d])) {
                    $hit = true;
                    break;
                }
            }
            $matches[] = ['matches' => $hit ? 1 : 0, 'score' => $hit ? 1.0 : 0.0];
        }
        if ($fc === 0) {
            return new ScorePiece(0, $weight, false, false, false, 0, 'contact-exact');
        }
        $total = 0.0;
        $totalMatches = 0;
        foreach ($matches as $m) {
            $total += $m['score'];
            $totalMatches += $m['matches'];
        }
        $final = $total / count($matches);

        return new ScorePiece($final, $weight, $totalMatches > 0, false, $final > 0.99, $fc, 'contact-exact');
    }

    /** @return array{matches:int,score:float} */
    private static function compareContactField(array $queryValues, array $indexValues): array
    {
        $matches = 0;
        foreach ($queryValues as $qv) {
            foreach ($indexValues as $iv) {
                if (Go::equalFold($qv, $iv)) {
                    $matches++;
                }
            }
        }

        return ['matches' => $matches, 'score' => $matches / count($queryValues)];
    }
}
