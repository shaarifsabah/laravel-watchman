<?php

declare(strict_types=1);

namespace Watchman\Search;

/** Port of pkg/search/similarity_recast.go */
final class Recast
{
    private static function personOrOrg(string $t): bool
    {
        return in_array($t, [EntityType::PERSON, EntityType::BUSINESS, EntityType::ORGANIZATION, EntityType::UNKNOWN, ''], true);
    }

    /** Returns null when the query cannot be compared against $type. */
    public static function toType(Entity $e, string $type): ?Entity
    {
        if ($e->type === $type || $e->type === '' || $e->type === EntityType::UNKNOWN) {
            return $e;
        }
        if (!self::personOrOrg($e->type) || !self::personOrOrg($type)) {
            return null;
        }
        $name = $e->name;
        $alts = [];
        $ids = [];
        if ($e->person !== null) {
            $name = $name !== '' ? $name : $e->person->name;
            $alts = $e->person->altNames;
            $ids = $e->person->governmentIDs;
        } elseif ($e->business !== null) {
            $name = $name !== '' ? $name : $e->business->name;
            $alts = $e->business->altNames;
            $ids = $e->business->governmentIDs;
        } elseif ($e->organization !== null) {
            $name = $name !== '' ? $name : $e->organization->name;
            $alts = $e->organization->altNames;
            $ids = $e->organization->governmentIDs;
        }
        $out = clone $e;
        $out->type = $type;
        switch ($type) {
            case EntityType::PERSON:
                $p = new Person($name, $alts, governmentIDs: $ids);
                if ($e->person !== null) {
                    if ($p->name === '') {
                        $p->name = $e->person->name;
                    }
                    $p->birthDate = $e->person->birthDate;
                    $p->deathDate = $e->person->deathDate;
                    $p->gender = $e->person->gender;
                    $p->placeOfBirth = $e->person->placeOfBirth;
                    $p->titles = $e->person->titles;
                    if ($p->governmentIDs === []) {
                        $p->governmentIDs = $e->person->governmentIDs;
                    }
                }
                $out->person = $p;
                break;
            case EntityType::BUSINESS:
                $out->business = new Business($name, $alts, governmentIDs: $ids);
                break;
            case EntityType::ORGANIZATION:
                $out->organization = new Organization($name, $alts, governmentIDs: $ids);
                break;
            default:
                return null;
        }

        return $out;
    }
}
