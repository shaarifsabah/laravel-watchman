<?php

declare(strict_types=1);

namespace Watchman\Sources\CslEu;

use RuntimeException;
use Watchman\Support\GoCsvReader;

/**
 * Port of pkg/sources/csl_eu/reader_eu.go (ParseEU).
 *
 * Records are associative arrays keyed by the CSLRecord json tags (see
 * CslEuRecord::emptyRecord()); rows sharing an Entity_LogicalId are merged.
 *
 * Go returns the merged records by ranging over a map (random order); this port
 * keeps first-seen order instead.
 */
final class CslEuReader
{
    // Column indices (pkg/sources/csl_eu/csl.go)
    public const FILE_GENERATION_DATE = 0;
    public const ENTITY_LOGICAL_ID = 1;
    public const MIN_RECORD_COLUMNS = 2;

    /** column index => [json key, kind] where kind is 's' (string, set when non-empty), 'a' (append unique), 'i' (append unique int) */
    private const ENTITY_COLUMNS = [
        2 => 'entityReferenceNumber',
        3 => 'entityUnitedNationId',
        4 => 'entityDesignationDate',
        5 => 'entityDesignationDetails',
        6 => 'entityRemark',
        7 => 'entitySubjectType',
        8 => 'entitySubjectTypeCode',
        9 => 'entityRegulationType',
        10 => 'entityRegulationOrgType',
        11 => 'entityRegulationPubDate',
        12 => 'entityRegulationEntryIntoForce',
        13 => 'entityRegulationNumberTitle',
        14 => 'entityRegulationProgramme',
        15 => 'entityPublicationUrl',
    ];

    /** Columns collected with appendUnique / appendUniqueInt, in Go's evaluation order. */
    private const LIST_COLUMNS = [
        // NameAlias (16-33)
        16 => ['nameAliasLastNames', 'a'],
        17 => ['nameAliasFirstNames', 'a'],
        18 => ['nameAliasMiddleNames', 'a'],
        19 => ['nameAliasWholeNames', 'a'],
        20 => ['nameAliasNameLanguages', 'a'],
        21 => ['nameAliasGenders', 'a'],
        22 => ['nameAliasTitles', 'a'],
        23 => ['nameAliasFunctions', 'a'],
        24 => ['nameAliasLogicalIds', 'i'],
        25 => ['nameAliasRegLanguages', 'a'],
        26 => ['nameAliasRemarks', 'a'],
        27 => ['nameAliasRegTypes', 'a'],
        28 => ['nameAliasRegOrgTypes', 'a'],
        29 => ['nameAliasRegPubDates', 'a'],
        30 => ['nameAliasRegEntryDates', 'a'],
        31 => ['nameAliasRegNumberTitles', 'a'],
        32 => ['nameAliasRegProgrammes', 'a'],
        33 => ['nameAliasRegPubUrls', 'a'],
        // Address (34-53)
        34 => ['addressCities', 'a'],
        35 => ['addressStreets', 'a'],
        36 => ['addressPoBoxes', 'a'],
        37 => ['addressZipCodes', 'a'],
        38 => ['addressRegions', 'a'],
        39 => ['addressPlaces', 'a'],
        40 => ['addressAsAtListingTimes', 'a'],
        41 => ['addressContactInfos', 'a'],
        42 => ['addressCountryIsos', 'a'],
        43 => ['addressCountryDescriptions', 'a'],
        44 => ['addressLogicalIds', 'i'],
        45 => ['addressRegLanguages', 'a'],
        46 => ['addressRemarks', 'a'],
        47 => ['addressRegTypes', 'a'],
        48 => ['addressRegOrgTypes', 'a'],
        49 => ['addressRegPubDates', 'a'],
        50 => ['addressRegEntryDates', 'a'],
        51 => ['addressRegNumberTitles', 'a'],
        52 => ['addressRegProgrammes', 'a'],
        53 => ['addressRegPubUrls', 'a'],
        // BirthDate (54-77)
        54 => ['birthDates', 'a'],
        55 => ['birthDays', 'a'],
        56 => ['birthMonths', 'a'],
        57 => ['birthYears', 'a'],
        58 => ['birthYearRangeFroms', 'a'],
        59 => ['birthYearRangeTos', 'a'],
        60 => ['birthCircas', 'a'],
        61 => ['birthCalendarTypes', 'a'],
        62 => ['birthZipCodes', 'a'],
        63 => ['birthRegions', 'a'],
        64 => ['birthPlaces', 'a'],
        65 => ['birthCities', 'a'],
        66 => ['birthCountryIsos', 'a'],
        67 => ['birthCountries', 'a'],
        68 => ['birthLogicalIds', 'i'],
        69 => ['birthRegLanguages', 'a'],
        70 => ['birthRemarks', 'a'],
        71 => ['birthRegTypes', 'a'],
        72 => ['birthRegOrgTypes', 'a'],
        73 => ['birthRegPubDates', 'a'],
        74 => ['birthRegEntryDates', 'a'],
        75 => ['birthRegNumberTitles', 'a'],
        76 => ['birthRegProgrammes', 'a'],
        77 => ['birthRegPubUrls', 'a'],
    ];

    /** Identification columns (78-104) => IdentificationInfo json key. */
    private const ID_COLUMNS = [
        79 => 'diplomatic',
        80 => 'knownExpired',
        81 => 'knownFalse',
        82 => 'reportedLost',
        83 => 'revokedByIssuer',
        84 => 'issuedBy',
        85 => 'issuedDate',
        86 => 'validFrom',
        87 => 'validTo',
        88 => 'latinNumber',
        89 => 'nameOnDocument',
        90 => 'typeCode',
        91 => 'typeDescription',
        92 => 'region',
        93 => 'countryIso',
        94 => 'countryDesc',
        // 95 logicalId handled separately
        96 => 'regLanguage',
        97 => 'remark',
        98 => 'regType',
        99 => 'regOrgType',
        100 => 'regPubDate',
        101 => 'regEntryDate',
        102 => 'regNumberTitle',
        103 => 'regProgramme',
        104 => 'regPubUrl',
    ];

    private const CITIZENSHIP_COLUMNS = [
        105 => ['citizenshipRegions', 'a'],
        106 => ['citizenshipCountryIsos', 'a'],
        107 => ['citizenships', 'a'],
        108 => ['citizenshipLogicalIds', 'i'],
        109 => ['citizenshipRegLanguages', 'a'],
        110 => ['citizenshipRemarks', 'a'],
        111 => ['citizenshipRegTypes', 'a'],
        112 => ['citizenshipRegOrgTypes', 'a'],
        113 => ['citizenshipRegPubDates', 'a'],
        114 => ['citizenshipRegEntryDates', 'a'],
        115 => ['citizenshipRegNumTitles', 'a'],
        116 => ['citizenshipRegProgrammes', 'a'],
        117 => ['citizenshipRegPubUrls', 'a'],
    ];

    /**
     * ParseEU: returns merged records keyed by EntityLogicalID (first-seen order).
     *
     * @param resource|string $source path or stream
     * @return array<int, array<string,mixed>>
     */
    public static function parse(mixed $source): array
    {
        $reader = $source instanceof GoCsvReader ? $source : new GoCsvReader($source, ';', true);
        $reader->comma = ';';
        $reader->lazyQuotes = true;

        [, $err] = $reader->read(); // header
        if ($err !== null) {
            throw new RuntimeException('failed to read csv: ' . $err);
        }

        $report = [];
        while (true) {
            [$record, $err] = $reader->read();
            if ($err === GoCsvReader::ERR_EOF) {
                break;
            }
            if ($err !== null) {
                continue; // ErrFieldCount / ErrBareQuote / ErrQuote: malformed row
            }
            if (count($record) < self::MIN_RECORD_COLUMNS) {
                continue;
            }
            $logicalId = CslEuRecord::atoi(\Watchman\Support\Go::trimSpace($record[self::ENTITY_LOGICAL_ID]));
            if ($logicalId === null) {
                continue;
            }
            if (!isset($report[$logicalId])) {
                $report[$logicalId] = CslEuRecord::emptyRecord();
            }
            self::unmarshalRecord($record, $report[$logicalId]);
        }

        return $report;
    }

    public static function parseString(string $csv): array
    {
        return self::parse(GoCsvReader::fromString($csv, ';', true));
    }

    /**
     * @param list<string> $csv
     * @param array<string,mixed> $r
     */
    public static function unmarshalRecord(array $csv, array &$r): void
    {
        $len = count($csv);
        if ($len <= self::ENTITY_LOGICAL_ID) {
            return;
        }
        $r['entityLogicalId'] = CslEuRecord::atoi($csv[self::ENTITY_LOGICAL_ID]) ?? 0;

        if ($csv[self::FILE_GENERATION_DATE] !== '') {
            $r['fileGenerationDate'] = $csv[self::FILE_GENERATION_DATE];
        }
        foreach (self::ENTITY_COLUMNS as $idx => $key) {
            if ($len > $idx && $csv[$idx] !== '') {
                $r[$key] = $csv[$idx];
            }
        }
        foreach (self::LIST_COLUMNS as $idx => [$key, $kind]) {
            self::collect($csv, $len, $idx, $key, $kind, $r);
        }
        self::unmarshalIdentification($csv, $len, $r);
        foreach (self::CITIZENSHIP_COLUMNS as $idx => [$key, $kind]) {
            self::collect($csv, $len, $idx, $key, $kind, $r);
        }
    }

    private static function collect(array $csv, int $len, int $idx, string $key, string $kind, array &$r): void
    {
        if ($len <= $idx || $csv[$idx] === '') {
            return;
        }
        if ($kind === 'i') {
            $id = CslEuRecord::atoi($csv[$idx]);
            if ($id !== null && !in_array($id, $r[$key], true)) {
                $r[$key][] = $id;
            }

            return;
        }
        if (!in_array($csv[$idx], $r[$key], true)) {
            $r[$key][] = $csv[$idx];
        }
    }

    private static function unmarshalIdentification(array $csv, int $len, array &$r): void
    {
        if ($len <= 78 || $csv[78] === '') {
            return;
        }
        $id = CslEuRecord::emptyIdentification();
        $id['number'] = $csv[78];
        foreach (self::ID_COLUMNS as $idx => $key) {
            if ($len > $idx) {
                $id[$key] = $csv[$idx];
            }
        }
        if ($len > 95 && $csv[95] !== '') {
            $id['logicalId'] = CslEuRecord::atoi($csv[95]) ?? 0;
        }
        foreach ($r['identifications'] as $existing) {
            if ($existing['number'] === $id['number'] && $existing['typeCode'] === $id['typeCode']) {
                return;
            }
        }
        $r['identifications'][] = $id;
    }
}
