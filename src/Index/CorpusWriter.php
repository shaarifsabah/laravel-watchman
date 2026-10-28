<?php

declare(strict_types=1);

namespace Watchman\Index;

use Watchman\Search\Entity;

/**
 * Builds a new corpus generation. Nothing is visible to searches until commit().
 */
interface CorpusWriter
{
    /** Add a normalized entity. */
    public function add(Entity $entity): void;

    /** Record list metadata (entity count and content hash). */
    public function finishList(string $source, int $count, string $hash): void;

    /** Atomically publish this generation and drop the previous one. */
    public function commit(array $meta = []): void;

    /** Discard everything written so far. */
    public function abort(): void;
}
