<?php

declare(strict_types=1);

namespace Watchman\Index;

use Watchman\Search\Entity;

/**
 * Array-backed corpus (direct equivalent of Go's in-memory corpus).
 */
final class MemoryCorpus implements Corpus
{
    /** @var list<Entity> */
    private array $entities = [];
    /** @var array<string, array<string, list<int>>> src => type => ids */
    private array $partitions = [];
    /** @var array<string, array<string, list<int>>> kind => key => ids */
    private array $postings = [];
    /** @var array<string, array<string, list<int>>> kind => value => ids */
    private array $ids = [];
    /** @var array<string, list<string>> kind => sorted values */
    private array $sortedIds = [];
    private bool $sorted = true;

    public function __construct(private string $name, private ?TfIdf $tfidf = null)
    {
    }

    public function add(Entity $e): void
    {
        $i = count($this->entities);
        $this->entities[] = $e;
        $src = $e->source;
        $type = $e->type;
        $this->partitions[''][''][] = $i;
        if ($src !== '') {
            $this->partitions[$src][''][] = $i;
        }
        if ($type !== '') {
            $this->partitions[''][$type][] = $i;
        }
        if ($src !== '' && $type !== '') {
            $this->partitions[$src][$type][] = $i;
        }
        $r = IndexRecord::fromEntity($e);
        if ($r->name !== '') {
            $this->postings['name'][$r->name][] = $i;
        }
        foreach (['tok' => $r->tokens, 'crypto' => $r->crypto, 'block' => $r->block, 'domain' => $r->domains] as $kind => $keys) {
            foreach ($keys as $k) {
                $this->postings[$kind][$k][] = $i;
            }
        }
        foreach ($r->ids as $kind => $vals) {
            foreach ($vals as $v) {
                $this->ids[$kind][$v][] = $i;
            }
        }
        $this->sorted = false;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sources(): array
    {
        $out = [];
        foreach ($this->partitions as $src => $types) {
            if ($src !== '') {
                $out[$src] = count($types[''] ?? []);
            }
        }

        return $out;
    }

    public function partitionCount(string $src, string $type): int
    {
        return count($this->partitions[$src][$type] ?? []);
    }

    public function partitionIds(string $src, string $type): array
    {
        return $this->partitions[$src][$type] ?? [];
    }

    private function filter(array $ids, string $src, string $type): array
    {
        if ($src === '' && $type === '') {
            return $ids;
        }

        return CandidateSelector::intersect($ids, $this->partitions[$src][$type] ?? []);
    }

    public function postings(string $kind, array $keys, string $src, string $type): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->filter($this->postings[$kind][$k] ?? [], $src, $type);
        }

        return $out;
    }

    public function idLookup(string $kind, array $exact, array $prefixes, string $src, string $type): array
    {
        $lists = [];
        foreach ($exact as $v) {
            if (isset($this->ids[$kind][$v])) {
                $lists[] = $this->ids[$kind][$v];
            }
        }
        if ($prefixes !== []) {
            if (!$this->sorted) {
                foreach ($this->ids as $k => $vals) {
                    $keys = array_map('strval', array_keys($vals));
                    sort($keys, SORT_STRING);
                    $this->sortedIds[$k] = $keys;
                }
                $this->sorted = true;
            }
            $sorted = $this->sortedIds[$kind] ?? [];
            foreach ($prefixes as $p) {
                $lo = 0;
                $hi = count($sorted);
                while ($lo < $hi) {
                    $mid = intdiv($lo + $hi, 2);
                    if (strcmp($sorted[$mid], $p) < 0) {
                        $lo = $mid + 1;
                    } else {
                        $hi = $mid;
                    }
                }
                for ($i = $lo, $n = count($sorted); $i < $n && str_starts_with($sorted[$i], $p); $i++) {
                    $lists[] = $this->ids[$kind][$sorted[$i]];
                }
            }
        }

        return $this->filter(CandidateSelector::union($lists), $src, $type);
    }

    public function entities(array $idxs): array
    {
        $out = [];
        foreach ($idxs as $i) {
            if (isset($this->entities[$i])) {
                $out[$i] = $this->entities[$i];
            }
        }

        return $out;
    }

    public function tfidf(): ?TfIdf
    {
        return $this->tfidf;
    }

    public function count(): int
    {
        return count($this->entities);
    }
}
