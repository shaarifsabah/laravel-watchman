<?php

declare(strict_types=1);

namespace Watchman\Sources\Senzing;

use InvalidArgumentException;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\Gender;
use Watchman\Search\GovernmentIDType;
use Watchman\Support\Go;

/**
 * Port of pkg/sources/senzing/writer.go: export entities as Senzing records.
 */
final class SenzingWriter
{
    private const ORDER = [
        'DATA_SOURCE', 'RECORD_ID', 'RECORD_TYPE', 'NAME_FIRST', 'NAME_MIDDLE', 'NAME_LAST', 'NAME_FULL', 'NAME_ORG',
        'ADDR_LINE1', 'ADDR_LINE2', 'ADDR_CITY', 'ADDR_STATE', 'ADDR_POSTAL_CODE', 'ADDR_COUNTRY',
        'SSN', 'PASSPORT_NUMBER', 'PASSPORT_COUNTRY', 'TAX_ID_NUMBER', 'TAX_ID_COUNTRY', 'NATIONAL_ID_NUMBER',
        'NATIONAL_ID_COUNTRY', 'DRIVERS_LICENSE_NUMBER', 'DRIVERS_LICENSE_STATE', 'PHONE_NUMBER', 'EMAIL_ADDRESS',
        'WEBSITE_ADDRESS', 'DATE_OF_BIRTH', 'DATE_OF_DEATH', 'GENDER',
    ];

    /** @param list<Entity> $entities */
    public static function write(array $entities, string $format = '', string $dataSource = ''): string
    {
        $records = array_map(static fn (Entity $e) => self::fromEntity($e, $dataSource), $entities);
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        return match (strtolower($format)) {
            'jsonl', 'json-lines', 'ndjson' => implode('', array_map(static fn ($r) => json_encode($r, $flags) . "\n", $records)),
            'json', 'array', '' => json_encode($records, $flags) . "\n",
            default => throw new InvalidArgumentException("unknown senzing export format: $format"),
        };
    }

    public static function fromEntity(Entity $e, string $dataSource = ''): array
    {
        $r = ['DATA_SOURCE' => Go::or($e->source, $dataSource), 'RECORD_ID' => $e->sourceId];
        $ids = [];
        switch ($e->type) {
            case EntityType::PERSON:
                $r['RECORD_TYPE'] = 'PERSON';
                if ($e->person !== null) {
                    $p = $e->person;
                    $r['NAME_FULL'] = $p->name;
                    $parts = Go::fields($p->name);
                    $n = count($parts);
                    if ($n === 1) {
                        $r['NAME_LAST'] = $parts[0];
                    } elseif ($n === 2) {
                        [$r['NAME_FIRST'], $r['NAME_LAST']] = $parts;
                    } elseif ($n >= 3) {
                        $r['NAME_FIRST'] = $parts[0];
                        $r['NAME_MIDDLE'] = implode(' ', array_slice($parts, 1, -1));
                        $r['NAME_LAST'] = $parts[$n - 1];
                    }
                    $r['GENDER'] = match ($p->gender) {
                        Gender::MALE => 'M',
                        Gender::FEMALE => 'F',
                        default => '',
                    };
                    if ($p->birthDate !== null) {
                        $r['DATE_OF_BIRTH'] = $p->birthDate->format('Y-m-d');
                    }
                    if ($p->deathDate !== null) {
                        $r['DATE_OF_DEATH'] = $p->deathDate->format('Y-m-d');
                    }
                    $ids = $p->governmentIDs;
                } else {
                    $r['NAME_FULL'] = $e->name;
                }
                break;
            case EntityType::BUSINESS:
            case EntityType::ORGANIZATION:
                $r['RECORD_TYPE'] = 'ORGANIZATION';
                $r['NAME_ORG'] = $e->name;
                $ids = ($e->type === EntityType::BUSINESS ? $e->business : $e->organization)?->governmentIDs ?? [];
                break;
            default:
                $r['RECORD_TYPE'] = 'ORGANIZATION';
                $r['NAME_ORG'] = $e->name;
        }
        foreach ($ids as $id) {
            switch ($id->type) {
                case GovernmentIDType::SSN:
                    $r['SSN'] = $id->identifier;
                    break;
                case GovernmentIDType::PASSPORT:
                    $r['PASSPORT_NUMBER'] = $id->identifier;
                    $r['PASSPORT_COUNTRY'] = $id->country;
                    break;
                case GovernmentIDType::TAX:
                    $r['TAX_ID_NUMBER'] = $id->identifier;
                    $r['TAX_ID_COUNTRY'] = $id->country;
                    break;
                case GovernmentIDType::NATIONAL:
                    $r['NATIONAL_ID_NUMBER'] = $id->identifier;
                    $r['NATIONAL_ID_COUNTRY'] = $id->country;
                    break;
                case GovernmentIDType::DRIVERS_LICENSE:
                    $r['DRIVERS_LICENSE_NUMBER'] = $id->identifier;
                    $r['DRIVERS_LICENSE_STATE'] = $id->country;
                    break;
            }
        }
        if ($e->addresses !== []) {
            $a = $e->addresses[0];
            $r += ['ADDR_LINE1' => $a->line1, 'ADDR_LINE2' => $a->line2, 'ADDR_CITY' => $a->city, 'ADDR_STATE' => $a->state,
                'ADDR_POSTAL_CODE' => $a->postalCode, 'ADDR_COUNTRY' => $a->country];
        }
        if ($e->contact->phoneNumbers !== []) {
            $r['PHONE_NUMBER'] = $e->contact->phoneNumbers[0];
        }
        if ($e->contact->emailAddresses !== []) {
            $r['EMAIL_ADDRESS'] = $e->contact->emailAddresses[0];
        }
        if ($e->contact->websites !== []) {
            $r['WEBSITE_ADDRESS'] = $e->contact->websites[0];
        }

        $out = [];
        foreach (self::ORDER as $k) {
            if (isset($r[$k]) && ($r[$k] !== '' || $k === 'DATA_SOURCE' || $k === 'RECORD_ID')) {
                $out[$k] = $r[$k];
            }
        }

        return $out;
    }
}
