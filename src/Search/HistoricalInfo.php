<?php

declare(strict_types=1);

namespace Watchman\Search;

use DateTimeImmutable;
use Watchman\Support\GoTime;

final class HistoricalInfo
{
    public function __construct(
        public string $type = '',
        public string $value = '',
        public ?DateTimeImmutable $date = null,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self((string) ($a['type'] ?? ''), (string) ($a['value'] ?? ''), GoTime::fromJson($a['date'] ?? null));
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
            'date' => GoTime::toJson($this->date ?? GoTime::zero()),
        ];
    }
}
