<?php

declare(strict_types=1);

namespace Watchman\Search;

final class ContactInfo
{
    /**
     * @param list<string> $emailAddresses
     * @param list<string> $phoneNumbers
     * @param list<string> $faxNumbers
     * @param list<string> $websites
     * @param list<string> $domains query-only (?domain=); list records leave it empty
     */
    public function __construct(
        public array $emailAddresses = [],
        public array $phoneNumbers = [],
        public array $faxNumbers = [],
        public array $websites = [],
        public array $domains = [],
    ) {
    }

    public static function fromArray(array $a): self
    {
        $l = static fn ($v) => array_values(array_map('strval', (array) ($v ?? [])));

        return new self($l($a['emailAddresses'] ?? null), $l($a['phoneNumbers'] ?? null), $l($a['faxNumbers'] ?? null), $l($a['websites'] ?? null), $l($a['domains'] ?? null));
    }

    public function toArray(): array
    {
        $n = static fn (array $v) => $v === [] ? null : $v;
        $out = [
            'emailAddresses' => $n($this->emailAddresses),
            'phoneNumbers' => $n($this->phoneNumbers),
            'faxNumbers' => $n($this->faxNumbers),
            'websites' => $n($this->websites),
        ];
        if ($this->domains !== []) {
            $out['domains'] = $this->domains;
        }

        return $out;
    }
}
