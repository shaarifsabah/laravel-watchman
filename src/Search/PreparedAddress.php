<?php

declare(strict_types=1);

namespace Watchman\Search;

final class PreparedAddress
{
    /**
     * @param list<string> $line1Fields
     * @param list<string> $line2Fields
     * @param list<string> $cityFields
     */
    public function __construct(
        public string $line1 = '',
        public array $line1Fields = [],
        public string $line2 = '',
        public array $line2Fields = [],
        public string $city = '',
        public array $cityFields = [],
        public string $postalCode = '',
        public string $state = '',
        public string $country = '',
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['line1'] ?? ''), (array) ($a['line1Fields'] ?? []),
            (string) ($a['line2'] ?? ''), (array) ($a['line2Fields'] ?? []),
            (string) ($a['city'] ?? ''), (array) ($a['cityFields'] ?? []),
            (string) ($a['postalCode'] ?? ''), (string) ($a['state'] ?? ''), (string) ($a['country'] ?? ''),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
