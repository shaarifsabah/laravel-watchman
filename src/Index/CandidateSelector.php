<?php

declare(strict_types=1);

namespace Watchman\Index;

use Watchman\Search\Entity;
use Watchman\Search\SourceList;

/**
 * Port of internal/index corpus.selectCandidates: decides which entities are
 * scored for a query (partitioning, identifier fast paths, name-token postings,
 * address blocking), never reducing recall below what Watchman does.
 */
final class CandidateSelector
{
    private const DISTINCTIVE_MAX_DF = 0.20;
    private const OPTIONAL_DF_RATIO = 2;

    private const MIN_PREFIX = ['imo' => 4, 'mmsi' => 4, 'air' => 4, 'email' => 3, 'phone' => 4, 'website' => 3];

    public static function select(Corpus $c, Entity $query, float $maxFraction = 0.5): Candidates
    {
        if ($maxFraction <= 0 || $maxFraction > 1) {
            $maxFraction = 0.5;
        }
        $src = SourceList::isRequestType($query->source) ? '' : $query->source;
        $type = $query->type;
        $empty = new Candidates($c, [], $src, $type, 0);

        if ($src !== '' && !array_key_exists($src, $c->sources())) {
            return $empty; // unknown source: do not scan unrelated lists
        }
        $partN = $c->partitionCount($src, $type);
        if ($partN === 0) {
            return $empty;
        }
        $full = new Candidates($c, null, $src, $type, $partN);
        $maxCount = max(1, (int) ($partN * $maxFraction));

        $idHits = self::identifierHits($c, $query, $src, $type);
        if ($idHits !== []) {
            if ($query->preparedFields->nameFields !== []) {
                $names = self::nameCandidates($c, $query, $src, $type, $partN, $maxCount);
                if ($names === null) {
                    return $full;
                }
                $idHits = self::union([$idHits, $names]);
            }

            return new Candidates($c, $idHits, $src, $type, count($idHits));
        }

        if ($query->preparedFields->nameFields !== []) {
            $names = self::nameCandidates($c, $query, $src, $type, $partN, $maxCount);

            return $names === null ? $full : new Candidates($c, $names, $src, $type, count($names));
        }

        $addr = self::addressHits($c, $query, $src, $type, $maxCount);
        if ($addr !== []) {
            return new Candidates($c, $addr, $src, $type, count($addr));
        }

        if ($query->preparedFields->name !== '') {
            $exact = $c->postings('name', [$query->preparedFields->name], $src, $type)[$query->preparedFields->name] ?? [];
            if ($exact !== []) {
                return new Candidates($c, $exact, $src, $type, count($exact));
            }
        }

        return $full;
    }

    /** @return list<int> */
    private static function identifierHits(Corpus $c, Entity $q, string $src, string $type): array
    {
        $lists = [];
        $crypto = [];
        foreach ($q->cryptoAddresses as $a) {
            if (($k = IndexRecord::cryptoKey($a->currency, $a->address)) !== '') {
                $crypto[] = $k;
            }
        }
        if ($crypto !== []) {
            $lists = array_merge($lists, array_values($c->postings('crypto', array_values(array_unique($crypto)), $src, $type)));
        }
        $gov = array_values(array_filter(Linksim::keys($q), static fn ($k) => str_starts_with($k, Linksim::KIND_GOVID . ':')));
        if ($gov !== []) {
            $lists = array_merge($lists, array_values($c->postings('block', $gov, $src, $type)));
        }

        $values = ['imo' => [], 'mmsi' => [], 'air' => [], 'email' => [], 'phone' => [], 'website' => []];
        if ($q->vessel !== null) {
            $values['imo'][] = $q->vessel->imoNumber;
            $values['mmsi'][] = $q->vessel->mmsi;
        }
        if ($q->aircraft !== null) {
            $values['air'][] = $q->aircraft->serialNumber;
        }
        $values['email'] = $q->contact->emailAddresses;
        $values['phone'] = $q->preparedFields->contact->phoneNumbers !== [] ? $q->preparedFields->contact->phoneNumbers : $q->contact->phoneNumbers;
        $values['website'] = $q->preparedFields->contact->websites !== [] ? $q->preparedFields->contact->websites : $q->contact->websites;
        foreach ($values as $kind => $raws) {
            $exact = [];
            $prefix = [];
            foreach ($raws as $raw) {
                $norm = Qwerty::normalizeId($raw);
                if ($norm === '') {
                    continue;
                }
                foreach (Qwerty::variants($norm) as $v) {
                    $exact[] = $v;
                    if (strlen($v) >= self::MIN_PREFIX[$kind]) {
                        $prefix[] = $v;
                    }
                }
            }
            if ($exact !== []) {
                $lists[] = $c->idLookup($kind, array_values(array_unique($exact)), array_values(array_unique($prefix)), $src, $type);
            }
        }
        if ($q->preparedFields->domains !== []) {
            $lists = array_merge($lists, array_values($c->postings('domain', $q->preparedFields->domains, $src, $type)));
        }

        return self::union($lists);
    }

    /**
     * @return list<int>|null null = use the full partition
     */
    private static function nameCandidates(Corpus $c, Entity $q, string $src, string $type, int $partN, int $maxCount): ?array
    {
        $tokens = $q->preparedFields->nameFields;
        if ($tokens === []) {
            return null;
        }
        $postings = $c->postings('tok', array_values(array_unique($tokens)), $src, $type);
        $hitting = [];
        foreach ($tokens as $tok) {
            $hits = $postings[$tok] ?? [];
            if ($hits !== []) {
                $hitting[] = $hits;
            }
        }
        if ($hitting === []) {
            return null; // pure typos: preserve recall
        }

        usort($hitting, static fn ($a, $b) => count($a) <=> count($b));
        $n = count($hitting);
        if ($n >= 2 && count($hitting[$n - 1]) >= self::OPTIONAL_DF_RATIO * count($hitting[$n - 2])) {
            array_pop($hitting);
        }
        $distinctive = array_values(array_filter($hitting, static fn ($h) => count($h) / max(1, $partN) <= self::DISTINCTIVE_MAX_DF));
        $lists = $distinctive !== [] ? $distinctive : $hitting;

        usort($lists, static fn ($a, $b) => count($a) <=> count($b));
        $candidates = $lists[0];
        for ($i = 1, $m = count($lists); $i < $m; $i++) {
            $candidates = self::intersect($candidates, $lists[$i]);
            if ($candidates === []) {
                break;
            }
        }
        if ($candidates === []) {
            $candidates = self::union($lists);
        }
        if (count($candidates) > $maxCount) {
            return null;
        }

        return $candidates;
    }

    /** @return list<int> */
    private static function addressHits(Corpus $c, Entity $q, string $src, string $type, int $maxCount): array
    {
        $all = [];
        $found = false;
        foreach (Linksim::keys($q) as $key) {
            if (!str_starts_with($key, Linksim::KIND_ADDR . ':')) {
                continue;
            }
            $prefixes = Linksim::prefixes($key);
            $postings = $c->postings('block', $prefixes, $src, $type);
            for ($i = count($prefixes) - 1; $i >= 0; $i--) {
                $hits = $postings[$prefixes[$i]] ?? [];
                if ($hits === []) {
                    continue;
                }
                if (count($hits) > $maxCount) {
                    break;
                }
                $all[] = $hits;
                $found = true;
                break;
            }
        }

        return $found ? self::union($all) : [];
    }

    /** @param list<int> $a @param list<int> $b @return list<int> */
    public static function intersect(array $a, array $b): array
    {
        if ($a === [] || $b === []) {
            return [];
        }
        if (count($a) > count($b)) {
            [$a, $b] = [$b, $a];
        }
        $set = array_flip($b);
        $out = [];
        foreach ($a as $v) {
            if (isset($set[$v])) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** @param list<list<int>> $lists @return list<int> */
    public static function union(array $lists): array
    {
        $out = [];
        foreach ($lists as $l) {
            foreach ($l as $v) {
                $out[$v] = true;
            }
        }
        $out = array_keys($out);
        sort($out, SORT_NUMERIC);

        return $out;
    }
}
