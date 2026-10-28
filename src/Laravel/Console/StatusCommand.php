<?php

declare(strict_types=1);

namespace Watchman\Laravel\Console;

use Illuminate\Console\Command;
use Watchman\Index\IndexStore;
use Watchman\Index\Redis\RedisStore;
use Watchman\Laravel\WatchmanManager;

final class StatusCommand extends Command
{
    protected $signature = 'watchman:status';

    protected $description = 'Show loaded lists, index store and refresh status';

    public function handle(WatchmanManager $watchman): int
    {
        $this->line('Driver: <info>' . config('watchman.driver') . '</info>' . (config('watchman.driver') === 'native' ? '   Store: <info>' . config('watchman.store') . '</info>' : ''));
        $info = $watchman->listInfo();
        $rows = [];
        foreach ((array) ($info['lists'] ?? []) as $list => $count) {
            $rows[] = [$list, number_format((int) $count), substr((string) (((array) $info['listHashes'])[$list] ?? ''), 0, 16)];
        }
        if ($rows === []) {
            $this->warn('No lists are indexed yet. Run: php artisan watchman:refresh');
        } else {
            $this->table(['List', 'Entities', 'Hash'], $rows);
            $this->line('Last refresh: ' . ($info['startedAt'] ?? '?') . ' → ' . ($info['endedAt'] ?? '?'));
        }
        $status = $watchman->refreshStatus();
        $this->line('Refresh status: ' . json_encode($status, JSON_UNESCAPED_SLASHES));
        $this->line('Version: ' . ($info['version'] ?? ''));

        if (config('watchman.driver') === 'native') {
            $store = app(IndexStore::class);
            if ($store instanceof RedisStore && ($w = $store->evictionWarning()) !== null) {
                $this->warn($w);
            }
        }

        return self::SUCCESS;
    }
}
