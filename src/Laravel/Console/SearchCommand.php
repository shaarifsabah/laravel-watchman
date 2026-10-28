<?php

declare(strict_types=1);

namespace Watchman\Laravel\Console;

use Illuminate\Console\Command;
use Throwable;
use Watchman\Laravel\WatchmanManager;

final class SearchCommand extends Command
{
    protected $signature = 'watchman:search
        {name : Name to screen}
        {--type= : person, business, organization, vessel or aircraft}
        {--limit=10}
        {--min-match=0}
        {--param=* : Extra /v2/search parameters as key=value (e.g. birthDate=1993-04-17, gov_passport=RU:123)}
        {--debug : Show field-level score details}
        {--json : Print the raw JSON response}';

    protected $description = 'Screen a name against the sanctions lists';

    public function handle(WatchmanManager $watchman): int
    {
        $params = ['name' => (string) $this->argument('name'), 'limit' => (string) $this->option('limit'), 'minMatch' => (string) $this->option('min-match')];
        if ($this->option('type')) {
            $params['type'] = (string) $this->option('type');
        }
        if ($this->option('debug')) {
            $params['debug'] = 'true';
        }
        foreach ((array) $this->option('param') as $kv) {
            [$k, $v] = array_pad(explode('=', (string) $kv, 2), 2, '');
            if (isset($params[$k]) && !is_array($params[$k])) {
                $params[$k] = [$params[$k]];
            }
            $params[$k][] = $v;
        }
        try {
            $t = microtime(true);
            $res = $watchman->search($params);
            $ms = (microtime(true) - $t) * 1000;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($res->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $rows = [];
        foreach ($res->entities as $hit) {
            $rows[] = [number_format($hit->match, 4), $hit->source, $hit->sourceId, $hit->type, $hit->name];
        }
        $this->table(['Match', 'List', 'Source ID', 'Type', 'Name'], $rows);
        $this->line(sprintf('%d hit(s) in %.1f ms', count($rows), $ms));
        if ($this->option('debug')) {
            foreach ($res->entities as $hit) {
                if ($hit->debug !== '') {
                    $this->newLine();
                    $this->info($hit->sourceId . ' ' . $hit->name);
                    $this->line((string) base64_decode($hit->debug));
                }
            }
        }

        return self::SUCCESS;
    }
}
