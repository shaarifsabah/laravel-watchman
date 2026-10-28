<?php

declare(strict_types=1);

namespace Watchman\Support;

/**
 * Loads generated lookup tables from resources/data (cached per process; OPcache
 * keeps these immutable arrays in shared memory).
 */
final class Data
{
    /** @var array<string, mixed> */
    private static array $cache = [];

    public static function get(string $name): mixed
    {
        return self::$cache[$name] ??= require dirname(__DIR__, 2) . '/resources/data/' . $name . '.php';
    }
}
