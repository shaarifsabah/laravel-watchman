<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUk;

use RuntimeException;
use Watchman\Sources\CslEu\CslEuRecord;
use Watchman\Support\GoCsvReader;
use Watchman\Support\Go;

/**
 * Port of pkg/sources/csl_uk/reader_uk.go.
 *
 * - readSanctionsList(): the UK Sanctions List CSV (UK-Sanctions-List.csv) used by Watchman.
 * - readConList(): legacy OFSI ConList.csv reader (ReadCSLFile / ParseCSL), kept for parity.
 * - readSanctionsListOds(): NOT in Go at 7f44b4b6 (ODS support was removed in b42f95c5); reads
 *   the same column layout from the .ods spreadsheet (first table) and feeds the rows
 *   through the CSV record logic. Used only as a fallback by CslUkSource.
 *
 * Go returns merged records by ranging over a map (random order); this port keeps first-seen order.
 */
final class CslUkReader
{
    // UK Sanctions List CSV column indices (uk_csl.go)
    public const LAST_UPDATED = 0;
    public const UNIQUE_ID = 1;
    public const OFSI_GROUP_ID = 2;
    public const UN_REFERENCE_NUMBER = 3;
    public const NAME6 = 4;
    public const NAME1 = 5;
    public const NAME2 = 6;
    public const NAME3 = 7;
    public const NAME4 = 8;
    public const NAME5 = 9;
    public const TITLE = 12;
    public const NON_LATIN_SCRIPT = 13;
    public const REGIME_NAME = 16;
    public const ENTITY_TYPE = 17;
    public const OTHER_INFO = 20;
    public const ADDRESS_LINE1 = 22;
    public const ADDRESS_LINE6 = 27;
    public const POSTAL_CODE = 28;
    public const ADDRESS_COUNTRY = 29;
    public const DATE_DESIGNATED = 33;
    public const DOB = 34;
    public const NATIONALITY = 35;
    public const NATIONAL_ID_NUMBER = 36;
    public const NATIONAL_ID_ADDITIONAL = 37;
    public const PASSPORT_NUMBER = 38;
    public const PASSPORT_ADDITIONAL = 39;
    public const POSITION = 40;
    public const GENDER = 41;
    public const TOWN_OF_BIRTH = 42;
    public const COUNTRY_OF_BIRTH = 43;
    public const BUSINESS_REG_NUMBER = 47;
    public const IMO_NUMBER = 48;
    public const CURRENT_OWNER = 49;
    public const CURRENT_FLAG = 51;
    public const TYPE_OF_SHIP = 53;
    public const TONNAGE = 54;

    /**
     * ReadSanctionsListFile / parseSanctionsListCSV.
     *
     * @param resource|string|GoCsvReader $source
     * @return array<string, array<string,mixed>> keyed by UniqueID
     */
    public static function readSanctionsList(mixed $source): array
    {
        $reader = $source instanceof GoCsvReader ? $source : new GoCsvReader($source);
        $reader->lazyQuotes = true;
        $reader->fieldsPerRecord = -1;

        return self::groupRows(self::csvRows($reader));
    }

    public static function readSanctionsListString(string $csv): array
    {
        return self::readSanctionsList(GoCsvReader::fromString($csv));
    }

    /**
     * Data rows of the CSV (Go skips the first two rows: report date + header; malformed rows still count).
     *
     * @return \Generator<int, list<string>>
     */
    private static function csvRows(GoCsvReader $reader): \Generator
    {
        $rowNum = 0;
        while (true) {
            [$record, $err] = $reader->read();
            if ($err === GoCsvReader::ERR_EOF) {
                return;
            }
            $rowNum++;
            if ($err !== null || $rowNum <= 2) {
                continue;
            }
            yield $record;
        }
    }

    /**
     * @param iterable<list<string>> $rows
     * @return array<string, array<string,mixed>>
     */
    private static function groupRows(iterable $rows): array
    {
        $report = [];
        foreach ($rows as $record) {
            if (count($record) <= self::UNIQUE_ID) {
                continue;
            }
            $uniqueId = Go::trimSpace($record[self::UNIQUE_ID]);
            if ($uniqueId === '') {
                continue;
            }
            if (!isset($report[$uniqueId])) {
                $report[$uniqueId] = CslUkRecord::empty();
                $report[$uniqueId]['UniqueID'] = $uniqueId;
            }
            self::unmarshalSanctionsListRecord($record, $report[$uniqueId]);
        }

        return $report;
    }

    /**
     * @param list<string> $record
     * @param array<string,mixed> $r
     */
    public static function unmarshalSanctionsListRecord(array $record, array &$r): void
    {
        $get = static fn (int $idx): string => isset($record[$idx]) ? Go::trimSpace($record[$idx]) : '';
        $first = static function (string $key, int $idx) use (&$r, $get): void {
            if ($r[$key] === '') {
                $r[$key] = $get($idx);
            }
        };
        $addUnique = static function (string $key, string $v) use (&$r): void {
            if ($v !== '' && !in_array($v, $r[$key], true)) {
                $r[$key][] = $v;
            }
        };

        $first('LastUpdated', self::LAST_UPDATED);
        $first('OFSIGroupID', self::OFSI_GROUP_ID);
        $first('UNReferenceNumber', self::UN_REFERENCE_NUMBER);

        $names = [];
        foreach ([self::NAME6, self::NAME1, self::NAME2, self::NAME3, self::NAME4, self::NAME5] as $idx) {
            if (($v = $get($idx)) !== '') {
                $names[] = $v;
            }
        }
        $addUnique('Names', implode(' ', $names));

        $first('NameTitle', self::TITLE);
        $addUnique('NonLatinScriptNames', $get(self::NON_LATIN_SCRIPT));

        if ($r['EntityType'] === null) {
            $cell = $get(self::ENTITY_TYPE);
            if ($cell !== '') {
                $r['EntityType'] = CslUkRecord::ENTITY_STRING_MAP[$cell] ?? CslUkRecord::UNDEFINED;
            }
        }

        $parts = [];
        for ($idx = self::ADDRESS_LINE1; $idx < self::ADDRESS_LINE6; $idx++) {
            if (($v = $get($idx)) !== '') {
                $parts[] = $v;
            }
        }
        if (($v = $get(self::ADDRESS_LINE6)) !== '') {
            $parts[] = $v;
            $addUnique('StateLocalities', $v);
        }
        $addUnique('AddressPostalCodes', $get(self::POSTAL_CODE));
        $addUnique('AddressCountries', $get(self::ADDRESS_COUNTRY));
        $addUnique('Addresses', implode(', ', $parts));

        $first('CountryOfBirth', self::COUNTRY_OF_BIRTH);
        $first('TownOfBirth', self::TOWN_OF_BIRTH);
        $first('DOB', self::DOB);
        $first('Nationality', self::NATIONALITY);
        $first('PassportNumber', self::PASSPORT_NUMBER);
        $first('PassportAdditionalInfo', self::PASSPORT_ADDITIONAL);
        $first('NationalIDNumber', self::NATIONAL_ID_NUMBER);
        $first('NationalIDAdditionalInfo', self::NATIONAL_ID_ADDITIONAL);
        $first('Position', self::POSITION);
        $first('Gender', self::GENDER);
        $first('Regime', self::REGIME_NAME);
        $first('DateDesignated', self::DATE_DESIGNATED);
        $first('OtherInfo', self::OTHER_INFO);
        $first('IMONumber', self::IMO_NUMBER);
        $first('VesselType', self::TYPE_OF_SHIP);
        $first('Tonnage', self::TONNAGE);
        $first('VesselFlag', self::CURRENT_FLAG);
        $first('VesselOwner', self::CURRENT_OWNER);
        $first('BusinessRegNumber', self::BUSINESS_REG_NUMBER);
    }

    // ---------------------------------------------------------------------------------------
    // Legacy OFSI ConList.csv (ReadCSLFile / ParseCSL / unmarshalCSLRecord)

    private const LEGACY_LIST_COLUMNS = [
        6 => 'titles', 10 => 'datesOfBirth', 11 => 'townsOfBirth', 12 => 'countriesOfBirth', 13 => 'nationalities',
    ];

    /**
     * @param resource|string|GoCsvReader $source
     * @return array<int, array<string,mixed>> keyed by GroupID
     */
    public static function readConList(mixed $source): array
    {
        $reader = $source instanceof GoCsvReader ? $source : new GoCsvReader($source);
        $reader->fieldsPerRecord = 36;
        $reader->read();
        $reader->read();

        $report = [];
        while (true) {
            [$record, $err] = $reader->read();
            if ($err === GoCsvReader::ERR_EOF) {
                break;
            }
            if ($err !== null) {
                continue;
            }
            if (count($record) <= 1) {
                continue;
            }
            $groupId = CslEuRecord::atoi($record[35]);
            if ($groupId === null) {
                throw new RuntimeException('strconv.Atoi: parsing ' . json_encode($record[35]) . ': invalid syntax');
            }
            if (!isset($report[$groupId])) {
                $report[$groupId] = CslUkRecord::emptyLegacy();
            }
            self::unmarshalConListRecord($record, $report[$groupId]);
        }

        return $report;
    }

    /** @param list<string> $c @param array<string,mixed> $r */
    private static function unmarshalConListRecord(array $c, array &$r): void
    {
        $addUnique = static function (string $key, string $v) use (&$r): void {
            if ($v !== '' && !in_array($v, $r[$key], true)) {
                $r[$key][] = $v;
            }
        };
        $names = array_values(array_filter([$c[0], $c[1], $c[2], $c[3], $c[4]], static fn ($v) => $v !== ''));
        $addUnique('names', implode(' ', $names));
        foreach (self::LEGACY_LIST_COLUMNS as $idx => $key) {
            $addUnique($key, $c[$idx]);
        }
        $addr = array_values(array_filter([$c[19], $c[20], $c[21], $c[22], $c[23], $c[24]], static fn ($v) => $v !== ''));
        $addUnique('addresses', implode(', ', $addr));
        $addUnique('postalCodes', $c[25]);
        $addUnique('countries', $c[26]);
        $addUnique('otherInfo', $c[27]);
        if ($c[28] !== '' && $r['groupType'] === '') {
            $r['groupType'] = $c[28];
        }
        $addUnique('listedDate', $c[32]);
        $addUnique('lastUpdated', $c[34]);
        $addUnique('sanctionListDate', $c[33]);
        if ($c[35] !== '') {
            $r['groupId'] = CslEuRecord::atoi($c[35]) ?? 0;
        }
    }

    // ---------------------------------------------------------------------------------------
    // ODS (OpenDocument spreadsheet) fallback — not part of Go at 7f44b4b6.

    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /**
     * Read the first sheet of a UK Sanctions List .ods (same column layout as the CSV).
     * Like the pre-b42f95c5 Go reader: the first three rows (report date, blank, header)
     * and empty rows are skipped. number-columns-repeated is expanded (trailing empty
     * cells are dropped); number-rows-repeated rows are read once (merging is idempotent).
     *
     * @return array<string, array<string,mixed>>
     */
    public static function readSanctionsListOds(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('ext-zip is required to read the UK sanctions list .ods');
        }

        return self::groupRows(self::odsRows($path));
    }

    /** @return \Generator<int, list<string>> */
    public static function odsRows(string $path): \Generator
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true || $zip->locateName('content.xml') === false) {
            throw new RuntimeException("$path is not an OpenDocument spreadsheet");
        }
        $zip->close();

        $xml = new \XMLReader();
        if (!@$xml->open('zip://' . $path . '#content.xml', null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException("unable to open content.xml in $path");
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $tableDepth = null;
            $i = -1;
            $more = @$xml->read();
            while ($more) {
                $isTable = $xml->namespaceURI === self::NS_TABLE;
                if ($tableDepth !== null && $isTable && $xml->nodeType === \XMLReader::END_ELEMENT
                    && $xml->depth === $tableDepth && $xml->localName === 'table') {
                    break; // only the first table
                }
                if ($isTable && $xml->nodeType === \XMLReader::ELEMENT) {
                    if ($tableDepth === null && $xml->localName === 'table') {
                        $tableDepth = $xml->depth;
                        if ($xml->isEmptyElement) {
                            break;
                        }
                    } elseif ($tableDepth !== null && $xml->localName === 'table-row') {
                        $node = $xml->expand();
                        $i++;
                        $row = $node instanceof \DOMElement ? self::odsRowCells($node) : [];
                        if ($row !== [] && $i > 2) {
                            yield $row;
                        }
                        $more = @$xml->next(); // skip the row's subtree

                        continue;
                    }
                }
                $more = @$xml->read();
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new RuntimeException('ods: ' . trim($error->message));
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $xml->close();
        }
    }

    /** @return list<string> cell texts with repeats expanded; [] for an empty row */
    private static function odsRowCells(\DOMElement $row): array
    {
        $cells = [];
        $pendingEmpty = 0;
        for ($c = $row->firstChild; $c !== null; $c = $c->nextSibling) {
            if (!$c instanceof \DOMElement || $c->namespaceURI !== self::NS_TABLE
                || ($c->localName !== 'table-cell' && $c->localName !== 'covered-table-cell')) {
                continue;
            }
            $repeat = max(1, (int) ($c->getAttributeNS(self::NS_TABLE, 'number-columns-repeated') ?: 1));
            $paras = [];
            for ($p = $c->firstChild; $p !== null; $p = $p->nextSibling) {
                if ($p instanceof \DOMElement && $p->namespaceURI === self::NS_TEXT && ($p->localName === 'p' || $p->localName === 'h')) {
                    $paras[] = self::odsText($p);
                }
            }
            if ($paras === []) {
                $pendingEmpty += $repeat;
                continue;
            }
            $text = implode("\n", $paras);
            for (; $pendingEmpty > 0; $pendingEmpty--) {
                $cells[] = '';
            }
            for ($k = 0; $k < $repeat; $k++) {
                $cells[] = $text;
            }
        }

        return $cells;
    }

    private static function odsText(\DOMNode $n): string
    {
        $out = '';
        for ($c = $n->firstChild; $c !== null; $c = $c->nextSibling) {
            if ($c instanceof \DOMText) { // includes CDATA
                $out .= $c->data;
            } elseif ($c instanceof \DOMElement) {
                if ($c->namespaceURI === self::NS_TEXT) {
                    switch ($c->localName) {
                        case 's':
                            $out .= str_repeat(' ', max(1, (int) ($c->getAttributeNS(self::NS_TEXT, 'c') ?: 1)));
                            continue 2;
                        case 'tab':
                            $out .= "\t";
                            continue 2;
                        case 'line-break':
                            $out .= "\n";
                            continue 2;
                        case 'note':
                        case 'annotation':
                            continue 2;
                    }
                }
                $out .= self::odsText($c);
            }
        }

        return $out;
    }
}
