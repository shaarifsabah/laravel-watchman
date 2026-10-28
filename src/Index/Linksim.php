<?php

declare(strict_types=1);

namespace Watchman\Index;

use Watchman\Norm\Country;
use Watchman\Search\Entity;
use Watchman\Support\Go;

/**
 * Port of the parts of internal/linksim used for candidate blocking:
 * hashed government-ID keys (GOVID) and address prefix keys (ADDR).
 */
final class Linksim
{
    public const KIND_GOVID = 'GOVID';
    public const KIND_ADDR = 'ADDR';

    public static function digest(string $field, string $value): string
    {
        $value = Go::trimSpace($value);
        if ($value === '') {
            return '';
        }

        return substr(hash('sha256', 'watchman/linksim/v1/' . $field . "\x00" . $value), 0, 8);
    }

    /** @return list<string> GOVID and ADDR keys for the entity (sorted, unique) */
    public static function keys(Entity $e): array
    {
        $out = [];
        $ids = [];
        foreach ([$e->person, $e->business, $e->organization] as $rec) {
            if ($rec !== null) {
                array_push($ids, ...$rec->governmentIDs);
            }
        }
        foreach ($ids as $id) {
            $ident = self::normalizeIdentifier($id->identifier);
            if ($ident === '') {
                continue;
            }
            $segs = [];
            if (($c = self::normalizeCountry($id->country)) !== '' && ($d = self::digest('gov-country', $c)) !== '') {
                $segs[] = 'C' . $d;
            }
            if (($t = Go::lower(Go::trimSpace($id->type))) !== '' && ($d = self::digest('gov-type', $t)) !== '') {
                $segs[] = 'T' . $d;
            }
            if (($d = self::digest('gov-id', $ident)) !== '') {
                $segs[] = 'X' . $d;
            }
            if ($segs !== []) {
                $out[] = self::KIND_GOVID . ':' . implode('|', $segs);
            }
        }

        if ($e->preparedFields->addresses !== []) {
            foreach ($e->preparedFields->addresses as $a) {
                if (($k = self::addressKey($a->country, $a->state, $a->postalCode, $a->city, $a->line1, $a->line2)) !== '') {
                    $out[] = $k;
                }
            }
        } else {
            foreach ($e->addresses as $a) {
                if (($k = self::addressKey($a->country, $a->state, $a->postalCode, $a->city, $a->line1, $a->line2)) !== '') {
                    $out[] = $k;
                }
            }
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);

        return $out;
    }

    /** @return list<string> */
    public static function prefixes(string $key): array
    {
        $pos = strpos($key, ':');
        if ($pos === false || $pos === strlen($key) - 1) {
            return [];
        }
        $kind = substr($key, 0, $pos);
        $parts = explode('|', substr($key, $pos + 1));
        $out = [];
        $prefix = $kind . ':';
        foreach ($parts as $i => $p) {
            $prefix .= ($i === 0 ? '' : '|') . $p;
            $out[] = $prefix;
        }

        return $out;
    }

    private static function addressKey(string $country, string $state, string $postal, string $city, string $line1, string $line2): string
    {
        $segs = [];
        $parts = [
            ['C', 'addr-country', self::normalizeCountry($country)],
            ['S', 'addr-state', Go::lower(Go::trimSpace($state))],
            ['P', 'addr-postal', str_replace([' ', '-'], '', Go::lower(Go::trimSpace($postal)))],
            ['Y', 'addr-city', Go::lower(Go::trimSpace($city))],
            ['L', 'addr-line1', Go::lower(Go::trimSpace($line1))],
            ['E', 'addr-line2', Go::lower(Go::trimSpace($line2))],
        ];
        foreach ($parts as [$tag, $field, $value]) {
            if (($d = self::digest($field, $value)) !== '') {
                $segs[] = $tag . $d;
            }
        }

        return $segs === [] ? '' : self::KIND_ADDR . ':' . implode('|', $segs);
    }

    private static function normalizeCountry(string $s): string
    {
        $s = Go::trimSpace($s);

        return $s === '' ? '' : Go::lower(Country::normalize($s));
    }

    private static function normalizeIdentifier(string $s): string
    {
        return str_replace([' ', '-'], '', Go::lower(Go::trimSpace($s)));
    }
}
