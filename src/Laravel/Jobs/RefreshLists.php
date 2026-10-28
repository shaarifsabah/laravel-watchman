<?php

declare(strict_types=1);

namespace Watchman\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Watchman\Laravel\Clients\NativeClient;

/**
 * Downloads all configured lists and swaps in a new search index.
 */
final class RefreshLists implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 7200;
    public int $tries = 1;
    public int $uniqueFor = 7200;

    public function __construct(public string $trigger = 'scheduled')
    {
    }

    public function handle(NativeClient $client): void
    {
        $client->refresh($this->trigger);
    }
}
