<?php

declare(strict_types=1);

namespace Watchman\Index\Database;

use Watchman\Index\Codec;
use Watchman\Index\Corpus;
use Watchman\Index\TfIdf;

final class DatabaseCorpus implements Corpus
{
    private ?TfIdf $tfidf = null;
    /** @var array<string,int> */
    private array $counts = [];

    public function __construct(
        private DatabaseStore $store,
        private string $name,
        private string $gen,
        private array $meta,
        array $tfidfConfig = [],
    ) {
        if (!empty($meta['tfidf']['enabled'])) {
            $this->tfidf = TfIdf::fromConfig($tfidfConfig + ['enabled' => true], (int) ($meta['tfidf']['documents'] ?? 0), function (array $terms): array {
                $out = [];
                foreach (array_chunk($terms, 500) as $chunk) {
                    $rows = $this->store->db()->table($this->store->table('terms'))
                        ->where('generation', $this->gen)
                        ->whereIn('term', array_map([DatabaseStore::class, 'key'], $chunk))
                        ->get(['term', 'df']);
                    foreach ($rows as $r) {
                        $out[(string) $r->term] = (int) $r->df;
                    }
                }

                return $out;
            });
        }
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sources(): array
    {
        return array_map('intval', (array) ($this->meta['sources'] ?? []));
    }

    private function scope($q, string $src, string $type)
    {
        $q->where('generation', $this->gen);
        if ($src !== '') {
            $q->where('source_list', $src);
        }
        if ($type !== '') {
            $q->where('entity_type', $type);
        }

        return $q;
    }

    public function partitionCount(string $src, string $type): int
    {
        $k = $src . "\0" . $type;
        if (!isset($this->counts[$k])) {
            if ($src === '' && $type === '') {
                $this->counts[$k] = (int) ($this->meta['entities'] ?? 0);
            } else {
                $this->counts[$k] = (int) $this->scope($this->store->db()->table($this->store->table('entities')), $src, $type)->count();
            }
        }

        return $this->counts[$k];
    }

    public function partitionIds(string $src, string $type): array
    {
        return array_map('intval', $this->scope($this->store->db()->table($this->store->table('entities')), $src, $type)
            ->orderBy('idx')->pluck('idx')->all());
    }

    public function postings(string $kind, array $keys, string $src, string $type): array
    {
        $out = [];
        $map = [];
        foreach ($keys as $k) {
            $out[$k] = [];
            $map[DatabaseStore::key($k)][] = $k;
        }
        foreach (array_chunk(array_keys($map), 500) as $chunk) {
            $rows = $this->scope($this->store->db()->table($this->store->table('postings')), $src, $type)
                ->where('kind', $kind)
                ->whereIn('pkey', array_map('strval', $chunk))
                ->get(['pkey', 'idx']);
            foreach ($rows as $r) {
                foreach ($map[(string) $r->pkey] ?? [] as $orig) {
                    $out[$orig][] = (int) $r->idx;
                }
            }
        }
        foreach ($out as &$ids) {
            sort($ids, SORT_NUMERIC);
            $ids = array_values(array_unique($ids));
        }

        return $out;
    }

    public function idLookup(string $kind, array $exact, array $prefixes, string $src, string $type): array
    {
        if ($exact === [] && $prefixes === []) {
            return [];
        }
        $q = $this->scope($this->store->db()->table($this->store->table('identifiers')), $src, $type)
            ->where('kind', $kind)
            ->where(function ($w) use ($exact, $prefixes) {
                if ($exact !== []) {
                    $w->orWhereIn('value', array_map([DatabaseStore::class, 'key'], $exact));
                }
                foreach ($prefixes as $p) {
                    $w->orWhere('value', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $p) . '%');
                }
            });
        $ids = array_values(array_unique(array_map('intval', $q->pluck('idx')->all())));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function entities(array $idxs): array
    {
        $out = [];
        foreach (array_chunk($idxs, 500) as $chunk) {
            $rows = $this->store->db()->table($this->store->table('entities'))
                ->where('generation', $this->gen)->whereIn('idx', $chunk)->get(['idx', 'payload']);
            $byIdx = [];
            foreach ($rows as $r) {
                $byIdx[(int) $r->idx] = $r->payload;
            }
            foreach ($chunk as $idx) {
                if (isset($byIdx[$idx])) {
                    $out[$idx] = Codec::decode((string) base64_decode((string) $byIdx[$idx], true));
                }
            }
        }

        return $out;
    }

    public function tfidf(): ?TfIdf
    {
        return $this->tfidf;
    }
}
