<?php

declare(strict_types=1);

namespace Watchman\Laravel;

use Illuminate\Contracts\Cache\Repository;

/**
 * Refresh status shared across workers (Watchman's Refresher.Status), kept in the cache.
 */
final class RefreshState
{
    private const KEY = 'watchman:refresh:status';

    /**
     * @param int $maxRunSeconds a "queued" or "running" state older than this is reported as failed
     *                           (the worker was killed before it could record the result)
     */
    public function __construct(private Repository $cache, private int $maxRunSeconds = 7200)
    {
    }

    public function get(): array
    {
        $s = $this->cache->get(self::KEY);
        if (!is_array($s)) {
            return ['state' => 'idle'];
        }
        if (in_array($s['state'] ?? '', ['running', 'queued'], true) && time() > (int) ($s['expiresAt'] ?? PHP_INT_MAX)) {
            $s['state'] = 'failed';
            $s['lastError'] = 'the refresh did not report back within ' . $this->maxRunSeconds . 's (worker stopped or job lost)';
        }
        unset($s['expiresAt']);

        return $s;
    }

    public function start(string $trigger): array
    {
        $s = ['state' => 'running', 'trigger' => $trigger, 'startedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'expiresAt' => time() + $this->maxRunSeconds];
        $this->cache->forever(self::KEY, $s);

        return $s;
    }

    public function queued(string $trigger): array
    {
        $s = ['state' => 'queued', 'trigger' => $trigger, 'queuedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'expiresAt' => time() + $this->maxRunSeconds];
        $this->cache->forever(self::KEY, $s);

        return $s;
    }

    public function finish(float $startedAt, ?string $error): array
    {
        $s = $this->get();
        $s['finishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $s['duration'] = sprintf('%.3fs', microtime(true) - $startedAt);
        if ($error !== null) {
            $s['state'] = 'failed';
            $s['lastError'] = $error;
        } else {
            $s['state'] = 'succeeded';
            unset($s['lastError']);
        }
        $this->cache->forever(self::KEY, $s);

        return $s;
    }
}
