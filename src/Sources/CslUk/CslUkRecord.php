<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUk;

/**
 * Shapes of pkg/sources/csl_uk.SanctionsListRecord (no json tags => Go field names)
 * and the legacy CSLRecord (ConList.csv, json tags), in struct order.
 */
final class CslUkRecord
{
    public const INDIVIDUAL = 'Individual';
    public const ENTITY = 'Entity';
    public const SHIP = 'Ship';
    public const UNDEFINED = '';

    /** EntityStringMap */
    public const ENTITY_STRING_MAP = ['Individual' => self::INDIVIDUAL, 'Entity' => self::ENTITY, 'Ship' => self::SHIP];

    public const SLICE_FIELDS = ['Names', 'NonLatinScriptNames', 'Addresses', 'StateLocalities', 'AddressCountries', 'AddressPostalCodes'];

    public const LEGACY_SLICE_FIELDS = [
        'names', 'titles', 'datesOfBirth', 'townsOfBirth', 'countriesOfBirth', 'nationalities', 'addresses',
        'postalCodes', 'countries', 'otherInfo', 'listedDate', 'sanctionListDate', 'lastUpdated',
    ];

    /**
     * SanctionsListRecord. EntityType is null (nil pointer) or one of the constants above.
     *
     * @return array<string,mixed>
     */
    public static function empty(): array
    {
        return [
            'LastUpdated' => '',
            'UniqueID' => '',
            'OFSIGroupID' => '',
            'UNReferenceNumber' => '',
            'Names' => [],
            'NameTitle' => '',
            'NonLatinScriptNames' => [],
            'EntityType' => null,
            'Addresses' => [],
            'StateLocalities' => [],
            'AddressCountries' => [],
            'AddressPostalCodes' => [],
            'CountryOfBirth' => '',
            'TownOfBirth' => '',
            'DOB' => '',
            'Nationality' => '',
            'PassportNumber' => '',
            'PassportAdditionalInfo' => '',
            'NationalIDNumber' => '',
            'NationalIDAdditionalInfo' => '',
            'Position' => '',
            'Gender' => '',
            'Regime' => '',
            'DateDesignated' => '',
            'OtherInfo' => '',
            'IMONumber' => '',
            'VesselType' => '',
            'Tonnage' => '',
            'VesselFlag' => '',
            'VesselOwner' => '',
            'BusinessRegNumber' => '',
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function complete(array $r): array
    {
        $out = self::empty();
        foreach ($r as $k => $v) {
            $out[$k] = ($v === null && in_array($k, self::SLICE_FIELDS, true)) ? [] : $v;
        }

        return $out;
    }

    /** Go encoding/json view: nil slices => null. @param array<string,mixed> $r */
    public static function toSourceData(array $r): array
    {
        foreach (self::SLICE_FIELDS as $f) {
            if (($r[$f] ?? []) === []) {
                $r[$f] = null;
            }
        }

        return $r;
    }

    /** Legacy ConList.csv CSLRecord (json tags). @return array<string,mixed> */
    public static function emptyLegacy(): array
    {
        return [
            'names' => [],
            'titles' => [],
            'datesOfBirth' => [],
            'townsOfBirth' => [],
            'countriesOfBirth' => [],
            'nationalities' => [],
            'addresses' => [],
            'postalCodes' => [],
            'countries' => [],
            'otherInfo' => [],
            'groupType' => '',
            'listedDate' => [],
            'sanctionListDate' => [],
            'lastUpdated' => [],
            'groupId' => 0,
        ];
    }

    /** @param array<string,mixed> $r */
    public static function legacyToSourceData(array $r): array
    {
        foreach (self::LEGACY_SLICE_FIELDS as $f) {
            if (($r[$f] ?? []) === []) {
                $r[$f] = null;
            }
        }

        return $r;
    }
}
