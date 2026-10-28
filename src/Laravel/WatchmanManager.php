<?php

declare(strict_types=1);

namespace Watchman\Laravel;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Watchman\Contracts\WatchmanClient;
use Watchman\Laravel\Clients\HttpClient;
use Watchman\Laravel\Clients\NativeClient;
use Watchman\Search\SearchResponse;

/**
 * Entry point behind the Watchman facade.
 *
 * @mixin WatchmanClient
 */
final class WatchmanManager implements WatchmanClient
{
    /** @var array<string, WatchmanClient> */
    private array $drivers = [];

    public function __construct(private Container $app, private array $config)
    {
    }

    public function driver(?string $name = null): WatchmanClient
    {
        $name ??= (string) ($this->config['driver'] ?? 'native');

        return $this->drivers[$name] ??= match ($name) {
            'native' => $this->app->make(NativeClient::class),
            'http' => $this->app->make(HttpClient::class),
            default => throw new InvalidArgumentException("Unknown watchman driver [$name]"),
        };
    }

    public function search(array $params): SearchResponse
    {
        return $this->driver()->search($params);
    }

    /** Screen a person by name with optional extra fields (birthDate, gov_passport, address, …). */
    public function searchPerson(string $name, array $params = []): SearchResponse
    {
        return $this->search(['type' => 'person', 'name' => $name] + $params);
    }

    /** Screen a business by name. */
    public function searchBusiness(string $name, array $params = []): SearchResponse
    {
        return $this->search(['type' => 'business', 'name' => $name] + $params);
    }

    /** Screen a vessel by name and/or IMO number. */
    public function searchVessel(string $name, array $params = []): SearchResponse
    {
        return $this->search(['type' => 'vessel', 'name' => $name] + $params);
    }

    /**
     * True when the best hit scores at least $minMatch (0.80 is Watchman's usual screening cutoff).
     */
    public function isMatch(array $params, float $minMatch = 0.80): bool
    {
        return !$this->search(['minMatch' => $minMatch, 'limit' => 1] + $params)->isEmpty();
    }

    public function listInfo(): array
    {
        return $this->driver()->listInfo();
    }

    public function refresh(): array
    {
        return $this->driver()->refresh();
    }

    public function refreshStatus(): array
    {
        return $this->driver()->refreshStatus();
    }

    public function ingest(string $fileType, string $contents): array
    {
        return $this->driver()->ingest($fileType, $contents);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->driver()->{$method}(...$args);
    }
}
