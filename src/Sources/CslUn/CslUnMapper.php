<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUn;

use Watchman\Search\Address;
use Watchman\Search\Business;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\Person;
use Watchman\Search\SourceList;
use Watchman\Sources\CslEu\CslEuRecord;
use Watchman\Support\Go;
use Watchman\Support\GoTime;

/** Port of pkg/sources/csl_un/mapper.go */
final class CslUnMapper
{
    // RE2 `\s` == [\t\n\f\r ]; `$` (no multiline) == \z; `.` excludes only \n.
    private const WEBSITE_RE = '/https?:\/\/[^\t\n\f\r ;]+|www\.[^\t\n\f\r ;]+/';
    private const EMAIL_LABEL_RE = '/email:[\t\n\f\r ]*([^\n]+)(?:[\t\n\f\r ]*\.[\t\n\f\r ]*|\z)/i';
    private const PHONE_LABEL_RE = '/(?:telephone|phone number|phone):[\t\n\f\r ]*([^\n]+)(?:[\t\n\f\r ]*\.[\t\n\f\r ]*|\z)/i';

    private const SLICES = ['Nationalities', 'Aliases', 'Addresses', 'BirthDates', 'BirthPlaces'];

    /** Fill defaults for partially specified records. */
    private static function complete(array $r, bool $individual): array
    {
        $base = $individual
            ? ['DataID' => '', 'FirstName' => '', 'SecondName' => '', 'ThirdName' => '', 'FourthName' => '', 'UNListType' => '',
                'ReferenceNumber' => '', 'ListedOn' => '', 'Gender' => '', 'Comments' => '', 'Nationalities' => [], 'Aliases' => [],
                'Addresses' => [], 'BirthDates' => [], 'BirthPlaces' => []]
            : ['DataID' => '', 'FirstName' => '', 'UNListType' => '', 'ReferenceNumber' => '', 'ListedOn' => '', 'Comments' => '',
                'Aliases' => [], 'Addresses' => []];
        $out = $base;
        foreach ($r as $k => $v) {
            $out[$k] = $v ?? $base[$k] ?? null;
        }
        $defaults = [
            'Nationalities' => ['Text' => ''],
            'Aliases' => ['Quality' => '', 'Name' => ''],
            'Addresses' => ['Street' => '', 'City' => '', 'StateProvince' => '', 'Country' => '', 'Note' => ''],
            'BirthDates' => ['Type' => '', 'Date' => '', 'Year' => ''],
            'BirthPlaces' => ['City' => '', 'State' => '', 'Country' => ''],
        ];
        foreach ($defaults as $k => $d) {
            if (isset($out[$k])) {
                $out[$k] = array_map(static fn ($x) => array_merge($d, (array) $x), array_values((array) $out[$k]));
            }
        }

        return $out;
    }

    /** encoding/json view of the record: nil slices => null. */
    private static function sourceData(array $r): array
    {
        foreach (self::SLICES as $k) {
            if (array_key_exists($k, $r) && $r[$k] === []) {
                $r[$k] = null;
            }
        }

        return $r;
    }

    /**
     * parseContactInfo
     *
     * @return array{emailAddresses: list<string>, phoneNumbers: list<string>, websites: list<string>}
     */
    public static function parseContactInfo(string $s): array
    {
        $ci = ['emailAddresses' => [], 'phoneNumbers' => [], 'websites' => []];
        if ($s === '') {
            return $ci;
        }

        if (preg_match_all(self::EMAIL_LABEL_RE, $s, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                foreach (explode(';', Go::trimSpace($m[1])) as $e) {
                    $e = Go::trimSpace($e);
                    if ($e === '') {
                        continue;
                    }
                    $idx = strpos($e, ' ');
                    if ($idx !== false && $idx > 0) {
                        $e = substr($e, 0, $idx);
                    }
                    $ci['emailAddresses'][] = $e;
                }
            }
        }

        if (preg_match_all(self::PHONE_LABEL_RE, $s, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $phones = Go::trimSpace($m[1]);
                if ($phones === '') {
                    continue;
                }
                $parts = [];
                foreach (Go::fields($phones) as $p) {
                    if (str_contains($p, ':')) {
                        break;
                    }
                    $parts[] = $p;
                }
                $phone = Go::trimSuffix(implode(' ', $parts), '.');
                if ($phone !== '') {
                    $ci['phoneNumbers'][] = $phone;
                }
            }
        }

        if (preg_match_all(self::WEBSITE_RE, $s, $mm)) {
            $ci['websites'] = $mm[0];
        }

        $ci['emailAddresses'] = self::dedup($ci['emailAddresses']);
        $ci['phoneNumbers'] = self::dedup($ci['phoneNumbers']);
        $ci['websites'] = self::dedup($ci['websites']);

        return $ci;
    }

    /** slices.Sort + slices.Compact @param list<string> $in @return list<string> */
    private static function dedup(array $in): array
    {
        sort($in, SORT_STRING);
        $out = [];
        foreach ($in as $v) {
            if ($out === [] || $out[count($out) - 1] !== $v) {
                $out[] = $v;
            }
        }

        return $out;
    }

    private static function addContact(Entity $e, string $text): void
    {
        $ci = self::parseContactInfo($text);
        array_push($e->contact->emailAddresses, ...$ci['emailAddresses']);
        array_push($e->contact->phoneNumbers, ...$ci['phoneNumbers']);
        array_push($e->contact->websites, ...$ci['websites']);
    }

    /** UNIndividual.ToEntity @param array<string,mixed> $p */
    public static function individualToEntity(array $p): Entity
    {
        $p = self::complete($p, true);
        $fullName = Go::trimSpace(implode(' ', [$p['FirstName'], $p['SecondName'], $p['ThirdName'], $p['FourthName']]));

        $e = new Entity($fullName, EntityType::PERSON, SourceList::UN_CSL, 'UN-' . $p['DataID']);
        $e->sourceData = self::sourceData($p);
        $e->person = new Person($fullName);

        if ($p['Gender'] !== '') {
            $e->person->gender = $p['Gender'];
        } elseif ($p['Comments'] !== '' && str_contains($p['Comments'], 'Gender:')) {
            $parts = explode('Gender:', $p['Comments']);
            if (count($parts) > 1) {
                $tok = Go::fields($parts[1]);
                if ($tok !== []) {
                    $e->person->gender = $tok[0];
                }
            }
        }

        foreach ($p['Aliases'] as $alias) {
            if ($alias['Name'] !== '') {
                $e->person->altNames[] = $alias['Name'];
            }
        }

        foreach ($p['Addresses'] as $addr) {
            $e->addresses[] = new Address(city: $addr['City'], country: $addr['Country']);
        }
        foreach ($p['Addresses'] as $addr) {
            if ($addr['Note'] !== '') {
                self::addContact($e, $addr['Note']);
            }
        }
        if ($p['Comments'] !== '') {
            self::addContact($e, $p['Comments']);
        }

        foreach ($p['BirthDates'] as $bd) {
            if ($bd['Date'] !== '' && ($t = GoTime::parse('2006-01-02', $bd['Date'])) !== null) {
                $e->person->birthDate = $t;
                break;
            }
            if ($bd['Year'] !== '' && ($y = CslEuRecord::atoi($bd['Year'])) !== null) {
                $e->person->birthDate = GoTime::date($y, 1, 1);
                break;
            }
        }

        foreach ($p['BirthPlaces'] as $bp) {
            $parts = array_values(array_filter([$bp['City'], $bp['State'], $bp['Country']], static fn ($v) => $v !== ''));
            if ($parts !== []) {
                $e->person->placeOfBirth = implode(', ', $parts);
                break;
            }
        }

        foreach ($p['Nationalities'] as $n) {
            if ($n['Text'] !== '') {
                $e->person->titles[] = $n['Text'];
            }
        }

        return $e->normalize();
    }

    /** UNEntity.ToEntity @param array<string,mixed> $u */
    public static function entityToEntity(array $u): Entity
    {
        $u = self::complete($u, false);
        $e = new Entity($u['FirstName'], EntityType::BUSINESS, SourceList::UN_CSL, 'UN-' . $u['DataID']);
        $e->sourceData = self::sourceData($u);
        $e->business = new Business($u['FirstName']);
        foreach ($u['Aliases'] as $alias) {
            if ($alias['Name'] !== '') {
                $e->business->altNames[] = $alias['Name'];
            }
        }
        foreach ($u['Addresses'] as $addr) {
            $e->addresses[] = new Address(city: $addr['City'], country: $addr['Country']);
            if ($addr['Note'] !== '') {
                self::addContact($e, $addr['Note']);
            }
        }
        if ($u['Comments'] !== '') {
            self::addContact($e, $u['Comments']);
        }

        return $e->normalize();
    }
}
