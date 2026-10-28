<?php

declare(strict_types=1);

namespace Watchman\Index\Database;

use Watchman\Index\Codec;
use Watchman\Index\CorpusWriter;
use Watchman\Index\IndexRecord;
use Watchman\Search\Entity;

final class DatabaseCorpusWriter implements CorpusWriter
{
    private int $next = 0;
    private array $entities = [];
    private array $postings = [];
    private array $identifiers = [];
    /** @var array<string,int> */
    private array $df = [];
    private int $documents = 0;
    private array $lists = [];
    private array $sources = [];

    public function __construct(
        private DatabaseStore $store,
        private string $name,
        private string $gen,
        private bool $tfidf = false,
        private int $batch = 500,
    ) {
    }

    public function add(Entity $entity): void
    {
        $i = $this->next++;
        $src = $entity->source;
        $type = $entity->type;
        $this->sources[$src] = ($this->sources[$src] ?? 0) + 1;
        $this->entities[] = [
            'generation' => $this->gen, 'idx' => $i, 'source_list' => $src, 'entity_type' => $type,
            'source_id' => mb_strcut($entity->sourceId, 0, 191, 'UTF-8'), 'payload' => base64_encode(Codec::encode($entity)),
        ];
        $r = IndexRecord::fromEntity($entity);
        $post = static fn (string $kind, string $key) => [
            'generation' => '', 'kind' => $kind, 'pkey' => DatabaseStore::key($key), 'idx' => $i, 'source_list' => $src, 'entity_type' => $type,
        ];
        if ($r->name !== '') {
            $this->postings[] = $post('name', $r->name);
        }
        foreach (['tok' => $r->tokens, 'crypto' => $r->crypto, 'block' => $r->block, 'domain' => $r->domains] as $kind => $keys) {
            foreach ($keys as $k) {
                $this->postings[] = $post($kind, (string) $k);
            }
        }
        foreach ($r->ids as $kind => $vals) {
            foreach ($vals as $v) {
                $this->identifiers[] = [
                    'generation' => $this->gen, 'kind' => $kind, 'value' => DatabaseStore::key((string) $v), 'idx' => $i,
                    'source_list' => $src, 'entity_type' => $type,
                ];
            }
        }
        if ($this->tfidf) {
            $pf = $entity->preparedFields;
            foreach (array_merge([$pf->nameFields], $pf->altNameFields) as $doc) {
                if ($doc === []) {
                    continue;
                }
                $this->documents++;
                foreach (array_unique($doc) as $term) {
                    if ($term !== '') {
                        $this->df[(string) $term] = ($this->df[(string) $term] ?? 0) + 1;
                    }
                }
            }
        }
        if (count($this->entities) >= $this->batch) {
            $this->flush();
        }
    }

    /** Stay under the smallest bound-parameter limit of the supported databases (SQL Server: 2100). */
    private const MAX_BINDINGS = 2000;

    /**
     * Split rows into insert batches whose bound parameters stay under MAX_BINDINGS.
     *
     * @param list<array<string,mixed>> $rows
     * @param int $extraColumns columns added to each row after chunking
     * @return list<list<array<string,mixed>>>
     */
    private static function chunks(array $rows, int $extraColumns = 0): array
    {
        if ($rows === []) {
            return [];
        }
        $cols = count($rows[array_key_first($rows)]) + $extraColumns;

        return array_chunk($rows, max(1, intdiv(self::MAX_BINDINGS, max(1, $cols))));
    }

    private function flush(): void
    {
        $db = $this->store->db();
        foreach (self::chunks($this->entities) as $rows) {
            $db->table($this->store->table('entities'))->insert($rows);
        }
        foreach (self::chunks($this->postings, 1) as $rows) {
            foreach ($rows as &$row) {
                $row['generation'] = $this->gen;
            }
            unset($row);
            $db->table($this->store->table('postings'))->insert($rows);
        }
        foreach (self::chunks($this->identifiers) as $rows) {
            $db->table($this->store->table('identifiers'))->insert($rows);
        }
        $this->entities = $this->postings = $this->identifiers = [];
    }

    public function finishList(string $source, int $count, string $hash): void
    {
        $this->lists[$source] = ['count' => $count, 'hash' => $hash];
    }

    public function commit(array $meta = []): void
    {
        $this->flush();
        if ($this->df !== []) {
            $rows = [];
            foreach ($this->df as $term => $n) {
                $rows[] = ['generation' => $this->gen, 'term' => DatabaseStore::key((string) $term), 'df' => $n];
                if (count($rows) >= intdiv(self::MAX_BINDINGS, 3)) {
                    $this->store->db()->table($this->store->table('terms'))->insert($rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                $this->store->db()->table($this->store->table('terms'))->insert($rows);
            }
        }
        $meta['lists'] = array_map(static fn ($l) => $l['count'], $this->lists) + ($meta['lists'] ?? []);
        $meta['listHashes'] = array_map(static fn ($l) => $l['hash'], $this->lists) + ($meta['listHashes'] ?? []);
        $meta['sources'] = $this->sources;
        $meta['entities'] = $this->next;
        $meta['tfidf'] = ['enabled' => $this->tfidf, 'documents' => $this->documents];
        $this->store->activate($this->name, $this->gen, $meta);
    }

    public function abort(): void
    {
        $this->entities = $this->postings = $this->identifiers = [];
        $this->store->deleteGeneration($this->gen);
    }
}
