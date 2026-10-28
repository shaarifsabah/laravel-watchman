<?php

declare(strict_types=1);

namespace Watchman\Sources\CslEu;

/**
 * Shape of pkg/sources/csl_eu.CSLRecord / IdentificationInfo, keyed by their json tags
 * in struct order (so json_encode() matches Go's encoding/json output, apart from
 * nil slices which toSourceData() turns into null).
 */
final class CslEuRecord
{
    /** Slice-typed fields (encode as null when empty, like a nil Go slice). */
    public const SLICE_FIELDS = [
        'nameAliasLastNames', 'nameAliasFirstNames', 'nameAliasMiddleNames', 'nameAliasWholeNames',
        'nameAliasNameLanguages', 'nameAliasGenders', 'nameAliasTitles', 'nameAliasFunctions', 'nameAliasLogicalIds',
        'nameAliasRegLanguages', 'nameAliasRemarks', 'nameAliasRegTypes', 'nameAliasRegOrgTypes', 'nameAliasRegPubDates',
        'nameAliasRegEntryDates', 'nameAliasRegNumberTitles', 'nameAliasRegProgrammes', 'nameAliasRegPubUrls',
        'addressCities', 'addressStreets', 'addressPoBoxes', 'addressZipCodes', 'addressRegions', 'addressPlaces',
        'addressAsAtListingTimes', 'addressContactInfos', 'addressCountryIsos', 'addressCountryDescriptions',
        'addressLogicalIds', 'addressRegLanguages', 'addressRemarks', 'addressRegTypes', 'addressRegOrgTypes',
        'addressRegPubDates', 'addressRegEntryDates', 'addressRegNumberTitles', 'addressRegProgrammes', 'addressRegPubUrls',
        'birthDates', 'birthDays', 'birthMonths', 'birthYears', 'birthYearRangeFroms', 'birthYearRangeTos', 'birthCircas',
        'birthCalendarTypes', 'birthZipCodes', 'birthRegions', 'birthPlaces', 'birthCities', 'birthCountryIsos',
        'birthCountries', 'birthLogicalIds', 'birthRegLanguages', 'birthRemarks', 'birthRegTypes', 'birthRegOrgTypes',
        'birthRegPubDates', 'birthRegEntryDates', 'birthRegNumberTitles', 'birthRegProgrammes', 'birthRegPubUrls',
        'identifications',
        'citizenshipRegions', 'citizenshipCountryIsos', 'citizenships', 'citizenshipLogicalIds', 'citizenshipRegLanguages',
        'citizenshipRemarks', 'citizenshipRegTypes', 'citizenshipRegOrgTypes', 'citizenshipRegPubDates',
        'citizenshipRegEntryDates', 'citizenshipRegNumTitles', 'citizenshipRegProgrammes', 'citizenshipRegPubUrls',
    ];

    /** @return array<string,mixed> */
    public static function emptyRecord(): array
    {
        $r = [
            'fileGenerationDate' => '',
            'entityLogicalId' => 0,
            'entityReferenceNumber' => '',
            'entityUnitedNationId' => '',
            'entityDesignationDate' => '',
            'entityDesignationDetails' => '',
            'entityRemark' => '',
            'entitySubjectType' => '',
            'entitySubjectTypeCode' => '',
            'entityRegulationType' => '',
            'entityRegulationOrgType' => '',
            'entityRegulationPubDate' => '',
            'entityRegulationEntryIntoForce' => '',
            'entityRegulationNumberTitle' => '',
            'entityRegulationProgramme' => '',
            'entityPublicationUrl' => '',
        ];
        foreach (self::SLICE_FIELDS as $f) {
            $r[$f] = [];
        }

        return $r;
    }

    /** @return array<string,mixed> */
    public static function emptyIdentification(): array
    {
        return [
            'number' => '', 'diplomatic' => '', 'knownExpired' => '', 'knownFalse' => '', 'reportedLost' => '',
            'revokedByIssuer' => '', 'issuedBy' => '', 'issuedDate' => '', 'validFrom' => '', 'validTo' => '',
            'latinNumber' => '', 'nameOnDocument' => '', 'typeCode' => '', 'typeDescription' => '', 'region' => '',
            'countryIso' => '', 'countryDesc' => '', 'logicalId' => 0, 'regLanguage' => '', 'remark' => '',
            'regType' => '', 'regOrgType' => '', 'regPubDate' => '', 'regEntryDate' => '', 'regNumberTitle' => '',
            'regProgramme' => '', 'regPubUrl' => '',
        ];
    }

    /**
     * Fill in missing keys (for partially specified records, e.g. in tests).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function complete(array $r): array
    {
        $out = self::emptyRecord();
        foreach ($r as $k => $v) {
            $out[$k] = $v ?? $out[$k] ?? null;
        }
        $ids = [];
        foreach ((array) $out['identifications'] as $id) {
            $ids[] = array_merge(self::emptyIdentification(), (array) $id);
        }
        $out['identifications'] = $ids;

        return $out;
    }

    /**
     * The record as Go's encoding/json would produce it (nil slices => null).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function toSourceData(array $r): array
    {
        foreach (self::SLICE_FIELDS as $f) {
            if (($r[$f] ?? []) === []) {
                $r[$f] = null;
            }
        }

        return $r;
    }

    /** strconv.Atoi: null on syntax or range error. */
    public static function atoi(string $s): ?int
    {
        if (!preg_match('/^([+-]?)([0-9]+)\z/', $s, $m)) {
            return null;
        }
        $digits = ltrim($m[2], '0');
        $limit = $m[1] === '-' ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) > 19 || (strlen($digits) === 19 && strcmp($digits, $limit) > 0)) {
            return null;
        }
        if ($m[1] === '-' && $digits === '9223372036854775808') {
            return PHP_INT_MIN;
        }

        return (int) $s;
    }
}
