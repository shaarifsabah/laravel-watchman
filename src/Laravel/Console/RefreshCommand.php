<?php

declare(strict_types=1);

namespace Watchman\Laravel\Console;

use Illuminate\Console\Command;
use Throwable;
use Watchman\Laravel\Clients\NativeClient;
use Watchman\Laravel\Jobs\RefreshLists;
use Watchman\Laravel\WatchmanManager;

final class RefreshCommand extends Command
{
    protected $signature = 'watchman:refresh
        {--queue : Dispatch a queued job instead of running now}
        {--trigger=manual : Trigger name recorded in the refresh status}';

    protected $description = 'Download the configured sanctions lists and rebuild the search index';

    public function handle(WatchmanManager $watchman): int
    {
        $client = $watchman->driver();
        if ($this->option('queue') && $client instanceof NativeClient) {
            $job = RefreshLists::dispatch((string) $this->option('trigger'));
            $cfg = config('watchman.refresh', []);
            if (!empty($cfg['queue_connection'])) {
                $job->onConnection($cfg['queue_connection']);
            }
            if (!empty($cfg['queue'])) {
                $job->onQueue($cfg['queue']);
            }
            unset($job);
            $this->info('Refresh job dispatched.');

            return self::SUCCESS;
        }

        $extra = array_merge(
            (array) config('watchman.download.open_sanctions.lists', []),
            (array) config('watchman.download.senzing', []),
        );
        $names = array_merge(
            (array) config('watchman.download.included_lists', []),
            array_filter(array_map(static fn ($l) => is_array($l) ? (string) ($l['source_list'] ?? '') : '', $extra)),
        );
        $this->info('Refreshing lists: ' . implode(', ', array_unique($names)));
        $start = microtime(true);
        try {
            $stats = $client instanceof NativeClient ? $client->refresh((string) $this->option('trigger')) : $client->refresh();
        } catch (Throwable $e) {
            $this->error('Refresh failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        foreach ((array) ($stats['warnings'] ?? []) as $w) {
            $this->warn($w);
        }
        $rows = [];
        foreach ((array) ($stats['lists'] ?? []) as $list => $count) {
            $rows[] = [$list, number_format((int) $count), substr((string) ($stats['listHashes'][$list] ?? ''), 0, 16)];
        }
        if ($rows !== []) {
            $this->table(['List', 'Entities', 'Hash'], $rows);
        }
        $this->info(sprintf('Done in %.1fs (peak memory %d MB).', microtime(true) - $start, memory_get_peak_usage(true) / 1048576));

        return self::SUCCESS;
    }
}
