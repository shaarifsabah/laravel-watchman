<?php

declare(strict_types=1);

namespace Watchman\Index\Redis;

use Watchman\Index\Codec;
use Watchman\Index\Corpus;
use Watchman\Index\CorpusWriter;
use Watchman\Index\IndexStore;
use Watchman\Index\TfIdf;

/**
 * Redis-backed index: one shared in-memory index for every PHP worker.
 *
 * Layout (all keys under the configured prefix, e.g. "watchman:"):
 *   active                 hash  corpus name => generation id
 *   retired                hash  generation id => unix time it stopped being active
 *   meta:{gen}             string JSON metadata
 *   {gen}:e                hash  idx => encoded entity
 *   {gen}:p:{src}:{type}   set   partition ids ("*" = all)
 *   {gen}:k:{kind}:{key}   set   posting list
 *   {gen}:i:{kind}         zset  "value\0idx" members for exact/prefix identifier lookups
 *   {gen}:df               hash  token => document frequency (TF-IDF)
 */
final class RedisStore implements IndexStore
{
    /** Generation ids look like g20261028123456a1b2c3 (UTC timestamp + random suffix). */
    public const GENERATION_PATTERN = '/^g(\d{14})[0-9a-f]{6}$/';

    /** @var array<string, RedisCorpus>|null */
    private ?array $cache = null;

    /** @var array<string,string> pointers the cache was built from */
    private array $cachedPointers = [];

    private float $checkedAt = 0.0;

    /**
     * @param int $retireAfterSeconds how long a replaced generation is kept, so searches already running
     *                                 in other processes can finish against it
     * @param int $buildTimeoutSeconds age after which an inactive, never-activated generation counts as abandoned
     */
    public function __construct(
        private RedisClient $redis,
        private string $prefix = 'watchman:',
        private array $tfidfConfig = [],
        private int $retireAfterSeconds = 300,
        private int $buildTimeoutSeconds = 7200,
        private float $pointerCheckSeconds = 1.0,
    ) {
    }

    public function client(): RedisClient
    {
        return $this->redis;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function corpora(): array
    {
        // Re-read the active pointers (at most once per $pointerCheckSeconds) so long-running workers
        // (Octane, queue workers) follow refreshes made by other processes.
        $now = microtime(true);
        if ($this->cache !== null && ($now - $this->checkedAt) < $this->pointerCheckSeconds) {
            return $this->cache;
        }
        $active = $this->hgetall($this->prefix . 'active');
        $this->checkedAt = $now;
        if ($this->cache !== null && $active === $this->cachedPointers) {
            return $this->cache;
        }
        $out = [];
        if ($active !== []) {
            $metas = $this->redis->pipeline(array_map(fn ($gen) => ['GET', $this->prefix . 'meta:' . $gen], array_values($active)));
            $i = 0;
            foreach ($active as $name => $gen) {
                $meta = is_string($metas[$i] ?? null) ? (json_decode($metas[$i], true) ?: []) : [];
                $i++;
                if (($meta['schema'] ?? null) !== Codec::SCHEMA) {
                    continue; // written by an incompatible version: needs a refresh
                }
                $out[(string) $name] = new RedisCorpus($this->redis, $this->prefix, (string) $name, (string) $gen, $meta, $this->tfidfConfig);
            }
        }
        $this->cachedPointers = $active;

        return $this->cache = $out;
    }

    /** Forget cached generation pointers (call after a refresh in long-running workers). */
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

        return new RedisCorpusWriter($this, $this->redis, $this->prefix, $name, $gen, $tfidf !== null && $tfidf->enabled());
    }

    /** @internal */
    public function activate(string $name, string $gen, array $meta): void
    {
        $old = $this->redis->command('HGET', $this->prefix . 'active', $name);
        $meta['schema'] = Codec::SCHEMA;
        $cmds = [
            ['SET', $this->prefix . 'meta:' . $gen, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ['HSET', $this->prefix . 'active', $name, $gen],
        ];
        if (is_string($old) && $old !== '' && $old !== $gen) {
            // Keep the replaced generation for a grace period: other workers may be mid-search on it.
            $cmds[] = ['HSET', $this->prefix . 'retired', $old, (string) time()];
        }
        $this->redis->pipeline($cmds);
        $this->flushCache();
        $this->purgeRetired();
    }

    public function drop(string $name): void
    {
        $gen = $this->redis->command('HGET', $this->prefix . 'active', $name);
        $this->redis->command('HDEL', $this->prefix . 'active', $name);
        if (is_string($gen) && $gen !== '') {
            $this->redis->command('HSET', $this->prefix . 'retired', $gen, (string) time());
        }
        $this->flushCache();
        $this->purgeRetired();
    }

    /** Delete retired generations whose grace period has passed. Returns how many were deleted. */
    public function purgeRetired(): int
    {
        $n = 0;
        $active = array_flip(array_values($this->hgetall($this->prefix . 'active')));
        foreach ($this->hgetall($this->prefix . 'retired') as $gen => $at) {
            if (isset($active[$gen])) {
                $this->redis->command('HDEL', $this->prefix . 'retired', $gen);
                continue;
            }
            if (time() - (int) $at >= $this->retireAfterSeconds) {
                $this->deleteGeneration($gen);
                $n++;
            }
        }

        return $n;
    }

    /** @internal */
    public function deleteGeneration(string $gen): void
    {
        if (!preg_match(self::GENERATION_PATTERN, $gen)) {
            return; // never SCAN/UNLINK with an unexpected pattern
        }
        $this->redis->command('DEL', $this->prefix . 'meta:' . $gen);
        $cursor = '0';
        do {
            $res = $this->redis->command('SCAN', $cursor, 'MATCH', $this->prefix . $gen . ':*', 'COUNT', '2000');
            if (!is_array($res) || count($res) < 2) {
                break;
            }
            $cursor = (string) $res[0];
            $keys = (array) $res[1];
            if ($keys !== []) {
                $this->redis->command('UNLINK', ...array_map('strval', $keys));
            }
        } while ($cursor !== '0');
        $this->redis->command('HDEL', $this->prefix . 'retired', $gen);
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
     * Delete generations nobody uses any more: retired ones past their grace period, and never-activated
     * ones (crashed or killed refreshes) older than the build timeout. A refresh still being built is
     * younger than the build timeout and is left alone.
     */
    public function gc(): int
    {
        $n = $this->purgeRetired();
        $active = array_flip(array_values($this->hgetall($this->prefix . 'active')));
        $retired = $this->hgetall($this->prefix . 'retired');
        $gens = [];
        $cursor = '0';
        $plen = strlen($this->prefix);
        do {
            $res = $this->redis->command('SCAN', $cursor, 'MATCH', $this->prefix . 'g*', 'COUNT', '2000');
            $cursor = (string) ($res[0] ?? '0');
            foreach ((array) ($res[1] ?? []) as $key) {
                $rest = substr((string) $key, $plen);
                $gen = strstr($rest, ':', true);
                if ($gen !== false && preg_match(self::GENERATION_PATTERN, $gen)) {
                    $gens[$gen] = true;
                }
            }
        } while ($cursor !== '0');
        foreach (array_keys($gens) as $gen) {
            $gen = (string) $gen;
            if (isset($active[$gen]) || isset($retired[$gen])) {
                continue;
            }
            if (self::generationAge($gen) >= $this->buildTimeoutSeconds) {
                $this->deleteGeneration($gen);
                $n++;
            }
        }

        return $n;
    }

    /** Seconds since the generation was started (from the UTC timestamp in its id). */
    public static function generationAge(string $gen): int
    {
        if (!preg_match(self::GENERATION_PATTERN, $gen, $m)) {
            return 0;
        }
        $t = \DateTimeImmutable::createFromFormat('YmdHis', $m[1], new \DateTimeZone('UTC'));

        return $t === false ? 0 : max(0, time() - $t->getTimestamp());
    }

    /** @return array<string,string> */
    private function hgetall(string $key): array
    {
        $res = $this->redis->command('HGETALL', $key);
        if (!is_array($res)) {
            return [];
        }
        if (array_is_list($res)) {
            $out = [];
            for ($i = 0; $i + 1 < count($res); $i += 2) {
                $out[(string) $res[$i]] = (string) $res[$i + 1];
            }

            return $out;
        }

        return array_map('strval', $res);
    }

    /** Memory policy check (returns a warning or null). */
    public function evictionWarning(): ?string
    {
        try {
            $res = $this->redis->command('CONFIG', 'GET', 'maxmemory-policy');
        } catch (\Throwable) {
            return null;
        }
        $policy = is_array($res) ? (string) (array_values($res)[1] ?? '') : '';
        if ($policy !== '' && $policy !== 'noeviction' && !str_starts_with($policy, 'volatile')) {
            return "Redis maxmemory-policy is '$policy': keys of the sanctions index can be evicted. Use a dedicated Redis database/instance with 'noeviction'.";
        }

        return null;
    }
}
