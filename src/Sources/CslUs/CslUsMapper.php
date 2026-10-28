<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUs;

use Watchman\Norm\Country;
use Watchman\Search\Address;
use Watchman\Search\Affiliation;
use Watchman\Search\Aircraft;
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

/** Port of pkg/sources/csl_us/mapper.go */
final class CslUsMapper
{
    private const COMPANY_NEEDLES = [
        'academy', 'aviation', 'bank', 'business', 'co.', 'commission', 'committee', 'company', 'corporation',
        'defense', 'electronics', 'equipment', 'export', 'group', 'guard', 'holding', 'import', 'industrial',
        'industries', 'industry', 'institute', 'intelligence', 'international', 'investment', 'lab', 'limited',
        'llc', 'logistics', 'ltd', 'ltd.', 'partnership', 'revolutionary', 'solutions', 'subsidiary', 'supply',
        'technology', 'trading', 'university',
    ];

    private const SUB_UNIT = '/^([^[;]+?)(?:[\t\n\f\r ]*\[([^\]]*)\])?(?:[\t\n\f\r ]*\(([^)]*)\))?(?:(?:[\t\n\f\r ]*[;,.][\t\n\f\r ]*|[\t\n\f\r ]+)(?:and[\t\n\f\r ]+subordinate[\t\n\f\r ]+entity|and[\t\n\f\r ]+its[\t\n\f\r ]+sub-units[\t\n\f\r ]+and[\t\n\f\r ]+successors|and[\t\n\f\r ]+any[\t\n\f\r ]+successor,[\t\n\f\r ]*sub-unit,[\t\n\f\r ]*or[\t\n\f\r ]*subsidiary[\t\n\f\r ]+thereof|Subordinate[\t\n\f\r ]+Institution[\t\n\f\r ]*-[\t\n\f\r ]+|Affiliated[\t\n\f\r ]+Entity[\t\n\f\r ]*:[\t\n\f\r ]+|including[\t\n\f\r ]+)(.*))?\z/s';

    /** @param array<string,string> $src */
    public static function toEntity(array $src): Entity
    {
        $e = new Entity('', '', SourceList::US_CSL, $src['ID']);
        $e->sourceData = $src;

        switch (Go::lower($src['Type'])) {
            case 'individual':
                $e->type = EntityType::PERSON;
                $e->person = self::person($src);
                break;
            case 'entity':
                if (str_contains(Go::lower($src['Source']), 'military-industrial') || str_contains(Go::lower($src['Name']), 'bank')) {
                    $e->type = EntityType::ORGANIZATION;
                    $e->organization = self::business($src, Organization::class);
                } else {
                    $e->type = EntityType::BUSINESS;
                    $e->business = self::business($src, Business::class);
                }
                break;
            case 'vessel':
                $e->type = EntityType::VESSEL;
                $e->vessel = self::vessel($src);
                break;
            case 'aircraft':
                $e->type = EntityType::AIRCRAFT;
                $e->aircraft = self::aircraft($src);
                break;
            default:
                $s = $src['Source'];
                $kind = null;
                if (str_contains($s, 'Entity List') || str_contains($s, 'ITAR Debarred') || str_contains($s, 'Military End User') || str_contains($s, 'Unverified List')) {
                    $kind = 'individual';
                    foreach (Go::fields(Go::lower($src['Name'])) as $tok) {
                        foreach (self::COMPANY_NEEDLES as $needle) {
                            if (Go::equalFold($tok, $needle)) {
                                $kind = 'business';
                                break 2;
                            }
                        }
                    }
                } elseif (self::isNonproliferation($s)) {
                    $kind = 'business';
                } elseif (str_contains($s, 'Denied Persons List')) {
                    $kind = 'individual';
                }
                if ($kind === 'individual') {
                    $e->type = EntityType::PERSON;
                    $e->person = self::person($src);
                } elseif ($kind === 'business') {
                    $e->type = EntityType::BUSINESS;
                    $e->business = self::business($src, Business::class);
                }
        }

        [$e->name] = self::splitNameIntoAlts($src['Name']);
        $e->contact = self::contact($src);
        $e->addresses = self::addresses($src);
        $e->affiliations = self::affiliations($src);
        $e->sanctionsInfo = self::sanctionsInfo($src);

        return $e->normalize();
    }

    private static function isNonproliferation(string $source): bool
    {
        if (str_contains($source, 'Nonproliferation Sanctions')) {
            return true;
        }

        return str_contains($source, 'State Department') && (str_contains($source, '(ACN)') || str_contains($source, '(ISN)'));
    }

    /** @return array{0:string,1:list<string>} */
    public static function splitNameIntoAlts(string $input): array
    {
        if (!preg_match(self::SUB_UNIT, $input, $m)) {
            return [Go::trimSpace($input), []];
        }
        $name = Go::trimSpace($m[1]);
        $alts = [];
        if (($m[2] ?? '') !== '') {
            $aliases = explode(', aka', $m[2]);
            if (count($aliases) === 1) {
                $aliases = explode(',aka', $m[2]);
            }
            foreach ($aliases as $alias) {
                $c = Go::trimSpace($alias);
                $c = Go::trimPrefix($c, 'aka');
                $c = Go::trimPrefix($c, 'and');
                $c = Go::trimSpace($c);
                if ($c !== '') {
                    $alts[] = $c;
                }
            }
        }
        if (($m[3] ?? '') !== '') {
            $alt = Go::trimSpace(Go::trimPrefix(Go::trimSpace($m[3]), 'and'));
            if ($alt !== '') {
                $alts[] = $alt;
            }
        }
        if (($m[4] ?? '') !== '') {
            $sub = Go::trimSpace(Go::trimPrefix(Go::trimSpace($m[4]), 'and'));
            if ($sub !== '') {
                $alts[] = $sub;
            }
        }

        return [$name, $alts];
    }

    /** @return list<string> */
    private static function allNames(array $src): array
    {
        if ($src['AltNames'] === '') {
            return [];
        }
        $out = [];
        foreach (explode(';', $src['AltNames']) as $n) {
            $n = Go::trimPrefix(Go::trimSpace($n), 'and ');
            if ($n !== '') {
                $out[] = $n;
            }
        }

        return $out;
    }

    private static function date(string $s): ?\DateTimeImmutable
    {
        return $s === '' ? null : GoTime::parse('2006-01-02', $s);
    }

    private static function person(array $src): Person
    {
        [$name, $alts] = self::splitNameIntoAlts($src['Name']);
        $p = new Person($name, array_merge($alts, self::allNames($src)));
        $gender = self::extractGender($src['IDs']);
        if ($gender !== '') {
            $p->gender = match (Go::lower($gender)) {
                'male', 'm' => Gender::MALE,
                'female', 'f' => Gender::FEMALE,
                default => Gender::UNKNOWN,
            };
        }
        if ($src['DatesOfBirth'] !== '') {
            $d = GoTime::parse('2006-01-02', $src['DatesOfBirth']);
            if ($d !== null) {
                $p->birthDate = $d;
            } elseif (($y = Go::atoi($src['DatesOfBirth'])) !== null) {
                $p->birthDate = GoTime::date($y, 1, 1);
            }
        }
        $p->placeOfBirth = $src['PlacesOfBirth'];
        $p->governmentIDs = self::govIds($src);
        if ($src['Title'] !== '') {
            $p->titles = [$src['Title']];
        }
        foreach (['Citizenships' => GovernmentIDType::CITIZENSHIP, 'Nationalities' => GovernmentIDType::NATIONALITY] as $field => $type) {
            if ($src[$field] === '') {
                continue;
            }
            foreach (explode(';', $src[$field]) as $v) {
                $v = Go::trimSpace($v);
                if ($v !== '') {
                    $p->governmentIDs[] = new GovernmentID($type, Country::normalize($v), $v);
                }
            }
        }

        return $p;
    }

    /**
     * @template T of Business
     * @param class-string<T> $class
     * @return T
     */
    private static function business(array $src, string $class): Business
    {
        [$name, $alts] = self::splitNameIntoAlts($src['Name']);
        $b = new $class($name, array_merge($alts, self::allNames($src)));
        $b->created = self::date($src['StartDate']);
        $b->dissolved = self::date($src['EndDate']);
        $b->governmentIDs = self::govIds($src);

        return $b;
    }

    private static function aircraft(array $src): Aircraft
    {
        [$name, $alts] = self::splitNameIntoAlts($src['Name']);

        return new Aircraft(
            name: $name,
            altNames: array_merge($alts, self::allNames($src)),
            type: Go::lower($src['VesselType']) === 'cargo' ? 'cargo' : 'unknown',
            flag: $src['VesselFlag'],
            built: self::date($src['StartDate']),
        );
    }

    private static function vessel(array $src): Vessel
    {
        [$name, $alts] = self::splitNameIntoAlts($src['Name']);

        return new Vessel(
            name: $name,
            altNames: array_merge($alts, self::allNames($src)),
            type: Go::lower($src['VesselType']) === 'cargo' ? 'cargo' : 'unknown',
            flag: $src['VesselFlag'],
            built: self::date($src['StartDate']),
            tonnage: Go::atoi($src['GrossTonnage']) ?? 0,
            callSign: $src['CallSign'],
            grossRegisteredTonnage: Go::atoi($src['GrossRegisteredTonnage']) ?? 0,
            owner: $src['VesselOwner'],
        );
    }

    private static function contact(array $src): ContactInfo
    {
        $c = new ContactInfo();
        if ($src['IDs'] === '') {
            return $c;
        }
        foreach (explode(';', $src['IDs']) as $id) {
            $id = Go::trimSpace($id);
            $l = Go::lower($id);
            if (str_starts_with($l, 'website')) {
                $c->websites[] = Go::trimPrefix($id, 'Website ');
            } elseif (str_starts_with($l, 'email address')) {
                $c->emailAddresses[] = Go::trimPrefix($id, 'Email Address ');
            } elseif (str_starts_with($l, 'telephone')) {
                $c->phoneNumbers[] = Go::trimPrefix($id, 'Telephone ');
            } elseif (str_starts_with($l, 'fax')) {
                $c->faxNumbers[] = Go::trimPrefix($id, 'Fax ');
            }
        }

        return $c;
    }

    /** @return list<Address> */
    private static function addresses(array $src): array
    {
        if ($src['Addresses'] === '') {
            return [];
        }
        $result = [];
        foreach (explode(';', $src['Addresses']) as $addr) {
            $addr = Go::trimSpace($addr);
            if ($addr === '') {
                continue;
            }
            $parts = explode(',', $addr);
            $n = count($parts);
            $a = new Address();
            foreach ($parts as $i => $part) {
                $part = Go::trimSpace($part);
                if ($part === '') {
                    continue;
                }
                if ($i === $n - 1) {
                    $a->country = Country::normalize($part);
                } elseif ($i === $n - 2) {
                    if (strlen($part) <= 10 && strpbrk($part, '0123456789') !== false) {
                        $a->postalCode = $part;
                    } else {
                        if ($a->city !== '' && $a->line1 === '') {
                            $a->line1 = $a->city;
                        }
                        $a->city = $part;
                    }
                } elseif ($i === $n - 3 && $a->postalCode === '') {
                    $a->city = $part;
                } else {
                    if ($a->line1 === '') {
                        $a->line1 = Go::trimSpace(Go::trimPrefix($part, 'and'));
                    } else {
                        $a->line2 .= $part . ' ';
                    }
                }
            }
            $a->line2 = Go::trimSpace($a->line2);
            if ($a->line1 !== '' && $a->country !== '') {
                $result[] = $a;
            }
        }

        return $result;
    }

    /** @return list<Affiliation> */
    private static function affiliations(array $src): array
    {
        if ($src['Remarks'] === '') {
            return [];
        }
        $out = [];
        foreach (explode(';', $src['Remarks']) as $remark) {
            $remark = Go::trimSpace($remark);
            $l = Go::lower($remark);
            if (str_contains($l, 'linked to') || str_contains($l, 'owned by') || str_contains($l, 'subsidiary of')) {
                $parts = explode(':', $remark, 2);
                if (count($parts) === 2) {
                    $out[] = new Affiliation('', Go::trimSpace($parts[0]), Go::trimSpace($parts[1]));
                }
            }
        }

        return $out;
    }

    private static function sanctionsInfo(array $src): SanctionsInfo
    {
        $info = new SanctionsInfo([], false, $src['Remarks']);
        if ($src['Programs'] !== '') {
            $info->programs = explode(';', $src['Programs']);
        }
        if (str_contains(Go::lower($src['IDs']), 'secondary sanctions') || str_contains(Go::lower($src['Remarks']), 'secondary sanctions')) {
            $info->secondary = true;
        }

        return $info;
    }

    /** @return list<GovernmentID> */
    private static function govIds(array $src): array
    {
        if ($src['IDs'] === '') {
            return [];
        }
        $ids = [];
        foreach (explode(';', $src['IDs']) as $entry) {
            $entry = Go::trimSpace($entry);
            if ($entry === '') {
                continue;
            }
            $parts = explode(',', $entry);
            if (count($parts) < 2) {
                continue;
            }
            $idType = Go::trimSpace($parts[0]);
            $country = count($parts) > 2 ? Country::normalize(Go::trimSpace($parts[2])) : '';
            $type = self::idType($idType);
            if ($type !== '') {
                $ids[] = new GovernmentID($type, $country, Go::trimSpace($parts[1]), $idType);
            }
        }

        return $ids;
    }

    private static function idType(string $t): string
    {
        return match (Go::lower($t)) {
            'passport' => GovernmentIDType::PASSPORT,
            "driver's license", 'drivers license' => GovernmentIDType::DRIVERS_LICENSE,
            'national id', 'national identification number' => GovernmentIDType::NATIONAL,
            'tax id', 'tax identification number' => GovernmentIDType::TAX,
            'ssn', 'social security number' => GovernmentIDType::SSN,
            'cedula' => GovernmentIDType::CEDULA,
            'curp' => GovernmentIDType::CURP,
            'cuit' => GovernmentIDType::CUIT,
            'electoral registry' => GovernmentIDType::ELECTORAL,
            'business registration', 'business registration document', 'business registration number' => GovernmentIDType::BUSINESS_REGISTRATION,
            'commercial registry' => GovernmentIDType::COMMERCIAL_REGISTRY,
            'birth certificate' => GovernmentIDType::BIRTH_CERT,
            'refugee id' => GovernmentIDType::REFUGEE,
            'diplomatic passport' => GovernmentIDType::DIPLOMATIC_PASS,
            'personal id' => GovernmentIDType::PERSONAL_ID,
            default => '',
        };
    }

    private static function extractGender(string $ids): string
    {
        foreach (explode(';', $ids) as $entry) {
            $entry = Go::trimSpace($entry);
            if (str_starts_with(Go::lower($entry), 'gender')) {
                $parts = explode(',', $entry, 2);
                if (count($parts) === 2) {
                    return Go::trimSpace($parts[1]);
                }
            }
        }

        return '';
    }
}
