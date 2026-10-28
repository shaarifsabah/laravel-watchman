<?php

declare(strict_types=1);

namespace Watchman\Index\Redis;

use RuntimeException;

/**
 * Raw-command Redis adapter over phpredis (\Redis / \RedisCluster not supported) or Predis.
 *
 * Raw commands bypass client-side key prefixing, so the store applies its own prefix.
 */
final class RedisClient
{
    public function __construct(private object $client)
    {
        if (!$client instanceof \Redis && !is_a($client, 'Predis\ClientInterface')) {
            throw new RuntimeException('Unsupported Redis client ' . $client::class . ' (use phpredis or predis)');
        }
    }

    public function command(string ...$args): mixed
    {
        if ($this->client instanceof \Redis) {
            $res = $this->client->rawCommand(...$args);
            if ($res === false && ($err = $this->client->getLastError()) !== null) {
                $this->client->clearLastError();
                throw new RuntimeException('Redis error: ' . $err);
            }

            return $res;
        }

        return $this->client->executeRaw($args);
    }

    /**
     * Run many commands in one round trip.
     *
     * @param list<list<string>> $commands
     * @return list<mixed>
     */
    public function pipeline(array $commands): array
    {
        if ($commands === []) {
            return [];
        }
        if ($this->client instanceof \Redis) {
            $pipe = $this->client->multi(\Redis::PIPELINE);
            foreach ($commands as $c) {
                $pipe->rawCommand(...$c);
            }
            $res = $pipe->exec();

            return is_array($res) ? array_values($res) : [];
        }

        return array_values($this->client->pipeline(function ($pipe) use ($commands) {
            foreach ($commands as $c) {
                $raw = class_exists('Predis\Command\RawCommand')
                    ? (method_exists('Predis\Command\RawCommand', 'create') ? \Predis\Command\RawCommand::create(...$c) : new \Predis\Command\RawCommand(array_shift($c), $c))
                    : throw new RuntimeException('Predis RawCommand unavailable');
                $pipe->executeCommand($raw);
            }
        }));
    }
}
