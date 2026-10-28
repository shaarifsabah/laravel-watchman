<?php

declare(strict_types=1);

namespace Watchman\Search;

use DateTimeImmutable;
use Watchman\Support\GoTime;

/**
 * Used for both Business and Organization records (identical shape in Go).
 */
class Business
{
    /**
     * @param list<string>       $altNames
     * @param list<GovernmentID> $governmentIDs
     */
    public function __construct(
        public string $name = '',
        public array $altNames = [],
        public ?DateTimeImmutable $created = null,
        public ?DateTimeImmutable $dissolved = null,
        public array $governmentIDs = [],
    ) {
    }

    public static function fromArray(array $a): static
    {
        return new static(
            (string) ($a['name'] ?? ''),
            Lists::strings($a['altNames'] ?? null),
            GoTime::fromJson($a['created'] ?? null),
            GoTime::fromJson($a['dissolved'] ?? null),
            Lists::govIds($a['governmentIDs'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'altNames' => Lists::nullable($this->altNames),
            'created' => GoTime::toJson($this->created),
            'dissolved' => GoTime::toJson($this->dissolved),
            'governmentIDs' => Lists::arrays($this->governmentIDs),
        ];
    }
}
