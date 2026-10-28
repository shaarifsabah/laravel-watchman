<?php

declare(strict_types=1);

namespace Watchman\Search;

final class Affiliation
{
    public function __construct(
        public string $entityName = '',
        public string $type = '',
        public string $details = '',
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self((string) ($a['entityName'] ?? ''), (string) ($a['type'] ?? ''), (string) ($a['details'] ?? ''));
    }

    public function toArray(): array
    {
        $out = ['entityName' => $this->entityName, 'type' => $this->type];
        if ($this->details !== '') {
            $out['details'] = $this->details;
        }

        return $out;
    }
}
