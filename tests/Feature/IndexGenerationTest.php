<?php

declare(strict_types=1);

namespace Watchman\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Watchman\Index\Database\DatabaseStore;
use Watchman\Index\IndexStore;
use Watchman\Index\Redis\RedisClient;
use Watchman\Index\Redis\RedisStore;
use Watchman\Search\Entity;
use Watchman\Search\Person;

/**
 * Generation switching between processes: each store instance below plays the part of a separate
 * PHP process (an Octane or queue worker, a refresh job, `watchman:gc`).
 */
final class IndexGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function store(int $retireAfter = 300, int $buildTimeout = 7200): RedisStore|DatabaseStore
    {
        $kind = env('WATCHMAN_TEST_STORE', 'memory');
        if ($kind === 'redis') {
            return new RedisStore(new RedisClient($this->app['redis']->connection()->client()), 'watchman-test:', [], $retireAfter, $buildTimeout, 0.0);
        }
        if ($kind === 'database') {
            return new DatabaseStore($this->app['db']->connection(), 'watchman_', [], $retireAfter, $buildTimeout, 0.0);
        }
        $this->markTestSkipped('generation switching only applies to the redis and database stores');
    }

    /** Build and activate a "lists" generation holding the given names. */
    private function build(IndexStore $store, array $names, ?callable $beforeCommit = null): void
    {
        $w = $store->writer(IndexStore::LISTS);
        foreach ($names as $i => $name) {
            $e = new Entity($name, 'person', 'us_ofac', (string) ($i + 1));
            $e->person = new Person($name);
            $w->add($e->normalize());
        }
        $w->finishList('us_ofac', count($names), 'hash-' . count($names));
        if ($beforeCommit !== null) {
            $beforeCommit();
        }
        $w->commit();
    }

    private function entityCount(IndexStore $store): int
    {
        $c = $store->corpus(IndexStore::LISTS);

        return $c === null ? 0 : $c->partitionCount('us_ofac', 'person');
    }

    public function test_long_running_worker_follows_a_refresh_made_elsewhere(): void
    {
        $worker = $this->store();
        $refresher = $this->store();

        $this->build($refresher, ['Nicolas Maduro', 'Dmitry Khoroshev']);
        $this->assertSame(2, $this->entityCount($worker));

        $this->build($refresher, ['Nicolas Maduro', 'Dmitry Khoroshev', 'Maria E Lopez']);
        $this->assertSame(3, $this->entityCount($worker), 'the worker must see the new generation');

        $c = $worker->corpus(IndexStore::LISTS);
        $this->assertCount(3, $c->entities($c->partitionIds('us_ofac', 'person')));
    }

    public function test_replaced_generation_survives_the_grace_period(): void
    {
        $worker = $this->store();
        $refresher = $this->store();

        $this->build($refresher, ['Nicolas Maduro']);
        $old = $worker->corpus(IndexStore::LISTS);   // a search in progress holds this generation
        $this->build($refresher, ['Nicolas Maduro', 'Dmitry Khoroshev']);

        $this->assertCount(1, $old->entities($old->partitionIds('us_ofac', 'person')), 'old generation must stay readable');

        // Once the grace period has passed, the next activation or gc removes it.
        $this->assertSame(1, $this->store(retireAfter: 0)->gc());
        $this->assertSame(2, $this->entityCount($this->store()));
    }

    public function test_gc_leaves_a_refresh_that_is_still_being_built(): void
    {
        $refresher = $this->store();
        $names = [];
        for ($i = 0; $i < 600; $i++) {
            $names[] = "Person Number $i Example";
        }

        $this->build($refresher, $names, function (): void {
            $this->assertSame(0, $this->store()->gc(), 'gc must not delete an in-progress build');
        });

        $this->assertSame(600, $this->entityCount($this->store()));
    }

    public function test_gc_keeps_the_active_index_intact_including_single_letter_tokens(): void
    {
        $refresher = $this->store();
        $this->build($refresher, ['Maria E Lopez', 'Nicolas Maduro']);
        $c = $refresher->corpus(IndexStore::LISTS);
        $before = $c->postings('tok', ['e', 'maduro'], 'us_ofac', 'person');

        $this->assertSame(0, $this->store(retireAfter: 0, buildTimeout: 0)->gc());

        $c = $this->store()->corpus(IndexStore::LISTS);
        $this->assertEquals($before, $c->postings('tok', ['e', 'maduro'], 'us_ofac', 'person'));
        $this->assertSame(2, $this->entityCount($this->store()));
    }
}
