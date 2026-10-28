<?php

declare(strict_types=1);

namespace Watchman\Tests\Feature;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Watchman\Laravel\WatchmanServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (env('WATCHMAN_TEST_STORE') === 'redis') {
            // Start every test from an empty index. Only this suite's keys are removed; other data
            // in the developer's Redis is never touched.
            $redis = new \Watchman\Index\Redis\RedisClient($this->app['redis']->connection()->client());
            $cursor = '0';
            do {
                $res = $redis->command('SCAN', $cursor, 'MATCH', 'watchman-test:*', 'COUNT', '1000');
                $cursor = (string) ($res[0] ?? '0');
                $keys = array_map('strval', (array) ($res[1] ?? []));
                if ($keys !== []) {
                    $redis->command('DEL', ...$keys);
                }
            } while ($cursor !== '0');
        }
    }

    protected function getPackageProviders($app): array
    {
        return [WatchmanServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Watchman' => \Watchman\Laravel\Facades\Watchman::class];
    }

    protected function defineEnvironment($app): void
    {
        $fixtures = dirname(__DIR__) . '/fixtures';
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('watchman.store', env('WATCHMAN_TEST_STORE', 'memory'));
        $app['config']->set('watchman.redis.prefix', 'watchman-test:');
        $app['config']->set('watchman.download.included_lists', ['us_ofac', 'us_csl']);
        $app['config']->set('watchman.download.initial_data_directory', $fixtures . '/ofac');
        $app['config']->set('watchman.download.urls.us_csl', 'file://' . $fixtures . '/%s');
        $app['config']->set('watchman.download.open_sanctions.lists', []);
        $app['config']->set('watchman.download.senzing', [
            ['source_list' => 'us_congress', 'location' => 'file://' . $fixtures . '/us_congress_senzing.jsonl'],
        ]);
        $app['config']->set('watchman.download.ignored_download_errors', []);
        $app['config']->set('watchman.routes.enabled', true);
        $app['config']->set('watchman.routes.middleware', ['api']);
        $app['config']->set('watchman.routes.admin_enabled', true);
        // Stand-in for real authentication middleware (admin routes refuse to register without one).
        $app['router']->aliasMiddleware('watchman.test-auth', PassThroughMiddleware::class);
        $app['config']->set('watchman.routes.admin_middleware', ['api', 'watchman.test-auth']);
        $app['config']->set('watchman.ingest.files', [
            'fincen-person' => [
                'format' => 'csv',
                'mapping' => [
                    'name' => ['merge' => ['first_name', 'suffix', 'middle_name', 'last_name']],
                    'sourceID' => ['column' => 'tracking_number'],
                    'type' => ['default' => 'person'],
                    'person' => [
                        'name' => ['merge' => ['first_name', 'middle_name', 'last_name']],
                        'altNames' => ['merge' => ['alias_first_name', 'alias_suffix', 'alias_middle_name', 'alias_last_name']],
                        'birthDate' => ['column' => 'dob'],
                        'governmentIDs' => ['type' => ['column' => 'number_type'], 'identifier' => ['column' => 'number']],
                    ],
                    'contact' => ['phoneNumbers' => ['columns' => 'phone']],
                    'addresses' => [
                        'line1' => ['columns' => 'street'], 'city' => ['columns' => 'city'], 'state' => ['columns' => 'state'],
                        'postalCode' => ['columns' => 'zip'], 'country' => ['columns' => 'country'],
                    ],
                ],
            ],
        ]);
    }
}
