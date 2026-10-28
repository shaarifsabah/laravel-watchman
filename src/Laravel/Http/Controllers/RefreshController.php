<?php

declare(strict_types=1);

namespace Watchman\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Watchman\Laravel\Clients\NativeClient;
use Watchman\Laravel\Jobs\RefreshLists;
use Watchman\Laravel\RefreshState;
use Watchman\Laravel\WatchmanManager;

/**
 * GET/POST /v2/data/refresh (internal/download/api_refresh.go).
 */
final class RefreshController extends Controller
{
    public function __construct(private WatchmanManager $watchman, private RefreshState $state)
    {
    }

    public function status(): JsonResponse
    {
        $status = $this->watchman->refreshStatus();
        if (isset($status['lastError'])) {
            // Error text can contain connection or SQL details: keep it in the log and artisan output.
            $status['lastError'] = 'refresh failed; see the application log or `php artisan watchman:status`';
        }

        return SearchController::json($status);
    }

    public function refresh(): JsonResponse
    {
        $client = $this->watchman->driver();
        if (!$client instanceof NativeClient) {
            return SearchController::json($client->refresh(), 202);
        }
        $current = $this->state->get();
        if (in_array($current['state'] ?? 'idle', ['running', 'queued'], true)) {
            return SearchController::json($current, 409);
        }
        $this->state->queued('manual');
        $job = RefreshLists::dispatch('manual');
        $cfg = config('watchman.refresh', []);
        if (!empty($cfg['queue_connection'])) {
            $job->onConnection($cfg['queue_connection']);
        }
        if (!empty($cfg['queue'])) {
            $job->onQueue($cfg['queue']);
        }
        unset($job); // dispatch happens on destruct

        return SearchController::json($this->state->get(), 202);
    }
}
