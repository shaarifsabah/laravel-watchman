<?php

declare(strict_types=1);

namespace Watchman\Search;

use DateTimeImmutable;
use Watchman\Support\GoTime;

final class Aircraft
{
    public const TYPE_UNKNOWN = 'unknown';
    public const TYPE_CARGO = 'cargo';

    /**
     * @param list<string> $altNames
     */
    public function __construct(
        public string $name = '',
        public array $altNames = [],
        public string $type = '',
        public string $flag = '',
        public ?DateTimeImmutable $built = null,
        public string $icaoCode = '',
        public string $model = '',
        public string $serialNumber = '',
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['name'] ?? ''),
            Lists::strings($a['altNames'] ?? null),
            (string) ($a['type'] ?? ''),
            (string) ($a['flag'] ?? ''),
            GoTime::fromJson($a['built'] ?? null),
            (string) ($a['icaoCode'] ?? ''),
            (string) ($a['model'] ?? ''),
            (string) ($a['serialNumber'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'altNames' => Lists::nullable($this->altNames),
            'type' => $this->type,
            'flag' => $this->flag,
            'built' => GoTime::toJson($this->built),
            'icaoCode' => $this->icaoCode,
            'model' => $this->model,
            'serialNumber' => $this->serialNumber,
        ];
    }
}
