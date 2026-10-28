<?php

declare(strict_types=1);

namespace Watchman\Laravel\Console;

use Illuminate\Console\Command;
use Watchman\Index\Database\DatabaseStore;
use Watchman\Index\IndexStore;
use Watchman\Index\Redis\RedisStore;

final class GcCommand extends Command
{
    protected $signature = 'watchman:gc';

    protected $description = 'Delete index generations left behind by interrupted refreshes';

    public function handle(IndexStore $store): int
    {
        if ($store instanceof RedisStore || $store instanceof DatabaseStore) {
            $this->info('Removed ' . $store->gc() . ' stale generation(s).');
        } else {
            $this->info('Nothing to clean for this store.');
        }

        return self::SUCCESS;
    }
}
