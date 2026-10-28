<?php

declare(strict_types=1);

namespace Watchman\Sources\Senzing;

use RuntimeException;
use Watchman\Search\Address;
use Watchman\Search\Business;
use Watchman\Search\ContactInfo;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\Gender;
use Watchman\Search\GovernmentID;
use Watchman\Search\GovernmentIDType;
use Watchman\Search\Person;
use Watchman\Support\Go;
use Watchman\Support\GoTime;

/**
 * Port of pkg/sources/senzing/reader.go: Senzing entity-specification records
 * (JSON array or JSON Lines), e.g. OpenSanctions "senzing.json" exports.
 */
final class SenzingReader
{
    private const FEATURE_FIELDS = [
        'RECORD_TYPE', 'NAME_FIRST', 'NAME_MIDDLE', 'NAME_LAST', 'NAME_FULL', 'NAME_PREFIX', 'NAME_SUFFIX', 'NAME_ORG',
        'ADDR_LINE1', 'ADDR_LINE2', 'ADDR_LINE3', 'ADDR_CITY', 'ADDR_STATE', 'ADDR_POSTAL_CODE', 'ADDR_COUNTRY', 'ADDR_FULL',
        'SSN', 'PASSPORT_NUMBER', 'PASSPORT_COUNTRY', 'TAX_ID_NUMBER', 'TAX_ID_COUNTRY', 'NATIONAL_ID_NUMBER',
        'NATIONAL_ID_COUNTRY', 'DRIVERS_LICENSE_NUMBER', 'DRIVERS_LICENSE_STATE', 'PHONE_NUMBER', 'PHONE_TYPE',
        'EMAIL_ADDRESS', 'WEBSITE_ADDRESS', 'DATE_OF_BIRTH', 'DATE_OF_DEATH', 'GENDER', 'NATIONALITY',
        'REL_ANCHOR_DOMAIN', 'REL_ANCHOR_KEY', 'REL_POINTER_DOMAIN', 'REL_POINTER_KEY', 'REL_POINTER_ROLE',
    ];

    private const DATE_LAYOUTS = ['2006-01-02', '20060102', '2006/01/02', '01/02/2006', '1/2/2006', 'Jan 2, 2006', 'January 2, 2006', '2006'];

    /**
     * Stream entities from a file (auto-detects JSON array vs JSON Lines).
     *
     * @return \Generator<int, Entity>
     */
    public static function readFile(string $path, string $sourceList): \Generator
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("unable to open $path");
        }
        try {
            $first = '';
            while (($c = fgetc($fh)) !== false) {
                if (!in_array($c, [' ', "\n", "\r", "\t"], true)) {
                    $first = $c;
                    break;
                }
            }
            if ($first === '') {
                return;
            }
            if ($first === '[') {
                $records = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                foreach ((array) $records as $rec) {
                    yield self::toEntity((array) $rec, $sourceList);
                }

                return;
            }
            rewind($fh);
            $lineNum = 0;
            while (($line = fgets($fh)) !== false) {
                $lineNum++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                try {
                    $rec = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (\JsonException $e) {
                    throw new RuntimeException("parsing line $lineNum: " . $e->getMessage(), 0, $e);
                }
                yield self::toEntity((array) $rec, $sourceList);
            }
        } finally {
            fclose($fh);
        }
    }

    /** @return list<Entity> */
    public static function readString(string $contents, string $sourceList): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'senzing');
        file_put_contents($tmp, $contents);
        try {
            return iterator_to_array(self::readFile($tmp, $sourceList), false);
        } finally {
            @unlink($tmp);
        }
    }

    public static function normalizeRecord(array $rec): array
    {
        $out = $rec;
        foreach ((array) ($rec['FEATURES'] ?? []) as $feature) {
            foreach ((array) $feature as $key => $val) {
                if (!is_string($val)) {
                    continue;
                }
                $k = strtoupper((string) $key);
                if (in_array($k, self::FEATURE_FIELDS, true)) {
                    $out[$k] = $val;
                }
            }
        }
        $s = static fn (string $k) => isset($out[$k]) && is_string($out[$k]) ? $out[$k] : '';
        if ($s('NAME_FULL') === '' && !empty($rec['NAMES'])) {
            foreach ($rec['NAMES'] as $n) {
                if (strtoupper((string) ($n['NAME_TYPE'] ?? '')) === 'PRIMARY') {
                    $out['NAME_FULL'] = (string) ($n['NAME_FULL'] ?? '');
                    break;
                }
            }
            if (($out['NAME_FULL'] ?? '') === '') {
                $out['NAME_FULL'] = (string) ($rec['NAMES'][0]['NAME_FULL'] ?? '');
            }
        }
        if ($s('DATE_OF_BIRTH') === '' && !empty($rec['DATES'])) {
            foreach ($rec['DATES'] as $d) {
                if (($d['DATE_OF_BIRTH'] ?? '') !== '') {
                    $out['DATE_OF_BIRTH'] = $d['DATE_OF_BIRTH'];
                    break;
                }
            }
        }
        if ($s('NATIONALITY') === '' && !empty($rec['COUNTRIES'])) {
            foreach ($rec['COUNTRIES'] as $c) {
                if (($c['NATIONALITY'] ?? '') !== '') {
                    $out['NATIONALITY'] = $c['NATIONALITY'];
                    break;
                }
            }
        }
        if ($s('REL_POINTER_ROLE') === '' && !empty($rec['RELATIONSHIPS'])) {
            $rel = $rec['RELATIONSHIPS'][0];
            $out['REL_POINTER_ROLE'] = (string) ($rel['REL_POINTER_ROLE'] ?? '');
            $out['REL_POINTER_DOMAIN'] = (string) ($rel['REL_POINTER_DOMAIN'] ?? '');
            $out['REL_POINTER_KEY'] = (string) ($rel['REL_POINTER_KEY'] ?? '');
        }

        // Go marshals with omitempty: drop empty strings / empty lists.
        return array_filter($out, static fn ($v) => !($v === '' || $v === [] || $v === null));
    }

    public static function toEntity(array $rec, string $sourceList): Entity
    {
        $r = self::normalizeRecord($rec);
        $g = static fn (string $k) => isset($r[$k]) && is_scalar($r[$k]) ? (string) $r[$k] : '';
        $e = new Entity('', '', $sourceList, $g('RECORD_ID'));
        $e->sourceData = $r;

        $type = strtoupper($g('RECORD_TYPE'));
        $isPerson = match ($type) {
            'PERSON', 'INDIVIDUAL' => true,
            'ORGANIZATION', 'BUSINESS', 'COMPANY' => false,
            default => $g('NAME_ORG') === '',
        };
        if ($isPerson) {
            $e->type = EntityType::PERSON;
            $e->name = self::personName($g);
            $e->person = new Person(
                name: $e->name,
                gender: match (strtoupper($g('GENDER'))) {
                    'M', 'MALE' => Gender::MALE,
                    'F', 'FEMALE' => Gender::FEMALE,
                    default => Gender::UNKNOWN,
                },
                birthDate: self::date($g('DATE_OF_BIRTH')),
                deathDate: self::date($g('DATE_OF_DEATH')),
                governmentIDs: self::govIds($r, $g),
            );
        } else {
            $e->type = EntityType::BUSINESS;
            $e->name = Go::or($g('NAME_ORG'), $g('NAME_FULL'), self::personName($g));
            $e->business = new Business($e->name, governmentIDs: self::govIds($r, $g));
        }
        $e->addresses = self::addresses($g);
        $c = new ContactInfo();
        if ($g('PHONE_NUMBER') !== '') {
            $c->phoneNumbers = [$g('PHONE_NUMBER')];
        }
        if ($g('EMAIL_ADDRESS') !== '') {
            $c->emailAddresses = [$g('EMAIL_ADDRESS')];
        }
        if ($g('WEBSITE_ADDRESS') !== '') {
            $c->websites = [$g('WEBSITE_ADDRESS')];
        }
        if ($g('URL') !== '') {
            $c->websites[] = $g('URL');
        }
        $e->contact = $c;

        return $e->normalize();
    }

    private static function personName(callable $g): string
    {
        if ($g('NAME_FULL') !== '') {
            return $g('NAME_FULL');
        }
        $sb = '';
        foreach (['NAME_PREFIX', 'NAME_FIRST', 'NAME_MIDDLE', 'NAME_LAST'] as $k) {
            if ($g($k) !== '') {
                $sb .= $g($k) . ' ';
            }
        }
        $sb .= $g('NAME_SUFFIX');

        return Go::trimSpace($sb);
    }

    private static function date(string $s): ?\DateTimeImmutable
    {
        return $s === '' ? null : GoTime::parseAny(self::DATE_LAYOUTS, $s);
    }

    /** @return list<GovernmentID> */
    private static function govIds(array $r, callable $g): array
    {
        $ids = [];
        if ($g('SSN') !== '') {
            $ids[] = new GovernmentID(GovernmentIDType::SSN, '', $g('SSN'));
        }
        if ($g('PASSPORT_NUMBER') !== '') {
            $ids[] = new GovernmentID(GovernmentIDType::PASSPORT, $g('PASSPORT_COUNTRY'), $g('PASSPORT_NUMBER'));
        }
        if ($g('TAX_ID_NUMBER') !== '') {
            $ids[] = new GovernmentID(GovernmentIDType::TAX, $g('TAX_ID_COUNTRY'), $g('TAX_ID_NUMBER'));
        }
        if ($g('NATIONAL_ID_NUMBER') !== '') {
            $ids[] = new GovernmentID(GovernmentIDType::NATIONAL, $g('NATIONAL_ID_COUNTRY'), $g('NATIONAL_ID_NUMBER'));
        }
        if ($g('DRIVERS_LICENSE_NUMBER') !== '') {
            $ids[] = new GovernmentID(GovernmentIDType::DRIVERS_LICENSE, $g('DRIVERS_LICENSE_STATE'), $g('DRIVERS_LICENSE_NUMBER'));
        }
        foreach ((array) ($r['IDENTIFIERS'] ?? []) as $entry) {
            if (!isset($entry['TRUSTED_ID_TYPE'])) {
                continue;
            }
            $num = (string) ($entry['TRUSTED_ID_NUMBER'] ?? '');
            if ($num === '') {
                continue;
            }
            $ids[] = new GovernmentID((string) $entry['TRUSTED_ID_TYPE'], '', $num);
        }

        return $ids;
    }

    /** @return list<Address> */
    private static function addresses(callable $g): array
    {
        if ($g('ADDR_FULL') !== '' && $g('ADDR_LINE1') === '') {
            return [new Address(line1: $g('ADDR_FULL'))];
        }
        if ($g('ADDR_LINE1') === '' && $g('ADDR_CITY') === '' && $g('ADDR_COUNTRY') === '') {
            return [];
        }
        $line2 = $g('ADDR_LINE2');
        if ($g('ADDR_LINE3') !== '') {
            $line2 = $line2 !== '' ? $line2 . ', ' . $g('ADDR_LINE3') : $g('ADDR_LINE3');
        }

        return [new Address(
            line1: $g('ADDR_LINE1'),
            line2: $line2,
            city: $g('ADDR_CITY'),
            postalCode: $g('ADDR_POSTAL_CODE'),
            state: $g('ADDR_STATE'),
            country: $g('ADDR_COUNTRY'),
        )];
    }
}
