<?php

declare(strict_types=1);

namespace Watchman\Search;

final class GovernmentID
{
    public function __construct(
        public string $type = '',
        public string $country = '',
        public string $identifier = '',
        public string $name = '',
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['type'] ?? ''),
            (string) ($a['country'] ?? ''),
            (string) ($a['identifier'] ?? ''),
            (string) ($a['name'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'country' => $this->country,
            'identifier' => $this->identifier,
        ];
    }
}
