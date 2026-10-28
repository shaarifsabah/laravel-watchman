<?php

declare(strict_types=1);

namespace Watchman\Laravel\Clients;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use Watchman\Contracts\WatchmanClient;
use Watchman\Search\Entity;
use Watchman\Search\SearchedEntity;
use Watchman\Search\SearchResponse;

/**
 * Talks to a running moov-io/watchman Go server (same API as the native driver).
 */
final class HttpClient implements WatchmanClient
{
    public function __construct(private Factory $http, private array $config = [])
    {
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? 'http://localhost:8084'), '/'))
            ->timeout((int) ($this->config['timeout'] ?? 10))
            ->withHeaders((array) ($this->config['headers'] ?? []))
            ->acceptJson();
    }

    /** Build a Go-style query string (repeated keys, no [] suffix). */
    public static function queryString(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            foreach (is_array($v) ? $v : [$v] as $item) {
                if ($item === null) {
                    continue;
                }
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                }
                $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    public function search(array $params): SearchResponse
    {
        $res = $this->request()->get('/v2/search?' . self::queryString($params));
        $this->ensure($res);
        $body = (array) $res->json();

        return new SearchResponse(
            Entity::fromArray((array) ($body['query'] ?? [])),
            array_map(static fn ($e) => SearchedEntity::fromArray((array) $e), (array) ($body['entities'] ?? [])),
        );
    }

    public function listInfo(): array
    {
        $res = $this->request()->get('/v2/listinfo');
        $this->ensure($res);

        return (array) $res->json();
    }

    public function refresh(): array
    {
        $res = $this->request()->post('/v2/data/refresh');
        if ($res->status() !== 202 && $res->status() !== 409) {
            $this->ensure($res);
        }

        return (array) $res->json();
    }

    public function refreshStatus(): array
    {
        $res = $this->request()->get('/v2/data/refresh');
        $this->ensure($res);

        return (array) $res->json();
    }

    public function ingest(string $fileType, string $contents): array
    {
        $res = $this->request()->withBody($contents, 'text/csv')->post('/v2/ingest/' . rawurlencode($fileType));
        $this->ensure($res);

        return (array) $res->json();
    }

    private function ensure(\Illuminate\Http\Client\Response $res): void
    {
        if ($res->failed()) {
            $msg = $res->json('error') ?? $res->body();
            throw new RuntimeException('watchman server error (' . $res->status() . '): ' . $msg);
        }
    }
}
