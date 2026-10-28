<?php

declare(strict_types=1);

namespace Watchman\Index;

/**
 * Entities to score for one corpus: either an explicit id list, or the full
 * source/type partition (streamed in chunks by the scorer).
 */
final class Candidates
{
    /**
     * @param list<int>|null $idxs null = full partition
     */
    public function __construct(
        public readonly Corpus $corpus,
        public readonly ?array $idxs,
        public readonly string $src,
        public readonly string $type,
        public readonly int $count,
    ) {
    }

    public function isFullPartition(): bool
    {
        return $this->idxs === null;
    }

    /** @return \Generator<int, list<int>> chunks of ids in ascending order */
    public function chunks(int $size = 500): \Generator
    {
        $ids = $this->idxs ?? $this->corpus->partitionIds($this->src, $this->type);
        foreach (array_chunk($ids, max(1, $size)) as $chunk) {
            yield $chunk;
        }
    }
}
