<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUk;

use DateTimeImmutable;
use Watchman\Norm\Country;
use Watchman\Search\Address;
use Watchman\Search\Business;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\Gender;
use Watchman\Search\GovernmentID;
use Watchman\Search\GovernmentIDType;
use Watchman\Search\Person;
use Watchman\Search\SanctionsInfo;
use Watchman\Search\SourceList;
use Watchman\Search\Vessel;
use Watchman\Sources\CslEu\CslEuRecord;
use Watchman\Support\Go;
use Watchman\Support\GoTime;

/** Port of pkg/sources/csl_uk/mapper.go */
final class CslUkMapper
{
    /**
     * ConvertSanctionsListData
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

    /** @param array<string,mixed> $record SanctionsListRecord (see CslUkRecord) */
    public static function toEntity(array $record): Entity
    {
        $record = CslUkRecord::complete($record);
        $e = new Entity('', '', SourceList::UK_CSL, $record['UniqueID']);
        $e->sourceData = CslUkRecord::toSourceData($record);

        if ($record['EntityType'] !== null) {
            switch ($record['EntityType']) {
                case CslUkRecord::INDIVIDUAL:
                    $e->type = EntityType::PERSON;
                    $e->person = self::mapPerson($record);
                    $e->name = $e->person->name;
                    break;
                case CslUkRecord::ENTITY:
                    $e->type = EntityType::BUSINESS;
                    $e->business = self::mapBusiness($record);
                    $e->name = $e->business->name;
                    break;
                case CslUkRecord::SHIP:
                    $e->type = EntityType::VESSEL;
                    $e->vessel = self::mapVessel($record);
                    $e->name = $e->vessel->name;
                    break;
                case CslUkRecord::UNDEFINED:
                    $e->type = EntityType::UNKNOWN;
                    break;
            }
        }

        $e->addresses = self::mapAddresses($record);
        $e->sanctionsInfo = self::mapSanctionsInfo($record);

        return $e->normalize();
    }

    /** @return list<string> */
    public static function collectAltNames(array $record): array
    {
        $alts = [];
        foreach (array_slice($record['Names'] ?? [], 1) as $n) {
            if (($t = Go::trimSpace($n)) !== '') {
                $alts[] = $t;
            }
        }
        foreach ($record['NonLatinScriptNames'] ?? [] as $n) {
            if (($t = Go::trimSpace($n)) !== '') {
                $alts[] = $t;
            }
        }

        return $alts;
    }

    private static function primaryName(array $record): string
    {
        return count($record['Names']) > 0 ? Go::trimSpace($record['Names'][0]) : '';
    }

    public static function mapPerson(array $record): Person
    {
        $record = CslUkRecord::complete($record);
        $p = new Person(self::primaryName($record), self::collectAltNames($record));
        if ($record['NameTitle'] !== '') {
            $p->titles = [$record['NameTitle']];
        }

        $town = $record['TownOfBirth'];
        $country = $record['CountryOfBirth'];
        if ($town !== '' && $country !== '') {
            $p->placeOfBirth = $town . ', ' . $country;
        } elseif ($country !== '') {
            $p->placeOfBirth = $country;
        } elseif ($town !== '') {
            $p->placeOfBirth = $town;
        }

        if ($record['DOB'] !== '' && ($t = self::parseUKDate($record['DOB'])) !== null) {
            $p->birthDate = $t;
        }

        if ($record['Gender'] !== '') {
            switch (Go::lower($record['Gender'])) {
                case 'male':
                    $p->gender = Gender::MALE;
                    break;
                case 'female':
                    $p->gender = Gender::FEMALE;
                    break;
            }
        }

        $ids = [];
        if ($record['PassportNumber'] !== '') {
            $id = new GovernmentID(GovernmentIDType::PASSPORT, '', $record['PassportNumber']);
            if ($record['PassportAdditionalInfo'] !== '') {
                $id->country = self::extractCountryFromDetails($record['PassportAdditionalInfo']);
            }
            $ids[] = $id;
        }
        if ($record['NationalIDNumber'] !== '') {
            $id = new GovernmentID(GovernmentIDType::NATIONAL, '', $record['NationalIDNumber']);
            if ($record['NationalIDAdditionalInfo'] !== '') {
                $id->country = self::extractCountryFromDetails($record['NationalIDAdditionalInfo']);
            }
            $ids[] = $id;
        }
        $p->governmentIDs = $ids;

        return $p;
    }

    /** parseUKDate: "02/01/2006", then with dd/mm placeholders replaced by 01. */
    public static function parseUKDate(string $s): ?DateTimeImmutable
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return null;
        }
        if (($t = GoTime::parse('02/01/2006', $s)) !== null) {
            return $t;
        }
        $s = str_replace('mm', '01', str_replace('dd', '01', $s));

        return GoTime::parse('02/01/2006', $s);
    }

    public static function extractCountryFromDetails(string $details): string
    {
        $details = Go::trimSpace($details);
        if ($details === '') {
            return '';
        }
        $lower = Go::lower($details);
        foreach ([' number', ' passport', ' id', ' issued', ','] as $suffix) {
            $idx = strpos($lower, $suffix);
            if ($idx !== false && $idx > 0) {
                $potential = Go::trimSpace(substr($details, 0, $idx));
                if (strlen($potential) > 1 && strlen($potential) < 50) {
                    return $potential;
                }
            }
        }
        if (strlen($details) < 30 && !str_contains($details, ' ')) {
            return $details;
        }

        return '';
    }

    public static function mapBusiness(array $record): Business
    {
        $record = CslUkRecord::complete($record);

        return new Business(self::primaryName($record), self::collectAltNames($record));
    }

    public static function mapVessel(array $record): Vessel
    {
        $record = CslUkRecord::complete($record);
        $v = new Vessel(self::primaryName($record), self::collectAltNames($record));
        if ($record['IMONumber'] !== '') {
            $v->imoNumber = $record['IMONumber'];
        }
        if ($record['VesselType'] !== '') {
            $v->type = $record['VesselType'];
        }
        if ($record['Tonnage'] !== '' && ($n = CslEuRecord::atoi($record['Tonnage'])) !== null) {
            $v->tonnage = $n;
        }
        if ($record['VesselFlag'] !== '') {
            $v->flag = Country::normalize($record['VesselFlag']);
        } elseif (count($record['AddressCountries']) > 0 && $record['AddressCountries'][0] !== '') {
            $v->flag = Country::normalize($record['AddressCountries'][0]);
        }
        if ($record['VesselOwner'] !== '') {
            $v->owner = $record['VesselOwner'];
        }

        return $v;
    }

    /** @return list<Address> */
    public static function mapAddresses(array $record): array
    {
        $record = CslUkRecord::complete($record);
        $out = [];
        foreach ($record['Addresses'] as $i => $addr) {
            if (Go::trimSpace($addr) === '') {
                continue;
            }
            $a = new Address(line1: $addr);
            if (($record['AddressCountries'][$i] ?? '') !== '') {
                $a->country = Country::normalize($record['AddressCountries'][$i]);
            }
            if (($record['StateLocalities'][$i] ?? '') !== '') {
                $a->state = $record['StateLocalities'][$i];
            }
            if (($record['AddressPostalCodes'][$i] ?? '') !== '') {
                $a->postalCode = $record['AddressPostalCodes'][$i];
            }
            $out[] = $a;
        }

        return $out;
    }

    public static function mapSanctionsInfo(array $record): ?SanctionsInfo
    {
        $record = CslUkRecord::complete($record);
        $info = new SanctionsInfo();
        $has = false;
        if ($record['Regime'] !== '') {
            $info->programs = [$record['Regime']];
            $has = true;
        }
        if ($record['UNReferenceNumber'] !== '') {
            $info->description = 'UN Reference: ' . $record['UNReferenceNumber'];
            $has = true;
        }

        return $has ? $info : null;
    }
}
