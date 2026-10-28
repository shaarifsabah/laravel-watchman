<?php

declare(strict_types=1);

namespace Watchman\Index\Database;

use Illuminate\Database\ConnectionInterface;
use Watchman\Index\Codec;
use Watchman\Index\Corpus;
use Watchman\Index\CorpusWriter;
use Watchman\Index\IndexStore;
use Watchman\Index\TfIdf;

/**
 * SQL-backed index (MySQL, PostgreSQL, SQLite, SQL Server) for hosts without Redis.
 * Tables are created by the package migration (watchman_* tables).
 */
final class DatabaseStore implements IndexStore
{
    /** @var array<string, DatabaseCorpus>|null */
    private ?array $cache = null;

    /** @var array<string,string> corpus => generation the cache was built from */
    private array $cachedPointers = [];

    private float $checkedAt = 0.0;

    /**
     * @param int $retireAfterSeconds how long a replaced generation is kept for searches still running on it
     * @param int $buildTimeoutSeconds age after which a never-activated generation counts as abandoned
     */
    public function __construct(
        private ConnectionInterface $db,
        private string $tablePrefix = 'watchman_',
        private array $tfidfConfig = [],
        private int $retireAfterSeconds = 300,
        private int $buildTimeoutSeconds = 7200,
        private float $pointerCheckSeconds = 1.0,
    ) {
    }

    public function db(): ConnectionInterface
    {
        return $this->db;
    }

    public function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    public function corpora(): array
    {
        // Re-check the active generations (at most once per $pointerCheckSeconds) so long-running
        // workers follow refreshes made by other processes.
        $now = microtime(true);
        if ($this->cache !== null && ($now - $this->checkedAt) < $this->pointerCheckSeconds) {
            return $this->cache;
        }
        $rows = $this->db->table($this->table('generations'))->where('active', true)->orderBy('corpus')->get();
        $this->checkedAt = $now;
        $pointers = [];
        foreach ($rows as $row) {
            $pointers[(string) $row->corpus] = (string) $row->id;
        }
        if ($this->cache !== null && $pointers === $this->cachedPointers) {
            return $this->cache;
        }
        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta, true) ?: [];
            if (($meta['schema'] ?? null) !== Codec::SCHEMA) {
                continue;
            }
            $out[(string) $row->corpus] = new DatabaseCorpus($this, (string) $row->corpus, (string) $row->id, $meta, $this->tfidfConfig);
        }
        $this->cachedPointers = $pointers;

        return $this->cache = $out;
    }

    public function flushCache(): void
    {
        $this->cache = null;
        $this->cachedPointers = [];
    }

    public function corpus(string $name): ?Corpus
    {
        return $this->corpora()[$name] ?? null;
    }

    public function writer(string $name, ?TfIdf $tfidf = null): CorpusWriter
    {
        $gen = 'g' . gmdate('YmdHis') . bin2hex(random_bytes(3));
        $this->db->table($this->table('generations'))->insert([
            'id' => $gen, 'corpus' => $name, 'active' => false, 'meta' => '{}',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return new DatabaseCorpusWriter($this, $name, $gen, $tfidf !== null && $tfidf->enabled());
    }

    /** @internal */
    public function activate(string $name, string $gen, array $meta): void
    {
        $meta['schema'] = Codec::SCHEMA;
        $this->db->transaction(function () use ($name, $gen, $meta) {
            // Only the generation that was active is retired; other inactive generations may be builds
            // still in progress in another process and are left to gc().
            // Lock this corpus's rows so two concurrent activations (e.g. two ingests of one file type)
            // run one after the other and never leave two active generations.
            $rows = $this->db->table($this->table('generations'))->where('corpus', $name)->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ((string) $row->id !== $gen && (bool) $row->active) {
                    $this->retire((string) $row->id, (string) $row->meta);
                }
            }
            $this->db->table($this->table('generations'))->where('id', $gen)->update([
                'active' => true,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        });
        $this->flushCache();
        $this->purgeRetired();
    }

    public function drop(string $name): void
    {
        $this->db->transaction(function () use ($name) {
            $rows = $this->db->table($this->table('generations'))->where('corpus', $name)->where('active', true)->get();
            foreach ($rows as $row) {
                $this->retire((string) $row->id, (string) $row->meta);
            }
        });
        $this->flushCache();
        $this->purgeRetired();
    }

    private function retire(string $gen, string $metaJson): void
    {
        $meta = json_decode($metaJson, true) ?: [];
        $meta['retiredAt'] = time();
        $this->db->table($this->table('generations'))->where('id', $gen)->update([
            'active' => false,
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** Delete retired generations whose grace period has passed. */
    public function purgeRetired(): int
    {
        $n = 0;
        $rows = $this->db->table($this->table('generations'))->where('active', false)->get();
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta, true) ?: [];
            if (isset($meta['retiredAt']) && time() - (int) $meta['retiredAt'] >= $this->retireAfterSeconds) {
                $this->deleteGeneration((string) $row->id);
                $n++;
            }
        }

        return $n;
    }

    /** @internal */
    public function deleteGeneration(string $gen): void
    {
        foreach (['postings', 'identifiers', 'terms', 'entities'] as $t) {
            do {
                // chunked deletes keep transactions/locks small on large lists
                $ids = $this->db->table($this->table($t))->where('generation', $gen)->limit(1000)->pluck('id')->all();
                if ($ids !== []) {
                    $this->db->table($this->table($t))->whereIn('id', $ids)->delete();
                }
            } while (count($ids) === 1000);
        }
        $this->db->table($this->table('generations'))->where('id', $gen)->delete();
    }

    public function meta(): array
    {
        $out = [];
        foreach ($this->corpora() as $name => $c) {
            $out[$name] = $c->meta();
        }

        return $out;
    }

    /**
     * Delete generations nobody uses any more: retired ones past their grace period and never-activated
     * ones (crashed or killed builds) older than the build timeout. Builds still running are left alone.
     */
    public function gc(): int
    {
        $n = $this->purgeRetired();
        $rows = $this->db->table($this->table('generations'))
            ->where('active', false)
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', time() - $this->buildTimeoutSeconds))
            ->get();
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta, true) ?: [];
            if (!isset($meta['retiredAt'])) {
                $this->deleteGeneration((string) $row->id);
                $n++;
            }
        }

        return $n;
    }

    /** Keys longer than the indexed column are stored hashed. */
    public static function key(string $k): string
    {
        return strlen($k) > 191 ? 'h:' . sha1($k) : $k;
    }
}
