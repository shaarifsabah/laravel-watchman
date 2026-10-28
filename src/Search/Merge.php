<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\Support\Go;

/**
 * Port of pkg/search/merge.go: combines rows that describe the same entity
 * (same source / sourceID / type), as used by file ingestion.
 */
final class Merge
{
    /**
     * @param list<Entity> $entities
     * @return list<Entity>
     */
    public static function entities(array $entities): array
    {
        $grouped = [];
        foreach ($entities as $e) {
            $grouped[Go::lower(sprintf('%s/%s/%s', $e->source, $e->sourceId, $e->type))][] = $e;
        }
        $out = [];
        foreach ($grouped as $values) {
            $acc = new Entity();
            foreach ($values as $v) {
                $acc = self::merge($acc, $v);
            }
            $out[] = $acc->normalize();
        }

        return $out;
    }

    private static function orDate(?\DateTimeImmutable ...$v): ?\DateTimeImmutable
    {
        foreach ($v as $d) {
            if ($d !== null) {
                return $d;
            }
        }

        return null;
    }

    private static function merge(Entity $e, Entity $other): Entity
    {
        if ($other->name === '') {
            return $e;
        }
        $out = new Entity();
        $alt = ($e->name !== '' && $other->name !== '') ? [$other->name] : [];
        $out->name = Go::or($e->name, $other->name);
        $out->type = Go::or($e->type, $other->type);
        $out->source = Go::or($e->source, $other->source);
        $out->sourceId = Go::or($e->sourceId, $other->sourceId);

        if ($other->person !== null) {
            $ep = $e->person ?? new Person();
            $op = $other->person;
            $out->person = new Person(
                Go::or($ep->name, $op->name, $e->name),
                self::strings($alt, $ep->altNames, $op->altNames),
                Go::or($ep->gender, $op->gender),
                self::orDate($ep->birthDate, $op->birthDate),
                Go::or($ep->placeOfBirth, $op->placeOfBirth),
                self::orDate($ep->deathDate, $op->deathDate),
                self::strings($ep->titles, $op->titles),
                self::govIds($ep->governmentIDs, $op->governmentIDs),
            );
        } elseif ($other->business !== null || $other->organization !== null) {
            $isOrg = $other->business === null;
            $eb = ($isOrg ? $e->organization : $e->business) ?? new Business();
            $ob = $isOrg ? $other->organization : $other->business;
            $class = $isOrg ? Organization::class : Business::class;
            $rec = new $class(
                Go::or($eb->name, $ob->name, $e->name),
                self::strings($alt, $eb->altNames, $ob->altNames),
                self::orDate($eb->created, $ob->created),
                self::orDate($eb->dissolved, $ob->dissolved),
                self::govIds($eb->governmentIDs, $ob->governmentIDs),
            );
            if ($isOrg) {
                $out->organization = $rec;
            } else {
                $out->business = $rec;
            }
        } elseif ($other->aircraft !== null) {
            $ea = $e->aircraft ?? new Aircraft();
            $oa = $other->aircraft;
            $out->aircraft = new Aircraft(
                Go::or($ea->name, $oa->name, $e->name), self::strings($alt, $ea->altNames, $oa->altNames),
                Go::or($ea->type, $oa->type), Go::or($ea->flag, $oa->flag), self::orDate($ea->built, $oa->built),
                Go::or($ea->icaoCode, $oa->icaoCode), Go::or($ea->model, $oa->model), Go::or($ea->serialNumber, $oa->serialNumber),
            );
        } elseif ($other->vessel !== null) {
            $ev = $e->vessel ?? new Vessel();
            $ov = $other->vessel;
            $out->vessel = new Vessel(
                Go::or($ev->name, $ov->name, $e->name), self::strings($alt, $ev->altNames, $ov->altNames),
                Go::or($ev->imoNumber, $ov->imoNumber), Go::or($ev->type, $ov->type), Go::or($ev->flag, $ov->flag),
                self::orDate($ev->built, $ov->built), Go::or($ev->model, $ov->model), $ev->tonnage ?: $ov->tonnage,
                Go::or($ev->mmsi, $ov->mmsi), Go::or($ev->callSign, $ov->callSign),
                $ev->grossRegisteredTonnage ?: $ov->grossRegisteredTonnage, Go::or($ev->owner, $ov->owner),
            );
        }

        $out->contact = new ContactInfo(
            self::strings($e->contact->emailAddresses, $other->contact->emailAddresses),
            self::strings($e->contact->phoneNumbers, $other->contact->phoneNumbers),
            self::strings($e->contact->faxNumbers, $other->contact->faxNumbers),
            self::strings($e->contact->websites, $other->contact->websites),
            self::strings($e->contact->domains, $other->contact->domains),
        );
        $out->addresses = self::unique(array_merge($e->addresses, $other->addresses), static fn (Address $a) => Go::lower($a->line1 . '/' . $a->line2), static function (Address $a1, Address $a2): void {
            $a1->line1 = Go::or($a1->line1, $a2->line1);
            $a1->line2 = Go::or($a1->line2, $a2->line2);
            $a1->city = Go::or($a1->city, $a2->city);
            $a1->postalCode = Go::or($a1->postalCode, $a2->postalCode);
            $a1->state = Go::or($a1->state, $a2->state);
            $a1->country = Go::or($a1->country, $a2->country);
        });
        $out->cryptoAddresses = self::unique(array_merge($e->cryptoAddresses, $other->cryptoAddresses), static fn (CryptoAddress $c) => Go::lower($c->currency . '/' . $c->address));
        $out->affiliations = self::unique(array_merge($e->affiliations, $other->affiliations), static fn (Affiliation $a) => Go::lower($a->entityName . '/' . $a->type));
        $out->historicalInfo = self::unique(array_merge($e->historicalInfo, $other->historicalInfo), static fn (HistoricalInfo $h) => Go::lower($h->type . '/' . $h->value));
        if ($e->sanctionsInfo !== null || $other->sanctionsInfo !== null) {
            $a = $e->sanctionsInfo ?? new SanctionsInfo();
            $b = $other->sanctionsInfo ?? new SanctionsInfo();
            $out->sanctionsInfo = new SanctionsInfo(self::strings($a->programs, $b->programs), $a->secondary || $b->secondary, Go::or($a->description, $b->description));
        }
        $out->sourceData = $other->sourceData;

        return $out;
    }

    /** merge.Slices semantics: unique by lowercase key, sorted by key, first value wins. */
    private static function strings(array ...$lists): array
    {
        return self::unique(array_merge(...$lists), static fn (string $s) => Go::lower($s));
    }

    private static function govIds(array $a, array $b): array
    {
        return self::unique(array_merge($a, $b), static fn (GovernmentID $id) => Go::lower($id->country . '/' . $id->type . '/' . $id->identifier));
    }

    private static function unique(array $items, callable $key, ?callable $combine = null): array
    {
        $map = [];
        foreach ($items as $item) {
            $k = (string) $key($item);
            if (isset($map[$k])) {
                if ($combine !== null) {
                    $combine($map[$k], $item);
                }
                continue;
            }
            $map[$k] = is_object($item) ? clone $item : $item;
        }
        ksort($map, SORT_STRING);

        return array_values($map);
    }
}
