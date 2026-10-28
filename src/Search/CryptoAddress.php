<?php

declare(strict_types=1);

namespace Watchman\Search;

final class CryptoAddress
{
    public function __construct(
        public string $currency = '',
        public string $address = '',
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self((string) ($a['currency'] ?? ''), (string) ($a['address'] ?? ''));
    }

    public function toArray(): array
    {
        return ['currency' => $this->currency, 'address' => $this->address];
    }
}
