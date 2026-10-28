<?php

declare(strict_types=1);

namespace Watchman\Sources\CslEu;

use DateTimeImmutable;
use Watchman\Norm\Country;
use Watchman\Search\Address;
use Watchman\Search\Business;
use Watchman\Search\ContactInfo;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\Gender;
use Watchman\Search\GovernmentID;
use Watchman\Search\GovernmentIDType;
use Watchman\Search\Organization;
use Watchman\Search\Person;
use Watchman\Search\SanctionsInfo;
use Watchman\Search\SourceList;
use Watchman\Search\Vessel;
use Watchman\Support\Go;
use Watchman\Support\GoTime;

/** Port of pkg/sources/csl_eu/mapper.go */
final class CslEuMapper
{
    /** imoPattern = (?i)IMO[:\s]*(\d{7}) — RE2 \s spelled out, ASCII digits. */
    private const IMO_PATTERN = '/IMO[:\t\n\f\r ]*([0-9]{7})/i';

    /**
     * ConvertEUCSLData
     *
     * @param iterable<array<string,mixed>> $records
     * @return \Generator<int, Entity>
     */
    public static function convert(iterable $records): \Generator
    {
        foreach ($records as $record) {
            yield self::toEntity($record);
        }
    }

    /** @param array<string,mixed> $record CSLRecord (json keys, see CslEuRecord) */
    public static function toEntity(array $record): Entity
    {
        $record = CslEuRecord::complete($record);

        $e = new Entity('', '', SourceList::EU_CSL, (string) $record['entityLogicalId']);
        $e->sourceData = CslEuRecord::toSourceData($record);

        switch (Go::lower(Go::trimSpace($record['entitySubjectTypeCode']))) {
            case 'person':
                $e->type = EntityType::PERSON;
                $e->person = self::mapPerson($record);
                $e->name = $e->person->name;
                break;
            case 'enterprise':
                $e->type = EntityType::BUSINESS;
                $e->business = self::mapBusiness($record, Business::class);
                $e->name = $e->business->name;
                break;
            default:
                if (self::isVessel($record)) {
                    $e->type = EntityType::VESSEL;
                    $e->vessel = self::mapVessel($record);
                    $e->name = $e->vessel->name;
                } else {
                    $e->type = EntityType::ORGANIZATION;
                    $e->organization = self::mapBusiness($record, Organization::class);
                    $e->name = $e->organization->name;
                }
        }

        $e->addresses = self::mapAddresses($record);
        $e->sanctionsInfo = self::mapSanctionsInfo($record);
        $e->contact = self::mapContactInfo($record);

        return $e->normalize();
    }

    private static function isVessel(array $record): bool
    {
        return preg_match(self::IMO_PATTERN, $record['entityRemark']) === 1;
    }

    public static function extractIMONumber(string $text): string
    {
        return preg_match(self::IMO_PATTERN, $text, $m) === 1 ? $m[1] : '';
    }

    /** @return array{0:string,1:list<string>} */
    private static function names(array $record): array
    {
        $names = $record['nameAliasWholeNames'];
        $name = count($names) > 0 ? Go::trimSpace($names[0]) : '';
        $alts = [];
        foreach (array_slice($names, 1) as $n) {
            $t = Go::trimSpace($n);
            if ($t !== '') {
                $alts[] = $t;
            }
        }

        return [$name, $alts];
    }

    public static function mapPerson(array $record): Person
    {
        $record = CslEuRecord::complete($record);
        [$name, $alts] = self::names($record);
        $p = new Person($name, $alts);

        if (count($record['nameAliasGenders']) > 0) {
            $p->gender = self::mapGender($record['nameAliasGenders'][0]);
        }

        $titles = [];
        foreach ([$record['nameAliasTitles'], $record['nameAliasFunctions']] as $list) {
            foreach ($list as $t) {
                $t = Go::trimSpace($t);
                if ($t !== '' && !in_array($t, $titles, true)) {
                    $titles[] = $t;
                }
            }
        }
        $p->titles = $titles;

        if (count($record['birthDates']) > 0 && $record['birthDates'][0] !== '') {
            $t = self::parseEUDate($record['birthDates'][0]);
            if ($t !== null) {
                $p->birthDate = $t;
            }
        }

        $p->placeOfBirth = self::buildPlaceOfBirth($record);
        $p->governmentIDs = self::mapGovernmentIDs($record['identifications']);

        return $p;
    }

    public static function buildPlaceOfBirth(array $record): string
    {
        $parts = [];
        if (count($record['birthPlaces']) > 0 && $record['birthPlaces'][0] !== '') {
            $parts[] = Go::trimSpace($record['birthPlaces'][0]);
        }
        if (count($record['birthCities']) > 0 && $record['birthCities'][0] !== '') {
            $city = Go::trimSpace($record['birthCities'][0]);
            if (count($parts) === 0 || !str_contains($parts[0], $city)) {
                $parts[] = $city;
            }
        }
        if (count($record['birthRegions']) > 0 && $record['birthRegions'][0] !== '') {
            $parts[] = Go::trimSpace($record['birthRegions'][0]);
        }
        if (count($record['birthCountries']) > 0 && $record['birthCountries'][0] !== '') {
            $parts[] = Go::trimSpace($record['birthCountries'][0]);
        }

        return implode(', ', $parts);
    }

    public static function mapGender(string $code): string
    {
        return match (Go::upper(Go::trimSpace($code))) {
            'M', 'MALE' => Gender::MALE,
            'F', 'FEMALE' => Gender::FEMALE,
            default => Gender::UNKNOWN,
        };
    }

    /**
     * @param list<array<string,mixed>> $ids IdentificationInfo
     * @return list<GovernmentID>
     */
    public static function mapGovernmentIDs(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = array_merge(CslEuRecord::emptyIdentification(), $id);
            if ($id['number'] === '') {
                continue;
            }
            $name = $id['typeDescription'] !== '' ? $id['typeDescription'] : ($id['typeCode'] !== '' ? $id['typeCode'] : 'Unknown');
            $out[] = new GovernmentID(
                self::mapIDType($id['typeCode'], $id['typeDescription']),
                Country::normalize(Go::or($id['countryDesc'], $id['countryIso'])),
                self::extractIDNumber($id['number']),
                $name,
            );
        }

        return $out;
    }

    public static function extractIDNumber(string $raw): string
    {
        $raw = Go::trimSpace($raw);
        if ($raw === '') {
            return '';
        }
        $idx = strpos($raw, '(');
        if ($idx !== false && $idx > 0) {
            return Go::trimSpace(substr($raw, 0, $idx));
        }

        return $raw;
    }

    public static function mapIDType(string $typeCode, string $typeDesc): string
    {
        $code = Go::lower(Go::trimSpace($typeCode));
        $desc = Go::lower(Go::trimSpace($typeDesc));

        switch ($code) {
            case 'passport':
                return GovernmentIDType::PASSPORT;
            case 'id':
                return GovernmentIDType::NATIONAL;
            case 'ssn':
                return GovernmentIDType::SSN;
            case 'tax':
                return GovernmentIDType::TAX;
        }
        if (str_contains($desc, 'passport')) {
            return str_contains($desc, 'diplomatic') ? GovernmentIDType::DIPLOMATIC_PASS : GovernmentIDType::PASSPORT;
        }
        if (str_contains($desc, 'national') && str_contains($desc, 'identification')) {
            return GovernmentIDType::NATIONAL;
        }
        if (str_contains($desc, 'driver') || str_contains($desc, 'license')) {
            return GovernmentIDType::DRIVERS_LICENSE;
        }
        if (str_contains($desc, 'tax')) {
            return GovernmentIDType::TAX;
        }
        if (str_contains($desc, 'birth')) {
            return GovernmentIDType::BIRTH_CERT;
        }
        if (str_contains($desc, 'business') || str_contains($desc, 'registration')) {
            return GovernmentIDType::BUSINESS_REGISTRATION;
        }

        return GovernmentIDType::PERSONAL_ID;
    }

    /** parseEUDate: "2006-01-02" then "02/01/2006". */
    public static function parseEUDate(string $s): ?DateTimeImmutable
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return null;
        }

        return GoTime::parse('2006-01-02', $s) ?? GoTime::parse('02/01/2006', $s);
    }

    /**
     * mapBusiness / mapOrganization (identical apart from the type).
     *
     * @template T of Business
     * @param class-string<T> $class
     * @return T
     */
    public static function mapBusiness(array $record, string $class = Business::class): Business
    {
        $record = CslEuRecord::complete($record);
        [$name, $alts] = self::names($record);
        $b = new $class($name, $alts);
        $b->governmentIDs = self::mapGovernmentIDs($record['identifications']);

        return $b;
    }

    public static function mapVessel(array $record): Vessel
    {
        $record = CslEuRecord::complete($record);
        [$name, $alts] = self::names($record);
        $v = new Vessel($name, $alts);
        $imo = self::extractIMONumber($record['entityRemark']);
        if ($imo !== '') {
            $v->imoNumber = $imo;
        }
        if (count($record['addressCountryDescriptions']) > 0 && $record['addressCountryDescriptions'][0] !== '') {
            $v->flag = Country::normalize($record['addressCountryDescriptions'][0]);
        }

        return $v;
    }

    /** @return list<Address> */
    public static function mapAddresses(array $record): array
    {
        $record = CslEuRecord::complete($record);
        $max = max(
            count($record['addressStreets']),
            count($record['addressCities']),
            count($record['addressZipCodes']),
            count($record['addressCountryDescriptions']),
        );
        $at = static fn (string $k, int $i): string => (string) ($record[$k][$i] ?? '');

        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $a = new Address();
            $has = false;
            if ($at('addressStreets', $i) !== '') {
                $a->line1 = Go::trimSpace($at('addressStreets', $i));
                $has = true;
            }
            if ($at('addressPoBoxes', $i) !== '') {
                $a->line2 = 'PO Box ' . Go::trimSpace($at('addressPoBoxes', $i));
                $has = true;
            }
            if ($a->line2 === '' && $at('addressPlaces', $i) !== '') {
                $a->line2 = Go::trimSpace($at('addressPlaces', $i));
                $has = true;
            }
            if ($at('addressCities', $i) !== '') {
                $a->city = Go::trimSpace($at('addressCities', $i));
                $has = true;
            }
            if ($at('addressZipCodes', $i) !== '') {
                $a->postalCode = Go::trimSpace($at('addressZipCodes', $i));
                $has = true;
            }
            if ($at('addressRegions', $i) !== '') {
                $a->state = Go::trimSpace($at('addressRegions', $i));
                $has = true;
            }
            if ($at('addressCountryDescriptions', $i) !== '') {
                $a->country = Country::normalize($at('addressCountryDescriptions', $i));
                $has = true;
            }
            if ($has) {
                $out[] = $a;
            }
        }

        return $out;
    }

    public static function mapContactInfo(array $record): ContactInfo
    {
        $c = new ContactInfo();
        foreach ($record['addressContactInfos'] ?? [] as $info) {
            $info = Go::trimSpace($info);
            if ($info === '') {
                continue;
            }
            $l = Go::lower($info);
            if (str_contains($l, '@')) {
                $c->emailAddresses[] = $info;
            } elseif (str_contains($l, 'fax')) {
                $c->faxNumbers[] = $info;
            } elseif (str_contains($l, 'tel') || str_contains($l, 'phone')) {
                $c->phoneNumbers[] = $info;
            } elseif (str_contains($l, 'http') || str_contains($l, 'www')) {
                $c->websites[] = $info;
            }
        }

        return $c;
    }

    public static function mapSanctionsInfo(array $record): ?SanctionsInfo
    {
        $record = CslEuRecord::complete($record);
        $has = $record['entityRemark'] !== ''
            || $record['entityReferenceNumber'] !== ''
            || count($record['citizenships']) > 0
            || $record['entityRegulationProgramme'] !== ''
            || $record['entityDesignationDate'] !== ''
            || $record['entityUnitedNationId'] !== '';
        if (!$has) {
            return null;
        }

        $info = new SanctionsInfo();
        if ($record['entityRegulationProgramme'] !== '') {
            foreach (explode(',', $record['entityRegulationProgramme']) as $p) {
                $t = Go::trimSpace($p);
                if ($t !== '') {
                    $info->programs[] = $t;
                }
            }
        }

        $parts = [];
        if ($record['entityRemark'] !== '') {
            $parts[] = $record['entityRemark'];
        }
        if ($record['entityReferenceNumber'] !== '') {
            $parts[] = 'Ref: ' . $record['entityReferenceNumber'];
        }
        if ($record['entityUnitedNationId'] !== '') {
            $parts[] = 'UN ID: ' . $record['entityUnitedNationId'];
        }
        if ($record['entityDesignationDate'] !== '') {
            $parts[] = 'Designated: ' . $record['entityDesignationDate'];
        }
        if ($record['entityDesignationDetails'] !== '') {
            $parts[] = $record['entityDesignationDetails'];
        }
        if (count($record['citizenships']) > 0) {
            $cs = [];
            foreach ($record['citizenships'] as $c) {
                $t = Go::trimSpace($c);
                if ($t !== '') {
                    $cs[] = $t;
                }
            }
            if ($cs !== []) {
                $parts[] = 'Citizenship: ' . implode(', ', $cs);
            }
        }
        if ($parts !== []) {
            $info->description = implode('; ', $parts);
        }

        return $info;
    }
}
