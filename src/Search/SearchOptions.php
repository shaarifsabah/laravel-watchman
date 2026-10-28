<?php

declare(strict_types=1);

namespace Watchman\Search;

final class SearchOptions
{
    /**
     * @param list<string> $debugSourceIds
     */
    public function __construct(
        public int $limit = 10,
        public float $minMatch = 0.0,
        public bool $debug = false,
        public float $debugMinMatch = 0.0,
        public string $algorithm = '',
        public string $requestId = '',
        public array $debugSourceIds = [],
    ) {
    }
}
