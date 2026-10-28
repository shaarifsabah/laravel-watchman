<?php

declare(strict_types=1);

namespace Watchman\Sources\Ofac;

use DateTimeImmutable;
use Watchman\Norm\Country;
use Watchman\Prepare\CompanyTitles;
use Watchman\Prepare\Reorder;
use Watchman\Search\Address;
use Watchman\Search\Affiliation;
use Watchman\Search\Aircraft;
use Watchman\Search\Business;
use Watchman\Search\ContactInfo;
use Watchman\Search\CryptoAddress;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\GovernmentID;
use Watchman\Search\GovernmentIDType;
use Watchman\Search\HistoricalInfo;
use Watchman\Search\Person;
use Watchman\Search\SanctionsInfo;
use Watchman\Search\SourceList;
use Watchman\Search\Vessel;
use Watchman\Support\Go;
use Watchman\Support\GoTime;

/**
 * Port of pkg/sources/ofac/mapper.go: converts OFAC SDN rows into entities.
 */
final class OfacMapper
{
    private const AKA = "/a\\.k\\.a\\.[\\t\\n\\f\\r ]+'([^']+)'/i";
    private const EMAIL = '/(?:Email|EMAIL)[:\t\n\f\r ](Address)?[:\t\n\f\r ]+([^;]+)/i';
    private const PHONE = '/(?:Telephone|Phone|PHONE)[:\t\n\f\r ](No.?)?[:\t\n\f\r ]+([^;]+)/i';
    private const FAX = '/Fax[:\t\n\f\r ](No.?)?[:\t\n\f\r ]+([^;]+)/i';
    private const WEBSITE = '/(?:Website|http)[:\t\n\f\r ]+([^;\t\n\f\r ]+)/i';
    private const COUNTRY_PAREN = '/\(([0-9A-Za-z_\t\n\f\r ]+)\)/';

    private const DOB_PATTERNS = ['02 Jan 2006', 'Jan 2006', '2006'];

    private const LINKED_TO = '/Linked[\t\n\f\r ]+To:[\t\n\f\r ]+([^;]+)/i';
    private const SUBSIDIARY = '/Subsidiary[\t\n\f\r ]+Of:[\t\n\f\r ]+([^;]+)/i';
    private const OWNED_BY = '/(?:Owned|Controlled)[\t\n\f\r ]+By:[\t\n\f\r ]+([^;]+)/i';
    private const SANCTIONS = '/Additional[\t\n\f\r ]+Sanctions[\t\n\f\r ]+Information[\t\n\f\r ]+-[\t\n\f\r ]+([^;]+)/i';
    private const FORMER_NAME = '/(?:Former|Previous|f\.k\.a\.|p\.k\.a\.)[\t\n\f\r ]+(?:Name|Vessel):[\t\n\f\r ]+([^;]+)/i';
    private const TITLE = '/Title:[\t\n\f\r ]+([^;]+)/i';
    private const CRYPTO = '/Digital[\t\n\f\r ]+Currency[\t\n\f\r ]+Address[\t\n\f\r ]+-[\t\n\f\r ]+([A-Z0-9]+)[\t\n\f\r ]+([A-Z0-9]+)/i';

    private const S = '[\t\n\f\r ]';

    /** @var list<array{0:string,1:string,2:string,3:string}>|null marker, regex, type, country */
    private static ?array $govPatterns = null;

    /**
     * @param array{sdns:list<array>,addresses:array,alts:array,comments:array} $res
     * @return \Generator<int, Entity>
     */
    public static function groupIntoEntities(array $res, string $source = SourceList::US_OFAC): \Generator
    {
        foreach ($res['sdns'] as $sdn) {
            $id = $sdn['entityID'];
            yield self::toEntity($sdn, $res['addresses'][$id] ?? [], $res['comments'][$id] ?? [], $res['alts'][$id] ?? [], $source);
        }
    }

    public static function toEntity(array $sdn, array $addresses, array $comments, array $altIds, string $source = SourceList::US_OFAC): Entity
    {
        $out = new Entity(Reorder::sdnName($sdn['sdnName'], $sdn['sdnType']), '', SourceList::US_OFAC, $sdn['entityID']);
        $out->sourceData = $sdn;

        $remarks = self::splitRemarks($sdn['remarks']);
        [$affiliations, $sanctionsInfo, $historical, $titles] = self::parseRemarks($remarks);
        $out->affiliations = $affiliations;
        $out->sanctionsInfo = $sanctionsInfo;
        $out->historicalInfo = $historical;
        $out->cryptoAddresses = self::parseCryptoAddresses($remarks);
        $out->contact = self::parseContactInfo($remarks);
        $out->addresses = self::parseAddresses($addresses);

        $altNames = self::parseAltNames($remarks);
        foreach ($altIds as $alt) {
            if ($alt['alternateName'] !== '') {
                $altNames[] = Go::trimSpace($alt['alternateName']);
            }
        }
        $altNames = Reorder::sdnNames(self::dedupe($altNames), $sdn['sdnType']);

        switch (Go::lower(Go::trimSpace($sdn['sdnType']))) {
            case '-0-':
            case '':
                $out->type = EntityType::BUSINESS;
                $out->business = new Business(CompanyTitles::remove($sdn['sdnName']), $altNames);
                $out->business->created = self::findDateStamp(self::findMatchingRemarks($remarks, 'Organization Established Date'));
                $out->business->governmentIDs = self::parseGovernmentIDs($remarks);
                break;
            case 'individual':
                $out->type = EntityType::PERSON;
                $out->person = new Person($out->name, $altNames, Go::lower(self::firstValue(self::findMatchingRemarks($remarks, 'Gender'))));
                if ($sdn['title'] !== '') {
                    array_unshift($titles, $sdn['title']);
                }
                $out->person->titles = $titles;
                $out->person->birthDate = self::findDateStamp(self::findMatchingRemarks($remarks, 'DOB'));
                $out->person->governmentIDs = self::parseGovernmentIDs($remarks);
                break;
            case 'vessel':
                $out->type = EntityType::VESSEL;
                $out->vessel = new Vessel(
                    name: $sdn['sdnName'],
                    altNames: $altNames,
                    imoNumber: self::firstValue(self::findMatchingRemarks($remarks, 'IMO')),
                    type: self::normalizeType(Go::or($sdn['vesselType'], self::firstValue(self::findMatchingRemarks($remarks, 'Vessel Type')))),
                    flag: Country::normalize(Go::or($sdn['vesselFlag'], self::firstValue(self::findMatchingRemarks($remarks, 'Flag')))),
                    tonnage: self::parseTonnage(Go::or($sdn['tonnage'], self::firstValue(self::findMatchingRemarks($remarks, 'Tonnage')))),
                    mmsi: self::firstValue(self::findMatchingRemarks($remarks, 'MMSI')),
                    callSign: $sdn['callSign'],
                    grossRegisteredTonnage: self::parseTonnage($sdn['grossRegisteredTonnage']),
                    owner: $sdn['vesselOwner'],
                );
                break;
            case 'aircraft':
                $out->type = EntityType::AIRCRAFT;
                $out->aircraft = new Aircraft(
                    name: $sdn['sdnName'],
                    altNames: $altNames,
                    type: self::normalizeType(self::firstValue(self::findMatchingRemarks($remarks, 'Aircraft Type'))),
                    flag: Country::normalize(self::firstValue(self::findMatchingRemarks($remarks, 'Flag'))),
                    built: self::findDateStamp(self::findMatchingRemarks($remarks, 'Manufacture Date')),
                    icaoCode: self::firstValue(self::findMatchingRemarks($remarks, 'ICAO Code')),
                    model: self::firstValue(self::findMatchingRemarks($remarks, 'Aircraft Model')),
                    serialNumber: self::parseSerialNumber($remarks),
                );
                break;
        }
        $out->source = $source;

        return $out->normalize();
    }

    /** @return list<string> */
    public static function splitRemarks(string $input): array
    {
        if ($input === '') {
            return [];
        }
        if (str_ends_with($input, '.')) {
            $input = substr($input, 0, -1);
        }

        return array_values(array_filter(explode(';', $input), static fn ($p) => $p !== ''));
    }

    /** @return list<array{matchedName:string,fullName:string,value:string}> */
    public static function findMatchingRemarks(array $remarks, string $suffix): array
    {
        $out = [];
        if ($suffix === '') {
            return $out;
        }
        foreach ($remarks as $r) {
            $idx = strpos($r, $suffix);
            if ($idx === false) {
                continue;
            }
            $value = (string) substr($r, $idx + strlen($suffix));
            if ($value !== '' && $value[0] === ':') {
                $value = substr($value, 1);
            }
            $end = strlen($value);
            if ($end > 0) {
                if ($value[$end - 1] === '.') {
                    $end--;
                }
                if ($end > 0 && $value[$end - 1] === ';') {
                    $end--;
                }
            }
            $out[] = ['matchedName' => $suffix, 'fullName' => substr($r, 0, $idx + strlen($suffix)), 'value' => Go::trimSpace(substr($value, 0, $end))];
        }

        return $out;
    }

    private static function firstValue(array $values): string
    {
        return $values[0]['value'] ?? '';
    }

    private static function findDateStamp(array $matching): ?DateTimeImmutable
    {
        if ($matching === []) {
            return null;
        }

        return self::parseTime($matching[0]['value']);
    }

    /**
     * Go's parseTime returns the zero time (not an error) when nothing parses,
     * so a DOB remark always yields a date (possibly 0001-01-01).
     */
    public static function parseTime(string $value): DateTimeImmutable
    {
        $value = Go::trimSpace(str_replace('circa', '', $value));
        $parts = explode('to', $value);
        if (count($parts) > 1) {
            $value = $parts[0];
        } else {
            $parts = explode('-', $value);
            if (count($parts) > 1) {
                $value = $parts[0];
            }
        }
        $value = Go::trimSpace($value);
        foreach (self::DOB_PATTERNS as $layout) {
            $t = GoTime::parse($layout, $value);
            if ($t !== null && (int) $t->format('Y') >= 1900) {
                return $t;
            }
        }

        return GoTime::zero();
    }

    private static function extractCountry(string $remark): string
    {
        if (!preg_match_all(self::COUNTRY_PAREN, $remark, $m, PREG_SET_ORDER)) {
            return '';
        }
        $m = array_slice($m, 0, 10);
        for ($i = count($m) - 1; $i >= 0; $i--) {
            $country = Country::normalize($m[$i][1]);
            if ($country !== '') {
                return $country;
            }
        }

        return '';
    }

    /** @return list<Address> */
    private static function parseAddresses(array $inputs): array
    {
        $out = [];
        foreach ($inputs as $in) {
            $addr = new Address(line1: $in['address'], country: Country::normalize($in['country']));
            $parts = Go::fields($in['cityStateProvincePostalCode']);
            if (count($parts) === 1) {
                $addr->city = $parts[0];
            } elseif (count($parts) > 0) {
                $addr->city = implode(' ', array_slice($parts, 0, -1));
                $addr->postalCode = $parts[count($parts) - 1];
            }
            if ($addr->line1 !== '') {
                $out[] = $addr;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function parseAltNames(array $remarks): array
    {
        $names = [];
        foreach ($remarks as $r) {
            if (preg_match_all(self::AKA, $r, $m)) {
                foreach ($m[1] as $name) {
                    $names[] = trim($name, "'");
                }
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function dedupe(array $input): array
    {
        $seen = [];
        $out = [];
        foreach ($input as $s) {
            $k = Go::lower(Go::trimSpace($s));
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $s;
            }
        }

        return $out;
    }

    private static function govPatterns(): array
    {
        if (self::$govPatterns !== null) {
            return self::$govPatterns;
        }
        $s = self::S;
        self::$govPatterns = [
            ['passport', "/Passport$s+(?:#|No\\.|Number)?$s*([A-Z0-9]+)/i", GovernmentIDType::PASSPORT, ''],
            ['driver', "/Driver'?s?$s+License$s+(?:No\\.|Number)?$s*([A-Z0-9\\-]+)/i", GovernmentIDType::DRIVERS_LICENSE, ''],
            ['diplomatic', "/Diplomatic$s+Passport$s+([A-Z0-9]+)/i", GovernmentIDType::DIPLOMATIC_PASS, ''],
            ['national', "/National$s+ID$s+(?:No\\.|Number)?$s*([A-Z0-9-]+)/i", GovernmentIDType::NATIONAL, ''],
            ['personal', "/Personal$s+ID$s+(?:Card)?$s*(?:No\\.|Number)?$s*([A-Z0-9-]+)/i", GovernmentIDType::PERSONAL_ID, ''],
            ['tax', "/Tax$s+ID$s+(?:No\\.|Number)?$s*([A-Z0-9-]+)/i", GovernmentIDType::TAX, ''],
            ['', "/C\\.?U\\.?I\\.?T\\.?$s+(?:No\\.|Number)?$s*([A-Z0-9-]+)/i", GovernmentIDType::CUIT, ''],
            ['ssn', "/SSN$s+([0-9-]+)/i", GovernmentIDType::SSN, ''],
            ['cedula', "/Cedula$s+(?:No\\.|Number)?$s*([A-Z0-9-]+)/i", GovernmentIDType::CEDULA, ''],
            ['', "/C\\.?U\\.?R\\.?P\\.?$s+(?:#|No\\.|Number)?$s*([A-Z0-9-]+)/i", GovernmentIDType::CURP, ''],
            ['electoral', "/Electoral$s+Registry$s+(?:No\\.|Number)?$s*([A-Z0-9-]+)/i", GovernmentIDType::ELECTORAL, ''],
            ['business', "/Business$s+Registration$s+(?:No\\.|Number|Document)?$s*#?$s*([A-Z0-9\\-\\.]+)/i", GovernmentIDType::BUSINESS_REGISTRATION, ''],
            ['company', "/Company$s+Number$s+([0-9]+)/i", GovernmentIDType::BUSINESS_REGISTRATION, ''],
            ['legal', "/Legal$s+Entity$s+Number$s+([A-Za-z0-9\\-\\.]+)/i", GovernmentIDType::BUSINESS_REGISTRATION, ''],
            ['commercial', "/Commercial$s+Registry$s+(?:No\\.|Number)?$s*([A-Z0-9\\-.\\/]+)/i", GovernmentIDType::COMMERCIAL_REGISTRY, ''],
            ['isin', "/ISIN$s+([A-Z0-9]{12})/i", GovernmentIDType::BUSINESS_REGISTRATION, 'China'],
            ['social', "/Social$s+Credit$s+Code$s+\\(USCC\\)$s+([A-Z0-9]{15,30})/i", GovernmentIDType::BUSINESS_REGISTRATION, ''],
            ['birth', "/Birth$s+Certificate$s+(?:No\\.|Number)?$s*([A-Z0-9]+)/i", GovernmentIDType::BIRTH_CERT, ''],
            ['refugee', "/Refugee$s+ID$s+(?:Card)?$s*([A-Z0-9]+)/i", GovernmentIDType::REFUGEE, ''],
        ];

        return self::$govPatterns;
    }

    /** @return list<GovernmentID> */
    private static function parseGovernmentIDs(array $remarks): array
    {
        $ids = [];
        foreach ($remarks as $r) {
            $country = self::extractCountry($r);
            $lower = strtolower($r);
            foreach (self::govPatterns() as [$marker, $regex, $type, $baseCountry]) {
                if ($marker !== '' && !str_contains($lower, $marker)) {
                    continue;
                }
                if (!preg_match($regex, $r, $m) || count($m) <= 1) {
                    continue;
                }
                $identifier = Go::trimSpace(rtrim($m[1], '.;,'));
                $idx = $identifier === '' ? 0 : strpos($r, $identifier);
                $name = '';
                if ($idx !== false && $idx > 0) {
                    $name = substr($r, 0, $idx);
                    $name = str_replace(['alt.', 'No.', '#'], '', $name);
                    $name = Go::trimSpace($name);
                }
                $ids[] = new GovernmentID($type, Go::or($country, $baseCountry), $identifier, $name);
            }
        }

        return $ids;
    }

    private static function normalizeType(string $t): string
    {
        return Go::lower(Go::trimSpace($t)) === 'cargo' ? 'cargo' : 'unknown';
    }

    private static function parseTonnage(string $value): int
    {
        $value = str_replace(',', '', $value);

        return Go::atoi($value) ?? 0;
    }

    private static function parseSerialNumber(array $remarks): string
    {
        foreach (self::findMatchingRemarks($remarks, 'Serial Number') as $r) {
            $idx = strpos($r['value'], ')');
            if ($idx !== false && strlen($r['value']) > $idx + 1) {
                return Go::trimSpace(substr($r['value'], $idx + 1));
            }

            return Go::trimSpace($r['value']);
        }

        return '';
    }

    /** @return array{0:list<Affiliation>,1:?SanctionsInfo,2:list<HistoricalInfo>,3:list<string>} */
    private static function parseRemarks(array $remarks): array
    {
        $affiliations = [];
        $historical = [];
        $titles = [];
        $info = new SanctionsInfo();
        foreach ($remarks as $r) {
            foreach ([[self::LINKED_TO, 'Linked To'], [self::SUBSIDIARY, 'Subsidiary Of'], [self::OWNED_BY, 'Subsidiary Of']] as [$re, $type]) {
                if (preg_match_all($re, $r, $m)) {
                    foreach ($m[1] as $name) {
                        $affiliations[] = new Affiliation(Go::trimSpace($name), $type);
                    }
                }
            }
            if (preg_match(self::SANCTIONS, $r, $m)) {
                $desc = Go::trimSpace($m[1]);
                $info->description = $desc;
                if (str_contains(Go::lower($desc), 'secondary sanctions')) {
                    $info->secondary = true;
                }
            }
            if (preg_match_all(self::FORMER_NAME, $r, $m)) {
                foreach ($m[1] as $v) {
                    $historical[] = new HistoricalInfo('Former Name', Go::trimSpace($v));
                }
            }
            if (preg_match_all(self::TITLE, $r, $m)) {
                foreach ($m[1] as $v) {
                    $titles[] = Go::trimSpace($v);
                }
            }
        }

        $seen = [];
        $affiliations = array_values(array_filter($affiliations, static function (Affiliation $a) use (&$seen) {
            $k = $a->type . '|' . $a->entityName;
            if (isset($seen[$k])) {
                return false;
            }

            return $seen[$k] = true;
        }));
        $seen = [];
        $historical = array_values(array_filter($historical, static function (HistoricalInfo $h) use (&$seen) {
            $k = $h->type . '|' . $h->value;
            if (isset($seen[$k])) {
                return false;
            }

            return $seen[$k] = true;
        }));
        $titles = array_values(array_unique($titles));
        if ($info->description === '' && !$info->secondary && $info->programs === []) {
            $info = null;
        }

        return [$affiliations, $info, $historical, $titles];
    }

    private static function parseContactInfo(array $remarks): ContactInfo
    {
        $out = new ContactInfo();
        foreach ($remarks as $r) {
            $r = Go::trimSuffix($r, '.');
            if (preg_match_all(self::EMAIL, $r, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $out->emailAddresses[] = $x[count($x) - 1];
                }
            }
            if (preg_match_all(self::PHONE, $r, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $out->phoneNumbers[] = $x[count($x) - 1];
                }
            }
            if (preg_match_all(self::FAX, $r, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $out->faxNumbers[] = $x[count($x) - 1];
                }
            }
            if (preg_match_all(self::WEBSITE, $r, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $site = $x[count($x) - 1];
                    if (str_contains($site, '//www.treasury.gov')) {
                        continue;
                    }
                    $out->websites[] = $site;
                }
            }
        }

        return $out;
    }

    /** @return list<CryptoAddress> */
    private static function parseCryptoAddresses(array $remarks): array
    {
        $out = [];
        $seen = [];
        foreach ($remarks as $r) {
            if (preg_match_all(self::CRYPTO, $r, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $c = new CryptoAddress(Go::trimSpace($x[1]), Go::trimSpace($x[2]));
                    $k = $c->currency . '|' . $c->address;
                    if (!isset($seen[$k])) {
                        $seen[$k] = true;
                        $out[] = $c;
                    }
                }
            }
        }

        return $out;
    }
}
