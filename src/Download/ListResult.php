<?php

declare(strict_types=1);

namespace Watchman\Download;

use Watchman\Search\Entity;

/**
 * Entities parsed from one source list plus the list's content hash.
 */
final class ListResult
{
    /**
     * @param iterable<Entity> $entities (may be a generator to stream large lists)
     */
    public function __construct(
        public readonly string $source,
        public readonly iterable $entities,
        public readonly string $hash,
    ) {
    }
}
