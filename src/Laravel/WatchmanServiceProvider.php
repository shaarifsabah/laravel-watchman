<?php

declare(strict_types=1);

namespace Watchman\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Watchman\Contracts\WatchmanClient;
use Watchman\Download\Downloader;
use Watchman\Download\ListRefresher;
use Watchman\Index\Database\DatabaseStore;
use Watchman\Index\IndexStore;
use Watchman\Index\MemoryStore;
use Watchman\Index\Redis\RedisClient;
use Watchman\Index\Redis\RedisStore;
use Watchman\Ingest\IngestService;
use Watchman\Laravel\Clients\HttpClient;
use Watchman\Laravel\Clients\NativeClient;
use Watchman\Laravel\Jobs\RefreshLists;
use Watchman\Prepare\Stopwords;
use Watchman\Search\Algorithm;
use Watchman\Search\SearchService;
use Watchman\StringScore\Params;
use Watchman\Version;

final class WatchmanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/watchman.php', 'watchman');

        $this->app->singleton(IndexStore::class, function (Application $app) {
            $cfg = $app['config']['watchman'];
            $tfidf = (array) ($cfg['tfidf'] ?? []);
            $retire = (int) ($cfg['index']['retire_after_seconds'] ?? 300);
            $buildTimeout = max((int) ($cfg['refresh']['lock_seconds'] ?? 7200), 600);

            return match ($cfg['store'] ?? 'redis') {
                'redis' => new RedisStore(
                    new RedisClient($app['redis']->connection($cfg['redis']['connection'] ?? 'default')->client()),
                    (string) ($cfg['redis']['prefix'] ?? 'watchman:'),
                    $tfidf,
                    $retire,
                    $buildTimeout,
                ),
                'database' => new DatabaseStore(
                    $app['db']->connection($cfg['database']['connection'] ?? null),
                    (string) ($cfg['database']['table_prefix'] ?? 'watchman_'),
                    $tfidf,
                    $retire,
                    $buildTimeout,
                ),
                'memory' => new MemoryStore(),
                default => throw new InvalidArgumentException('Unknown watchman store [' . $cfg['store'] . '] (redis, database, memory)'),
            };
        });

        $this->app->bind(Downloader::class, function (Application $app) {
            $cfg = $app['config']['watchman.download'];
            $tmp = $cfg['temp_directory'] ?: $app->storagePath('app/watchman');

            return new Downloader(
                (int) ($cfg['timeout'] ?? 120),
                Version::string(),
                $tmp,
                static function (string $level, string $message): void {
                    Log::log($level === 'warning' ? 'warning' : $level, 'watchman: ' . $message);
                },
            );
        });

        $this->app->bind(ListRefresher::class, function (Application $app) {
            $cfg = $app['config']['watchman'];

            return new ListRefresher(
                $app->make(IndexStore::class),
                $app->make(Downloader::class),
                ((array) $cfg['download']) + ['tfidf' => (array) ($cfg['tfidf'] ?? [])],
                static function (string $level, string $message): void {
                    Log::log($level, 'watchman: ' . $message);
                },
            );
        });

        $this->app->singleton(SearchService::class, fn (Application $app) => new SearchService(
            $app->make(IndexStore::class),
            (int) $app['config']['watchman.search.chunk_size'] ?: 500,
        ));

        $this->app->singleton(IngestService::class, fn (Application $app) => new IngestService(
            (array) $app['config']['watchman.ingest.files'],
            (int) ($app['config']['watchman.ingest.max_decompressed_bytes'] ?? 268435456),
        ));

        $this->app->singleton(RefreshState::class, function (Application $app) {
            $store = $app['config']['watchman.refresh.cache_store'];

            return new RefreshState(
                $app['cache']->store($store ?: null),
                (int) ($app['config']['watchman.refresh.lock_seconds'] ?? 7200),
            );
        });

        $this->app->singleton(NativeClient::class, function (Application $app) {
            $store = $app['config']['watchman.refresh.cache_store'];

            return new NativeClient(
                $app->make(IndexStore::class),
                $app->make(SearchService::class),
                $app->make(ListRefresher::class),
                $app->make(IngestService::class),
                $app->make(RefreshState::class),
                $app['cache']->store($store ?: null),
                (array) $app['config']['watchman'],
            );
        });

        $this->app->singleton(HttpClient::class, fn (Application $app) => new HttpClient($app->make(HttpFactory::class), (array) $app['config']['watchman.http']));

        $this->app->singleton(WatchmanManager::class, fn (Application $app) => new WatchmanManager($app, (array) $app['config']['watchman']));
        $this->app->alias(WatchmanManager::class, WatchmanClient::class);
        $this->app->alias(WatchmanManager::class, 'watchman');
    }

    /**
     * Whether a middleware list contains authentication/authorization middleware, recognised by name:
     * "auth…" (auth, auth:sanctum, auth.basic), "can:…", or a class name containing "Auth".
     *
     * @param array<int, mixed> $middleware
     */
    public static function hasAuthMiddleware(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (!is_string($m)) {
                continue;
            }
            $name = explode(':', $m)[0];
            if (str_starts_with($name, 'auth') || $name === 'can' || stripos(class_basename($name), 'auth') !== false) {
                return true;
            }
        }

        return false;
    }

    public function boot(): void
    {
        $this->applyScoringConfig();
        \Watchman\Index\Codec::useIgbinary(($this->app['config']['watchman.index.serializer'] ?? 'auto') !== 'php');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/watchman.php' => $this->app->configPath('watchman.php')], 'watchman-config');
            $this->publishes([__DIR__ . '/../../database/migrations' => $this->app->databasePath('migrations')], 'watchman-migrations');
            $this->commands([
                Console\RefreshCommand::class,
                Console\SearchCommand::class,
                Console\StatusCommand::class,
                Console\GcCommand::class,
            ]);
        }
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app['config']['watchman.routes.enabled'] ?? false) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/watchman.php');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $cfg = $this->app['config']['watchman'];
            if (($cfg['driver'] ?? 'native') !== 'native' || !($cfg['refresh']['schedule'] ?? true)) {
                return;
            }
            $event = $schedule->job(new RefreshLists('scheduled'), $cfg['refresh']['queue'] ?? null, $cfg['refresh']['queue_connection'] ?? null)
                ->cron((string) ($cfg['refresh']['cron'] ?? '17 */12 * * *'))
                ->name('watchman:refresh');
            if (method_exists($event, 'onOneServer')) {
                $event->onOneServer();
            }
        });
    }

    private function applyScoringConfig(): void
    {
        $scoring = (array) ($this->app['config']['watchman.scoring'] ?? []);
        Params::set(Params::fromEnvironment($scoring));
        Algorithm::reset();
        $keep = $scoring['KEEP_STOPWORDS'] ?? null;
        if ($keep !== null && $keep !== '') {
            Stopwords::setKeepStopwords(filter_var($keep, FILTER_VALIDATE_BOOLEAN));
        }
    }
}
