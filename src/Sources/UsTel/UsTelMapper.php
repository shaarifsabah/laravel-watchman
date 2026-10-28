<?php

declare(strict_types=1);

namespace Watchman\Sources\UsTel;

use Watchman\Search\Business;
use Watchman\Search\Entity;
use Watchman\Search\EntityType;
use Watchman\Search\Person;
use Watchman\Search\SourceList;
use Watchman\Support\Go;

/** Port of pkg/sources/us_tel/mapper.go */
final class UsTelMapper
{
    /** TELRecord.ToEntity @param array<string,mixed> $r */
    public static function toEntity(array $r): Entity
    {
        $r = self::complete($r);
        $name = self::primaryName($r);
        $aliases = self::altNames($r, $name);

        $e = new Entity($name, self::entityTypeForSchema($r['schema']), SourceList::US_TEL, $r['id']);
        $e->sourceData = $r;

        if ($e->type === EntityType::PERSON) {
            $e->person = new Person($name, $aliases);
        }
        if ($e->type === EntityType::BUSINESS) {
            $e->business = new Business($name, $aliases);
        }

        return $e->normalize();
    }

    public static function primaryName(array $r): string
    {
        foreach ($r['properties']['name'] ?? [] as $name) {
            $name = Go::trimSpace($name);
            if ($name !== '') {
                return $name;
            }
        }

        return Go::trimSpace($r['caption'] ?? '');
    }

    /** @return list<string> */
    public static function altNames(array $r, string $primary): array
    {
        $seen = [];
        $alts = [];
        $primary = Go::trimSpace($primary);
        if ($primary !== '') {
            $seen[Go::lower($primary)] = true;
        }
        foreach ($r['properties']['name'] ?? [] as $name) {
            $name = Go::trimSpace($name);
            if ($name === '') {
                continue;
            }
            $lower = Go::lower($name);
            if (isset($seen[$lower])) {
                continue;
            }
            $alts[] = $name;
            $seen[$lower] = true;
        }

        return $alts;
    }

    public static function entityTypeForSchema(string $schema): string
    {
        return Go::lower(Go::trimSpace($schema)) === 'person' ? EntityType::PERSON : EntityType::BUSINESS;
    }

    /** Fill zero values (TELRecord field order) for partially specified records. */
    private static function complete(array $r): array
    {
        $out = UsTelReader::emptyRecord();
        foreach ($r as $k => $v) {
            if ($k === 'properties' && is_array($v)) {
                $out['properties'] = array_merge($out['properties'], $v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
