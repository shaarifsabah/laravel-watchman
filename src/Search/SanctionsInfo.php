<?php

declare(strict_types=1);

namespace Watchman\Search;

final class SanctionsInfo
{
    /**
     * @param list<string> $programs
     */
    public function __construct(
        public array $programs = [],
        public bool $secondary = false,
        public string $description = '',
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(array_values(array_map('strval', (array) ($a['programs'] ?? []))), (bool) ($a['secondary'] ?? false), (string) ($a['description'] ?? ''));
    }

    public function toArray(): array
    {
        return [
            'programs' => $this->programs === [] ? null : $this->programs,
            'secondary' => $this->secondary,
            'description' => $this->description,
        ];
    }
}
