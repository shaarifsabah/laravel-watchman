<?php

declare(strict_types=1);

namespace Watchman\Search;

use JsonSerializable;

/** /v2/search response: {"query": …, "entities": […]} */
final class SearchResponse implements JsonSerializable
{
    /**
     * @param list<SearchedEntity> $entities
     */
    public function __construct(
        public Entity $query,
        public array $entities = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'query' => $this->query->toArray(),
            'entities' => array_map(static fn (SearchedEntity $e) => $e->toArray(), $this->entities),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function first(): ?SearchedEntity
    {
        return $this->entities[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->entities === [];
    }
}
