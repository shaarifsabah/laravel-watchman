<?php

declare(strict_types=1);

namespace Watchman\Search;

use DateTimeImmutable;
use Watchman\Support\GoTime;

final class Vessel
{
    public const TYPE_UNKNOWN = 'unknown';
    public const TYPE_CARGO = 'cargo';

    /**
     * @param list<string> $altNames
     */
    public function __construct(
        public string $name = '',
        public array $altNames = [],
        public string $imoNumber = '',
        public string $type = '',
        public string $flag = '',
        public ?DateTimeImmutable $built = null,
        public string $model = '',
        public int $tonnage = 0,
        public string $mmsi = '',
        public string $callSign = '',
        public int $grossRegisteredTonnage = 0,
        public string $owner = '',
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['name'] ?? ''),
            Lists::strings($a['altNames'] ?? null),
            (string) ($a['imoNumber'] ?? ''),
            (string) ($a['type'] ?? ''),
            (string) ($a['flag'] ?? ''),
            GoTime::fromJson($a['built'] ?? null),
            (string) ($a['model'] ?? ''),
            (int) ($a['tonnage'] ?? 0),
            (string) ($a['mmsi'] ?? ''),
            (string) ($a['callSign'] ?? ''),
            (int) ($a['grossRegisteredTonnage'] ?? 0),
            (string) ($a['owner'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'altNames' => Lists::nullable($this->altNames),
            'imoNumber' => $this->imoNumber,
            'type' => $this->type,
            'flag' => $this->flag,
            'built' => GoTime::toJson($this->built),
            'model' => $this->model,
            'tonnage' => $this->tonnage,
            'mmsi' => $this->mmsi,
            'callSign' => $this->callSign,
            'grossRegisteredTonnage' => $this->grossRegisteredTonnage,
            'owner' => $this->owner,
        ];
    }
}
