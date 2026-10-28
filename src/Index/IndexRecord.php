<?php

declare(strict_types=1);

namespace Watchman\Index;

use Watchman\Search\Entity;
use Watchman\Support\Go;

/**
 * Everything a store needs to index one entity (postings + identifier values).
 */
final class IndexRecord
{
    /** @var list<string> */
    public array $tokens = [];
    /** @var list<string> */
    public array $crypto = [];
    /** @var list<string> */
    public array $block = [];
    /** @var list<string> */
    public array $domains = [];
    /** @var array<string, list<string>> kind => normalized values */
    public array $ids = [];
    public string $name = '';

    public static function cryptoKey(string $currency, string $address): string
    {
        $currency = Go::upper(Go::trimSpace($currency));
        $address = Go::trimSpace($address);

        return ($currency === '' || $address === '') ? '' : $currency . ':' . $address;
    }

    public static function fromEntity(Entity $e): self
    {
        $r = new self();
        $pf = $e->preparedFields;
        $r->name = $pf->name;

        $seen = [];
        $add = static function (array $tokens) use (&$seen, $r): void {
            foreach ($tokens as $t) {
                if ($t === '' || isset($seen[$t])) {
                    continue;
                }
                $seen[$t] = true;
                $r->tokens[] = (string) $t;
            }
        };
        $add($pf->nameFields);
        foreach ($pf->altNameFields as $a) {
            $add($a);
        }
        foreach ($pf->historicalNameFields as $h) {
            $add($h);
        }

        foreach ($e->cryptoAddresses as $c) {
            if (($k = self::cryptoKey($c->currency, $c->address)) !== '') {
                $r->crypto[] = $k;
            }
        }
        $r->crypto = array_values(array_unique($r->crypto));

        $block = [];
        foreach (Linksim::keys($e) as $key) {
            foreach (Linksim::prefixes($key) as $p) {
                $block[$p] = true;
            }
        }
        $r->block = array_map('strval', array_keys($block));

        $ids = ['imo' => [], 'mmsi' => [], 'air' => [], 'email' => [], 'phone' => [], 'website' => []];
        if ($e->vessel !== null) {
            $ids['imo'][] = Qwerty::normalizeId($e->vessel->imoNumber);
            $ids['mmsi'][] = Qwerty::normalizeId($e->vessel->mmsi);
        }
        if ($e->aircraft !== null) {
            $ids['air'][] = Qwerty::normalizeId($e->aircraft->serialNumber);
        }
        foreach ($e->contact->emailAddresses as $v) {
            $ids['email'][] = Qwerty::normalizeId($v);
        }
        foreach ($pf->contact->phoneNumbers !== [] ? $pf->contact->phoneNumbers : $e->contact->phoneNumbers as $v) {
            $ids['phone'][] = Qwerty::normalizeId($v);
        }
        foreach ($pf->contact->websites !== [] ? $pf->contact->websites : $e->contact->websites as $v) {
            $ids['website'][] = Qwerty::normalizeId($v);
        }
        foreach ($ids as $k => $vals) {
            $vals = array_values(array_unique(array_filter($vals, static fn ($v) => $v !== '')));
            if ($vals !== []) {
                $r->ids[$k] = $vals;
            }
        }
        $r->domains = array_values(array_unique(array_filter($pf->domains, static fn ($d) => $d !== '')));

        return $r;
    }
}
