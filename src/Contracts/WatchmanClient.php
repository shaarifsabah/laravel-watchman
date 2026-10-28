<?php

declare(strict_types=1);

namespace Watchman\Contracts;

use Watchman\Search\SearchResponse;

/**
 * Common API for the native (PHP) and http (Go server) drivers.
 */
interface WatchmanClient
{
    /**
     * Search the sanctions lists.
     *
     * $params uses the /v2/search query parameter names, e.g.
     *   ['type' => 'person', 'name' => 'Dmitry Khoroshev', 'birthDate' => '1993-04-17',
     *    'gov_passport' => 'RU:2018278055', 'minMatch' => 0.8, 'limit' => 5, 'debug' => true]
     * Repeatable fields (altNames, address, email, phone, cryptoAddress, …) accept arrays.
     */
    public function search(array $params): SearchResponse;

    /** GET /v2/listinfo: {lists, listHashes, startedAt, endedAt, version} */
    public function listInfo(): array;

    /** Run a refresh now (blocking) and return the new list info. */
    public function refresh(): array;

    /** GET /v2/data/refresh status: {state, trigger, startedAt, finishedAt, duration, lastError} */
    public function refreshStatus(): array;

    /**
     * Ingest a custom file for a configured fileType.
     *
     * @return array{fileType: string, entities: list<array>}
     */
    public function ingest(string $fileType, string $contents): array;
}
