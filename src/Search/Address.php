<?php

declare(strict_types=1);

namespace Watchman\Search;

final class Address
{
    public function __construct(
        public string $line1 = '',
        public string $line2 = '',
        public string $city = '',
        public string $postalCode = '',
        public string $state = '',
        public string $country = '',
        public float $latitude = 0.0,
        public float $longitude = 0.0,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['line1'] ?? ''),
            (string) ($a['line2'] ?? ''),
            (string) ($a['city'] ?? ''),
            (string) ($a['postalCode'] ?? ''),
            (string) ($a['state'] ?? ''),
            (string) ($a['country'] ?? ''),
            (float) ($a['latitude'] ?? 0),
            (float) ($a['longitude'] ?? 0),
        );
    }

    public function format(): string
    {
        $out = sprintf('%s %s %s %s %s %s', $this->line1, $this->line2, $this->city, $this->postalCode, $this->state, $this->country);

        return str_replace('  ', ' ', trim($out));
    }

    public function toArray(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'postalCode' => $this->postalCode,
            'state' => $this->state,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
