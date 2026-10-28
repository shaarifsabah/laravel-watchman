<?php

declare(strict_types=1);

namespace Watchman\Laravel\Clients;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Watchman\Contracts\WatchmanClient;
use Watchman\Download\ListRefresher;
use Watchman\Index\IndexStore;
use Watchman\Ingest\IngestService;
use Watchman\Laravel\RefreshState;
use Watchman\Search\Entity;
use Watchman\Search\QueryParser;
use Watchman\Search\SearchOptions;
use Watchman\Search\SearchResponse;
use Watchman\Search\SearchService;
use Watchman\Version;

/**
 * Runs Watchman inside the Laravel application.
 */
final class NativeClient implements WatchmanClient
{
    public function __construct(
        private IndexStore $store,
        private SearchService $search,
        private ListRefresher $refresher,
        private IngestService $ingest,
        private RefreshState $state,
        private Repository $cache,
        private array $config = [],
    ) {
    }

    public function search(array $params): SearchResponse
    {
        $parser = new QueryParser($params);
        $query = $parser->entity();
        $opts = $parser->options(
            (int) ($this->config['search']['default_limit'] ?? 10),
            (int) ($this->config['search']['max_limit'] ?? 100),
        );
        $parser->get('format'); // HTTP-only option, accepted and ignored here
        if (($extra = $parser->unused()) !== []) {
            // Same rule as Watchman's HTTP API: a misspelled field must not be silently dropped.
            throw new \InvalidArgumentException('extra/unused query parameters in request: ' . implode(',', $extra));
        }

        return $this->searchEntity($query, $opts);
    }

    /** Search with a prepared query entity (call ->normalize() first) and options. */
    public function searchEntity(Entity $query, SearchOptions $options): SearchResponse
    {
        if ($this->store->corpora() === []) {
            // An empty index would answer "no matches" for everyone: fail loudly instead.
            throw new RuntimeException('no sanctions lists are loaded; run `php artisan watchman:refresh`');
        }

        return new SearchResponse($query, $this->search->search($query, $options));
    }

    public function listInfo(): array
    {
        $lists = [];
        $hashes = [];
        $startedAt = null;
        $endedAt = null;
        foreach ($this->store->meta() as $name => $meta) {
            $lists += (array) ($meta['lists'] ?? []);
            $hashes += (array) ($meta['listHashes'] ?? []);
            if ($name === IndexStore::LISTS) {
                $startedAt = $meta['startedAt'] ?? null;
                $endedAt = $meta['endedAt'] ?? null;
            }
        }
        ksort($lists);
        ksort($hashes);

        return [
            'lists' => (object) $lists,
            'listHashes' => (object) $hashes,
            'startedAt' => $startedAt ?? '0001-01-01T00:00:00Z',
            'endedAt' => $endedAt ?? '0001-01-01T00:00:00Z',
            'version' => Version::string(),
        ];
    }

    public function refresh(string $trigger = 'manual'): array
    {
        $lock = $this->cache->lock('watchman:refresh:lock', (int) ($this->config['refresh']['lock_seconds'] ?? 7200));
        if (!$lock->get()) {
            throw new RuntimeException('a data refresh is already running');
        }
        $limit = (string) ($this->config['refresh']['memory_limit'] ?? '');
        if ($limit !== '') {
            @ini_set('memory_limit', $limit);
        }
        @set_time_limit(0);
        $start = microtime(true);
        $this->state->start($trigger);
        try {
            $stats = $this->refresher->refresh();
            $this->rebuildIngest();
            $this->state->finish($start, null);
            Log::info('watchman: data refreshed', ['lists' => $stats['lists'], 'seconds' => round(microtime(true) - $start, 1)]);

            return $stats;
        } catch (Throwable $e) {
            $this->state->finish($start, $e->getMessage());
            Log::error('watchman: data refresh failed: ' . $e->getMessage());

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function refreshStatus(): array
    {
        return $this->state->get();
    }

    /**
     * Parse an uploaded file, persist its rows and rebuild that ingest corpus.
     */
    public function ingest(string $fileType, string $contents): array
    {
        $res = $this->ingest->read($fileType, $contents);
        $this->persistIngest($res['fileType'], $res['entities']);
        $this->indexIngest($res['fileType'], $res['entities']);

        return ['fileType' => $res['fileType'], 'entities' => array_map(static fn (Entity $e) => $e->toArray(), $res['entities'])];
    }

    /** Rebuild ingest corpora from the database (e.g. after a Redis restart). */
    public function rebuildIngest(): void
    {
        $db = $this->ingestDb();
        if ($db === null) {
            return;
        }
        try {
            $types = $db->table($this->ingestTable())->distinct()->pluck('file_type')->all();
        } catch (Throwable) {
            return; // migration not run
        }
        foreach ($types as $type) {
            $entities = [];
            foreach ($db->table($this->ingestTable())->where('file_type', $type)->orderBy('source_id')->cursor() as $row) {
                $entities[] = Entity::fromArray(json_decode((string) $row->entity, true) ?: [])->normalize();
            }
            $this->indexIngest((string) $type, $entities);
        }
    }

    /** @param list<Entity> $entities */
    private function indexIngest(string $fileType, array $entities): void
    {
        $writer = $this->store->writer('ingest:' . $fileType);
        try {
            foreach ($entities as $e) {
                $writer->add($e);
            }
            $hash = hash('sha256', implode("\n", array_map(static fn (Entity $e) => $e->sourceId, $entities)));
            $writer->finishList($fileType, count($entities), $hash);
            $writer->commit(['version' => Version::string()]);
        } catch (Throwable $e) {
            $writer->abort();
            throw $e;
        }
    }

    /** @param list<Entity> $entities */
    private function persistIngest(string $fileType, array $entities): void
    {
        $db = $this->ingestDb();
        if ($db === null) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $db->transaction(function () use ($db, $fileType, $entities, $now) {
            $db->table($this->ingestTable())->where('file_type', $fileType)->delete();
            foreach (array_chunk($entities, 200) as $chunk) {
                $db->table($this->ingestTable())->insert(array_map(static fn (Entity $e) => [
                    'file_type' => $fileType,
                    'source_id' => mb_strcut($e->sourceId, 0, 191, 'UTF-8'),
                    'entity' => json_encode($e->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });
    }

    private function ingestDb(): ?\Illuminate\Database\ConnectionInterface
    {
        try {
            return app('db')->connection($this->config['database']['connection'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    private function ingestTable(): string
    {
        return ($this->config['database']['table_prefix'] ?? 'watchman_') . 'ingested_entities';
    }
}
