<?php

declare(strict_types=1);

namespace Watchman\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Watchman\Laravel\WatchmanManager;

/**
 * @method static \Watchman\Search\SearchResponse search(array $params)
 * @method static \Watchman\Search\SearchResponse searchPerson(string $name, array $params = [])
 * @method static \Watchman\Search\SearchResponse searchBusiness(string $name, array $params = [])
 * @method static \Watchman\Search\SearchResponse searchVessel(string $name, array $params = [])
 * @method static bool isMatch(array $params, float $minMatch = 0.80)
 * @method static array listInfo()
 * @method static array refresh()
 * @method static array refreshStatus()
 * @method static array ingest(string $fileType, string $contents)
 * @method static \Watchman\Contracts\WatchmanClient driver(?string $name = null)
 *
 * @see WatchmanManager
 */
final class Watchman extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WatchmanManager::class;
    }
}
