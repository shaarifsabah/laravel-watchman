<?php

declare(strict_types=1);

namespace Watchman\Search;

use DateTimeImmutable;
use Watchman\Support\GoTime;

final class Person
{
    /**
     * @param list<string>       $altNames
     * @param list<string>       $titles
     * @param list<GovernmentID> $governmentIDs
     */
    public function __construct(
        public string $name = '',
        public array $altNames = [],
        public string $gender = '',
        public ?DateTimeImmutable $birthDate = null,
        public string $placeOfBirth = '',
        public ?DateTimeImmutable $deathDate = null,
        public array $titles = [],
        public array $governmentIDs = [],
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['name'] ?? ''),
            Lists::strings($a['altNames'] ?? null),
            (string) ($a['gender'] ?? ''),
            GoTime::fromJson($a['birthDate'] ?? null),
            (string) ($a['placeOfBirth'] ?? ''),
            GoTime::fromJson($a['deathDate'] ?? null),
            Lists::strings($a['titles'] ?? null),
            Lists::govIds($a['governmentIDs'] ?? null),
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'altNames' => Lists::nullable($this->altNames),
            'gender' => $this->gender,
            'birthDate' => GoTime::toJson($this->birthDate),
            'placeOfBirth' => $this->placeOfBirth,
            'deathDate' => GoTime::toJson($this->deathDate),
            'titles' => Lists::nullable($this->titles),
            'governmentIDs' => Lists::arrays($this->governmentIDs),
        ];
    }
}
